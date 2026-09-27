"""
MAC address helpers and vendor lookup from the bundled IEEE OUI list.
"""

import gzip
import re

from .paths import OUI_PATH

_VENDORS = None
_MAC_PARTS = re.compile(r"[0-9A-Fa-f]{1,2}")


def normalize_mac(value):
    """
    "b8:9f:cc:1:2:3" / "B8-9F-CC-01-02-03" / "b89f.cc01.0203" -> "B8:9F:CC:01:02:03".
    Returns None for broadcast, multicast or malformed values.
    """
    if not value:
        return None
    text = str(value).strip()
    if re.fullmatch(r"[0-9A-Fa-f]{12}", text):
        parts = [text[i:i + 2] for i in range(0, 12, 2)]
    elif re.fullmatch(r"[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}", text):
        flat = text.replace(".", "")
        parts = [flat[i:i + 2] for i in range(0, 12, 2)]
    else:
        parts = re.split(r"[:-]", text)
        if len(parts) != 6 or not all(_MAC_PARTS.fullmatch(part) for part in parts):
            return None
    mac = ":".join(part.zfill(2).upper() for part in parts)
    first = int(mac[:2], 16)
    if mac in ("FF:FF:FF:FF:FF:FF", "00:00:00:00:00:00") or first & 0x01:
        return None
    return mac


def is_randomized(mac):
    """Locally administered (private / randomized) MAC, typical of phones and laptops."""
    return bool(mac) and bool(int(mac[:2], 16) & 0x02)


def _load():
    global _VENDORS
    if _VENDORS is not None:
        return _VENDORS
    vendors = {}
    try:
        with gzip.open(OUI_PATH, "rt", encoding="utf-8", errors="ignore") as handle:
            for line in handle:
                prefix, _, name = line.rstrip("\n").partition("\t")
                if len(prefix) == 6 and name:
                    vendors[prefix.upper()] = name.strip()
    except OSError:
        pass
    _VENDORS = vendors
    return vendors


def vendor_for(mac):
    if not mac:
        return None
    if is_randomized(mac):
        return "Private (randomized) address"
    return _load().get(mac.replace(":", "")[:6])


def mac_from_uuid(text):
    """
    Many cameras end their ONVIF endpoint UUID with their MAC address
    (urn:uuid:xxxxxxxx-xxxx-xxxx-xxxx-AABBCCDDEEFF). Use it only when the
    prefix belongs to a known vendor.
    """
    match = re.search(r"([0-9A-Fa-f]{12})\s*$", str(text or ""))
    if not match:
        return None
    mac = normalize_mac(match.group(1))
    if mac and not is_randomized(mac) and _load().get(mac.replace(":", "")[:6]):
        return mac
    return None
