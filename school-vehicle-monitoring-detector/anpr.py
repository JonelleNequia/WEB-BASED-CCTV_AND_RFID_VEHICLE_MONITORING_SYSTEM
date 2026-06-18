"""
Lightweight ALPR helper for guest vehicle review.

The detector crops the full vehicle bounding box, improves contrast, and then
tries local EasyOCR first with model downloads disabled. If EasyOCR is not
ready, it falls back to local pytesseract only when the tesseract binary exists.
A missing OCR engine returns None instead of generating mock plates, so guest
records are never polluted with fake plate numbers.
"""

import re
import shutil
import time
from functools import lru_cache
from typing import Dict, List, Optional, Tuple

import cv2
import numpy as np

MAX_OCR_CANDIDATES = 6
MAX_OCR_VARIANTS_PER_CANDIDATE = 2
OCR_TIME_BUDGET_SECONDS = 8.0
PLATE_LAYOUTS = (
    (3, 4),
    (3, 3),
    (2, 5),
    (2, 4),
)


@lru_cache(maxsize=1)
def easyocr_reader():
    try:
        import easyocr
    except Exception:
        return None

    try:
        return easyocr.Reader(["en"], gpu=False, verbose=False, download_enabled=False)
    except TypeError:
        return None
    except Exception:
        return None


@lru_cache(maxsize=1)
def tesseract_binary_available() -> bool:
    return shutil.which("tesseract") is not None


def ocr_runtime_status() -> Dict[str, str]:
    return {
        "easyocr": "ready" if easyocr_reader() is not None else "unavailable_or_model_missing",
        "tesseract": "ready" if tesseract_binary_available() else "binary_missing",
    }


def crop_vehicle(frame, bounding_box, padding_ratio=0.08, min_padding=8):
    frame_height, frame_width = frame.shape[:2]
    x1, y1, x2, y2 = [int(value) for value in bounding_box]
    padding_x = max(int((x2 - x1) * padding_ratio), min_padding)
    padding_y = max(int((y2 - y1) * padding_ratio), min_padding)

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

    if len(cleaned) < 5:
        return None

    if not re.search(r"[A-Z]", cleaned) or not re.search(r"\d", cleaned):
        return None

    reordered_plate = number_first_plate_candidate(cleaned)

    if reordered_plate:
        return reordered_plate

    candidates = plate_layout_candidates(cleaned)

    if candidates:
        return candidates[0]

    return None


def number_first_plate_candidate(text: str) -> Optional[str]:
    """
    OCR sometimes returns separated plate groups in visual order as 233DPF.
    Save that as the expected letter-first PH plate format: DPF-233.
    """
    for prefix_len, digit_len in PLATE_LAYOUTS:
        match = re.fullmatch(rf"(\d{{{digit_len}}})([A-Z]{{{prefix_len}}})", text)

        if match:
            digits, letters = match.groups()

            return f"{letters}-{digits}"

    return None


def plate_layout_candidates(text: str) -> List[str]:
    """
    Accept only letter-first PH-style plate candidates after number-first
    OCR group ordering has already been normalized.
    """
    candidates: List[Tuple[int, int, int, str]] = []

    for priority, (prefix_len, digit_len) in enumerate(PLATE_LAYOUTS):
        total_len = prefix_len + digit_len

        if len(text) == total_len:
            candidate = format_plate(text, prefix_len)

            if candidate:
                candidates.append((0, priority, 0, candidate))

        if len(text) == total_len:
            candidate = correct_plate_character_positions(text, prefix_len, digit_len)

            if candidate:
                candidates.append((2, priority, 0, candidate))

        pattern = re.compile(rf"(?<![A-Z0-9])[A-Z]{{{prefix_len}}}\d{{{digit_len}}}(?![A-Z0-9])")

        for match in pattern.finditer(text):
            candidate = format_plate(match.group(0), prefix_len)

            if candidate:
                candidates.append((1, priority, match.start() + 1, candidate))

        for start in range(0, max(len(text) - total_len + 1, 0)):
            window = text[start:start + total_len]

            if not is_plausible_corrected_plate_window(window, prefix_len):
                continue

            candidate = correct_plate_character_positions(window, prefix_len, digit_len)

            if candidate:
                candidates.append((2, priority, start + 2, candidate))

    if not candidates:
        return []

    candidates.sort(key=lambda item: (item[0], item[1], item[2], -len(item[3])))

    unique = []
    seen = set()

    for _, _, _, candidate in candidates:
        if candidate in seen:
            continue

        seen.add(candidate)
        unique.append(candidate)

    return unique


def format_plate(text: str, prefix_len: int) -> Optional[str]:
    prefix = text[:prefix_len]
    suffix = text[prefix_len:]

    if not prefix.isalpha() or not suffix.isdigit():
        return None

    return f"{prefix}-{suffix}"


def is_plausible_corrected_plate_window(text: str, prefix_len: int) -> bool:
    """
    Let OCR corrections recover common swaps while keeping the format letter-first.
    This prevents number-first strings like 123ABC from being accepted.
    """
    prefix = text[:prefix_len]
    suffix = text[prefix_len:]

    if not prefix or not suffix:
        return False

    if sum(character.isalpha() for character in prefix) < max(prefix_len - 1, 1):
        return False

    if sum(character.isdigit() for character in suffix) < max(len(suffix) - 1, 1):
        return False

    return True


def correct_plate_character_positions(text: str, prefix_len: int = 3, digit_len: int = 4) -> Optional[str]:
    """
    Apply only position-safe OCR corrections for common PH plate layouts.
    """
    total_len = prefix_len + digit_len

    if len(text) != total_len:
        return None

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

    for index, character in enumerate(text):
        if index < prefix_len:
            corrected.append(prefix_map.get(character, character))
        else:
            corrected.append(suffix_map.get(character, character))

    corrected_text = "".join(corrected)

    return format_plate(corrected_text, prefix_len)


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
    text_items = []

    for result in results:
        bbox = result[0] if result else None
        text = result[1] if len(result) > 1 else ""
        confidence = float(result[2]) if len(result) > 2 else 0.0

        if confidence < 0.35:
            continue

        text_items.append((bbox, text, confidence))
        plate = normalize_plate_text(text)
        if plate:
            candidates.append((plate, min(confidence, 1.0)))

    if len(text_items) > 1:
        sorted_items = sorted(text_items, key=lambda item: ocr_box_sort_key(item[0]))
        combined_text = "".join(item[1] for item in sorted_items)
        combined_confidence = sum(item[2] for item in sorted_items) / len(sorted_items)
        plate = normalize_plate_text(combined_text)

        if plate and combined_confidence >= 0.35:
            candidates.append((plate, min(combined_confidence, 1.0)))

    return candidates


def ocr_box_sort_key(bbox) -> Tuple[float, float]:
    if not bbox:
        return 0.0, 0.0

    try:
        xs = [float(point[0]) for point in bbox]
        ys = [float(point[1]) for point in bbox]
    except Exception:
        return 0.0, 0.0

    return sum(ys) / len(ys), sum(xs) / len(xs)


def read_with_easyocr(image) -> Optional[str]:
    candidates = read_with_easyocr_candidates(image)

    if not candidates:
        return None

    candidates.sort(key=lambda item: item[1], reverse=True)

    return candidates[0][0]


def read_with_tesseract_candidates(image) -> List[Tuple[str, float]]:
    if not tesseract_binary_available():
        return []

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


def crop_fraction(image, x1_ratio, y1_ratio, x2_ratio, y2_ratio):
    height, width = image.shape[:2]
    x1 = max(0, min(width - 1, int(width * x1_ratio)))
    y1 = max(0, min(height - 1, int(height * y1_ratio)))
    x2 = max(x1 + 1, min(width, int(width * x2_ratio)))
    y2 = max(y1 + 1, min(height, int(height * y2_ratio)))
    crop = image[y1:y2, x1:x2]

    return crop if crop.size else None


def heuristic_plate_regions(vehicle_crop) -> List[np.ndarray]:
    """
    Add bounded front/rear plate search windows when contour detection misses the
    plate. Full lower-half OCR is slow and often loses small visible plates.
    """
    height, width = vehicle_crop.shape[:2]

    if height < 80 or width < 140:
        return []

    region_specs = [
        (0.50, 0.46, 0.82, 0.84),
        (0.34, 0.46, 0.66, 0.84),
        (0.18, 0.46, 0.50, 0.84),
        (0.02, 0.46, 0.34, 0.84),
        (0.52, 0.36, 0.88, 0.74),
        (0.32, 0.36, 0.68, 0.74),
        (0.08, 0.36, 0.44, 0.74),
        (0.42, 0.54, 0.90, 0.94),
    ]
    regions = []

    for spec in region_specs:
        region = crop_fraction(vehicle_crop, *spec)

        if region is not None:
            regions.append(region)

    return regions


def crop_signature(crop) -> Tuple[Tuple[int, int], bytes]:
    try:
        preview = cv2.resize(crop, (16, 16), interpolation=cv2.INTER_AREA)
        return crop.shape[:2], preview.tobytes()
    except Exception:
        return crop.shape[:2], b""


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
        key=lambda plate: (scores[plate], counts[plate], plate_layout_rank(plate), len(plate)),
        reverse=True,
    )
    best = ranked[0]
    best_score = scores[best]
    best_count = counts[best]

    if re.match(r"^[A-Z]{3}-\d{3,4}$", best) and best_score >= 0.42:
        return best

    if best_score >= 0.70:
        return best

    if best_count >= 2 and best_score >= 0.55:
        return best

    if best_score >= 0.55:
        return best

    return None


def plate_layout_rank(plate: str) -> int:
    if re.match(r"^[A-Z]{3}-\d{4}$", plate):
        return 4

    if re.match(r"^[A-Z]{3}-\d{3}$", plate):
        return 3

    if re.match(r"^[A-Z]{2}-\d{5}$", plate):
        return 2

    if re.match(r"^[A-Z]{2}-\d{4}$", plate):
        return 1

    return 0


def read_license_plate(frame, bounding_box) -> Optional[str]:
    """
    Attempt to read a plate number from the detected vehicle crop.
    """
    crop = crop_vehicle(frame, bounding_box)
    candidates = (
        plate_like_crops(crop)
        + heuristic_plate_regions(crop)
    )

    seen_shapes = set()

    ocr_candidates = []
    started_at = time.monotonic()

    for candidate in candidates[:MAX_OCR_CANDIDATES]:
        shape_key = crop_signature(candidate)

        if shape_key in seen_shapes:
            continue

        seen_shapes.add(shape_key)

        for prepared in preprocess_variants_for_ocr(candidate)[:MAX_OCR_VARIANTS_PER_CANDIDATE]:
            ocr_candidates.extend(read_with_easyocr_candidates(prepared))
            ocr_candidates.extend(read_with_tesseract_candidates(prepared))

        selected_plate = select_consensus_plate(ocr_candidates)

        if selected_plate and re.match(r"^[A-Z]{3}-\d{3,4}$", selected_plate):
            return selected_plate

        if time.monotonic() - started_at >= OCR_TIME_BUDGET_SECONDS:
            break

    return select_consensus_plate(ocr_candidates)


def dominant_neutral_color(black_ratio, white_ratio, silver_ratio, gray_ratio) -> Optional[str]:
    neutral_scores = {
        "Black": black_ratio,
        "White": white_ratio,
        "Silver": silver_ratio,
        "Gray": gray_ratio,
    }
    color, score = max(neutral_scores.items(), key=lambda item: item[1])

    return color if score >= 0.20 else None


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

    black_mask = value < 44
    neutral_mask = (saturation < 70) & (value >= 36)
    white_mask = neutral_mask & (value >= 165)
    silver_mask = neutral_mask & (value >= 112) & (value < 175)
    gray_mask = neutral_mask & (value >= 58) & (value < 128)
    chroma_mask = (saturation >= 58) & (value >= 55)

    black_ratio = float(np.count_nonzero(black_mask)) / total_pixels
    white_ratio = float(np.count_nonzero(white_mask)) / total_pixels
    silver_ratio = float(np.count_nonzero(silver_mask)) / total_pixels
    gray_ratio = float(np.count_nonzero(gray_mask)) / total_pixels

    neutral_values = value[neutral_mask]
    neutral_ratio = float(len(neutral_values)) / total_pixels if total_pixels else 0.0
    neutral_mean = float(neutral_values.mean()) if len(neutral_values) else 0.0
    neutral_p75 = float(np.percentile(neutral_values, 75)) if len(neutral_values) else 0.0

    chroma_hue = hue[chroma_mask]
    chroma_total = len(chroma_hue)

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

    if (
        chroma_total >= total_pixels * 0.10
        and count > 0
        and count >= chroma_total * 0.34
    ):
        return color, count / float(chroma_total)

    if black_ratio >= 0.58 and black_ratio >= max(white_ratio, silver_ratio, gray_ratio) * 1.30:
        return "Black", black_ratio

    if white_ratio >= 0.28 or (neutral_ratio >= 0.42 and neutral_mean >= 155 and neutral_p75 >= 165):
        return "White", max(white_ratio, neutral_ratio)

    if silver_ratio >= 0.34 or (neutral_ratio >= 0.42 and neutral_mean >= 118 and neutral_p75 >= 135):
        return "Silver", max(silver_ratio, neutral_ratio)

    if gray_ratio >= 0.30 or neutral_ratio >= 0.36:
        return "Gray", max(gray_ratio, neutral_ratio)

    neutral_color = dominant_neutral_color(black_ratio, white_ratio, silver_ratio, gray_ratio)
    return neutral_color, max(black_ratio, white_ratio, silver_ratio, gray_ratio)


def body_color_regions(vehicle_crop) -> List[np.ndarray]:
    """
    Sample probable paint/body panels. Avoid padded edges, wheels, plate area,
    and the lowest crop bands where road and shadows commonly dominate.
    """
    region_specs = [
        (0.20, 0.20, 0.80, 0.54),
        (0.18, 0.34, 0.82, 0.68),
        (0.30, 0.24, 0.70, 0.64),
        (0.10, 0.30, 0.46, 0.72),
        (0.54, 0.30, 0.90, 0.72),
        (0.24, 0.48, 0.76, 0.78),
    ]
    regions = []

    for spec in region_specs:
        region = crop_fraction(vehicle_crop, *spec)

        if region is not None and region.size:
            regions.append(region)

    return regions


def detect_vehicle_color(frame, bounding_box) -> Optional[str]:
    """
    Categorize the dominant visible vehicle color from the YOLO crop.

    Multiple body-focused samples reduce false gray/black results from windows,
    tires, shadows, and background pixels.
    """
    crop = crop_vehicle(frame, bounding_box, padding_ratio=0.0, min_padding=0)

    if crop.size == 0:
        return None

    regions = body_color_regions(crop)
    scores: Dict[str, float] = {}
    max_scores: Dict[str, float] = {}

    for region in regions:
        color, score = classify_vehicle_color_sample(region)

        if not color:
            continue

        scores[color] = scores.get(color, 0.0) + score
        max_scores[color] = max(max_scores.get(color, 0.0), score)

    if not scores:
        color, score = classify_vehicle_color_sample(crop)

        return color if score >= 0.24 else None

    color, score = max(scores.items(), key=lambda item: item[1])

    if score < 0.42 and max_scores.get(color, 0.0) < 0.28:
        return None

    if (
        color == "Gray"
        and scores.get("White", 0.0) >= 0.60
        and max_scores.get("White", 0.0) >= 0.32
    ):
        return "White"

    if (
        color == "Black"
        and score < 0.90
        and max(scores.get("White", 0.0), scores.get("Silver", 0.0), scores.get("Gray", 0.0)) >= score * 0.75
    ):
        return max(("White", "Silver", "Gray"), key=lambda name: scores.get(name, 0.0))

    return color
