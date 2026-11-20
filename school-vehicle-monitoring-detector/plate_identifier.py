"""Literal identifier rules shared with PHP PlateNumber and browser plate inputs."""
import re
import unicodedata


def normalize(value):
    value = "".join(" " if unicodedata.category(char) == "Zs" else char for char in (value or ""))
    value = re.sub(r" +", " ", value).strip(" ")
    return re.sub(r"[a-z]", lambda match: match.group().upper(), value) or None


def key(value):
    value = normalize(value)
    if not value or not re.fullmatch(r"[A-Z0-9 -]+", value):
        return None
    return value.replace(" ", "").replace("-", "") or None


def display(value):
    compact = key(value)
    if compact:
        match = re.fullmatch(r"([A-Z]{2,3})([0-9]{3,5})", compact)
        if match:
            return f"{match[1]} {match[2]}"
    return normalize(value)


def valid(value):
    compact = key(value)
    return bool(compact and len(compact) <= 20 and len(display(value) or "") <= 30)


def has_temporary_label(details):
    """Explicit OCR words are a review cue; character patterns alone are not."""
    reads = list(details.get("raw_reads") or [])
    for frame in details.get("frame_results") or []:
        if isinstance(frame, dict):
            reads.extend(frame.get("raw_reads") or [])
    return any(isinstance(read, dict) and isinstance(read.get("text"), str)
               and re.search(r"\b(?:CONDUCTION(?:\s+STICKER)?|MV[\s-]+FILE|TEMPORARY[\s-]+PLATE)\b", read["text"], re.I)
               for read in reads)


def review_submission(vote, details):
    """Accept clear plate-region OCR; retain explicit ambiguity for Review.

    This operational policy is not a legal identifier-type classifier. Never
    infer temporary type from a pattern or use a Registry match to invent text.
    The name is retained for existing detector callback interfaces.
    """
    details = dict(details)
    candidate = vote.get("plate") or vote.get("best_guess")
    evidence = details.get("identifier_evidence") or {}
    kind = evidence.get("kind") if isinstance(evidence, dict) else "uncertain"
    source = evidence.get("source") if isinstance(evidence, dict) else None
    reason_code = "ocr_accepted"
    reason = "Clear plate-region OCR accepted for exact matching."
    if not candidate:
        reason_code, reason = "ocr_no_text", "No usable plate text was read. Inspect the available images."
    elif not valid(candidate):
        reason_code, reason = "ocr_invalid_text", "OCR candidate is incomplete or outside the plate contract. Confirm the literal text from the images."
    elif vote.get("status") != "read":
        reason_code, reason = "ocr_vote_uncertain", (vote.get("reason") or "OCR observations are uncertain.") + " Confirm the candidate and identifier type from the images."
    elif type(vote.get("confidence")) not in (int, float) or not .6 <= vote["confidence"] <= 1:
        reason_code, reason = "ocr_low_confidence", "OCR confidence is missing or below the existing review threshold."
    elif kind in ("temporary_plate", "conduction_sticker", "mv_file") or has_temporary_label(details):
        reason_code, reason = "temporary_identifier_evidence", "Temporary identifier evidence only; not an accepted actual plate."
    elif kind not in (None, "plate_region", "actual_plate") or source in ("staff", "registry_match", "pattern", "length"):
        reason_code, reason = "identifier_type_unconfirmed", "Identifier type is explicitly uncertain. Inspect the occurrence images."
    accepted = reason_code == "ocr_accepted"
    details["identifier_evidence"] = {
        "kind": "plate_region" if accepted else (kind if kind in ("temporary_plate", "conduction_sticker", "mv_file") else "uncertain"), "source": "ocr_plate_region",
        "reason_code": reason_code, "reason": reason,
    }
    details.setdefault("vote", dict(vote))
    return {"plate_number": vote.get("plate") if accepted else None, "plate_status": "read" if accepted else "unreadable",
            "plate_confidence": vote.get("confidence", 0.0),
            "best_guess": candidate if candidate and len(candidate) <= 30 else None,
            "ocr_details": details}
