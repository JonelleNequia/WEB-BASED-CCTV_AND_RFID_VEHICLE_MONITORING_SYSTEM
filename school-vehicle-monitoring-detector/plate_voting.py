"""
Phase 5 (visitor model): one plate per vehicle from several frames.

Each frame gives at most one plate (already in PH letter-first form with
position-safe corrections, e.g. "ABC-1234", see anpr.py) and a score. The
vote:

- groups the reads by layout (3 letters + 4 digits, 3+3, 2+5, 2+4) and takes
  the layout with the most weight;
- votes character by character inside that layout, so one frame's 3/8 swap
  is outvoted by the others;
- says "unreadable" (keeping the best guess for the guard) when no frame
  read a plate, when frames disagree on a character (no majority), or when
  only one frame read it and that read is weak;
- on a car, jeep, bus or truck accepts only 3-letter plates: a 2-letter
  read there is a 3-letter plate with a letter missed (measured on Gate 1
  snapshots: "LAK 3767" read as "AK-3767" and "IK-3767"). 2-letter layouts
  are for motorcycles and tricycles.
"""

import re

# A single read is trusted only when it is this sure.
STRONG_SINGLE_READ = 0.80
# Each character needs this share of the weight when frames disagree.
MAJORITY_SHARE = 0.6
# Vehicle classes whose plates may have 2 letters (detector labels, lowercase).
TWO_LETTER_PLATE_VEHICLES = {"motorcycle", "motorbike", "electric scooter", "auto rickshaw", "tricycle"}
# Two frames that agree on the exact plate end OCR early.
AGREEING_FRAMES_TO_STOP = 2


def plate_layout(plate):
    match = re.fullmatch(r"([A-Z]+)-(\d+)", plate or "")
    return (len(match.group(1)), len(match.group(2))) if match else None


def frames_agree(frame_reads, needed=AGREEING_FRAMES_TO_STOP):
    """True once `needed` frames read the exact same plate (OCR can stop)."""
    counts = {}
    for read in frame_reads:
        if read.get("plate"):
            counts[read["plate"]] = counts.get(read["plate"], 0) + 1
            if counts[read["plate"]] >= needed:
                return True
    return False


def vote_plate(frame_reads, vehicle_type=None):
    """
    frame_reads: [{"plate": "ABC-1234" | None, "score": 0-1}, ...] (one per frame)
    vehicle_type: the detector's label ("Car", "Motorcycle", ...) or None.

    Returns {"plate", "status" ("read" | "unreadable"), "confidence",
    "best_guess", "reason", "frames_checked", "frames_with_plate", "candidates"}.
    """
    reads = [
        {"plate": read["plate"], "score": max(0.05, min(1.0, float(read.get("score") or 0.0)))}
        for read in frame_reads
        if read.get("plate") and plate_layout(read["plate"])
    ]
    candidates = {}
    for read in reads:
        entry = candidates.setdefault(read["plate"], {"count": 0, "weight": 0.0})
        entry["count"] += 1
        entry["weight"] = round(entry["weight"] + read["score"], 3)

    result = {
        "plate": None,
        "status": "unreadable",
        "confidence": 0.0,
        "best_guess": None,
        "reason": "",
        "frames_checked": len(frame_reads),
        "frames_with_plate": len(reads),
        "candidates": candidates,
    }

    if not reads:
        result["reason"] = f"No plate found in {len(frame_reads)} frame(s)."
        return result

    vehicle = str(vehicle_type or "").strip().lower()
    if vehicle and vehicle not in TWO_LETTER_PLATE_VEHICLES:
        three_letter = [read for read in reads if plate_layout(read["plate"])[0] >= 3]
        if not three_letter:
            best = max(reads, key=lambda read: read["score"])
            result["best_guess"] = best["plate"]
            result["reason"] = f"Only 2-letter plates were read on a {vehicle}; a letter was probably missed."
            return result
        reads = three_letter

    layouts = {}
    for read in reads:
        layouts.setdefault(plate_layout(read["plate"]), []).append(read)
    layout_reads = max(layouts.values(), key=lambda group: (sum(read["score"] for read in group), len(group)))

    # Character by character inside the winning layout.
    texts = [read["plate"] for read in layout_reads]
    voted, shares = [], []
    for position in range(len(texts[0])):
        weights = {}
        for read in layout_reads:
            character = read["plate"][position]
            weights[character] = weights.get(character, 0.0) + read["score"]
        character, weight = max(weights.items(), key=lambda item: item[1])
        voted.append(character)
        shares.append(weight / sum(weights.values()))

    plate = "".join(voted)
    mean_score = sum(read["score"] for read in layout_reads) / len(layout_reads)
    agreement = min(shares)
    result["best_guess"] = plate
    result["confidence"] = round(mean_score * (sum(shares) / len(shares)), 3)

    if len(layout_reads) == 1:
        if layout_reads[0]["score"] >= STRONG_SINGLE_READ:
            result.update(plate=plate, status="read", reason="One clear read.")
        else:
            result["reason"] = "Only one frame read a plate and the read is weak."
        return result

    if agreement < MAJORITY_SHARE:
        result["reason"] = "Frames disagree on the plate."
        return result

    exact = sum(1 for text in texts if text == plate)
    result.update(plate=plate, status="read", reason=f"{exact} of {len(texts)} frame reads match exactly; character vote.")
    return result
