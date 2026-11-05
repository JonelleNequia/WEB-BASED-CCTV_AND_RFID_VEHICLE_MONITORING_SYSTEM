"""
Phase 5 (visitor model): one plate per vehicle from several frames.

Each frame gives at most one plate (literal PH letter-first or number-first form,
e.g. "ABC-1234", see anpr.py) and a score. The
vote:

- groups the reads by layout (3 letters + 4 digits, 3+3, 2+5, 2+4) and takes
  the layout with the most weight;
- votes character by character inside that layout, so one frame's 3/8 swap
  is outvoted by the others;
- says "unreadable" (keeping the best guess for the guard) when no frame
  read a plate, when frames disagree on a character (no majority), or when
  only one frame read it and that read is weak;
- accepts the supported literal layouts for every physical type; vehicle
  subtype is not evidence that a letter was missing.
"""

import re

# A single read is trusted only when it is this sure.
STRONG_SINGLE_READ = 0.80
# Each character needs this share of the weight when frames disagree.
MAJORITY_SHARE = 0.6
# Two frames that agree on the exact plate end OCR early.
AGREEING_FRAMES_TO_STOP = 2


def plate_layout(plate):
    match = re.fullmatch(r"([A-Z]+)-(\d+)", plate or "")
    if match:
        return (len(match.group(1)), len(match.group(2)))
    number_first = re.fullmatch(r"(\d+)([A-Z]+)", plate or "")
    return (len(number_first.group(1)), len(number_first.group(2)), "number_first") if number_first else None


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

    # Physical type (especially a broad/mistaken model class) is not proof
    # of a plate layout. Keep the same literal layouts and voting thresholds.
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
