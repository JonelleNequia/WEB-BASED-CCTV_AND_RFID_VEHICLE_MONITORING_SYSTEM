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
# A3: a track's path is remembered through a short gap (a person or another
# vehicle passing in front) as long as ByteTrack keeps its ID: track_buffer 30
# at 7-8 detections per second is about 4 s.
TRACK_MEMORY_SECONDS = 4.0
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
    "debug_overlay": 0,        # draw raw detections, zone, line, tracks and counters on the live view
    # A2: vehicle type (vehicle_type.py).
    "type_second_pass": 1,          # second check on the best full-resolution crop
    "type_model": "yolov8s.pt",     # model of the second check (once per vehicle; yolov8n.pt when missing)
    "type_second_pass_imgsz": 960,  # input size of the second check
    "type_truck_min_height": 0.35,  # Truck/Bus needs a back at least this share of the zone's height...
    "type_car_min_aspect": 1.05,    # ...and narrower (width / height) than this; otherwise it is a Car
    # A3: one vehicle = one crossing (tracking.LineCrossing).
    "cross_margin": 0.05,           # the centre must be this far past the line (share of the zone's height)
    "cross_min_points": 3,          # sightings of the track before it can count
    "cross_min_move": 0.10,         # movement since it was first seen (share of the zone's height)
}
# The full-resolution stream is decoded with more threads, which delays it by
# about this much against the low-delay live stream (measured on the VIGI C240).
HIRES_DECODE_DELAY_SECONDS = 0.35

# Windows install kit: the models come from a local folder (offline; the
# installer puts them in models/). The old place (this folder) still works
# for development. Never a download at run time.
MODELS_DIR = Path(os.environ.get("DETECTOR_MODELS_DIR") or (MODULE_ROOT / "models"))


def model_file(name):
    """Absolute path of a model file: models/ first, then this folder."""
    for folder in (MODELS_DIR, MODULE_ROOT):
        if (folder / name).exists():
            return folder / name
    return MODELS_DIR / name


# EasyOCR's detector and recognizer files (craft_mlt_25k.pth, english_g2.pth):
# models/easyocr when present, else EasyOCR's own folder in the user's home.
EASYOCR_MODEL_DIR = os.environ.get("EASYOCR_MODEL_DIR") or (
    str(MODELS_DIR / "easyocr") if (MODELS_DIR / "easyocr").is_dir() else None
)

# Ultralytics keeps its settings in a writable folder of this project (a
# Windows service has no normal user folder) and never goes online.
os.environ.setdefault("YOLO_CONFIG_DIR", str(PROJECT_ROOT / "storage" / "app" / "ultralytics"))
os.environ.setdefault("YOLO_OFFLINE", "1")

# Detection settings.
MODEL_PATH = str(model_file("yolov8n.pt"))

# Tuned for 7-8 detections per second (see the file); overridable for tests.
TRACKER_CONFIG = os.environ.get("DETECTOR_TRACKER_CONFIG") or str(Path(__file__).resolve().parent / "trackers" / "bytetrack_gate.yaml")
DETECTION_CONFIDENCE_THRESHOLD = 0.35
# YOLO reports every class down to this score; the detector then keeps
# vehicle classes at DETECTION_CONFIDENCE_THRESHOLD (a vehicle already being
# tracked may dip lower for a frame without losing its track). The debug view
# shows everything YOLO saw, and ByteTrack's second stage gets the low scores
# it is designed for (fewer lost track IDs at 7-8 detections per second).
RAW_CONFIDENCE_FLOOR = 0.10
# Last positions kept per track (line crossing and the debug trail).
TRACK_TRAIL_POINTS = 12
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
        # Camera source work: no camera until Laravel exports one (no USB webcam).
        "source_type": "none",
        "source_value": "",
        "source_username": "",
        "source_password": "",
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
    # Phase 1: one camera per gate (keys = gate codes). Laravel exports the
    # real list; these two are only used before the first export.
    "cameras": {
        "gate-1": default_camera_config("gate-1"),
        "gate-2": default_camera_config("gate-2"),
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

    source_type = str(config.get("source_type", "none")).strip().lower()
    # B1 (Settings): "none" = this gate has no camera yet. Network cameras
    # (RTSP / stream URL); "webcam" = this PC's webcam, for testing only.
    if source_type not in {"rtsp", "url", "webcam", "none"}:
        source_type = "none"

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
    config["calibration_mask"] = config.get("calibration_mask")
    config["calibration_line"] = config.get("calibration_line")

    if source_type == "webcam":
        try:
            config["source_value"] = int(config.get("source_value") or 0)
        except (TypeError, ValueError):
            config["source_value"] = 0
    else:
        config["source_value"] = str(config.get("source_value", "") or "").strip()

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
    if isinstance(loaded_cameras, dict) and loaded_cameras:
        # Gate order from Laravel; any camera without a gate entry goes last.
        order = [gate.get("code") for gate in loaded.get("gates") or [] if isinstance(gate, dict)]
        roles = [role for role in order if role in loaded_cameras] + [role for role in loaded_cameras if role not in order]
        config["cameras"] = {role: normalize_camera_config(role, loaded_cameras.get(role)) for role in roles}
    config["gates"] = loaded.get("gates") or []

    return config


def camera_roles(runtime_config):
    """Gate codes that have a camera, in gate order (Phase 1)."""
    return list((runtime_config or {}).get("cameras") or {})


def resolve_capture_source(camera_config):
    """
    Convert the configured source into the value expected by OpenCV.
    """
    if camera_config["source_type"] == "webcam":
        return int(camera_config["source_value"])
    return str(camera_config["source_value"])
