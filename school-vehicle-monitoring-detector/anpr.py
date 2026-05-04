"""
Lightweight ALPR helper for guest vehicle review.

The detector crops the full vehicle bounding box, improves contrast, and then
tries EasyOCR first. If EasyOCR is not installed, it falls back to pytesseract
when available. A missing OCR engine returns None instead of generating mock
plates, so guest records are never polluted with fake plate numbers.
"""

import re
from functools import lru_cache
from typing import Optional

import cv2


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


def normalize_plate_text(text: str) -> Optional[str]:
    cleaned = re.sub(r"[^A-Z0-9]", "", (text or "").upper())

    if len(cleaned) < 4:
        return None

    if len(cleaned) >= 7:
        return f"{cleaned[:3]}-{cleaned[3:7]}"

    return cleaned


def read_with_easyocr(image) -> Optional[str]:
    reader = easyocr_reader()

    if reader is None:
        return None

    try:
        results = reader.readtext(image, detail=1, paragraph=False)
    except Exception:
        return None

    candidates = []

    for result in results:
        text = result[1] if len(result) > 1 else ""
        confidence = float(result[2]) if len(result) > 2 else 0.0

        if confidence < 0.25:
            continue

        plate = normalize_plate_text(text)
        if plate:
            candidates.append((confidence, plate))

    if not candidates:
        return None

    candidates.sort(reverse=True)

    return candidates[0][1]


def read_with_tesseract(image) -> Optional[str]:
    try:
        import pytesseract
    except Exception:
        return None

    try:
        raw_text = pytesseract.image_to_string(
            image,
            config="--psm 7 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-",
        )
    except Exception:
        return None

    return normalize_plate_text(raw_text)


def read_license_plate(frame, bounding_box) -> Optional[str]:
    """
    Attempt to read a plate number from the detected vehicle crop.
    """
    crop = crop_vehicle(frame, bounding_box)
    prepared = preprocess_for_ocr(crop)

    return read_with_easyocr(prepared) or read_with_tesseract(prepared)

