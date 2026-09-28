import json
import os
from pathlib import Path

MODULE_ROOT = Path(__file__).resolve().parent
PROJECT_ROOT = MODULE_ROOT.parent
# Phase 6: runtime camera files stay out of public/. Laravel serves them only
# to signed-in users (see app/Support/CameraFiles.php for the same layout).
CAMERA_FILES_DIR = PROJECT_ROOT / "storage" / "app" / "camera"
FRAMES_DIR = CAMERA_FILES_DIR / "frames"
SNAPSHOTS_DIR = CAMERA_FILES_DIR / "snapshots"
STATUS_FILE_PATH = CAMERA_FILES_DIR / "camera_status.json"
RUNTIME_CONFIG_PATH = PROJECT_ROOT / "storage" / "app" / "camera" / "camera_runtime_config.json"
STATION_ACTIVITY_PATH = PROJECT_ROOT / "storage" / "app" / "camera" / "station_activity.json"
PUBLIC_STORAGE_DIR = PROJECT_ROOT / "storage" / "app" / "public"
DETECTED_IMAGE_DIR = PUBLIC_STORAGE_DIR / "detected-vehicle-images"

CAPTURE_INTERVAL_SECONDS = 0.04
# Low latency: reconnect when a camera stops delivering new frames for this long.
CAPTURE_STALL_SECONDS = 5.0
# The first frame after connecting takes longer (key frame, high-resolution
# main stream with the low-latency options): allow more time for that one only.
CAPTURE_FIRST_FRAME_SECONDS = 15.0
RECONNECT_DELAY_SECONDS = 3.0
TRACK_STALE_AFTER_SECONDS = 1.5
STATUS_WRITE_INTERVAL_SECONDS = 1.0
API_TIMEOUT_SECONDS = 10
RFID_MATCH_TIMEOUT_SECONDS = 0.45
JPEG_QUALITY = 82


def _exported_system_settings():
    """
    Plug-and-detect: the stream host/port are set once in Laravel
    (config/monitoring.php, DETECTOR_STREAM_PORT) and exported here.
    """
    try:
        loaded = json.loads(RUNTIME_CONFIG_PATH.read_text(encoding="utf-8"))
        return loaded.get("system_settings") or {}
    except (OSError, ValueError, AttributeError):
        return {}


_EXPORTED = _exported_system_settings()
MJPEG_STREAM_BIND_HOST = os.environ.get("MJPEG_STREAM_BIND_HOST", "0.0.0.0")
MJPEG_STREAM_HOST = os.environ.get("MJPEG_STREAM_HOST") or _EXPORTED.get("stream_host") or "localhost"
# Fallback only when Laravel has not exported a config yet (same default as config/monitoring.php).
MJPEG_STREAM_PORT = int(os.environ.get("DETECTOR_STREAM_PORT") or _EXPORTED.get("stream_port") or 8765)
DETECTION_FRAME_INTERVAL = 3
CAPTURE_DRAIN_FRAMES = 1
STREAM_FRAME_MAX_WIDTH = 1280
YOLO_IMAGE_SIZE = 640
RFID_DETECTION_WINDOW_SECONDS = 4.0
# Phase 5: accept RFID reads from this many seconds BEFORE the vehicle crosses
# the trigger line (a UHF reader reads the tag while the car is approaching).
RFID_LOOKBACK_SECONDS = 10.0
RFID_POLL_INTERVAL_SECONDS = 0.5
CAMERA_RETRY_DELAY_SECONDS = 2.0
STATION_VIEWER_IDLE_AFTER_SECONDS = 10.0
STATION_IDLE_POLL_SECONDS = 1.0

# Live-latency defaults; Laravel exports the values set in Settings › Cameras.
PERFORMANCE_DEFAULTS = {
    "stream_fps": 15.0,        # JPEGs per second sent to the live views
    "stream_width": 960,       # live view width in pixels (resized before encoding)
    "jpeg_quality": 70,
    "detection_fps": 8.0,      # YOLO runs per second per camera
    "yolo_imgsz": 480,         # YOLO input size
    "yolo_device": "auto",     # auto = cuda, then Apple mps, then cpu
    "roi_crop": 1,             # run YOLO only on the calibrated zone when it is smaller than the frame
    "hires_on_trigger": 1,     # fetch a full-resolution frame from the snapshot stream on each trigger
}
# The full-resolution stream is decoded with more threads, which delays it by
# about this much against the low-delay live stream (measured on the VIGI C240).
HIRES_DECODE_DELAY_SECONDS = 0.35

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
        # Plug-and-detect: Laravel writes the real URLs (from APP_URL) into
        # camera_runtime_config.json before it starts the detector.
        "app_url": "",
        "event_ingest_url": "",
        "guest_observation_url": "",
        "rfid_match_url": "",
        "status_url": "",
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
    return FRAMES_DIR / f"{role}_latest_frame.jpg"


def annotated_frame_path(role):
    """
    Build the per-camera annotated-frame output path for the guard monitor.
    """
    return FRAMES_DIR / f"{role}_annotated_frame.jpg"


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
    # Live-latency work: full-resolution stream used only for trigger snapshots,
    # and the decoder thread hint (1 = lowest delay, 0 = let FFmpeg decide).
    config["snapshot_source_value"] = str(config.get("snapshot_source_value", "") or "").strip()
    try:
        config["decoder_threads"] = int(config.get("decoder_threads", 1))
    except (TypeError, ValueError):
        config["decoder_threads"] = 1
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


_RUNTIME_CACHE = {"mtime": None, "config": None}


def load_runtime_config():
    """
    Load the dual-camera config exported by Laravel.

    Live-latency work: the stream workers call this for every frame; the file
    is parsed again only when Laravel rewrote it.
    """
    try:
        mtime = RUNTIME_CONFIG_PATH.stat().st_mtime_ns
    except OSError:
        mtime = None
    cached = _RUNTIME_CACHE["config"]
    if cached is not None and mtime is not None and mtime == _RUNTIME_CACHE["mtime"]:
        return json.loads(json.dumps(cached))
    config = _load_runtime_config_file()
    _RUNTIME_CACHE.update({"mtime": mtime, "config": config})
    return json.loads(json.dumps(config))


def performance_settings(runtime_config):
    """
    Live view and detection tuning (Settings › Cameras › Live view performance).
    """
    settings = dict(PERFORMANCE_DEFAULTS)
    exported = (runtime_config.get("system_settings") or {}).get("performance") or {}
    for key, default in PERFORMANCE_DEFAULTS.items():
        try:
            settings[key] = type(default)(exported.get(key, default)) if not isinstance(default, str) else str(exported.get(key, default))
        except (TypeError, ValueError):
            settings[key] = default
    return settings


def _load_runtime_config_file():
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
