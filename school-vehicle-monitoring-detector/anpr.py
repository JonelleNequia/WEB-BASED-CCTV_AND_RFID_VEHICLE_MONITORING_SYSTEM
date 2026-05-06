"""
Lightweight ALPR helper for guest vehicle review.

The detector crops the full vehicle bounding box, improves contrast, and then
tries EasyOCR first. If EasyOCR is not installed, it falls back to pytesseract
when available. A missing OCR engine returns None instead of generating mock
plates, so guest records are never polluted with fake plate numbers.
"""

import re
from functools import lru_cache
from typing import Dict, List, Optional, Tuple

import cv2
import numpy as np


@lru_cache(maxsize=1)
def easyocr_reader():
    try:
        import easyocr
    except Exception:
        return None

    try:
        return easyocr.Reader(["en"], gpu=False, verbose=False)
    except Exception:
        return None


def crop_vehicle(frame, bounding_box):
    frame_height, frame_width = frame.shape[:2]
    x1, y1, x2, y2 = [int(value) for value in bounding_box]
    padding_x = max(int((x2 - x1) * 0.08), 8)
    padding_y = max(int((y2 - y1) * 0.08), 8)

    crop_x1 = max(x1 - padding_x, 0)
    crop_y1 = max(y1 - padding_y, 0)
    crop_x2 = min(x2 + padding_x, frame_width)
    crop_y2 = min(y2 + padding_y, frame_height)
    crop = frame[crop_y1:crop_y2, crop_x1:crop_x2]

    return crop if crop.size else frame


def preprocess_for_ocr(crop):
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
    gray = cv2.bilateralFilter(gray, 7, 55, 55)
    gray = cv2.equalizeHist(gray)

    scale = 2 if min(gray.shape[:2]) < 240 else 1
    if scale > 1:
        gray = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)

    return gray


def preprocess_variants_for_ocr(crop) -> List[np.ndarray]:
    gray = preprocess_for_ocr(crop)
    variants = [gray]

    try:
        _, otsu = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
        variants.append(otsu)
        variants.append(cv2.bitwise_not(otsu))
    except Exception:
        pass

    try:
        adaptive = cv2.adaptiveThreshold(
            gray,
            255,
            cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
            cv2.THRESH_BINARY,
            31,
            7,
        )
        variants.append(adaptive)
    except Exception:
        pass

    return variants


def normalize_plate_text(text: str) -> Optional[str]:
    cleaned = re.sub(r"[^A-Z0-9]", "", (text or "").upper())

    if len(cleaned) < 3:
        return None

    if not re.search(r"[A-Z]", cleaned):
        return None

    if 3 <= len(cleaned) <= 4 and re.match(r"^[A-Z]+$", cleaned):
        return cleaned

    normalized = correct_plate_character_positions(cleaned)

    if re.match(r"^[A-Z]{3}\d{4}$", normalized):
        return f"{normalized[:3]}-{normalized[3:]}"

    if re.match(r"^[A-Z]{3}\d{3}$", normalized):
        return f"{normalized[:3]}-{normalized[3:]}"

    if 6 <= len(cleaned) <= 8 and re.search(r"\d", cleaned):
        return cleaned

    return None


def correct_plate_character_positions(text: str) -> str:
    """
    Apply only position-safe OCR corrections for common PH plate layouts.
    """
    cleaned = text[:7]
    prefix_map = {
        "0": "O",
        "1": "I",
        "2": "Z",
        "3": "B",
        "4": "A",
        "5": "S",
        "6": "G",
        "7": "T",
        "8": "B",
    }
    suffix_map = {
        "B": "8",
        "D": "0",
        "I": "1",
        "L": "1",
        "O": "0",
        "Q": "0",
        "S": "5",
        "Z": "2",
    }
    corrected = []

    for index, character in enumerate(cleaned):
        if index < 3:
            corrected.append(prefix_map.get(character, character))
        else:
            corrected.append(suffix_map.get(character, character))

    return "".join(corrected)


def read_with_easyocr_candidates(image) -> List[Tuple[str, float]]:
    reader = easyocr_reader()

    if reader is None:
        return []

    try:
        try:
            results = reader.readtext(
                image,
                detail=1,
                paragraph=False,
                allowlist="ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-",
            )
        except TypeError:
            results = reader.readtext(image, detail=1, paragraph=False)
    except Exception:
        return []

    candidates = []

    for result in results:
        text = result[1] if len(result) > 1 else ""
        confidence = float(result[2]) if len(result) > 2 else 0.0

        if confidence < 0.35:
            continue

        plate = normalize_plate_text(text)
        if plate:
            candidates.append((plate, min(confidence, 1.0)))

    return candidates


def read_with_easyocr(image) -> Optional[str]:
    candidates = read_with_easyocr_candidates(image)

    if not candidates:
        return None

    candidates.sort(key=lambda item: item[1], reverse=True)

    return candidates[0][0]


def read_with_tesseract_candidates(image) -> List[Tuple[str, float]]:
    try:
        import pytesseract
    except Exception:
        return []

    candidates = []
    try:
        for page_mode in (7, 8, 13):
            try:
                data = pytesseract.image_to_data(
                    image,
                    config=f"--psm {page_mode} -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-",
                    output_type=pytesseract.Output.DICT,
                )
                text = "".join(data.get("text", []))
                confidences = [
                    float(value)
                    for value in data.get("conf", [])
                    if str(value).strip() not in {"", "-1"}
                ]
                confidence = (sum(confidences) / len(confidences) / 100.0) if confidences else 0.0
                plate = normalize_plate_text(text)

                if plate and confidence >= 0.35:
                    candidates.append((plate, min(confidence, 1.0)))
            except Exception:
                pass

            raw_text = pytesseract.image_to_string(
                image,
                config=f"--psm {page_mode} -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-",
            )
            plate = normalize_plate_text(raw_text)

            if plate:
                candidates.append((plate, 0.32))
    except Exception:
        return candidates

    return candidates


def read_with_tesseract(image) -> Optional[str]:
    candidates = read_with_tesseract_candidates(image)

    if not candidates:
        return None

    candidates.sort(key=lambda item: item[1], reverse=True)

    return candidates[0][0]


def plate_like_crops(vehicle_crop) -> List[np.ndarray]:
    height, width = vehicle_crop.shape[:2]

    if height < 30 or width < 60:
        return []

    gray = cv2.cvtColor(vehicle_crop, cv2.COLOR_BGR2GRAY)
    gray = cv2.bilateralFilter(gray, 7, 55, 55)
    blackhat_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (23, 7))
    close_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (19, 5))

    blackhat = cv2.morphologyEx(gray, cv2.MORPH_BLACKHAT, blackhat_kernel)
    grad_x = cv2.Sobel(blackhat, cv2.CV_32F, 1, 0, ksize=3)
    grad_x = np.absolute(grad_x)

    max_value = grad_x.max()
    if max_value > 0:
        grad_x = (255 * ((grad_x - grad_x.min()) / max_value)).astype("uint8")
    else:
        grad_x = grad_x.astype("uint8")

    grad_x = cv2.GaussianBlur(grad_x, (5, 5), 0)
    grad_x = cv2.morphologyEx(grad_x, cv2.MORPH_CLOSE, close_kernel)
    _, threshold = cv2.threshold(grad_x, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    threshold = cv2.erode(threshold, None, iterations=1)
    threshold = cv2.dilate(threshold, None, iterations=2)

    contours, _ = cv2.findContours(threshold, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    candidates = []

    for contour in contours:
        x, y, box_width, box_height = cv2.boundingRect(contour)

        if box_width <= 0 or box_height <= 0:
            continue

        aspect_ratio = box_width / float(box_height)
        area_ratio = (box_width * box_height) / float(width * height)

        if aspect_ratio < 1.6 or aspect_ratio > 7.5:
            continue

        if y < height * 0.22:
            continue

        if area_ratio < 0.006 or area_ratio > 0.28:
            continue

        if box_width < width * 0.12 or box_height < height * 0.025:
            continue

        padding_x = max(int(box_width * 0.18), 5)
        padding_y = max(int(box_height * 0.45), 4)
        x1 = max(x - padding_x, 0)
        y1 = max(y - padding_y, 0)
        x2 = min(x + box_width + padding_x, width)
        y2 = min(y + box_height + padding_y, height)
        crop = vehicle_crop[y1:y2, x1:x2]

        if crop.size:
            lower_half_bonus = 1 if y > height * 0.35 else 0
            candidates.append((lower_half_bonus, box_width * box_height, crop))

    candidates.sort(key=lambda item: (item[0], item[1]), reverse=True)

    return [crop for _, _, crop in candidates[:6]]


def fallback_vehicle_regions(vehicle_crop) -> List[np.ndarray]:
    height, width = vehicle_crop.shape[:2]
    regions = []

    if height >= 80:
        regions.insert(0, vehicle_crop[int(height * 0.35):height, :])
        regions.insert(0, vehicle_crop[int(height * 0.45):height, int(width * 0.12):int(width * 0.88)])

    return [region for region in regions if region.size]


def select_consensus_plate(candidates: List[Tuple[str, float]]) -> Optional[str]:
    """
    Prefer plates repeated across OCR variants; avoid saving one-off low-confidence
    misreads such as C/D or 8/B swaps.
    """
    if not candidates:
        return None

    scores: Dict[str, float] = {}
    counts: Dict[str, int] = {}

    for plate, confidence in candidates:
        scores[plate] = scores.get(plate, 0.0) + max(confidence, 0.0)
        counts[plate] = counts.get(plate, 0) + 1

    ranked = sorted(
        scores,
        key=lambda plate: (scores[plate], counts[plate], len(plate)),
        reverse=True,
    )
    best = ranked[0]
    best_score = scores[best]
    best_count = counts[best]

    if best_score >= 0.70:
        return best

    if best_count >= 2 and best_score >= 0.55:
        return best

    return None


def read_license_plate(frame, bounding_box) -> Optional[str]:
    """
    Attempt to read a plate number from the detected vehicle crop.
    """
    crop = crop_vehicle(frame, bounding_box)
    candidates = plate_like_crops(crop)

    if not candidates:
        candidates = fallback_vehicle_regions(crop)

    seen_shapes = set()

    ocr_candidates = []

    for candidate in candidates:
        shape_key = candidate.shape[:2]

        if shape_key in seen_shapes:
            continue

        seen_shapes.add(shape_key)

        for prepared in preprocess_variants_for_ocr(candidate):
            ocr_candidates.extend(read_with_easyocr_candidates(prepared))
            ocr_candidates.extend(read_with_tesseract_candidates(prepared))

    return select_consensus_plate(ocr_candidates)


def dominant_neutral_color(black_ratio, white_ratio, gray_ratio, gray_value_mean) -> Optional[str]:
    neutral_scores = {
        "Black": black_ratio,
        "White": white_ratio,
        "Silver" if gray_value_mean >= 150 else "Gray": gray_ratio,
    }
    color, score = max(neutral_scores.items(), key=lambda item: item[1])

    return color if score >= 0.18 else None


def classify_vehicle_color_sample(sample) -> Tuple[Optional[str], float]:
    """
    Classify one body-color sample and return a confidence-like score.
    """
    if sample.size == 0:
        return None, 0.0

    max_side = max(sample.shape[:2])
    if max_side > 180:
        scale = 180 / max_side
        sample = cv2.resize(sample, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA)

    blurred = cv2.medianBlur(sample, 5)
    hsv = cv2.cvtColor(blurred, cv2.COLOR_BGR2HSV).reshape(-1, 3)

    if hsv.size == 0:
        return None, 0.0

    hue = hsv[:, 0]
    saturation = hsv[:, 1]
    value = hsv[:, 2]
    total_pixels = float(len(hsv))

    valid_body_mask = value >= 32
    black_mask = value < 48
    white_mask = valid_body_mask & (saturation < 32) & (value >= 190)
    gray_mask = valid_body_mask & (saturation < 48) & (value >= 55) & (value < 190)
    chroma_mask = valid_body_mask & (saturation >= 55) & (value >= 60)

    black_ratio = float(np.count_nonzero(black_mask)) / total_pixels
    white_ratio = float(np.count_nonzero(white_mask)) / total_pixels
    gray_ratio = float(np.count_nonzero(gray_mask)) / total_pixels
    gray_value_mean = float(value[gray_mask].mean()) if np.any(gray_mask) else 0.0

    if black_ratio >= 0.50:
        return "Black", black_ratio

    if white_ratio >= 0.34:
        return "White", white_ratio

    if gray_ratio >= 0.42:
        return "Silver" if gray_value_mean >= 150 else "Gray", gray_ratio

    chroma_hue = hue[chroma_mask]
    chroma_total = len(chroma_hue)

    if chroma_total < total_pixels * 0.10:
        color = dominant_neutral_color(black_ratio, white_ratio, gray_ratio, gray_value_mean)
        return color, max(black_ratio, white_ratio, gray_ratio)

    color_ranges = [
        ("Red", (chroma_hue <= 10) | (chroma_hue >= 170)),
        ("Orange", (chroma_hue > 10) & (chroma_hue <= 24)),
        ("Yellow", (chroma_hue > 24) & (chroma_hue <= 34)),
        ("Green", (chroma_hue > 34) & (chroma_hue <= 85)),
        ("Blue", (chroma_hue > 85) & (chroma_hue <= 130)),
        ("Purple", (chroma_hue > 130) & (chroma_hue < 170)),
    ]
    counts = {
        color_name: int(np.count_nonzero(mask))
        for color_name, mask in color_ranges
    }
    color, count = max(counts.items(), key=lambda item: item[1])

    if count <= 0 or count < chroma_total * 0.32:
        neutral_color = dominant_neutral_color(black_ratio, white_ratio, gray_ratio, gray_value_mean)
        return neutral_color, max(black_ratio, white_ratio, gray_ratio)

    return color, count / float(chroma_total)


def detect_vehicle_color(frame, bounding_box) -> Optional[str]:
    """
    Categorize the dominant visible vehicle color from the YOLO crop.

    Multiple body-focused samples reduce false gray/black results from windows,
    tires, shadows, and background pixels.
    """
    crop = crop_vehicle(frame, bounding_box)

    if crop.size == 0:
        return None

    height, width = crop.shape[:2]
    regions = [
        crop[int(height * 0.28):int(height * 0.78), int(width * 0.14):int(width * 0.86)],
        crop[int(height * 0.38):int(height * 0.86), int(width * 0.08):int(width * 0.92)],
        crop[int(height * 0.22):int(height * 0.68), int(width * 0.24):int(width * 0.76)],
    ]
    scores: Dict[str, float] = {}

    for region in regions:
        color, score = classify_vehicle_color_sample(region)

        if not color:
            continue

        scores[color] = scores.get(color, 0.0) + score

    if not scores:
        return None

    color, score = max(scores.items(), key=lambda item: item[1])

    return color if score >= 0.22 else None
