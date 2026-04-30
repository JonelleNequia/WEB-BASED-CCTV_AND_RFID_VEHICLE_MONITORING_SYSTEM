import json
from pathlib import Path

# Resolve project paths from this Python module directory.
MODULE_ROOT = Path(__file__).resolve().parent
PROJECT_ROOT = MODULE_ROOT.parent
PUBLIC_CAMERA_DIR = PROJECT_ROOT / "public" / "camera"
SNAPSHOTS_DIR = PUBLIC_CAMERA_DIR / "snapshots"
STATUS_FILE_PATH = PUBLIC_CAMERA_DIR / "camera_status.json"
RUNTIME_CONFIG_PATH = PROJECT_ROOT / "storage" / "app" / "camera" / "camera_runtime_config.json"
PUBLIC_STORAGE_DIR = PROJECT_ROOT / "storage" / "app" / "public"
DETECTED_IMAGE_DIR = PUBLIC_STORAGE_DIR / "detected-vehicle-images"

# Runtime behavior.
CAPTURE_INTERVAL_SECONDS = 0.08
RECONNECT_DELAY_SECONDS = 3.0
TRACK_STALE_AFTER_SECONDS = 5.0
STATUS_WRITE_INTERVAL_SECONDS = 1.0
API_TIMEOUT_SECONDS = 10
JPEG_QUALITY = 90
MJPEG_STREAM_HOST = "127.0.0.1"
MJPEG_STREAM_PORT = 8765

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
        "source_value": 0 if role == "entrance" else 1,
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
        "status_url": "http://127.0.0.1:8000/api/v1/integration/status",
    },
    "cameras": {
        "entrance": default_camera_config("entrance"),
        "exit": default_camera_config("exit"),
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
    if source_type not in {"webcam", "rtsp", "url"}:
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
    else:
        config["source_value"] = str(config.get("source_value", "")).strip()

    return config


def load_runtime_config():
    """
    Load the dual-camera config exported by Laravel.
    """
    config = json.loads(json.dumps(DEFAULT_RUNTIME_CONFIG))

    if not RUNTIME_CONFIG_PATH.exists():
        return config

    try:
        loaded = json.loads(RUNTIME_CONFIG_PATH.read_text(encoding="utf-8"))
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

    return config


def resolve_capture_source(camera_config):
    """
    Convert the configured source into the value expected by OpenCV.
    """
    if camera_config["source_type"] == "webcam":
        return int(camera_config["source_value"])

    return str(camera_config["source_value"])
