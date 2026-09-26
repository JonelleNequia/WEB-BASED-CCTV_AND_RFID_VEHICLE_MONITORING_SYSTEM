import json
import os
from pathlib import Path

MODULE_ROOT = Path(__file__).resolve().parent
PROJECT_ROOT = MODULE_ROOT.parent
PUBLIC_CAMERA_DIR = PROJECT_ROOT / "public" / "camera"
SNAPSHOTS_DIR = PUBLIC_CAMERA_DIR / "snapshots"
STATUS_FILE_PATH = PUBLIC_CAMERA_DIR / "camera_status.json"
RUNTIME_CONFIG_PATH = PROJECT_ROOT / "storage" / "app" / "camera" / "camera_runtime_config.json"
STATION_ACTIVITY_PATH = PROJECT_ROOT / "storage" / "app" / "camera" / "station_activity.json"
PUBLIC_STORAGE_DIR = PROJECT_ROOT / "storage" / "app" / "public"
DETECTED_IMAGE_DIR = PUBLIC_STORAGE_DIR / "detected-vehicle-images"

# CCTV Detection: added shared state paths for discovery, thumbnails, and the
# single-instance detector lock. All of them live beside the runtime config.
CAMERA_STATE_DIR = PROJECT_ROOT / "storage" / "app" / "camera"
DISCOVERY_THUMBNAILS_DIR = CAMERA_STATE_DIR / "thumbnails"
DISCOVERY_ATTEMPTS_PATH = CAMERA_STATE_DIR / "discovery_attempts.json"
DISCOVERY_RESCAN_FLAG_PATH = CAMERA_STATE_DIR / "discovery_rescan.flag"
DETECTOR_LOCK_PATH = CAMERA_STATE_DIR / "detector.lock"
DETECTOR_PID_PATH = CAMERA_STATE_DIR / "detector.pid"

CAPTURE_INTERVAL_SECONDS = 0.04
# Low latency: reconnect when a camera stops delivering new frames for this long.
CAPTURE_STALL_SECONDS = 5.0
RECONNECT_DELAY_SECONDS = 3.0
TRACK_STALE_AFTER_SECONDS = 1.5
STATUS_WRITE_INTERVAL_SECONDS = 1.0
API_TIMEOUT_SECONDS = 10
RFID_MATCH_TIMEOUT_SECONDS = 0.45
JPEG_QUALITY = 82
MJPEG_STREAM_BIND_HOST = os.environ.get("MJPEG_STREAM_BIND_HOST", "0.0.0.0")
MJPEG_STREAM_HOST = os.environ.get("MJPEG_STREAM_HOST", "127.0.0.1")
MJPEG_STREAM_PORT = 8765
DETECTION_FRAME_INTERVAL = 3
CAPTURE_DRAIN_FRAMES = 1
STREAM_FRAME_MAX_WIDTH = 1280
YOLO_IMAGE_SIZE = 640
RFID_DETECTION_WINDOW_SECONDS = 4.0
RFID_POLL_INTERVAL_SECONDS = 0.5
CAMERA_RETRY_DELAY_SECONDS = 2.0
STATION_VIEWER_IDLE_AFTER_SECONDS = 10.0
STATION_IDLE_POLL_SECONDS = 1.0

# Detection settings.
MODEL_PATH = "yolov8n.pt"

TRACKER_CONFIG = "bytetrack.yaml"
DETECTION_CONFIDENCE_THRESHOLD = 0.35
DETECTION_IOU_THRESHOLD = 0.45

# Allow all practical road or campus vehicle names that may appear in the
# current model or in a future custom detector. Unsupported names are skipped
# automatically when the model does not expose them.
ALLOWED_VEHICLE_CLASS_NAMES = {
    "auto rickshaw",
    "bus",
    "car",
    "electric scooter",
    "jeep",
    "jeepney",
    "motorbike",
    "motorcycle",
    "pickup",
    "pickup truck",
    "scooter",
    "suv",
    "tricycle",
    "truck",
    "van",
    "ebike",
}


def default_camera_config(role):
    """
    Provide a safe fallback config when Laravel has not written the runtime file yet.
    """
    return {
        "camera_role": role,
        "camera_name": f"PHILCST {role.capitalize()} Camera",
        "camera_id": None,
        "source_type": "webcam",
        "source_value": 0,
        "source_username": "",
        "source_password": "",
        "browser_device_id": None,
        "browser_label": None,
        "calibration_mask": None,
        "calibration_line": None,
    }


DEFAULT_RUNTIME_CONFIG = {
    "generated_at": None,
    "system_settings": {
        "operating_mode": "manual",
        "python_api_key": "",
        "app_url": "http://127.0.0.1:8000",
        "event_ingest_url": "http://127.0.0.1:8000/api/v1/integration/events",
        "guest_observation_url": "http://127.0.0.1:8000/api/guest-observation",
        "rfid_match_url": "http://127.0.0.1:8000/api/latest-scan",
        "status_url": "http://127.0.0.1:8000/api/v1/integration/status",
    },
    "cameras": {
        "entrance": default_camera_config("entrance"),
        "exit": default_camera_config("exit"),
    },
    # CCTV Detection: added defaults so discovery still runs before Laravel
    # exports its first "discovery" block.
    "discovery": {
        "enabled": True,
        "probe_interval_seconds": 8,
        "ip_scan_interval_seconds": 60,
        "offline_after_seconds": 30,
        "ingest_url": "http://127.0.0.1:8000/api/v1/integration/discovered-cameras",
        "rescan_flag_path": str(DISCOVERY_RESCAN_FLAG_PATH),
        "device_credentials": [],
        "site_credentials": [],
        "assigned_device_keys": [],
    },
}


def latest_frame_path(role):
    """
    Build the per-camera latest-frame output path.
    """
    return PUBLIC_CAMERA_DIR / f"{role}_latest_frame.jpg"


def annotated_frame_path(role):
    """
    Build the per-camera annotated-frame output path for the guard monitor.
    """
    return PUBLIC_CAMERA_DIR / f"{role}_annotated_frame.jpg"


def normalize_camera_config(role, loaded_config):
    """
    Normalize a camera config so the rest of the service can trust its keys.
    """
    config = default_camera_config(role)

    if isinstance(loaded_config, dict):
        config.update(loaded_config)

    source_type = str(config.get("source_type", "webcam")).strip().lower()
    # CCTV Detection: "none" means the station has no assigned camera yet, so
    # it must not silently fall back to the laptop webcam.
    if source_type not in {"webcam", "rtsp", "url", "none"}:
        source_type = "webcam"

    config["camera_role"] = role
    config["camera_id"] = config.get("camera_id") or config.get("id")
    config["camera_name"] = str(config.get("camera_name", config["camera_name"])).strip() or config["camera_name"]
    config["source_type"] = source_type
    config["source_username"] = str(config.get("source_username", "") or "").strip()
    config["source_password"] = str(config.get("source_password", "") or "").strip()
    config["browser_device_id"] = config.get("browser_device_id")
    config["browser_label"] = config.get("browser_label")
    config["calibration_mask"] = config.get("calibration_mask")
    config["calibration_line"] = config.get("calibration_line")

    if source_type == "webcam":
        try:
            config["source_value"] = int(config.get("source_value", config["source_value"]))
        except (TypeError, ValueError):
            config["source_value"] = default_camera_config(role)["source_value"]
    elif source_type == "none":
        config["source_value"] = ""
    else:
        config["source_value"] = str(config.get("source_value", "")).strip()

    return config


def normalize_discovery_config(loaded_discovery):
    """
    CCTV Detection: merge Laravel's discovery block onto safe defaults.
    """
    discovery = json.loads(json.dumps(DEFAULT_RUNTIME_CONFIG["discovery"]))

    if not isinstance(loaded_discovery, dict):
        return discovery

    discovery.update({key: value for key, value in loaded_discovery.items() if value is not None})

    for key in ("probe_interval_seconds", "ip_scan_interval_seconds", "offline_after_seconds"):
        try:
            discovery[key] = max(1, int(discovery[key]))
        except (TypeError, ValueError):
            discovery[key] = DEFAULT_RUNTIME_CONFIG["discovery"][key]

    for key in ("device_credentials", "site_credentials", "assigned_device_keys"):
        if not isinstance(discovery.get(key), list):
            discovery[key] = []

    discovery["enabled"] = bool(discovery.get("enabled", True))

    return discovery


def load_runtime_config(path=None):
    """
    Load the dual-camera config exported by Laravel.

    CCTV Detection: accepts an optional path so discovery tests can use an
    isolated copy, and keeps the new "discovery" block.
    """
    config = json.loads(json.dumps(DEFAULT_RUNTIME_CONFIG))
    runtime_config_path = Path(path) if path else RUNTIME_CONFIG_PATH

    if not runtime_config_path.exists():
        return config

    try:
        loaded = json.loads(runtime_config_path.read_text(encoding="utf-8"))
    except Exception:
        return config

    if not isinstance(loaded, dict):
        return config

    config["generated_at"] = loaded.get("generated_at")

    if isinstance(loaded.get("system_settings"), dict):
        config["system_settings"].update(loaded["system_settings"])

    loaded_cameras = loaded.get("cameras", {})
    if isinstance(loaded_cameras, dict):
        for role in ("entrance", "exit"):
            config["cameras"][role] = normalize_camera_config(role, loaded_cameras.get(role))

    # CCTV Detection: added — previously any extra top-level block was dropped.
    config["discovery"] = normalize_discovery_config(loaded.get("discovery"))

    return config


def resolve_capture_source(camera_config):
    """
    Convert the configured source into the value expected by OpenCV.
    """
    if camera_config["source_type"] == "webcam":
        return int(camera_config["source_value"])

    # CCTV Detection: an unassigned station has nothing to open.
    if camera_config["source_type"] == "none":
        return ""

    return str(camera_config["source_value"])
