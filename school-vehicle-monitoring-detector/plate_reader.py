"""
Gate direction work, Phase B: the plate of one pass, from many frames.

1. Find the plate in every full-resolution frame of the pass with a small
   plate detector (YOLO, models/license_plate_detector.pt), on the vehicle's
   crop only.
2. Score every plate crop: sharp (Laplacian at one fixed height, so small
   and big crops compare), big (its width in pixels, about 150 px is enough),
   straight (its width / height), and how sure the detector is.
3. Read the best crops with a plate OCR model made for cropped plates
   (fast-plate-ocr, models/plate_ocr/, a few milliseconds a crop, much
   better on motion blur than EasyOCR; EasyOCR when it is missing), the
   mirrored crop too when nothing valid was read (a mirrored picture), into
   PH plate layouts with position-safe corrections (anpr.py).
4. Vote character by character over the crops (plate_voting.vote_plate).

The sharpest plate crop is kept for the record and the guard, even when the
plate could not be read. Without the model file the detector falls back to
the older way (anpr.read_license_plate_details on the vehicle).
"""

import os
import time
from functools import lru_cache
from pathlib import Path

import cv2
import numpy as np

import anpr
from config import model_file
from plate_voting import frames_agree, vote_plate

PLATE_MODEL_FILE = os.environ.get("DETECTOR_PLATE_MODEL") or "license_plate_detector.pt"
# The plate OCR model (models/plate_ocr/<name>.onnx and <name>_plate_config.yaml).
PLATE_OCR_MODEL = os.environ.get("DETECTOR_PLATE_OCR_MODEL") or "cct_s_v2_global"
# Frames of one pass looked at, and plate crops read (the best ones).
MAX_FRAMES = 10
MAX_OCR_CROPS = 8
# EasyOCR (without the plate OCR model) is slow: fewer crops.
MAX_EASYOCR_CROPS = 5
OCR_BUDGET_SECONDS = 10.0
PLATE_CONFIDENCE = 0.25
# A plate this wide (pixels) or more has enough detail to read.
GOOD_PLATE_WIDTH = 150.0
# Laplacian variance (crop at 64 px high) where a crop counts as half sharp.
HALF_SHARP = 600.0
# Plate width / height: car plates about 2.7, motorcycle plates about 1.7.
PLATE_ASPECT = (1.3, 5.0)


@lru_cache(maxsize=1)
def plate_detector():
    """The plate model, or None when its file is missing (then the older way is used)."""
    path = model_file(PLATE_MODEL_FILE)
    if not Path(path).exists():
        return None
    try:
        from ultralytics import YOLO

        return YOLO(str(path))
    except Exception:
        return None


@lru_cache(maxsize=1)
def plate_ocr():
    """The plate OCR model (fast-plate-ocr), or None when it or its files are missing (then EasyOCR)."""
    folder = Path(model_file("plate_ocr"))
    onnx, config = folder / f"{PLATE_OCR_MODEL}.onnx", folder / f"{PLATE_OCR_MODEL}_plate_config.yaml"
    if not onnx.exists() or not config.exists():
        return None
    try:
        from fast_plate_ocr import LicensePlateRecognizer

        return LicensePlateRecognizer(onnx_model_path=onnx, plate_config_path=config, device="cpu")
    except Exception:
        return None


def read_with_plate_ocr(engine, crop):
    """[(plate "ABC-1234", confidence)] from the plate OCR model, or [] when it is not a PH plate layout."""
    image = cv2.cvtColor(crop, cv2.COLOR_BGR2RGB) if engine.config.image_color_mode == "rgb" else cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
    prediction = engine.run(image, return_confidence=True)[0]
    text = str(prediction.plate or "")
    plate = anpr.normalize_plate_text(text)
    if not plate:
        return []
    probabilities = [float(value) for value in list(prediction.char_probs)[:len(text)]] or [0.0]
    # The weakest character counts as much as the average.
    confidence = 0.5 * (sum(probabilities) / len(probabilities)) + 0.5 * min(probabilities)
    return [(plate, round(confidence, 3))]


@lru_cache(maxsize=1)
def inference_device():
    try:
        import torch

        if torch.cuda.is_available():
            return 0
        if getattr(torch.backends, "mps", None) and torch.backends.mps.is_available():
            return "mps"
    except Exception:
        pass
    return "cpu"


def available():
    return plate_detector() is not None


def vehicle_region(frame, xyxy, padding=0.06):
    height, width = frame.shape[:2]
    x1, y1, x2, y2 = [float(value) for value in xyxy]
    pad_x, pad_y = (x2 - x1) * padding, (y2 - y1) * padding
    left, top = max(0, int(x1 - pad_x)), max(0, int(y1 - pad_y))
    right, bottom = min(width, int(x2 + pad_x)), min(height, int(y2 + pad_y))
    return left, top, right, bottom


def plate_crop(frame, xyxy):
    """The plate with a small margin (the detector's box is tight)."""
    height, width = frame.shape[:2]
    x1, y1, x2, y2 = xyxy
    pad_x, pad_y = (x2 - x1) * 0.04, (y2 - y1) * 0.10
    left, top = max(0, int(x1 - pad_x)), max(0, int(y1 - pad_y))
    right, bottom = min(width, int(x2 + pad_x)), min(height, int(y2 + pad_y))
    return frame[top:bottom, left:right]


def sharpness(crop):
    """Laplacian variance of the crop at 64 px high (comparable between small and big crops)."""
    height, width = crop.shape[:2]
    if height < 4 or width < 4:
        return 0.0
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY) if crop.ndim == 3 else crop
    gray = cv2.resize(gray, (max(8, int(64 * width / height)), 64), interpolation=cv2.INTER_AREA if height > 64 else cv2.INTER_CUBIC)
    return float(cv2.Laplacian(gray, cv2.CV_64F).var())


def crop_quality(crop, confidence):
    """0-1: detector confidence x sharp x big x straight."""
    height, width = crop.shape[:2]
    if height < 4 or width < 4:
        return 0.0, {}
    sharp = sharpness(crop)
    sharp_share = sharp / (sharp + HALF_SHARP)
    size_share = min(1.0, width / GOOD_PLATE_WIDTH)
    aspect = width / float(height)
    straight = 1.0 if PLATE_ASPECT[0] <= aspect <= PLATE_ASPECT[1] else 0.5
    quality = float(confidence) * (0.2 + 0.8 * sharp_share) * (0.3 + 0.7 * size_share) * straight
    return quality, {"width_px": width, "height_px": height, "sharpness": round(sharp, 1), "aspect": round(aspect, 2)}


def prepared_variants(crop):
    """Enlarged to ~100 px high, grey, contrast evened out (CLAHE); and a sharpened copy."""
    height, width = crop.shape[:2]
    scale = min(4.0, 100.0 / max(height, 1))
    big = cv2.resize(crop, (max(1, int(width * scale)), max(1, int(height * scale))), interpolation=cv2.INTER_CUBIC)
    gray = cv2.cvtColor(big, cv2.COLOR_BGR2GRAY)
    even = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(4, 8)).apply(gray)
    blurred = cv2.GaussianBlur(even, (0, 0), 2.0)
    sharpened = cv2.addWeighted(even, 1.6, blurred, -0.6, 0)
    return [even, sharpened]


def read_crop(crop):
    """[(plate "ABC-1234", confidence), ...] for one plate crop (mirrored too when nothing was read)."""
    engine = plate_ocr()
    if engine is not None:
        try:
            reads = read_with_plate_ocr(engine, crop)
            if not reads:
                reads = [(plate, confidence * 0.9) for plate, confidence in read_with_plate_ocr(engine, cv2.flip(crop, 1))]
            return reads
        except Exception:
            pass
    reads = []
    for variant in prepared_variants(crop):
        reads.extend(anpr.read_with_easyocr_candidates(variant))
        if any(confidence >= 0.6 for _, confidence in reads):
            break
    if not reads:
        # A mirrored picture (a webcam or a test through a phone screen).
        mirrored = [(plate, confidence * 0.9) for plate, confidence in anpr.read_with_easyocr_candidates(prepared_variants(cv2.flip(crop, 1))[0])]
        reads.extend(mirrored)
    return reads


def find_plates(frames):
    """
    frames: [(image, vehicle xyxy), ...]. Returns plate crops of every frame:
    [{"frame": index, "xyxy": (...), "confidence": 0-1, "crop": image}, ...]
    (at most two per frame, the surest first).
    """
    model = plate_detector()
    if model is None or not frames:
        return []
    regions, offsets = [], []
    for image, xyxy in frames:
        left, top, right, bottom = vehicle_region(image, xyxy)
        regions.append(image[top:bottom, left:right])
        offsets.append((left, top))
    found = []
    results = model.predict(regions, imgsz=640, conf=PLATE_CONFIDENCE, verbose=False, device=inference_device())
    for index, (result, (left, top)) in enumerate(zip(results, offsets)):
        boxes = result.boxes
        if boxes is None or len(boxes) == 0:
            continue
        ranked = sorted(zip(boxes.conf.tolist(), boxes.xyxy.tolist()), key=lambda item: item[0], reverse=True)[:2]
        for confidence, (x1, y1, x2, y2) in ranked:
            xyxy = (x1 + left, y1 + top, x2 + left, y2 + top)
            crop = plate_crop(frames[index][0], xyxy)
            if crop.size:
                found.append({"frame": index, "xyxy": tuple(round(value, 1) for value in xyxy), "confidence": round(float(confidence), 3), "crop": crop})
    return found


def read_pass(frames, vehicle_type=None, budget_seconds=OCR_BUDGET_SECONDS):
    """
    The plate of one pass. frames: [(image, vehicle xyxy), ...], best first.
    Returns (vote, sharpest plate crop or None, details) or None without the model.
    """
    if not available():
        return None
    started = time.monotonic()
    plates = find_plates(frames[:MAX_FRAMES])
    for plate in plates:
        plate["quality"], plate["measure"] = crop_quality(plate["crop"], plate["confidence"])
    plates.sort(key=lambda plate: plate["quality"], reverse=True)

    reads = []
    fast = plate_ocr() is not None
    for plate in plates[:MAX_OCR_CROPS if fast else MAX_EASYOCR_CROPS]:
        if reads and time.monotonic() - started >= budget_seconds:
            break
        text, score = anpr.select_consensus_plate_scored(read_crop(plate["crop"]))
        sharp_share = plate["measure"]["sharpness"] / (plate["measure"]["sharpness"] + HALF_SHARP)
        plate["read"], plate["score"] = text, round(score, 3)
        reads.append({"plate": text, "score": score * (0.6 + 0.4 * sharp_share)})
        # EasyOCR is slow: stop once two crops agree. The plate OCR model reads them all.
        if not fast and frames_agree(reads):
            break

    vote = vote_plate(reads, vehicle_type)
    best = plates[0] if plates else None
    details = {
        "reader": "plate_detector",
        "model": Path(str(model_file(PLATE_MODEL_FILE))).name,
        "ocr": PLATE_OCR_MODEL if fast else "easyocr",
        "frames_checked": min(len(frames), MAX_FRAMES),
        "plates_found": len(plates),
        "crops_read": len(reads),
        "elapsed_seconds": round(time.monotonic() - started, 3),
        "best_crop": {"frame": best["frame"], "confidence": best["confidence"], "quality": round(best["quality"], 3), **best["measure"]} if best else None,
        "crops": [
            {"frame": plate["frame"], "confidence": plate["confidence"], "quality": round(plate["quality"], 3), **plate["measure"],
             "read": plate.get("read"), "score": plate.get("score")}
            for plate in plates[:8]
        ],
    }
    return vote, (best["crop"] if best else None), details
