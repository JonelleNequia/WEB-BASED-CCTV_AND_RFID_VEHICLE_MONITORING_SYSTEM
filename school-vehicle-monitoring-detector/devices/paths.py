"""
File locations and editable data shared by the device service.

Nothing here names a device address: ports, discovery packets and factory
defaults live in data/discovery_profiles.json, and the station assignments
come from Laravel (device_runtime_config.json).
"""

import json
import os
from pathlib import Path

MODULE_ROOT = Path(__file__).resolve().parent
DETECTOR_ROOT = MODULE_ROOT.parent
PROJECT_ROOT = DETECTOR_ROOT.parent

DEVICE_FILES_DIR = Path(os.environ.get("DEVICE_FILES_PATH") or (PROJECT_ROOT / "storage" / "app" / "devices"))
STATUS_PATH = DEVICE_FILES_DIR / "device_service_status.json"
SCAN_RESULT_PATH = DEVICE_FILES_DIR / "last_scan.json"
# Overridable so `sudo ... --find` can read the config without writing root-owned files here.
RUNTIME_CONFIG_PATH = Path(os.environ.get("DEVICE_RUNTIME_CONFIG_PATH") or (DEVICE_FILES_DIR / "device_runtime_config.json"))
LOCK_PATH = DEVICE_FILES_DIR / "device_service.pid"
CAPTURE_LOG_PATH = DEVICE_FILES_DIR / "reader_capture.log"
SERVICE_LOG_PATH = PROJECT_ROOT / "storage" / "logs" / "device-service.log"

PROFILES_PATH = Path(os.environ.get("DEVICE_PROFILES_PATH") or (MODULE_ROOT / "data" / "discovery_profiles.json"))
OUI_PATH = MODULE_ROOT / "data" / "oui.tsv.gz"


def load_profiles():
    with open(PROFILES_PATH, "r", encoding="utf-8") as handle:
        return json.load(handle)


def load_runtime_config():
    """
    Station assignments, Laravel URLs and the API key, written by Laravel.
    """
    try:
        loaded = json.loads(RUNTIME_CONFIG_PATH.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}
    return loaded if isinstance(loaded, dict) else {}


def write_json_atomic(path, payload):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(f"{path.name}.{os.getpid()}.tmp")
    temporary.write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")
    try:
        os.replace(temporary, path)
    except OSError:
        # Windows cannot replace a file another process has open.
        path.write_text(temporary.read_text(encoding="utf-8"), encoding="utf-8")
        temporary.unlink(missing_ok=True)
