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


def review_submission(vote, details):
    """Current models recognize text/regions, not legal identifier type.

    Preserve the character vote verbatim in diagnostics. Never send it as an
    actual plate on the strength of shape, length, confidence or a Registry hit.
    """
    details = dict(details)
    candidate = vote.get("plate") or vote.get("best_guess")
    reason_code = "identifier_type_unconfirmed"
    reason = "Identifier type unconfirmed: inspect the images and confirm actual plate or temporary identifier."
    if not candidate:
        reason_code, reason = "ocr_no_text", "No usable plate text was read. Inspect the available images."
    elif not valid(candidate):
        reason_code, reason = "ocr_invalid_text", "OCR candidate is incomplete or outside the plate contract. Confirm the literal text from the images."
    elif vote.get("status") != "read":
        reason_code, reason = "ocr_vote_uncertain", (vote.get("reason") or "OCR observations are uncertain.") + " Confirm the candidate and identifier type from the images."
    details["identifier_evidence"] = {
        "kind": "uncertain", "source": "unclassified_region",
        "reason_code": reason_code, "reason": reason,
    }
    details.setdefault("vote", dict(vote))
    return {"plate_number": None, "plate_status": "unreadable",
            "plate_confidence": vote.get("confidence", 0.0),
            "best_guess": candidate if candidate and len(candidate) <= 30 else None,
            "ocr_details": details}
