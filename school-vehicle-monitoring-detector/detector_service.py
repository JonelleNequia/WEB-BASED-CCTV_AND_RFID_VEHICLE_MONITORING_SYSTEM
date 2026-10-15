import json
import os
import sys
import threading
import time
from datetime import datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import quote, urlparse, urlunparse

import cv2
import numpy as np

from config import (
    ALLOWED_VEHICLE_CLASS_NAMES,
    CAMERA_FILES_DIR,
    CAPTURE_INTERVAL_SECONDS,
    CAPTURE_DRAIN_FRAMES,
    CAPTURE_STALL_SECONDS,
    CAPTURE_FIRST_FRAME_SECONDS,
    CAMERA_RETRY_DELAY_SECONDS,
    DETECTED_IMAGE_DIR,
    DETECTION_FRAME_INTERVAL,
    DETECTION_CONFIDENCE_THRESHOLD,
    DETECTION_IOU_THRESHOLD,
    FRAMES_DIR,
    JPEG_QUALITY,
    MJPEG_STREAM_BIND_HOST,
    MJPEG_STREAM_HOST,
    MJPEG_STREAM_PORT,
    MODEL_PATH,
    model_file,
    PERFORMANCE_DEFAULTS,
    RECONNECT_DELAY_SECONDS,
    RFID_DETECTION_WINDOW_SECONDS,
    RFID_LOOKBACK_SECONDS,
    RFID_MATCH_TIMEOUT_SECONDS,
    RFID_POLL_INTERVAL_SECONDS,
    RAW_CONFIDENCE_FLOOR,
    SNAPSHOTS_DIR,
    STATION_ACTIVITY_PATH,
    STATION_VIEWER_IDLE_AFTER_SECONDS,
    STATUS_FILE_PATH,
    STATUS_WRITE_INTERVAL_SECONDS,
    STREAM_FRAME_MAX_WIDTH,
    TRACK_MEMORY_SECONDS,
    TRACK_STALE_AFTER_SECONDS,
    TRACK_TRAIL_POINTS,
    TRACKER_CONFIG,
    YOLO_IMAGE_SIZE,
    annotated_frame_path,
    latest_frame_path,
    camera_roles,
    load_runtime_config,
    performance_settings,
    resolve_capture_source,
)
from code_version import code_stamp, code_version
from motion import MotionGate
from laravel_client import LaravelEventClient
from tracking import (
    STILL_SHARE,
    VehiclePass,
    bbox_bottom_center,
    bbox_center,
    calibration_problem,
    calibration_ready,
    normalized_away_direction,
    normalized_polygon_to_pixels,
    point_in_polygon,
    zone_height,
)
from anpr import detect_vehicle_color, ocr_runtime_status, read_license_plate_details
from plate_voting import frames_agree, vote_plate
import plate_reader
from camera_health import ReconnectBackoff, RtspDiagnosis, rtsp_play_check, short_reason, strip_credentials, take_over_stale_detector
import metrics
from hires import HiResGrabber, scale_box
from capture_hub import HUB as CAPTURE_HUB
import vehicle_type as vtype

# Phase 1: one camera per gate; the gate codes come from Laravel's runtime
# config (camera_roles()) and can change while the detector runs.
STREAM_FRAMES = {}
# When the frame behind each published JPEG was decoded (for latency metrics).
STREAM_FRAME_TIMES = {}
STREAM_CONDITION = threading.Condition()
# Open MJPEG connections per camera. Any page that shows the live view
# (Station, Gate Monitor, Calibration, Settings › Cameras) counts as a viewer.
STREAM_CLIENTS = {}
STREAM_CLIENTS_LOCK = threading.Lock()
# Live view (go2rtc) work: each gate's state, for the /overlay/{gate} JSON
# the browser draws over the WebRTC video.
OVERLAY_STATES = {}
RTSP_DIAGNOSIS = RtspDiagnosis()
# Shared camera work: "does it still play?" when DESCRIBE is fine but OpenCV could not open (too many connections).
RTSP_PLAY_CHECK = RtspDiagnosis(check=rtsp_play_check)
# Full-resolution frames from the snapshot (main) stream, only around triggers.
HIRES = {}
# Shared camera work: one full-resolution grabber per main stream (its address
# with the login); HIRES maps each gate to the grabber of its camera.
SHARED_HIRES = {}


def hires_grabber(role, camera_config):
    url = build_connection_source(camera_config, camera_config.get("snapshot_source_value") or "")
    key = capture_key("rtsp", url)
    grabber = SHARED_HIRES.get(key)
    if grabber is None:
        grabber = SHARED_HIRES[key] = HiResGrabber(
            role,
            lambda config: build_connection_source(config, config["snapshot_source_value"]),
            opener=open_hires_capture,
        )
    previous = HIRES.get(role)
    if previous is not None and previous is not grabber:
        previous.roles.discard(role)  # this gate's camera changed
    grabber.roles.add(role)
    HIRES[role] = grabber
    return grabber


def open_hires_capture(url, role):
    """The main stream through the shared connections (FFmpeg's own decoder threads)."""
    def connect():
        configure_network_capture_options("rtsp")
        capture = cv2.VideoCapture(url, cv2.CAP_FFMPEG)
        return LatestFrameReader(capture, role, 0) if capture.isOpened() else capture

    return CAPTURE_HUB.acquire(capture_key("rtsp", url), role, connect, 0)
RESOLVED_OVERLAY_HOLD_SECONDS = 1.25
RESOLVED_DETECTION_COOLDOWN_SECONDS = 1.5
GUEST_TRACK_COOLDOWN_SECONDS = 10.0
DUPLICATE_TRACK_IOU_THRESHOLD = 0.18
DUPLICATE_TRACK_CENTER_DISTANCE_RATIO = 0.28
MAX_GUEST_ANALYSIS_FRAMES = 5
MAX_GUEST_OCR_ANALYSIS_FRAMES = 4
# Phase 5: OCR of one vehicle stops after this long (it runs after the alert is sent).
PLATE_OCR_BUDGET_SECONDS = 12.0
MAX_GUEST_COLOR_ANALYSIS_FRAMES = 3
GUEST_ANALYSIS_FRAME_INTERVAL_SECONDS = 0.45
# Gate direction work, Phase B: a burst for the plate, a frame every 0.15 s
# while the vehicle is in the zone (the main stream's when it is open); the
# biggest views of the vehicle are kept (plate_reader picks the sharpest plate).
PLATE_FRAME_INTERVAL_SECONDS = 0.15
MAX_PLATE_FRAMES = 8
LATEST_FRAME_SAVE_INTERVAL_SECONDS = 0.20


class ReusableThreadingHTTPServer(ThreadingHTTPServer):
    allow_reuse_address = True
    daemon_threads = True


class MjpegStreamHandler(BaseHTTPRequestHandler):
    """
    Serve live detector frames from memory so station screens do not poll saved files.
    """

    def do_GET(self):
        request_path = urlparse(self.path).path

        if request_path == "/health":
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Access-Control-Allow-Origin", "*")
            self.end_headers()
            self.wfile.write(b'{"ok":true}')
            return

        role = request_path.strip("/").split("/")
        if len(role) != 2 or role[0] not in ("stream", "overlay") or role[1] not in camera_roles(load_runtime_config()):
            self.send_error(404)
            return

        if role[0] == "overlay":
            self.send_overlay(role[1])
            return

        self.stream_role(role[1])

    def send_overlay(self, role):
        """Live view work: boxes, zone, line and the latest IN/OUT as JSON (0-1 coordinates)."""
        state = OVERLAY_STATES.get(role)
        camera_config = (load_runtime_config().get("cameras") or {}).get(role) or {}
        body = json.dumps(overlay_payload(state, camera_config) if state else {"tracks": [], "ready": False}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()
        self.wfile.write(body)

    def stream_role(self, role):
        self.send_response(200)
        self.send_header("Content-Type", "multipart/x-mixed-replace; boundary=frame")
        self.send_header("Cache-Control", "no-store, no-cache, must-revalidate, max-age=0")
        self.send_header("Pragma", "no-cache")
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()

        last_frame_id = None

        with STREAM_CLIENTS_LOCK:
            STREAM_CLIENTS[role] = STREAM_CLIENTS.get(role, 0) + 1
        try:
            self._send_frames(role, last_frame_id)
        finally:
            with STREAM_CLIENTS_LOCK:
                STREAM_CLIENTS[role] -= 1

    def _send_frames(self, role, last_frame_id):
        while True:
            with STREAM_CONDITION:
                STREAM_CONDITION.wait_for(
                    lambda: STREAM_FRAMES.get(role) is not None and id(STREAM_FRAMES.get(role)) != last_frame_id,
                    timeout=1.0,
                )
                frame = STREAM_FRAMES.get(role)

            if frame is None:
                continue

            last_frame_id = id(frame)
            frame_time = STREAM_FRAME_TIMES.get(role, 0.0)

            try:
                self.wfile.write(b"--frame\r\n")
                self.wfile.write(b"Content-Type: image/jpeg\r\n")
                self.wfile.write(f"Content-Length: {len(frame)}\r\n\r\n".encode("ascii"))
                self.wfile.write(frame)
                self.wfile.write(b"\r\n")
                self.wfile.flush()
            except (BrokenPipeError, ConnectionResetError):
                return
            metrics.rate(role, "stream_sent")
            if frame_time:
                metrics.timing(role, "pipeline", (time.monotonic() - frame_time) * 1000.0)

    def log_message(self, format, *args):
        return


def ensure_output_directories():
    """
    Create the folders that Laravel and the detector both read from.
    """
    CAMERA_FILES_DIR.mkdir(parents=True, exist_ok=True)
    FRAMES_DIR.mkdir(parents=True, exist_ok=True)
    SNAPSHOTS_DIR.mkdir(parents=True, exist_ok=True)
    DETECTED_IMAGE_DIR.mkdir(parents=True, exist_ok=True)

    for role in camera_roles(load_runtime_config()):
        (DETECTED_IMAGE_DIR / role).mkdir(parents=True, exist_ok=True)


def write_text_atomic(path, content):
    """
    Write text atomically so Laravel does not read partial JSON.
    """
    path.parent.mkdir(parents=True, exist_ok=True)
    temp_path = path.with_suffix(f"{path.suffix}.{os.getpid()}.{threading.get_ident()}.tmp")
    temp_path.write_text(content, encoding="utf-8")
    os.replace(temp_path, path)


def parse_timestamp(value):
    """
    Parse an ISO timestamp from Laravel without assuming local or UTC format.
    """
    if not value:
        return None

    try:
        return datetime.fromisoformat(str(value).replace("Z", "+00:00")).astimezone()
    except ValueError:
        return None


# Phase 1: last successfully parsed heartbeat, reused when a read fails.
_STATION_ACTIVITY_CACHE = {"status": None, "read_at": 0.0}
_STATION_ACTIVITY_LOCK = threading.Lock()
STATION_ACTIVITY_CACHE_SECONDS = 0.5


def station_activity_status():
    """
    Read Laravel's station heartbeat so the live stream idles when no station is open.

    Phase 1: cached for 0.5s (it was re-read for every frame), and a failed or
    partial read keeps the last good value instead of reporting "no viewer".
    Laravel now also writes the file atomically.
    """
    now_monotonic = time.monotonic()

    with _STATION_ACTIVITY_LOCK:
        cached = _STATION_ACTIVITY_CACHE["status"]

        if cached is not None and now_monotonic - _STATION_ACTIVITY_CACHE["read_at"] < STATION_ACTIVITY_CACHE_SECONDS:
            return cached

    status = _read_station_activity_status(cached)

    with _STATION_ACTIVITY_LOCK:
        _STATION_ACTIVITY_CACHE["status"] = status
        _STATION_ACTIVITY_CACHE["read_at"] = now_monotonic

    return status


def _read_station_activity_status(last_good_status=None):
    """
    Parse station_activity.json once.
    """
    default_status = {
        "active": False,
        "last_seen_at": None,
        "active_until": None,
        "seconds_until_idle": 0,
        "active_locations": [],
    }

    try:
        with open(STATION_ACTIVITY_PATH, "r", encoding="utf-8") as activity_file:
            activity = json.load(activity_file)
    except FileNotFoundError:
        return default_status
    except (OSError, json.JSONDecodeError):
        # Phase 1: a half-written file used to pause the cameras.
        return last_good_status or default_status

    now = datetime.now().astimezone()
    active_until = parse_timestamp(activity.get("active_until"))
    locations = activity.get("locations") if isinstance(activity.get("locations"), dict) else {}
    active_locations = []
    last_seen_at = parse_timestamp(activity.get("last_seen_at"))

    for location, seen_at in locations.items():
        location_seen_at = parse_timestamp(seen_at)

        if not location_seen_at:
            continue

        if last_seen_at is None or location_seen_at > last_seen_at:
            last_seen_at = location_seen_at

        if (now - location_seen_at).total_seconds() <= STATION_VIEWER_IDLE_AFTER_SECONDS:
            active_locations.append(location)

    active = bool(active_locations) or bool(active_until and active_until > now)

    if active_until is None and last_seen_at is not None:
        active_until = last_seen_at + timedelta(seconds=STATION_VIEWER_IDLE_AFTER_SECONDS)

    seconds_until_idle = (
        max(0, int((active_until - now).total_seconds()))
        if active_until is not None
        else 0
    )

    return {
        "active": active,
        "last_seen_at": last_seen_at.isoformat() if last_seen_at else None,
        "active_until": active_until.isoformat() if active_until else None,
        "seconds_until_idle": seconds_until_idle,
        "active_locations": sorted(active_locations),
    }


def station_viewer_active(role=None):
    """
    Whether someone is watching: a page checked in recently, or a browser
    has this camera's live stream open right now.
    """
    if role is not None:
        with STREAM_CLIENTS_LOCK:
            if STREAM_CLIENTS.get(role, 0) > 0:
                return True
    return station_activity_status()["active"]


def save_frame_atomic(role, frame):
    """
    Keep the latest raw frame per camera for debugging.
    """
    encoded, buffer = cv2.imencode(
        ".jpg",
        frame,
        [cv2.IMWRITE_JPEG_QUALITY, JPEG_QUALITY],
    )

    if not encoded:
        return False

    output_path = latest_frame_path(role)
    temp_path = output_path.with_suffix(output_path.suffix + ".tmp")
    temp_path.write_bytes(buffer.tobytes())
    os.replace(temp_path, output_path)

    return True


def maybe_save_latest_frame(role, frame, state, now_monotonic):
    """
    Keep a recent raw frame on disk for Laravel-side Guest RFID snapshots.
    """
    last_saved_at = float(state.get("last_frame_saved_at", 0.0))

    if now_monotonic - last_saved_at < LATEST_FRAME_SAVE_INTERVAL_SECONDS:
        return False

    try:
        saved = save_frame_atomic(role, frame)
    except Exception:
        saved = False

    state["last_frame_saved_at"] = now_monotonic

    return saved


def publish_due(state, perf):
    """
    Live view rate cap (stream_fps): skip encoding frames nobody would see.
    A running schedule keeps the average at stream_fps even when the camera's
    frame rate is not a multiple of it (e.g. 15 of 25 fps).
    """
    now = time.monotonic()
    interval = 1.0 / max(1.0, float(perf.get("stream_fps", 15.0)))
    due = state.get("next_publish_at", 0.0)
    if now < due:
        return False
    state["next_publish_at"] = max(due + interval, now - interval)
    return True


def publish_stream_frame(role, frame, frame_time=None, perf=None):
    """
    Publish one live frame to connected MJPEG clients without saving it to disk.

    Encoded once per frame; every viewer of this camera gets the same JPEG.
    """
    width = int((perf or {}).get("stream_width", STREAM_FRAME_MAX_WIDTH))
    quality = int((perf or {}).get("jpeg_quality", JPEG_QUALITY))
    with metrics.timed(role, "resize"):
        frame = resize_frame_for_stream(frame, width)
    with metrics.timed(role, "encode"):
        encoded, buffer = cv2.imencode(
            ".jpg",
            frame,
            [cv2.IMWRITE_JPEG_QUALITY, quality],
        )

    if not encoded:
        return False

    metrics.rate(role, "stream_published")
    metrics.value(role, "jpeg_kb", round(len(buffer) / 1024, 1))
    with STREAM_CONDITION:
        STREAM_FRAMES[role] = buffer.tobytes()
        STREAM_FRAME_TIMES[role] = frame_time or time.monotonic()
        STREAM_CONDITION.notify_all()

    return True


def resize_frame_for_stream(frame, max_width=STREAM_FRAME_MAX_WIDTH):
    """
    Bound MJPEG frame size so browser display stays responsive on low-end CPUs.
    """
    height, width = frame.shape[:2]

    if max_width <= 0 or width <= max_width:
        return frame

    scale = max_width / float(width)
    target_size = (max_width, max(1, int(height * scale)))

    return cv2.resize(frame, target_size, interpolation=cv2.INTER_AREA)


def gate_name(role):
    """Phase 1: the gate's display name ("Main Gate") from Laravel's export."""
    for gate in load_runtime_config().get("gates") or []:
        if isinstance(gate, dict) and gate.get("code") == role:
            return str(gate.get("name") or role)
    return str(role)


def publish_status_frame(role, title, detail):
    """
    Publish a simple diagnostic frame when a configured camera cannot provide
    live frames. This keeps station/calibration MJPEG clients connected.
    """
    frame = np.zeros((720, 1280, 3), dtype=np.uint8)
    frame[:, :] = (34, 25, 54)

    cv2.putText(
        frame,
        f"{gate_name(role).upper()} CAMERA",
        (70, 170),
        cv2.FONT_HERSHEY_SIMPLEX,
        1.6,
        (255, 255, 255),
        3,
        cv2.LINE_AA,
    )
    cv2.putText(
        frame,
        title,
        (70, 245),
        cv2.FONT_HERSHEY_SIMPLEX,
        1.0,
        (180, 150, 220),
        2,
        cv2.LINE_AA,
    )

    y = 315
    # OpenCV draws ASCII only ("Settings › Devices" came out as "Settings ??? Devices").
    detail = str(detail or "").replace("›", ">").replace("’", "'").replace("…", "...")
    for line in detail.split(". "):
        cv2.putText(
            frame,
            line[:78],
            (70, y),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.72,
            (230, 224, 240),
            2,
            cv2.LINE_AA,
        )
        y += 42

    return publish_stream_frame(role, frame)


def start_stream_server(max_attempts=5):
    """
    Start the local in-memory MJPEG server used by the station kiosk windows.
    """
    last_error = None

    for attempt in range(1, max_attempts + 1):
        try:
            server = ReusableThreadingHTTPServer(
                (MJPEG_STREAM_BIND_HOST, MJPEG_STREAM_PORT),
                MjpegStreamHandler,
            )
            break
        except OSError as error:
            last_error = error
            print(f"MJPEG stream server attempt {attempt} failed: {error}", flush=True)
            time.sleep(0.75)
    else:
        print(f"MJPEG stream server could not start: {last_error}", flush=True)
        return None

    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    print(
        f"MJPEG stream server running at http://{MJPEG_STREAM_HOST}:{MJPEG_STREAM_PORT}"
        f" (bound to {MJPEG_STREAM_BIND_HOST})",
        flush=True,
    )

    return server


def save_annotated_frame_atomic(role, frame):
    """
    Keep the latest AI/RFID annotated frame per camera for the Live Monitor.
    """
    encoded, buffer = cv2.imencode(
        ".jpg",
        frame,
        [cv2.IMWRITE_JPEG_QUALITY, JPEG_QUALITY],
    )

    if not encoded:
        return False

    output_path = annotated_frame_path(role)
    temp_path = output_path.with_suffix(output_path.suffix + ".tmp")
    temp_path.write_bytes(buffer.tobytes())
    os.replace(temp_path, output_path)

    return True


def normalize_detector_label(label):
    """
    Keep detector labels readable for Laravel logs and forms.
    """
    normalized = str(label or "").strip().lower().replace("_", " ").replace("-", " ")

    return " ".join(part for part in normalized.split() if part)


def display_vehicle_label(label):
    """
    Convert detector labels into the beginner-friendly labels used in the UI.
    """
    normalized = normalize_detector_label(label)

    if normalized in {"motorbike", "motor cycle"}:
        normalized = "motorcycle"
    elif normalized in {"pickup", "pickup truck"}:
        normalized = "truck"
    elif normalized == "suv":
        normalized = "car"

    return " ".join(word.capitalize() for word in normalized.split()) or "Vehicle"


def resolve_allowed_vehicle_classes(model):
    """
    Resolve allowed vehicle classes from the detector's advertised class names.

    This keeps the detector compatible with both the default COCO model and
    future custom models that may expose extra vehicle labels like `van`,
    `jeepney`, or `tricycle`.
    """
    supported = {}
    raw_names = getattr(model, "names", {}) or {}

    if isinstance(raw_names, list):
        raw_names = {index: name for index, name in enumerate(raw_names)}

    for class_id, class_name in raw_names.items():
        normalized_name = normalize_detector_label(class_name)

        if normalized_name not in ALLOWED_VEHICLE_CLASS_NAMES:
            continue

        # A2: Car / Motorcycle / Truck/Bus (vehicle_type.py), never "Pickup" or "Truck".
        vehicle_type = vtype.type_for_label(normalized_name)
        if vehicle_type:
            supported[int(class_id)] = vehicle_type

    return supported


def build_capture(source_type, capture_source, decoder_threads=0):
    """
    Use the most practical OpenCV backend for the configured source.
    """
    if source_type in {"rtsp", "url"}:
        configure_network_capture_options(source_type)

        if hasattr(cv2, "CAP_FFMPEG"):
            # Live latency: FFmpeg's default frame threading holds ~9 frames
            # back (~350 ms measured on the VIGI sub stream). One decoder
            # thread is enough for a sub stream and delivers each frame at once.
            if decoder_threads > 0 and hasattr(cv2, "CAP_PROP_N_THREADS"):
                return cv2.VideoCapture(capture_source, cv2.CAP_FFMPEG, [cv2.CAP_PROP_N_THREADS, int(decoder_threads)])
            return cv2.VideoCapture(capture_source, cv2.CAP_FFMPEG)

        return cv2.VideoCapture(capture_source)

    if source_type == "webcam":
        # Testing only: this PC's webcam (AVFoundation on macOS, DirectShow on Windows).
        system_name = sys.platform
        if system_name == "darwin" and hasattr(cv2, "CAP_AVFOUNDATION"):
            return cv2.VideoCapture(int(capture_source), cv2.CAP_AVFOUNDATION)
        if system_name.startswith("win") and hasattr(cv2, "CAP_DSHOW"):
            return cv2.VideoCapture(int(capture_source), cv2.CAP_DSHOW)
        return cv2.VideoCapture(int(capture_source))

    return cv2.VideoCapture()


def configure_network_capture_options(source_type):
    """
    Prefer TCP for RTSP cameras. Many LAN CCTV devices drop or delay UDP packets,
    which can make OpenCV connect without delivering usable frames.
    """
    if source_type != "rtsp":
        return

    if os.environ.get("OPENCV_FFMPEG_CAPTURE_OPTIONS"):
        return

    # Measured on the VIGI C240 (single-thread decoder, identical frames
    # compared between two connections): fflags=nobuffer, flags=low_delay,
    # max_delay=0 and reorder_queue_size=0 gave 0 ms less delay, but made the
    # first frame take 2.4 s (sub) to 12.4 s (main) instead of 0.7 s. The low
    # delay comes from the one-thread decoder (see build_capture), so only TCP,
    # timeouts and a short stream probe are set here. "timeout" is the newer
    # FFmpeg name for "stimeout"; the unused one is ignored.
    os.environ["OPENCV_FFMPEG_CAPTURE_OPTIONS"] = (
        "rtsp_transport;tcp"
        "|probesize;500000"
        "|analyzeduration;500000"
        "|stimeout;5000000"
        "|timeout;5000000"
    )


def validate_camera_source(camera_config, capture_source):
    """
    Return a user-friendly setup error before OpenCV tries an impossible source.
    """
    source_type = camera_config["source_type"]
    if source_type == "webcam":
        return ""
    source_text = str(capture_source or "").strip()
    parsed = urlparse(source_text)

    if source_type == "rtsp" and (parsed.scheme.lower() != "rtsp" or not parsed.netloc):
        return (
            "RTSP source must be a full rtsp:// URL. "
            f"Current source is {source_text or 'blank'}."
        )

    if source_type == "url" and (not parsed.scheme or not parsed.netloc):
        return (
            "URL source must be a full camera stream URL. "
            f"Current source is {source_text or 'blank'}."
        )

    return ""


def camera_open_error(camera_config, capture_source, state):
    """
    Build the status message shown in the station stream and Laravel status JSON.

    For RTSP cameras the camera itself is asked why it failed (wrong login,
    wrong path, unreachable), instead of a generic "could not open".
    """
    if camera_config["source_type"] == "none":
        # B1 (Settings): the gate has no camera (new gate, or removed).
        state["error_code"] = "no_camera"
        return "No camera added for this gate."

    if state.get("source_validation_error"):
        state["error_code"] = "invalid_source"
        return state["source_validation_error"]

    # A1: the reason found by the last connection attempt (pre-check or open
    # time-limit), so the status does not probe the camera again.
    if state.get("open_problem"):
        state["error_code"] = state["open_problem"]["code"]
        return state["open_problem"]["message"]

    if camera_config["source_type"] == "rtsp":
        diagnosis = RTSP_DIAGNOSIS.get(
            camera_config["camera_role"],
            str(capture_source),
            camera_config["source_username"],
            camera_config["source_password"],
        )
        if diagnosis["code"] != "ok":
            state["error_code"] = diagnosis["code"]
            return diagnosis["message"]
        # It answers, yet OpenCV could not open it: a full camera refuses at PLAY.
        playing = RTSP_PLAY_CHECK.get(
            camera_config["camera_role"],
            str(capture_source),
            camera_config["source_username"],
            camera_config["source_password"],
        )
        if playing["code"] != "ok":
            state["error_code"] = playing["code"]
            return playing["message"]

    if camera_config["source_type"] == "webcam":
        state["error_code"] = "no_frames"
        return "The webcam sends no picture. Allow camera access for this app, or close other apps that use it."

    state["error_code"] = "open_failed"
    return f"Could not open camera source: {strip_credentials(capture_source)}"


def build_connection_source(camera_config, capture_source):
    """
    Add credentials to RTSP or URL sources when they are stored separately.
    """
    if camera_config["source_type"] == "webcam":
        return capture_source
    source_value = str(capture_source).strip()
    username = camera_config["source_username"]
    password = camera_config["source_password"]

    if not source_value or not username or "://" not in source_value:
        return source_value

    parsed = urlparse(source_value)

    if not parsed.netloc or "@" in parsed.netloc:
        return source_value

    credentials = quote(username, safe="")
    if password:
        credentials = f"{credentials}:{quote(password, safe='')}"

    return urlunparse(parsed._replace(netloc=f"{credentials}@{parsed.netloc}"))


class LatestFrameReader:
    """
    Low latency: read the camera continuously on its own thread and keep only
    the newest frame.

    Before this, the stream loop read one frame, then drew overlays, encoded a
    JPEG and slept. Whenever that loop was slower than the camera's FPS, frames
    queued inside FFmpeg and the station view fell further and further behind
    real time. Reading non-stop here means old frames are simply overwritten.
    """

    def __init__(self, capture, role=None, decoder_threads=0):
        self.capture = capture
        self.role = role
        # Shared camera work: every gate reading this connection (metrics per gate).
        self.roles = {role} if role else set()
        self.decoder_threads = decoder_threads
        # Set when one decoder thread cannot keep up (e.g. a manual 4K source):
        # the stream worker then reconnects with FFmpeg's own threading.
        self.too_slow = False
        self.frame_time = 0.0
        self._clock_start = None
        self.condition = threading.Condition()
        self.frame = None
        self.sequence = 0
        self.consumed_sequence = 0
        self.failed = False
        self.last_frame_at = time.monotonic()
        self.stop_event = threading.Event()
        self.release_lock = threading.Lock()
        self.released = False
        self.thread = threading.Thread(target=self._run, daemon=True)
        self.thread.start()

    def _run(self):
        try:
            while not self.stop_event.is_set():
                started = time.perf_counter()
                try:
                    has_frame, frame = self.capture.read()
                except Exception:
                    has_frame, frame = False, None
                if has_frame and frame is not None and self.roles:
                    self._measure(started, frame)

                if not has_frame or frame is None:
                    with self.condition:
                        self.failed = True
                        self.condition.notify_all()
                    return

                with self.condition:
                    self.frame = frame
                    self.sequence += 1
                    self.last_frame_at = time.monotonic()
                    self.condition.notify_all()
        finally:
            # A release() that could not wait for read() to return left the
            # capture to this thread: free it only now, after read() is done.
            if self.stop_event.is_set():
                self._release_capture()

    def _measure(self, started, frame):
        """Read time, capture FPS and decoder backlog against the stream clock."""
        now = time.monotonic()
        roles = list(self.roles)
        for role in roles:
            metrics.timing(role, "read", (time.perf_counter() - started) * 1000.0)
            metrics.rate(role, "capture")
        try:
            position = float(self.capture.get(cv2.CAP_PROP_POS_MSEC))
        except Exception:
            position = 0.0
        if position > 0:
            if self._clock_start is None or position < self._clock_start[1]:
                self._clock_start = (now, position)
            backlog = (now - self._clock_start[0]) * 1000.0 - (position - self._clock_start[1])
            for role in roles:
                metrics.value(role, "decode_backlog_ms", round(backlog))
            if backlog > 1500 and self.decoder_threads == 1 and now - self._clock_start[0] > 3:
                self.too_slow = True
        for role in roles:
            metrics.value(role, "resolution", f"{frame.shape[1]}x{frame.shape[0]}")
            metrics.value(role, "decoder_threads", self.decoder_threads or "auto")
        self.frame_time = now

    def isOpened(self):
        return not self.failed and self.capture.isOpened()

    def read_latest(self, timeout=None):
        """
        Wait for a frame newer than the last one handed out, then return it.
        Frames that arrived in between are skipped on purpose.
        """
        sequence, frame = self.wait_newer(self.consumed_sequence, timeout)
        if frame is None:
            return False, None
        self.consumed_sequence = sequence
        return True, frame

    def wait_newer(self, after, timeout=None):
        """
        Shared camera work: (sequence, frame) newer than `after`, or (after,
        None) on a stall or a failure. Each gate's view keeps its own `after`.
        """
        if timeout is None:
            timeout = CAPTURE_FIRST_FRAME_SECONDS if self.sequence == 0 else CAPTURE_STALL_SECONDS
        deadline = time.monotonic() + timeout

        with self.condition:
            while self.sequence == after and not self.failed:
                remaining = deadline - time.monotonic()

                if remaining <= 0:
                    return after, None

                self.condition.wait(remaining)

            if self.sequence == after:
                return after, None

            return self.sequence, self.frame

    def read(self):
        return self.read_latest()

    def release(self):
        """
        Stop reading and free the camera connection.

        The capture must never be freed while read() is still running on the
        reader thread: FFmpeg then logs through a freed decoder context and
        the whole detector crashes (SIGSEGV in av_log). This happened on every
        reconnect whose read() took longer than the join timeout, e.g. after a
        camera IP change or a slow first frame from a high-resolution stream.
        """
        self.stop_event.set()
        self.thread.join(timeout=2.0)
        if not self.thread.is_alive():
            self._release_capture()
        # Otherwise the reader thread releases it when read() returns.

    def _release_capture(self):
        with self.release_lock:
            if self.released:
                return
            self.released = True
        self.capture.release()


def open_capture(camera_config, decoder_threads=None):
    """
    Open one configured camera source.
    """
    capture_source = resolve_capture_source(camera_config)
    connection_source = build_connection_source(camera_config, capture_source)
    threads = camera_config.get("decoder_threads", 1) if decoder_threads is None else decoder_threads
    role = camera_config.get("camera_role")

    def connect():
        capture = build_capture(camera_config["source_type"], connection_source, threads)

        try:
            capture.set(cv2.CAP_PROP_BUFFERSIZE, 1)
        except Exception:
            pass

        # Low latency: wrap the opened capture so a background thread always holds
        # the newest frame.
        if capture.isOpened():
            capture = LatestFrameReader(capture, role, threads)

        return capture

    # Shared camera work: a stream several gates use is opened once (the
    # VIGI-C240 refuses a 4th session per stream); each gate gets its own view.
    capture = CAPTURE_HUB.acquire(capture_key(camera_config["source_type"], connection_source), role, connect, threads)
    return capture, capture_source


def capture_key(source_type, connection_source):
    """What makes two gates' streams the same connection: type and full address (with its login)."""
    return f"{source_type}|{connection_source}"


# A1: the longest a camera connection may take before the worker gives up on
# it (the FFmpeg RTSP timeout is 5 s; HTTP streams have none).
CAMERA_OPEN_TIMEOUT_SECONDS = 12.0


def open_capture_with_deadline(camera_config, decoder_threads=None, timeout=None):
    """
    Open a camera in a helper thread. Returns (capture, source, timed_out).
    A camera that hangs while connecting no longer freezes its gate's worker
    (and its status): the late capture is released when it finally returns.
    """
    box = {}

    def target():
        try:
            box["result"] = open_capture(camera_config, decoder_threads)
        except Exception as error:  # reported by the worker like any open error
            box["error"] = error

    thread = threading.Thread(target=target, daemon=True)
    thread.start()
    thread.join(CAMERA_OPEN_TIMEOUT_SECONDS if timeout is None else timeout)

    if thread.is_alive():
        def release_late():
            thread.join()
            late = box.get("result")
            if late and late[0] is not None:
                late[0].release()

        threading.Thread(target=release_late, daemon=True).start()
        return None, resolve_capture_source(camera_config), True

    if "error" in box:
        raise box["error"]

    capture, capture_source = box["result"]
    return capture, capture_source, False


def camera_signature(camera_config):
    """
    Detect when Laravel settings changed and the detector should reconnect.
    """
    return json.dumps({
        "source_type": camera_config["source_type"],
        "source_value": camera_config["source_value"],
        "source_username": camera_config["source_username"],
        "source_password": camera_config["source_password"],
    }, sort_keys=True)


def initial_camera_state():
    """
    Keep mutable runtime state for one camera role.
    """
    return {
        "capture": None,
        "signature": None,
        "camera_running": False,
        "detection_ready": False,
        "last_capture_time": None,
        "last_error": "Detector service is starting.",
        "source_validation_error": "",
        "error_code": None,
        "retry_count": 0,
        "processed_frames": 0,
        "latest_frame": None,
        "latest_camera_config": None,
        "latest_frame_version": 0,
        "last_frame_saved_at": 0.0,
        "last_detected_frame_version": 0,
        "detections_seen": 0,
        "active_detections": 0,
        "crossings_logged": 0,
        "retry_after": 0.0,
        # A1: reconnect with a growing wait; the last connection problem; the
        # status code last written to the log.
        "backoff": ReconnectBackoff(),
        "open_problem": None,
        "logged_status": None,
        "track_sides": {},
        "track_last_seen": {},
        "track_boxes": {},
        "crossed_track_ids": {},
        "processed_as_guest": {},
        "tracked_vehicles": {},
        "track_overlays": {},
        "pending_windows": {},
        "recent_resolutions": [],
        # Last positions per track: kept through a missed detection (unlike
        # the ROI overlays), so a crossing between two frames is not lost.
        "track_points": {},
        "confirmed_tracks": {},
        # A2: every frame of a track votes for its type (vehicle_type.TrackVote).
        "track_votes": {},
        # ROI work: one pass per vehicle in the zone (tracking.VehiclePass),
        # keyed by the track that opened it; a track ID the tracker gave the
        # same vehicle later points to it (pass_alias).
        "passes": {},
        "pass_alias": {},
        "passes_detected": 0,
        "passes_recorded": 0,
        # The latest records (debug view, live overlay).
        "recent_records": [],
        "direction_counts": {"IN": 0, "OUT": 0, "UNKNOWN": 0},
        "detection_times": [],
        "debug": None,
        "debug_enabled": False,
        "last_detection_error": "",
        "lock": threading.Lock(),
    }


def release_capture(state):
    """
    Release one capture handle if it exists.
    """
    capture = state.get("capture")
    if capture is not None:
        capture.release()

    state["capture"] = None
    state["signature"] = None


def ensure_capture(camera_config, state):
    """
    Reconnect when the configured source changed or the capture dropped.

    A1: a failed connection waits longer each time (ReconnectBackoff); a new
    camera setting retries at once; an RTSP camera is asked first whether it
    answers (a few hundred ms) instead of letting OpenCV wait 5 s; the open
    itself has a time limit.
    """
    signature = camera_signature(camera_config)
    now_monotonic = time.monotonic()
    capture_source = resolve_capture_source(camera_config)
    validation_error = validate_camera_source(camera_config, capture_source)
    backoff = state["backoff"]

    if state["signature"] is not None and state["signature"] != signature or state.get("wanted_signature") not in (None, signature):
        # New source or login from Settings: try it now.
        backoff.reset()
        state["open_problem"] = None
        RTSP_DIAGNOSIS.forget(camera_config["camera_role"])
        RTSP_PLAY_CHECK.forget(camera_config["camera_role"])
    state["wanted_signature"] = signature

    if camera_config["source_type"] == "none":
        # B1: no camera for this gate: nothing to open, nothing to retry.
        release_capture(state)
        backoff.reset()
        state["open_problem"] = None
        return None, capture_source

    if validation_error:
        release_capture(state)
        state["source_validation_error"] = validation_error
        if backoff.ready(now_monotonic):
            backoff.failed(now_monotonic)

        return None, capture_source

    state["source_validation_error"] = ""

    if state["capture"] is None and not backoff.ready(now_monotonic):
        return None, capture_source

    current = state["capture"]
    if current is not None and getattr(current, "too_slow", False):
        # One decoder thread fell behind this source: use FFmpeg threading for it.
        state.setdefault("decoder_threads_override", {})[signature] = 0
        print(f"{camera_config['camera_role']}: single-thread decoding too slow for this source; using FFmpeg threads.", flush=True)
        release_capture(state)
        current = None

    if current is not None and state["signature"] == signature and current.isOpened():
        return current, capture_source

    release_capture(state)

    if camera_config["source_type"] == "rtsp":
        diagnosis = rtsp_precheck(camera_config, capture_source)
        if diagnosis["code"] not in ("ok", "error"):
            # Not reachable, no answer, wrong login or path: OpenCV would only
            # fail more slowly ("error" = an odd answer; OpenCV may still work).
            state["open_problem"] = diagnosis
            backoff.failed(now_monotonic)
            return None, capture_source

    capture, capture_source, timed_out = open_capture_with_deadline(
        camera_config, state.get("decoder_threads_override", {}).get(signature)
    )

    if timed_out:
        state["open_problem"] = {
            "code": "timeout",
            "message": f"The camera did not answer within {CAMERA_OPEN_TIMEOUT_SECONDS:.0f} s. Check its cable and network.",
        }
        backoff.failed(now_monotonic)
        return None, capture_source

    state["capture"] = capture
    state["signature"] = signature

    if not capture.isOpened():
        state["open_problem"] = None  # camera_open_error asks the camera why
        backoff.failed(now_monotonic)

    return capture, capture_source


def rtsp_precheck(camera_config, capture_source):
    """Fresh RTSP DESCRIBE before opening (cached for camera_open_error)."""
    role = camera_config["camera_role"]
    RTSP_DIAGNOSIS.forget(role)
    return RTSP_DIAGNOSIS.get(role, str(capture_source), camera_config["source_username"], camera_config["source_password"])


def read_fresh_frame(capture):
    """
    Drop queued camera frames before retrieving, reducing visible stream latency.
    """
    # Low latency: the background reader already discards old frames.
    if isinstance(capture, LatestFrameReader) or getattr(capture, "waits_for_frames", False):
        return capture.read_latest()

    if CAPTURE_DRAIN_FRAMES <= 0:
        return capture.read()

    grabbed = False

    for _ in range(CAPTURE_DRAIN_FRAMES):
        if not capture.grab():
            break

        grabbed = True

    if grabbed:
        has_frame, frame = capture.retrieve()

        if has_frame and frame is not None:
            return has_frame, frame

    return capture.read()


def status_payload(runtime_config, camera_states, detector_models, service_running, service_message):
    """
    Build the combined detector status JSON for Laravel.
    """
    station_activity = station_activity_status()
    payload = {
        "service_running": service_running,
        "service_message": service_message,
        "updated_at": datetime.now().astimezone().isoformat(),
        "detector_model_path": MODEL_PATH,
        # The code this detector loaded (Laravel compares it with the files on disk).
        "code_version": RUNNING_CODE["version"],
        "started_at": RUNNING_CODE["started_at"],
        "station_activity": station_activity,
        "camera_power_mode": "active" if station_activity["active"] else "standby",
        "pid": os.getpid(),
        "metrics": metrics.snapshot(),
        "cpu": metrics.cpu_percent(),
        "stream_server": {
            "bind_host": MJPEG_STREAM_BIND_HOST,
            "host": MJPEG_STREAM_HOST,
            "port": MJPEG_STREAM_PORT,
        },
        "cameras": {},
    }

    for role in camera_roles(runtime_config):
        if role not in camera_states:
            continue
        camera_config = runtime_config["cameras"][role]
        state = camera_states[role]
        model_info = detector_models.get(role, {})
        status = detection_status(role, state, camera_config, model_info)
        if service_running and state.get("logged_status") != status["code"]:
            state["logged_status"] = status["code"]
            print(log_status_change(role, status), flush=True)

        payload["cameras"][role] = {
            "camera_role": role,
            "camera_name": camera_config["camera_name"],
            "camera_running": state["camera_running"],
            "detection_ready": state["detection_ready"],
            "calibration_ready": calibration_ready(camera_config),
            "source_type": camera_config["source_type"],
            "source_value": camera_config["source_value"],
            "stream_url": f"http://{MJPEG_STREAM_HOST}:{MJPEG_STREAM_PORT}/stream/{role}",
            "supported_vehicle_classes": list(model_info.get("vehicle_labels", {}).values()),
            "last_capture_time": state["last_capture_time"],
            "last_error": state["last_error"],
            "error_code": state.get("error_code"),
            "stream_clients": STREAM_CLIENTS.get(role, 0),
            "retry_count": state["retry_count"],
            "processed_frames": state["processed_frames"],
            "detections_seen": state["detections_seen"],
            "active_detections": state.get("active_detections", 0),
            "crossings_logged": state["crossings_logged"],
            # Debug counters (also drawn on the live view in debug mode).
            "detection": detection_counters(state),
            # ROI work, Phase 3: vehicles Detected and not Recorded yet (the
            # kiosk and Gate Monitor show "Vehicle detected · checking RFID…").
            "open_passes": open_passes(state),
            # A1: one line: what the gate is doing, why, and what to do.
            "detection_status": status,
            "offline_since": datetime.fromtimestamp(state["backoff"].offline_since).astimezone().isoformat()
            if state["backoff"].offline_since else None,
        }

    return payload


# A1: detection that has not run for this long while the camera is live is "stalled".
DETECTION_STALLED_AFTER_SECONDS = 15.0

NEXT_STEP_BY_ERROR = {
    "unauthorized": "Enter the camera's username and password in Settings.",
    "not_found": "Assign the camera again in Settings.",
    "invalid_source": "Set up this gate's camera in Settings.",
    "unreachable": "Check the camera's LAN cable and power.",
    "timeout": "Check the camera's LAN cable and network.",
    "no_frames": "Wait a moment; if it stays, restart the camera.",
    # Shared camera work: the camera has no room for one more connection.
    "busy": "Close other viewers of this camera (VIGI app, recorder, another PC), or use one camera per gate.",
}


def detection_status(role, state, camera_config, model_info):
    """
    A1: {code, label, message, next_step, retry_in}: what one gate's
    detection is doing now. Codes: connecting, camera_offline, no_zone,
    model_loading, model_error, error, stalled, running.
    """
    now = time.monotonic()
    backoff = state["backoff"]
    debug = state.get("debug") or {}

    def result(code, label, message, next_step="", retry_in=None):
        return {"code": code, "label": label, "message": message, "next_step": next_step,
                "retry_in": None if retry_in is None else round(retry_in)}

    if camera_config.get("source_type") == "none":
        return result("no_camera", "No camera", "No camera added for this gate.", "Add one in Settings › Gates.")
    if not state.get("camera_running"):
        if not backoff.failures and not state.get("open_problem"):
            return result("connecting", "Connecting", "Connecting to the camera…")
        return result(
            "camera_offline", "Camera offline",
            short_reason(state.get("last_error")) or "The camera is not connected.",
            NEXT_STEP_BY_ERROR.get(state.get("error_code"), "Check the camera's LAN cable and power."),
            backoff.seconds_left(now),
        )
    zone_problem = calibration_problem(camera_config)
    if zone_problem and not camera_config.get("calibration_mask"):
        return result("no_zone", "Zone not set", zone_problem, "Draw it in Settings › Calibration.")
    if zone_problem:
        return result("zone_invalid", "Zone invalid", zone_problem, "Draw the zone again in Settings › Calibration.")
    if model_info.get("model") is None:
        if "could not be loaded" in str(state.get("last_error")):
            return result("model_error", "Model error", short_reason(state.get("last_error")),
                          "Check that yolov8n.pt is in the detector's models folder.")
        return result("model_loading", "Starting", "Loading the detection model…")
    if not model_info.get("vehicle_labels"):
        return result("model_error", "Model error", "The detection model has no vehicle classes.",
                      "Use a vehicle detection model.")
    if state.get("last_detection_error") and not state.get("detection_ready"):
        return result("error", "Detection error", short_reason(state["last_detection_error"]),
                      "It retries by itself; restart the detector if it stays.")
    if debug.get("at") and now - debug["at"] > DETECTION_STALLED_AFTER_SECONDS:
        return result("stalled", "Paused", f"No detection for {now - debug['at']:.0f} s while the camera is live.",
                      "Restart the detector if it stays.")
    if not debug.get("at"):
        return result("model_loading", "Starting", "First check starts in a moment…")
    if normalized_away_direction(camera_config.get("away_direction")) is None:
        # Still Detected and Recorded, only without IN / OUT.
        return result("running", "Running", f"Watching for vehicles ({debug.get('detection_fps') or 0:.1f} checks per second). "
                      "IN / OUT is not set: every vehicle is Direction unknown.", "Choose IN / OUT in Settings › Calibration.")
    return result("running", "Running", f"Watching for vehicles ({debug.get('detection_fps') or 0:.1f} checks per second).")


def log_status_change(role, status):
    """A1: one log line when a gate's detection status changes (not every retry)."""
    return f"{role}: {status['label']}: {status['message']}" + (f" Next: {status['next_step']}" if status["next_step"] else "")


# A pass open longer than this (a vehicle waiting in the zone) no longer shows "checking RFID".
OPEN_PASS_SHOWN_SECONDS = 60.0


def open_passes(state, now=None):
    """
    ROI work, Phase 3: passes Detected and not Recorded yet, newest first:
    their event key, when they were Detected, and the RFID check so far
    ("checking", or "matched" once the tag was found).
    """
    now = time.monotonic() if now is None else now
    with state["lock"]:
        windows = [window for window in state.get("pending_windows", {}).values()
                   if not window.get("recorded_at") and now - float(window.get("started_at", now)) <= OPEN_PASS_SHOWN_SECONDS]
        passes = [{
            "event_key": window.get("event_key"),
            "detected_at": window.get("event_time"),
            "rfid": "matched" if window.get("rfid_status") == "matched" else "checking",
        } for window in sorted(windows, key=lambda w: float(w.get("started_at", 0.0)), reverse=True)]
    return passes


def detection_counters(state):
    debug = state.get("debug") or {}
    return {
        "passes_detected": int(state.get("passes_detected", 0)),
        "passes_recorded": int(state.get("passes_recorded", 0)),
        "last_raw_detections": debug.get("raw_count"),
        "last_vehicles": debug.get("vehicle_count"),
        "last_in_zone": debug.get("in_roi"),
        "detection_fps": debug.get("detection_fps"),
        "device": debug.get("device"),
        "seconds_since_detection": round(time.monotonic() - debug["at"], 1) if debug.get("at") else None,
        "last_error": state.get("last_detection_error") or None,
        "debug_overlay": bool(state.get("debug_enabled")),
    }


def write_status(runtime_config, camera_states, detector_models, service_running=True, service_message=""):
    """
    Persist the combined detector status to JSON for Laravel.
    """
    write_text_atomic(
        STATUS_FILE_PATH,
        json.dumps(
            status_payload(
                runtime_config,
                camera_states,
                detector_models,
                service_running,
                service_message,
            ),
            indent=2,
        ),
    )


def cleanup_stale_tracks(state):
    """
    Remove stale tracking state so new tracks can reuse numeric IDs later.
    """
    now_monotonic = time.monotonic()

    with state["lock"]:
        cleanup_recent_resolutions_locked(state, now_monotonic)

        for track_id, last_seen in list(state["track_last_seen"].items()):
            if now_monotonic - last_seen <= TRACK_STALE_AFTER_SECONDS:
                continue

            if track_id in state["pending_windows"]:
                continue

            state["track_last_seen"].pop(track_id, None)
            state["track_sides"].pop(track_id, None)
            state["track_boxes"].pop(track_id, None)
            state["crossed_track_ids"].pop(track_id, None)
            state["processed_as_guest"].pop(track_id, None)
            state["tracked_vehicles"].pop(track_id, None)
            state["track_overlays"].pop(track_id, None)
            state["track_votes"].pop(track_id, None)


def forget_track_locked(state, track_id):
    """
    Drop detector state for a track that is no longer active in the ROI.
    """
    if track_id in state["pending_windows"]:
        return

    state["track_last_seen"].pop(track_id, None)
    state["track_sides"].pop(track_id, None)
    state["track_boxes"].pop(track_id, None)
    state["crossed_track_ids"].pop(track_id, None)
    state["processed_as_guest"].pop(track_id, None)
    state["tracked_vehicles"].pop(track_id, None)
    state["track_overlays"].pop(track_id, None)
    state["track_votes"].pop(track_id, None)


def cleanup_tracks_outside_roi(state, visible_roi_track_ids):
    """
    Make station overlays and recent decisions follow the ROI strictly.
    """
    visible_roi_track_ids = set(visible_roi_track_ids)

    with state["lock"]:
        known_track_ids = set()

        for key in (
            "track_last_seen",
            "track_sides",
            "track_boxes",
            "crossed_track_ids",
            "processed_as_guest",
            "tracked_vehicles",
            "track_overlays",
        ):
            known_track_ids.update(state.get(key, {}).keys())

        for track_id in known_track_ids - visible_roi_track_ids:
            forget_track_locked(state, track_id)

        if not visible_roi_track_ids:
            state["recent_resolutions"] = []


def mark_processed_as_guest_locked(state, track_id, xyxy, overlay, now_monotonic):
    """
    Remember that one YOLO track already produced a guest API submission.
    """
    state.setdefault("processed_as_guest", {})[track_id] = {
        "processed_at": now_monotonic,
        "expires_at": now_monotonic + GUEST_TRACK_COOLDOWN_SECONDS,
    }
    state.setdefault("tracked_vehicles", {}).setdefault(track_id, {
        "first_seen": time.time(),
        "first_seen_monotonic": now_monotonic,
    })
    state["tracked_vehicles"][track_id].update({
        "status": "processed",
        "processed_at": time.time(),
        "processed_at_monotonic": now_monotonic,
    })
    state["crossed_track_ids"][track_id] = now_monotonic
    state["track_overlays"][track_id] = (overlay or default_overlay()).copy()
    remember_recent_resolution_locked(state, track_id, xyxy, state["track_overlays"][track_id], now_monotonic)


def track_is_processed_as_guest_locked(state, track_id, inside_roi, now_monotonic):
    """
    Block repeat guest windows for a processed track while it remains in view.
    """
    guest_cooldown = state.get("processed_as_guest", {}).get(track_id)

    if not guest_cooldown:
        return False

    if inside_roi:
        return True

    if float(guest_cooldown.get("expires_at", 0.0)) > now_monotonic:
        return True

    state["processed_as_guest"].pop(track_id, None)

    return False


def ensure_tracked_vehicle_locked(state, track_id, now_monotonic):
    """
    Create or return the explicit per-YOLO-track RFID checking state.
    """
    tracked = state.setdefault("tracked_vehicles", {}).get(track_id)

    if tracked:
        return tracked

    tracked = {
        "first_seen": time.time(),
        "first_seen_monotonic": now_monotonic,
        "status": "checking",
    }
    state["tracked_vehicles"][track_id] = tracked

    return tracked


def bbox_iou(left_xyxy, right_xyxy):
    """
    Compute overlap between two detector boxes.
    """
    left_x1, left_y1, left_x2, left_y2 = [float(value) for value in left_xyxy]
    right_x1, right_y1, right_x2, right_y2 = [float(value) for value in right_xyxy]
    intersection_x1 = max(left_x1, right_x1)
    intersection_y1 = max(left_y1, right_y1)
    intersection_x2 = min(left_x2, right_x2)
    intersection_y2 = min(left_y2, right_y2)
    intersection_width = max(0.0, intersection_x2 - intersection_x1)
    intersection_height = max(0.0, intersection_y2 - intersection_y1)
    intersection_area = intersection_width * intersection_height

    if intersection_area <= 0:
        return 0.0

    left_area = max(0.0, left_x2 - left_x1) * max(0.0, left_y2 - left_y1)
    right_area = max(0.0, right_x2 - right_x1) * max(0.0, right_y2 - right_y1)
    union_area = left_area + right_area - intersection_area

    if union_area <= 0:
        return 0.0

    return intersection_area / union_area


def bboxes_look_like_same_vehicle(left_xyxy, right_xyxy):
    """
    Track IDs can change while the same vehicle is still cutting the trigger
    line. Use overlap plus center distance so one physical pass keeps one
    RFID/guest decision.
    """
    if bbox_iou(left_xyxy, right_xyxy) >= DUPLICATE_TRACK_IOU_THRESHOLD:
        return True

    left_center = bbox_center(left_xyxy)
    right_center = bbox_center(right_xyxy)
    left_x1, left_y1, left_x2, left_y2 = [float(value) for value in left_xyxy]
    right_x1, right_y1, right_x2, right_y2 = [float(value) for value in right_xyxy]
    left_width = max(1.0, left_x2 - left_x1)
    left_height = max(1.0, left_y2 - left_y1)
    right_width = max(1.0, right_x2 - right_x1)
    right_height = max(1.0, right_y2 - right_y1)
    reference_size = max(
        min(left_width, right_width),
        min(left_height, right_height),
        1.0,
    )
    distance = float(np.linalg.norm(np.array(left_center) - np.array(right_center)))

    return distance <= reference_size * DUPLICATE_TRACK_CENTER_DISTANCE_RATIO


def cleanup_recent_resolutions_locked(state, now_monotonic):
    """
    Drop resolved vehicle decisions after the vehicle has likely left the scene.
    """
    state["recent_resolutions"] = [
        resolution
        for resolution in state.get("recent_resolutions", [])
        if float(resolution.get("expires_at", 0.0)) > now_monotonic
    ]


def remember_recent_resolution_locked(state, track_id, xyxy, overlay, now_monotonic):
    """
    Remember a registered/guest decision so a YOLO track-id swap does not create
    another RFID window for the same physical vehicle.
    """
    cleanup_recent_resolutions_locked(state, now_monotonic)
    state.setdefault("recent_resolutions", []).append({
        "track_id": track_id,
        "xyxy": tuple(xyxy),
        "overlay": (overlay or default_overlay()).copy(),
        "last_seen": now_monotonic,
        "expires_at": now_monotonic + RESOLVED_DETECTION_COOLDOWN_SECONDS,
    })


def matching_active_decision_locked(state, xyxy, now_monotonic):
    """
    Find an active pending/resolved decision for a new track that overlaps it.
    """
    cleanup_recent_resolutions_locked(state, now_monotonic)

    for window in state.get("pending_windows", {}).values():
        if bboxes_look_like_same_vehicle(xyxy, window.get("xyxy", (0, 0, 0, 0))):
            return waiting_overlay()

    for resolution in state.get("recent_resolutions", []):
        if not bboxes_look_like_same_vehicle(xyxy, resolution.get("xyxy", (0, 0, 0, 0))):
            continue

        resolution["xyxy"] = tuple(xyxy)
        resolution["last_seen"] = now_monotonic
        resolution["expires_at"] = now_monotonic + RESOLVED_DETECTION_COOLDOWN_SECONDS

        return (resolution.get("overlay") or default_overlay()).copy()

    return None


def encode_frame_snapshot(role, frame, event_key):
    """
    Encode the current full camera frame for Laravel multipart upload.
    """
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S_%f")
    filename = f"{role}_{timestamp}_{event_key}.jpg"

    encoded, buffer = cv2.imencode(
        ".jpg",
        frame,
        [cv2.IMWRITE_JPEG_QUALITY, JPEG_QUALITY],
    )

    if not encoded:
        return None

    return {
        "filename": filename,
        "bytes": buffer.tobytes(),
    }


def overlay_color(overlay):
    """
    Convert Laravel overlay color names into OpenCV BGR colors.
    """
    if overlay.get("color") == "green":
        return (46, 155, 98)

    if overlay.get("color") == "blue":
        return (180, 116, 35)

    if overlay.get("color") == "amber":
        return (0, 165, 255)

    return (38, 38, 220)


def default_overlay():
    """
    Label when no registered tag was read in the window.
    """
    return {
        "label": "UNREGISTERED",
        "color": "red",
        "verification": "no_pass",
    }


# Overlays that are a final decision and stay on screen briefly after the box is lost.
RESOLVED_VERIFICATIONS = {"registered", "pass_alert", "no_pass"}


def waiting_overlay():
    """
    Temporary label while the RFID detection window is still open.
    """
    return {
        "label": "DETECTED - CHECKING RFID...",
        "color": "amber",
        "verification": "pending",
    }


def registered_overlay(plate_number=None):
    """
    Fallback label when Laravel confirms RFID but returns no overlay body.
    """
    label = f"REGISTERED - {plate_number}" if plate_number else "REGISTERED"

    return {
        "label": label,
        "color": "green",
        "verification": "registered",
    }


def detection_overlay():
    """
    Neutral label for vehicles YOLO sees before their pass is Detected (a few frames in the zone).
    """
    return {
        "label": "VEHICLE DETECTED",
        "color": "blue",
        "verification": "detected",
    }


def draw_label(frame, text, x, y, color):
    """
    Draw a readable filled label near a bounding box.
    """
    font = cv2.FONT_HERSHEY_SIMPLEX
    font_scale = 0.62
    thickness = 2
    padding = 7
    frame_height, frame_width = frame.shape[:2]
    text_size, baseline = cv2.getTextSize(text, font, font_scale, thickness)
    text_width, text_height = text_size
    label_x1 = max(min(x, frame_width - text_width - padding * 2), 0)
    label_y1 = max(y - text_height - padding * 2, 0)
    label_x2 = min(label_x1 + text_width + padding * 2, frame_width)
    label_y2 = min(label_y1 + text_height + padding * 2 + baseline, frame_height)

    cv2.rectangle(frame, (label_x1, label_y1), (label_x2, label_y2), color, -1)
    cv2.putText(
        frame,
        text,
        (label_x1 + padding, label_y2 - padding - baseline),
        font,
        font_scale,
        (255, 255, 255),
        thickness,
        cv2.LINE_AA,
    )


def draw_calibration_guides(frame, camera_config):
    """
    Reserved for admin diagnostics. Station MJPEG feeds intentionally stay clean
    and only show algorithm detection boxes/labels around vehicles.
    """
    return


OVERLAY_COLORS = {"green": "#16a34a", "blue": "#2563eb", "amber": "#f59e0b", "red": "#dc2626"}


def overlay_payload(state, camera_config, now=None):
    """
    Live view work: what the browser draws over the video: each vehicle in
    the zone (box, label, color), the zone, and the
    latest records. Coordinates are 0-1 of the detection frame (same
    shape as the camera's main stream).
    """
    now = time.monotonic() if now is None else now
    with state["lock"]:
        frame = state.get("latest_frame")
        boxes = {track_id: box.copy() for track_id, box in state.get("track_boxes", {}).items()}
        overlays = {track_id: overlay.copy() for track_id, overlay in state.get("track_overlays", {}).items()}
        recent = list(state.get("recent_records", []))
        debug = state.get("debug") or {}
    if frame is None:
        return {"tracks": [], "ready": False}
    height, width = frame.shape[:2]
    tracks = []
    for track_id, box in boxes.items():
        overlay = overlays.get(track_id) or detection_overlay()
        hold = RESOLVED_OVERLAY_HOLD_SECONDS if overlay.get("verification") in RESOLVED_VERIFICATIONS else 0.75
        if now - float(box.get("last_seen", 0.0)) > hold:
            continue
        x1, y1, x2, y2 = box.get("xyxy", (0, 0, 0, 0))
        tracks.append({
            "id": int(track_id),
            "box": [round(x1 / width, 4), round(y1 / height, 4), round(x2 / width, 4), round(y2 / height, 4)],
            "label": overlay.get("label") or "VEHICLE",
            "color": OVERLAY_COLORS.get(overlay.get("color"), "#dc2626"),
        })
    return {
        "ready": True,
        "age_ms": round((now - debug["at"]) * 1000) if debug.get("at") else None,
        "zone": camera_config.get("calibration_mask"),
        "tracks": tracks,
        "crossings": [{"direction": item.get("direction"), "seconds_ago": round(now - item.get("at", now), 1)} for item in recent[-3:]],
    }


def render_annotated_frame(role, frame, results, camera_config, state, vehicle_labels):
    """
    Draw live YOLO detections, then upgrade the label when RFID/guest state resolves.
    """
    annotated = frame.copy()
    draw_calibration_guides(annotated, camera_config)
    frame_height, frame_width = frame.shape[:2]
    mask_polygon = normalized_polygon_to_pixels(camera_config.get("calibration_mask"), frame_width, frame_height)
    boxes = results.boxes if results is not None else None
    drawn_track_ids = set()

    if boxes is not None and boxes.id is not None:
        ids = boxes.id.int().cpu().tolist()
        classes = boxes.cls.int().cpu().tolist()
        confidences = boxes.conf.cpu().tolist()
        coordinates = boxes.xyxy.cpu().tolist()

        for track_id, class_id, confidence, xyxy in zip(ids, classes, confidences, coordinates):
            if class_id not in vehicle_labels:
                continue

            if not bbox_inside_roi(xyxy, mask_polygon):
                continue

            overlay = state["track_overlays"].get(track_id) or detection_overlay()
            color = overlay_color(overlay)
            x1, y1, x2, y2 = [int(value) for value in xyxy]
            label = overlay.get("label") or default_overlay()["label"]
            label = f"{label} | {vehicle_labels[class_id]} {confidence:.0%}"
            drawn_track_ids.add(track_id)

            cv2.rectangle(annotated, (x1, y1), (x2, y2), color, 3)
            draw_label(annotated, label, x1, y1, color)

    now_monotonic = time.monotonic()

    with state["lock"]:
        fallback_boxes = {
            track_id: box.copy()
            for track_id, box in state.get("track_boxes", {}).items()
            if track_id not in drawn_track_ids
        }
        fallback_overlays = {
            track_id: overlay.copy()
            for track_id, overlay in state.get("track_overlays", {}).items()
        }

    for track_id, box in fallback_boxes.items():
        overlay = fallback_overlays.get(track_id)

        if not overlay:
            continue

        if not bbox_inside_roi(box.get("xyxy", (0, 0, 0, 0)), mask_polygon):
            continue

        hold_seconds = (
            RESOLVED_OVERLAY_HOLD_SECONDS
            if overlay.get("verification") in RESOLVED_VERIFICATIONS
            else 0.75
        )

        if now_monotonic - float(box.get("last_seen", 0.0)) > hold_seconds:
            continue

        class_id = box.get("class_id")
        if class_id not in vehicle_labels:
            continue

        confidence = float(box.get("confidence", 0.0))
        x1, y1, x2, y2 = [int(value) for value in box.get("xyxy", (0, 0, 0, 0))]
        color = overlay_color(overlay)
        label = overlay.get("label") or default_overlay()["label"]
        label = f"{label} | {vehicle_labels[class_id]} {confidence:.0%}"

        cv2.rectangle(annotated, (x1, y1), (x2, y2), color, 3)
        draw_label(annotated, label, x1, y1, color)

    if state.get("debug_enabled"):
        draw_debug_overlay(annotated, camera_config, state)

    return annotated


DEBUG_COLORS = {
    "zone": (255, 255, 0),       # cyan
    "crop": (160, 160, 160),
    "vehicle": (0, 220, 0),
    "low confidence": (0, 215, 255),
    "not a vehicle": (150, 150, 150),
    "track": (0, 140, 255),
}


def draw_debug_panel(frame, lines, color=(255, 255, 255)):
    scale = max(0.45, frame.shape[1] / 1600.0)
    height = int(22 * scale / 0.5)
    width = int(max(len(text) for text in lines) * 10 * scale / 0.5) + 16
    overlay = frame.copy()
    cv2.rectangle(overlay, (0, 0), (min(frame.shape[1], width), height * len(lines) + 10), (0, 0, 0), -1)
    cv2.addWeighted(overlay, 0.6, frame, 0.4, 0, frame)
    for index, text in enumerate(lines):
        cv2.putText(frame, text, (8, height * (index + 1)), cv2.FONT_HERSHEY_SIMPLEX, scale, color, 1, cv2.LINE_AA)


def draw_debug_overlay(frame, camera_config, state, message=None):
    """
    Debug view (Settings > Calibration): every raw YOLO detection before any
    filter, the zone as the detector uses it (scaled to this
    frame), the crop YOLO saw, track IDs with their last positions, and
    counters. Drawn only while the debug switch is on.
    """
    height, width = frame.shape[:2]
    thickness = max(1, width // 640)
    polygon = normalized_polygon_to_pixels(camera_config.get("calibration_mask"), width, height)

    if polygon:
        cv2.polylines(frame, [np.array(polygon, dtype=np.int32)], True, DEBUG_COLORS["zone"], thickness + 1)

    with state["lock"]:
        debug = dict(state.get("debug") or {})

    if message or not debug:
        draw_debug_panel(frame, ["DEBUG", message or "No detection has run yet."], (0, 215, 255))
        return

    source = debug.get("frame_size") or [width, height]
    sx, sy = width / float(source[0] or width), height / float(source[1] or height)

    def point(x, y):
        return int(x * sx), int(y * sy)

    if debug.get("crop"):
        x1, y1, x2, y2 = debug["crop"]
        cv2.rectangle(frame, point(x1, y1), point(x2, y2), DEBUG_COLORS["crop"], 1)

    for item in debug.get("raw", []):
        x1, y1, x2, y2 = item["xyxy"]
        color = DEBUG_COLORS.get(item["reason"], DEBUG_COLORS["not a vehicle"])
        cv2.rectangle(frame, point(x1, y1), point(x2, y2), color, thickness)
        label = f"{item['name']} {item['confidence']:.2f}" + (f" #{item['track_id']}" if item.get("track_id") is not None else "")
        cv2.putText(frame, label, (point(x1, y1)[0], max(12, point(x1, y1)[1] - 4)), cv2.FONT_HERSHEY_SIMPLEX,
                    0.4 * max(1, thickness), color, 1, cv2.LINE_AA)

    for track_id, points in (debug.get("tracks") or {}).items():
        path = [point(x, y) for x, y in points]
        if len(path) > 1:
            cv2.polylines(frame, [np.array(path, dtype=np.int32)], False, DEBUG_COLORS["track"], thickness)
        cv2.circle(frame, path[-1], thickness * 3, DEBUG_COLORS["track"], -1)
        cv2.putText(frame, f"ID {track_id}", (path[-1][0] + 6, path[-1][1] + 4), cv2.FONT_HERSHEY_SIMPLEX,
                    0.45 * max(1, thickness), DEBUG_COLORS["track"], 1, cv2.LINE_AA)

    age = time.monotonic() - float(debug.get("at") or 0)
    draw_debug_panel(frame, [
        f"DEBUG  detections/frame {debug.get('raw_count', 0)}  vehicles {debug.get('vehicle_count', 0)}  in zone {debug.get('in_roi', 0)}",
        f"detected {debug.get('passes_detected', 0)}  recorded {debug.get('passes_recorded', 0)}  in zone now {len(state.get('passes') or {})}  detection {debug.get('detection_fps', 0)} fps  on {debug.get('device') or '-'} @{debug.get('imgsz')}",
        "IN {IN}  OUT {OUT}  unknown {UNKNOWN}".format(**{"IN": 0, "OUT": 0, "UNKNOWN": 0, **(state.get("direction_counts") or {})})
        + ("  last: " + ", ".join(f"{item['direction']} #{item['track_id']}" for item in reversed(state.get("recent_records") or [])) if state.get("recent_records") else ""),
        f"frame {source[0]}x{source[1]}  last detection {age:.1f}s ago" + (f"  ERROR {state.get('last_detection_error')}" if state.get("last_detection_error") else ""),
    ])


def current_track_boxes(results):
    """
    Return the latest visible YOLO boxes keyed by track id.
    """
    boxes = results.boxes if results is not None else None

    if boxes is None or boxes.id is None:
        return {}

    ids = boxes.id.int().cpu().tolist()
    classes = boxes.cls.int().cpu().tolist()
    confidences = boxes.conf.cpu().tolist()
    coordinates = boxes.xyxy.cpu().tolist()

    return {
        track_id: {
            "class_id": class_id,
            "confidence": confidence,
            "xyxy": xyxy,
        }
        for track_id, class_id, confidence, xyxy in zip(ids, classes, confidences, coordinates)
    }


def bbox_inside_roi(xyxy, mask_polygon):
    """
    Treat a detection as active only when its center is inside the saved ROI.
    """
    return point_in_polygon(bbox_center(xyxy), mask_polygon) if mask_polygon else False


def refresh_pending_window_snapshots(frame, state):
    """
    Keep a fallback guest snapshot without replacing YOLO-aligned snapshots.
    """
    with state["lock"]:
        if not state["pending_windows"]:
            return

        for window in state["pending_windows"].values():
            if window.get("snapshot_frame") is None:
                window["snapshot_frame"] = frame.copy()


def rfid_seconds(camera_config, key, default):
    """
    Phase 3: an RFID window length from the exported gate settings, or the
    built-in default for older exports.
    """
    try:
        value = float((camera_config or {}).get(key) or default)
    except (TypeError, ValueError):
        return default
    return max(1.0, min(15.0, value))


def detect_pass(role, frame, state, track_id, class_id, confidence, xyxy, camera_config, vehicle_labels, laravel_client, yolo_track_id=None):
    """
    Detected: the vehicle was seen in the zone for a few frames. Its pass
    opens: the Gate Monitor / kiosk show "Vehicle detected · checking RFID…"
    at once and the RFID window starts (tag reads from the lookback before
    now count too). The pass's worker (pass_worker) makes the record.
    """
    # track_id here is the PASS key (unique per pass); the record shows the
    # tracker's own ID (the tracker can give a new vehicle an old ID).
    yolo_track_id = track_id if yolo_track_id is None else yolo_track_id
    now_monotonic = time.monotonic()
    window_seconds = rfid_seconds(camera_config, "rfid_window_seconds", RFID_DETECTION_WINDOW_SECONDS)
    event_key = f"{role}-track-{yolo_track_id}-{int(time.time() * 1000)}"
    event_time = datetime.now().astimezone().isoformat()
    display_label = vehicle_labels[class_id]

    with state["lock"]:
        tracked = ensure_tracked_vehicle_locked(state, track_id, now_monotonic)

        if tracked.get("status") in {"registered", "no_pass", "processed"}:
            return

        tracked.update({
            "status": "checking",
            "event_key": event_key,
            "event_time": event_time,
        })
        frame_height, frame_width = frame.shape[:2]
        zone = normalized_polygon_to_pixels(camera_config.get("calibration_mask"), frame_width, frame_height) or []
        x1, y1, x2, y2 = xyxy
        state["pending_windows"][track_id] = {
            # A2: the track's type votes (still filled while it is in view) and
            # the zone height in pixels (size rule).
            "vote": state["track_votes"].setdefault(track_id, vtype.TrackVote()),
            "zone_height": (max(point[1] for point in zone) - min(point[1] for point in zone)) if zone else float(frame_height),
            "event_key": event_key,
            "camera_role": role,
            "camera_id": camera_config.get("camera_id"),
            "track_id": yolo_track_id,
            "class_id": class_id,
            "detected_vehicle_type": display_label,
            "confidence": confidence,
            "xyxy": xyxy,
            # IN / OUT / UNKNOWN / STILL once decided (process_results / Left zone).
            "direction": None,
            "direction_reason": None,
            "direction_confidence": None,
            "measure": None,
            "event_time": event_time,
            "started_at": now_monotonic,
            # Phase 3: Settings > Timing (tag read after / before the vehicle is detected).
            "window_seconds": window_seconds,
            "lookback_seconds": rfid_seconds(camera_config, "rfid_lookback_seconds", RFID_LOOKBACK_SECONDS),
            "deadline_at": now_monotonic + window_seconds,
            "rfid_status": None,
            "left_zone_at": None,
            "recorded_at": None,
            "snapshot_frame": frame.copy(),
            # The clearest picture so far: the vehicle at its largest in the zone.
            "best_frame": frame.copy(),
            "best_xyxy": tuple(xyxy),
            "best_area": float((x2 - x1) * (y2 - y1)),
            "best_at": now_monotonic,
            "last_snapshot_refresh_at": now_monotonic,
            "analysis_frames": [(frame.copy(), tuple(xyxy))],
            "last_analysis_frame_at": now_monotonic,
            "last_message": "Waiting for RFID scan.",
        }
        state["track_overlays"][track_id] = waiting_overlay()
        # "Vehicle detected · checking RFID…" on the vehicle's own box right away.
        state["track_overlays"][yolo_track_id] = state["track_overlays"][track_id]
        state["passes_detected"] += 1

    print(f"{role} pass track {yolo_track_id}: Detected, checking RFID", flush=True)
    worker = threading.Thread(
        target=pass_worker,
        args=(role, state, track_id, laravel_client),
        daemon=True,
    )
    worker.start()


def apply_rfid_match_result(state, track_id, match):
    """
    Resolve a pending detection as soon as Laravel finds a registered tag read.
    """
    now_monotonic = time.monotonic()

    with state["lock"]:
        window = state["pending_windows"].get(track_id)

        if not window:
            return True

        window["last_message"] = match.get("message", window.get("last_message"))

        if not match.get("matched"):
            return False

        vehicle = (match.get("body") or {}).get("vehicle") or {}
        plate_number = vehicle.get("plate_number")
        overlay = match.get("overlay") or registered_overlay(plate_number)
        status = "registered"
        state["track_overlays"][track_id] = overlay
        state["crossed_track_ids"][track_id] = now_monotonic
        state.setdefault("tracked_vehicles", {}).setdefault(track_id, {
            "first_seen": time.time(),
            "first_seen_monotonic": now_monotonic,
        })
        state["tracked_vehicles"][track_id].update({
            "status": status,
            "registered_at": time.time(),
            "registered_at_monotonic": now_monotonic,
        })
        remember_recent_resolution_locked(state, track_id, window.get("xyxy", (0, 0, 0, 0)), overlay, now_monotonic)
        # ROI work: the pass stays open; its record is made once the
        # direction is sure (pass_worker).
        window["rfid_status"] = "matched"
        state["last_error"] = ""

        return True


# A pass whose vehicle never leaves the zone (parked) is closed after this long.
PASS_MAX_OPEN_SECONDS = 600.0
# Update the record at Left zone when the clearest picture grew this much since it was made.
BETTER_PICTURE_RATIO = 1.2


def wait_for_window(state, track_id, window, ready, max_seconds):
    """
    Wait (outside the frame loop) until ready(window): "ready", "timeout"
    after max_seconds, or "gone" when the pass was dropped (detector reset).
    """
    deadline = time.monotonic() + max_seconds
    while True:
        with state["lock"]:
            if state["pending_windows"].get(track_id) is not window:
                return "gone"
            if ready(window):
                return "ready"
        if time.monotonic() >= deadline:
            return "timeout"
        time.sleep(0.2)


def pass_worker(role, state, track_id, laravel_client):
    """
    One vehicle pass, outside the frame loop:

    1. RFID check (ROI work, Phase 3): from the moment the vehicle was
       Detected until the pass is ready to be Recorded, Laravel is asked for
       this vehicle's tag (any read from `lookback` before Detected on).
    2. Recorded: once the direction is sure (it clearly moved away from the
       camera or toward it) AND the RFID window (`window_seconds` after
       Detected) is over, even while it is still in the zone; or when it left
       the zone / was lost before that (IN / OUT if the data allows, else
       "Direction unknown"). A vehicle that never moved is not recorded.
    3. Until Left zone the SAME record is updated: a clearer picture (and the
       plate, type) replaces the first one. Never a second record.
    """
    with state["lock"]:
        window = state["pending_windows"].get(track_id)

    if not window:
        return

    try:
        outcome, matched = check_rfid_until_ready(role, state, track_id, window, laravel_client)
        if outcome == "gone":
            return
        with state["lock"]:
            window["rfid_status"] = "matched" if matched else "no_pass"
            if outcome == "timeout":
                # Still in the zone after all this time and never sure: a parked vehicle.
                measure = window.get("measure") or {}
                if measure.get("moved", 0.0) < STILL_SHARE:
                    window.update(direction="STILL", direction_reason="did not move")
                else:
                    window.update(direction="UNKNOWN", direction_reason=measure.get("reason") or "Direction unknown")
            direction = window.get("direction")

        if direction == "STILL":
            with state["lock"]:
                state["track_overlays"][track_id] = default_overlay()
            print(f"{role} pass track {track_id}: Left zone without moving, not recorded", flush=True)
            return

        record_pass(role, state, track_id, window, "matched" if matched else "no_pass", laravel_client)

        if not matched:
            submit_guest_observation_for_window(role, state, track_id, laravel_client)

        if wait_for_window(state, track_id, window, lambda w: w.get("left_zone_at"), PASS_MAX_OPEN_SECONDS) == "gone":
            return
        update_record_after_left_zone(role, state, track_id, window, matched, laravel_client)
    finally:
        with state["lock"]:
            state["pending_windows"].pop(track_id, None)
            tracked = state.get("tracked_vehicles", {}).get(track_id)
            if tracked is not None and tracked.get("status") == "checking":
                tracked["status"] = "processed"


# A pass that stays open long (a vehicle stopped in the zone: traffic, a drop-off; there is no barrier) is asked about less often.
RFID_SLOW_POLL_AFTER_SECONDS = 30.0
RFID_SLOW_POLL_INTERVAL_SECONDS = 2.0


def check_rfid_until_ready(role, state, track_id, window, laravel_client):
    """
    ROI work, Phase 3: the RFID check of one pass. Every Detected vehicle is
    checked: Laravel is polled from Detected until the pass is ready to be
    Recorded (direction decided or Left zone, and the tag found or the RFID
    window after Detected over), with `until` = now, so a tag read anywhere
    in the pass (and `lookback` before it) counts. Once a tag matched, no
    more polls.

    Returns (outcome, matched): outcome "ready", "timeout" (open for
    PASS_MAX_OPEN_SECONDS and never sure) or "gone" (the pass was dropped).
    """
    opened = time.monotonic()
    matched = False
    last_poll = None

    while True:
        now = time.monotonic()
        with state["lock"]:
            if state["pending_windows"].get(track_id) is not window:
                return "gone", matched
            matched = matched or window.get("rfid_status") == "matched"
            # Decided, and the RFID check is over: the tag was found, or its window ran out.
            ready = window.get("decided_at") is not None and (matched or now >= window["deadline_at"])
            event_time, event_key = window["event_time"], window["event_key"]
            window_seconds = window.get("window_seconds", RFID_DETECTION_WINDOW_SECONDS)
            lookback_seconds = window.get("lookback_seconds", RFID_LOOKBACK_SECONDS)

        interval = RFID_SLOW_POLL_INTERVAL_SECONDS if now - opened > RFID_SLOW_POLL_AFTER_SECONDS else RFID_POLL_INTERVAL_SECONDS
        # A last check at the moment it is ready, so the latest reads count too.
        if not matched and (last_poll is None or ready or now - last_poll >= interval):
            last_poll = now
            match = laravel_client.check_rfid_match(
                role, event_time, window_seconds, lookback_seconds, event_key,
                until=datetime.now().astimezone().isoformat(),
            )
            matched = apply_rfid_match_result(state, track_id, match) and bool(match.get("matched"))

        if ready:
            return "ready", matched
        if now - opened >= PASS_MAX_OPEN_SECONDS:
            return "timeout", matched
        time.sleep(0.1)


def set_window_direction_locked(window, decision):
    """The pass's direction (IN / OUT / UNKNOWN / STILL), why, and how sure."""
    if decision.get("direction") is not None:
        # The RFID check ends once the pass is decided (and its window is over).
        window.setdefault("decided_at", time.monotonic())
    window["direction"] = decision.get("direction")
    window["direction_reason"] = decision.get("reason")
    window["direction_confidence"] = decision.get("confidence")
    window["measure"] = decision


_SECOND_PASS = {"path": None, "model": None, "labels": {}, "lock": threading.Lock()}


def second_pass_model(model_path):
    """A2: the model of the second check (loaded once; yolov8n.pt when the chosen file is missing)."""
    path = model_file(Path(str(model_path or MODEL_PATH)).name)
    if not path.exists():
        path = Path(MODEL_PATH)
    if _SECOND_PASS["path"] != str(path):
        from ultralytics import YOLO

        model = YOLO(str(path))
        _SECOND_PASS.update({"path": str(path), "model": model, "labels": resolve_allowed_vehicle_classes(model)})
    return _SECOND_PASS["model"], _SECOND_PASS["labels"]


def second_pass_type(crop, settings):
    """
    A2: the type on the best full-resolution crop at a larger input size:
    {"type", "confidence", "model", "imgsz"} or None.
    """
    if crop is None or crop.size == 0:
        return None
    imgsz = int(settings.get("type_second_pass_imgsz") or vtype.DEFAULTS["type_second_pass_imgsz"])
    with _SECOND_PASS["lock"]:
        model, labels = second_pass_model(settings.get("type_model"))
        results = model.predict(crop, imgsz=imgsz, conf=0.2, verbose=False, device=yolo_device("auto"))
    boxes = results[0].boxes if results else None
    best = None
    if boxes is not None:
        for class_id, confidence, xyxy in zip(boxes.cls.int().tolist(), boxes.conf.tolist(), boxes.xyxy.tolist()):
            if class_id not in labels:
                continue
            area = (xyxy[2] - xyxy[0]) * (xyxy[3] - xyxy[1])
            if best is None or area > best[0]:
                best = (area, labels[class_id], confidence)
    if best is None:
        return None
    return {"type": best[1], "confidence": round(float(best[2]), 3), "model": Path(_SECOND_PASS["path"]).name, "imgsz": imgsz}


def best_vehicle_crop(window, hires_frame):
    """The vehicle in its best picture: from the full-resolution frame of that moment when there is one."""
    best_hires = window.get("best_hires")
    if hires_frame is not None and best_hires and best_hires[0] is hires_frame:
        x1, y1, x2, y2 = scale_box(best_hires[1], hires_frame.shape, hires_frame.shape, pad=0.1)
        return hires_frame[y1:y2, x1:x2]
    snapshot = window.get("best_frame")
    if hires_frame is not None and snapshot is not None:
        x1, y1, x2, y2 = scale_box(window.get("best_xyxy") or window["xyxy"], snapshot.shape, hires_frame.shape, pad=0.1)
        return hires_frame[y1:y2, x1:x2]
    frames = [(frame, xyxy) for frame, xyxy in window.get("analysis_frames", []) if frame is not None]
    if not frames:
        return None
    frame, xyxy = max(frames, key=lambda item: (item[1][2] - item[1][0]) * (item[1][3] - item[1][1]))
    x1, y1, x2, y2 = scale_box(xyxy, frame.shape, frame.shape, pad=0.1)
    return frame[y1:y2, x1:x2]


def final_vehicle_type(window, hires_frame):
    """A2: votes of every frame + second check + size/shape rule (vehicle_type.decide)."""
    settings = {**vtype.DEFAULTS, **performance_settings(load_runtime_config())}
    second = None
    if int(settings.get("type_second_pass") or 0):
        try:
            second = second_pass_type(best_vehicle_crop(window, hires_frame), settings)
        except Exception as error:  # the frame votes still decide
            print(f"Second type check failed: {error}", flush=True)
    vote = window.get("vote") or vtype.TrackVote()
    return vtype.decide(vote, second, float(window.get("zone_height") or 0.0), settings, fallback=window.get("detected_vehicle_type"))


def best_picture_locked(window, full_resolution=False):
    """
    The best picture of the pass so far and the vehicle's box in it.
    full_resolution: the main stream's frame of that moment when there is
    one (Phase 5), else the live frame.
    """
    if full_resolution and window.get("best_hires"):
        frame, box = window["best_hires"]
        return frame.copy(), tuple(box)
    frame = window.get("best_frame")
    if frame is None:
        frame = window.get("snapshot_frame")
        return (frame.copy() if frame is not None else None), tuple(window.get("xyxy", ()))
    return frame.copy(), tuple(window.get("best_xyxy") or window.get("xyxy", ()))


def vehicle_sharpness(frame, xyxy):
    """Laplacian variance of the vehicle's part of the frame, 160 px wide (blur from speed or a bad frame is low)."""
    x1, y1, x2, y2 = [int(value) for value in xyxy]
    crop = frame[max(0, y1):max(0, y2), max(0, x1):max(0, x2)]
    if not crop.size:
        return 0.0
    small = cv2.resize(crop, (160, max(1, int(160 * crop.shape[0] / crop.shape[1]))), interpolation=cv2.INTER_AREA)
    return float(cv2.Laplacian(cv2.cvtColor(small, cv2.COLOR_BGR2GRAY), cv2.CV_64F).var())


def sharpest_hires_frame(role, moment, live_xyxy, live_shape, velocity=(0.0, 0.0)):
    """
    Phase 5: of the main stream's frames of this moment (within 0.15 s),
    the one where the vehicle is sharpest, with the vehicle's box in it;
    None without a main stream. A frame or two apart can differ a lot (a
    compressed or blurred frame now and then). The vehicle moves between
    them: its box is moved by its speed (live-frame pixels per second).
    """
    grabber = HIRES.get(role)
    frames = grabber.frames_near(moment, 0.1) if grabber is not None and hasattr(grabber, "frames_near") else []
    if not frames:
        frame = grabber.frame_near(moment) if grabber is not None else None
        frames = [(moment, frame)] if frame is not None else []
    if not frames:
        return None

    def box_at(at):
        dx, dy = velocity[0] * (at - moment), velocity[1] * (at - moment)
        x1, y1, x2, y2 = live_xyxy
        return scale_box((x1 + dx, y1 + dy, x2 + dx, y2 + dy), live_shape, frames[0][1].shape, pad=0.0)

    def inside(box, shape):
        x1, y1, x2, y2 = box
        visible = max(0, min(x2, shape[1]) - max(x1, 0)) * max(0, min(y2, shape[0]) - max(y1, 0))
        return visible >= 0.8 * max(1, (x2 - x1) * (y2 - y1))

    # The frame nearest the moment, unless another is clearly sharper (a
    # sharper-looking frame further away may show the road, not the vehicle).
    frames.sort(key=lambda item: abs(item[0] - moment))
    at, best = frames[0]
    best_sharpness = vehicle_sharpness(best, box_at(at))
    for other_at, other in frames[1:]:
        box = box_at(other_at)
        sharpness = vehicle_sharpness(other, box)
        if inside(box, other.shape) and sharpness >= 1.3 * best_sharpness:
            at, best, best_sharpness = other_at, other, sharpness
    return best.copy(), box_at(at)


def picture_details(window, frame, box):
    """Phase 5: what the record's picture is (kept with the record): main or live stream, size, sharpness, whole vehicle."""
    if frame is None or not box:
        return None
    height, width = frame.shape[:2]
    mx, my = EDGE_MARGIN * width, EDGE_MARGIN * height
    return {
        "source": "main" if window.get("best_hires") else "live",
        "size": [int(width), int(height)],
        "sharpness": round(vehicle_sharpness(frame, box), 1),
        "whole": bool(box[0] > mx and box[1] > my and box[2] < width - mx and box[3] < height - my),
        "score": round(float(window.get("best_score") or 0.0), 4),
    }


# Phase 5: a box closer than this share of the picture to its edge is cut off.
EDGE_MARGIN = 0.02


def picture_score(frame, xyxy):
    """
    Phase 5: how good a picture of the vehicle this frame is (higher is
    better): bigger is better (square root: a little bigger does not beat a
    lot sharper), a vehicle cut off by the picture's edge counts much less,
    and a blurred one (speed, a bad frame) less (Laplacian variance of the
    vehicle, 160 px wide).
    """
    height, width = frame.shape[:2]
    x1, y1, x2, y2 = [float(value) for value in xyxy]
    area = max(0.0, x2 - x1) * max(0.0, y2 - y1)
    if area <= 0:
        return 0.0
    mx, my = EDGE_MARGIN * width, EDGE_MARGIN * height
    whole = x1 > mx and y1 > my and x2 < width - mx and y2 < height - my
    sharp = vehicle_sharpness(frame, xyxy)
    sharpness = sharp / (sharp + 100.0)
    return float(np.sqrt(area / (width * height))) * (1.0 if whole else 0.4) * (0.3 + 0.7 * sharpness)


def crossing_payload_locked(role, track_id, window, rfid_status, type_decision, picture_xyxy):
    return {
        "external_event_key": window["event_key"],
        "camera_role": role,
        "camera_id": window.get("camera_id"),
        "direction": window.get("direction") if window.get("direction") in {"IN", "OUT"} else "UNKNOWN",
        "direction_reason": (window.get("direction_reason") or "")[:120] or None,
        "event_time": window["event_time"],
        "track_id": window.get("track_id", track_id),
        "confidence": round(float(window.get("confidence") or 0.0), 4),
        "detected_vehicle_type": window.get("detected_vehicle_type"),
        "detection_metadata": {
            "bbox_xyxy": [round(float(value), 1) for value in picture_xyxy],
            # ROI work: how the direction was decided (away from / toward the
            # camera, share of the zone's height, box size change, confidence)
            # and the pass's stages.
            "direction_confidence": window.get("direction_confidence"),
            "movement": {key: value for key, value in (window.get("measure") or {}).items() if key in {"movement", "rise", "moved", "size"}},
            "pass": {
                "detected_at": window["event_time"],
                "recorded_at": window.get("recorded_at"),
                "left_zone": bool(window.get("left_zone_at")),
            },
            "rfid_status": rfid_status,
            "vehicle_type": {key: value for key, value in type_decision.items() if key != "type"},
        },
    }


def record_pass(role, state, track_id, window, rfid_status, laravel_client):
    """
    Recorded: send the pass as one record (gate, IN / OUT / UNKNOWN, the time
    it was detected, track, type, the clearest picture so far) to Laravel.
    """
    # A2: the final type from every frame, a second check and the size rule.
    # Phase 5: the main stream's frame of the best moment (taken then), for the type's second check.
    hires_frame = (window.get("best_hires") or (None, None))[0]
    type_decision = final_vehicle_type(window, hires_frame)

    with state["lock"]:
        window["detected_vehicle_type"] = type_decision["type"] or window.get("detected_vehicle_type")
        window["recorded_at"] = datetime.now().astimezone().isoformat()
        window["recorded_area"] = float(window.get("best_area") or 0.0)
        window["recorded_score"] = float(window.get("best_score") or 0.0)
        picture, picture_xyxy = best_picture_locked(window)
        payload = crossing_payload_locked(role, track_id, window, rfid_status, type_decision, picture_xyxy)
        # Phase 5: the picture sent is the main stream's frame of the best moment when there is one.
        snapshot_frame, snapshot_box = best_picture_locked(window, full_resolution=True)
        payload["detection_metadata"]["snapshot_box"] = [round(float(value), 1) for value in snapshot_box]
        payload["detection_metadata"]["picture"] = picture_details(window, snapshot_frame, snapshot_box)
        direction = payload["direction"]
        state["direction_counts"][direction] = state["direction_counts"].get(direction, 0) + 1
        state["recent_records"].append({"track_id": window.get("track_id", track_id), "direction": direction, "at": time.monotonic()})
        del state["recent_records"][:-5]
        state["passes_recorded"] += 1
        state["crossings_logged"] += 1

    print(f"{role} pass track {window.get('track_id', track_id)}: Recorded {direction} ({payload['direction_reason']}), "
          f"{payload['detected_vehicle_type']} ({type_decision['rule']}), RFID {rfid_status}", flush=True)

    snapshot = encode_frame_snapshot(role, snapshot_frame, f"crossing-{window['event_key']}") if snapshot_frame is not None else None
    submit = getattr(laravel_client, "submit_crossing", None)
    if submit is None:
        return
    result = submit(payload, snapshot["bytes"] if snapshot else None, snapshot["filename"] if snapshot else None)

    if not result.get("accepted"):
        with state["lock"]:
            state["last_error"] = result.get("message", "Vehicle record could not be saved.")


def update_record_after_left_zone(role, state, track_id, window, matched, laravel_client):
    """
    Left zone: the same record gets the clearest picture the pass had (and,
    for an unregistered vehicle, the plate read from every frame), when it is
    clearly better than the one sent when it was Recorded.
    """
    with state["lock"]:
        # Phase 5: a clearly better picture (bigger, whole, sharper), not only a bigger box.
        better = float(window.get("best_score") or 0.0) >= float(window.get("recorded_score") or 0.0) * BETTER_PICTURE_RATIO
        if not better:
            return
        picture, picture_xyxy = best_picture_locked(window)
        snapshot_frame, snapshot_box = best_picture_locked(window, full_resolution=True)

    # Phase 5: the main stream's frame of the best moment (taken then), for the type's second check.
    hires_frame = (window.get("best_hires") or (None, None))[0]
    type_decision = final_vehicle_type(window, hires_frame)
    with state["lock"]:
        window["detected_vehicle_type"] = type_decision["type"] or window.get("detected_vehicle_type")
        payload = crossing_payload_locked(role, track_id, window, "matched" if matched else "no_pass", type_decision, picture_xyxy)
        payload["detection_metadata"]["snapshot_box"] = [round(float(value), 1) for value in snapshot_box]
        payload["detection_metadata"]["picture"] = picture_details(window, snapshot_frame, snapshot_box)
        analysis_frames = [(frame.copy(), tuple(xyxy)) for frame, xyxy in window.get("analysis_frames", []) if frame is not None]
        hires_frames = list(window.get("hires_frames", []))
        best_hires = window.get("best_hires")
        plate_frames = plate_frames_locked(window)

    if best_hires:
        # The sharpest full-resolution frame first for the plate as well.
        hires_frames = [(best_hires[0], best_hires[1])] + hires_frames
    snapshot = encode_frame_snapshot(role, snapshot_frame, f"crossing-{window['event_key']}-left") if snapshot_frame is not None else None
    submit = getattr(laravel_client, "submit_crossing", None)
    if submit is not None:
        submit(payload, snapshot["bytes"] if snapshot else None, snapshot["filename"] if snapshot else None)
    print(f"{role} pass track {track_id}: Left zone, record updated with a clearer picture", flush=True)

    submit_visitor_plate = getattr(laravel_client, "submit_visitor_plate", None)
    if matched or submit_visitor_plate is None:
        return
    # Phase 5: the plate again, from every sharp frame of the whole pass.
    vote, vehicle_color, _, analysis_details, plate_crop = analyze_guest_vehicle_details(hires_frames + analysis_frames, payload["detected_vehicle_type"], plate_frames)
    plate_image = encode_frame_snapshot(role, plate_crop, f"plate-{window['event_key']}") if plate_crop is not None else None
    submit_visitor_plate(
        {
            "external_event_key": window["event_key"],
            "camera_role": role,
            "event_time": window["event_time"],
            "plate_number": vote["plate"],
            "plate_status": vote["status"],
            "plate_confidence": vote["confidence"],
            "best_guess": vote["best_guess"],
            "vehicle_color": vehicle_color,
            "detected_vehicle_type": payload["detected_vehicle_type"],
            "ocr_details": analysis_details,
        },
        plate_image["bytes"] if plate_image else None,
        plate_image["filename"] if plate_image else None,
    )


def most_common_value(values):
    """
    Pick the most repeated non-empty analysis result.
    """
    counts = {}

    for value in values:
        if not value:
            continue

        counts[value] = counts.get(value, 0) + 1

    if not counts:
        return None

    return sorted(counts, key=lambda value: (counts[value], len(value)), reverse=True)[0]


def most_common_color(values):
    """
    Pick the most repeated color without favoring longer color names on ties.
    """
    counts = {}
    first_seen = {}

    for index, value in enumerate(values):
        if not value:
            continue

        counts[value] = counts.get(value, 0) + 1
        first_seen.setdefault(value, index)

    if not counts:
        return None

    return sorted(
        counts,
        key=lambda value: (counts[value], -first_seen[value]),
        reverse=True,
    )[0]


def reconcile_guest_vehicle_color(initial_color, final_color):
    """
    Keep the wider fast color consensus when a later smaller OCR pass disagrees.
    """
    if initial_color and final_color and initial_color != final_color:
        return initial_color

    return final_color or initial_color


def analyze_guest_vehicle_color(analysis_frames):
    """
    Run the fast color classifier before the slower OCR pass so the Guest record
    gets useful visible-vehicle data even if OCR takes too long.
    """
    vehicle_colors = []
    frame_results = []
    started_at = time.monotonic()

    for index, (frame, xyxy) in enumerate(analysis_frames[:MAX_GUEST_COLOR_ANALYSIS_FRAMES]):
        color_error = None

        try:
            vehicle_color = detect_vehicle_color(frame, xyxy)
        except Exception as error:
            vehicle_color = None
            color_error = str(error)

        if vehicle_color:
            vehicle_colors.append(vehicle_color)

        frame_results.append({
            "index": index,
            "bbox_xyxy": [float(value) for value in xyxy],
            "vehicle_color": vehicle_color,
            "color_error": color_error,
        })

    return most_common_color(vehicle_colors), {
        "frames_checked": len(frame_results),
        "elapsed_seconds": round(time.monotonic() - started_at, 3),
        "frame_results": frame_results,
    }


def analyze_guest_vehicle_details(analysis_frames, vehicle_type=None, plate_frames=None):
    """
    Phase 5 (visitor model): read the plate on up to MAX_GUEST_OCR_ANALYSIS_FRAMES
    frames (full-resolution first) and vote (plate_voting.vote_plate); stop
    early once two frames agree. Returns (vote, color, ocr runtime, details,
    plate crop image or None).

    Gate direction work, Phase B: with the plate detector (plate_reader), the
    plate is found in every frame of the pass's burst, the sharpest plate
    crops are read and voted on, and the sharpest crop is kept.
    """
    if plate_reader.available():
        try:
            result = plate_reader.read_pass(list(plate_frames or []) + list(analysis_frames), vehicle_type)
        except Exception as error:
            print(f"Plate reader failed, using the older reader: {error}", flush=True)
            result = None
        if result is not None:
            vote, crop, details = result
            colors = []
            for frame, xyxy in analysis_frames[:MAX_GUEST_COLOR_ANALYSIS_FRAMES]:
                try:
                    colors.append(detect_vehicle_color(frame, xyxy))
                except Exception:
                    pass
            details.update(vote={key: value for key, value in vote.items() if key != "candidates"}, candidates=vote["candidates"])
            return vote, most_common_color([color for color in colors if color]), ocr_runtime_status(), details, crop

    vehicle_colors = []
    frame_reads = []
    crops = {}
    runtime_status = ocr_runtime_status()
    frame_results = []
    started_at = time.monotonic()

    for index, (frame, xyxy) in enumerate(analysis_frames[:MAX_GUEST_OCR_ANALYSIS_FRAMES]):
        if index and time.monotonic() - started_at >= PLATE_OCR_BUDGET_SECONDS:
            break

        plate_error = None
        color_error = None

        try:
            read = read_license_plate_details(frame, xyxy)
        except Exception as error:
            read = {"plate": None, "score": 0.0, "crop": None}
            plate_error = str(error)

        try:
            vehicle_color = detect_vehicle_color(frame, xyxy)
        except Exception as error:
            vehicle_color = None
            color_error = str(error)

        if read["plate"] and read.get("crop") is not None:
            if read["score"] > crops.get(read["plate"], (-1.0, None))[0]:
                crops[read["plate"]] = (read["score"], read["crop"])

        if vehicle_color:
            vehicle_colors.append(vehicle_color)

        frame_reads.append({"plate": read["plate"], "score": read["score"]})
        frame_results.append({
            "index": index,
            "frame_size": [int(frame.shape[1]), int(frame.shape[0])],
            "bbox_xyxy": [float(value) for value in xyxy],
            "plate_number": read["plate"],
            "plate_score": read["score"],
            "vehicle_color": vehicle_color,
            "plate_error": plate_error,
            "color_error": color_error,
        })

        if frames_agree(frame_reads):
            break

    vote = vote_plate(frame_reads, vehicle_type)
    crop = crops.get(vote["plate"] or vote["best_guess"] or "", (0.0, None))[1]

    return (
        vote,
        most_common_color(vehicle_colors),
        runtime_status,
        {
            "frames_checked": len(frame_results),
            "elapsed_seconds": round(time.monotonic() - started_at, 3),
            "frame_results": frame_results,
            "vote": {key: value for key, value in vote.items() if key != "candidates"},
            "candidates": vote["candidates"],
        },
        crop,
    )


def submit_guest_observation_for_window(role, state, track_id, laravel_client):
    """
    Send one Unregistered Visitor camera record (snapshot, then color/plate) after
    the RFID window expires without blocking video. Laravel no longer turns
    this into a guest record or an INSIDE guest session.
    """
    now_monotonic = time.monotonic()

    with state["lock"]:
        window = state["pending_windows"].get(track_id)

        if not window:
            return

        # ROI work: the clearest picture of the pass so far, not the last frame.
        snapshot_frame, best_xyxy = best_picture_locked(window)

        analysis_frames = [
            (analysis_frame.copy(), tuple(analysis_xyxy))
            for analysis_frame, analysis_xyxy in window.get("analysis_frames", [])
            if analysis_frame is not None
        ]
        window_started_at = window.get("started_at", now_monotonic)
        hires_analysis_frames = list(window.get("hires_frames", []))
        best_hires = window.get("best_hires")
        plate_frames = plate_frames_locked(window)
        window_payload = {
            "event_key": window["event_key"],
            "camera_id": window.get("camera_id"),
            "detected_vehicle_type": window["detected_vehicle_type"],
            "event_time": window["event_time"],
            "xyxy": best_xyxy,
            "confidence": window["confidence"],
            "direction": window["direction"] if window.get("direction") in {"IN", "OUT"} else "UNKNOWN",
            "window_seconds": window.get("window_seconds", RFID_DETECTION_WINDOW_SECONDS),
            "lookback_seconds": window.get("lookback_seconds", RFID_LOOKBACK_SECONDS),
        }
        tracked = ensure_tracked_vehicle_locked(state, track_id, now_monotonic)

        if tracked.get("status") != "checking":
            return

        tracked.update({
            "status": "no_pass",
            "no_pass_declared_at": time.time(),
            "no_pass_declared_at_monotonic": now_monotonic,
        })
        mark_processed_as_guest_locked(
            state,
            track_id,
            window_payload["xyxy"],
            default_overlay(),
            now_monotonic,
        )

    if snapshot_frame is None:
        with state["lock"]:
            state["last_error"] = f"{role.capitalize()} unregistered vehicle: no snapshot frame was available."
        return

    # A full-resolution frame of the best moment (Phase 5: taken when it was
    # seen; the ring buffer only keeps the last second): sharper snapshot
    # and plate reading than the small live-stream frame.
    hires_frame = best_hires[0] if best_hires else None
    if hires_frame is not None:
        hires_box = scale_box(window_payload["xyxy"], snapshot_frame.shape, hires_frame.shape)
        analysis_frames = [(hires_frame, hires_box)] + analysis_frames
        snapshot_frame = hires_frame
        # Live-frame box kept for matching later live detections of this car.
        window_payload["live_xyxy"] = window_payload["xyxy"]
        window_payload["xyxy"] = hires_box
        metrics.rate(role, "hires_used")

    # Phase 5: sharp frames first for the plate vote, then the live frames.
    analysis_frames = hires_analysis_frames + analysis_frames

    snapshot = encode_frame_snapshot(
        role,
        snapshot_frame,
        window_payload["event_key"],
    )

    if not snapshot:
        with state["lock"]:
            state["last_error"] = f"{role.capitalize()} unregistered vehicle: snapshot encoding failed."
        return

    base_metadata = {
        "track_id": track_id,
        "confidence": window_payload["confidence"],
        "direction": window_payload["direction"],
        "bbox_xyxy": list(window_payload["xyxy"]),
        "rfid_window_seconds": window_payload["window_seconds"],
        "rfid_lookback_seconds": window_payload["lookback_seconds"],
        "alert_type": "no_pass",
        "analysis_status": "pending",
    }
    base_payload = {
        "external_event_key": window_payload["event_key"],
        "camera_role": role,
        "camera_id": window_payload["camera_id"],
        "detected_vehicle_type": window_payload["detected_vehicle_type"],
        "event_time": window_payload["event_time"],
        "detection_metadata": base_metadata,
    }
    initial_result = laravel_client.submit_guest_observation(
        base_payload,
        snapshot["bytes"],
        snapshot["filename"],
    )

    with state["lock"]:
        state["track_overlays"][track_id] = initial_result.get("overlay") or state["track_overlays"].get(track_id) or default_overlay()
        remember_recent_resolution_locked(state, track_id, window_payload.get("live_xyxy", window_payload["xyxy"]), state["track_overlays"][track_id], time.monotonic())

        if initial_result.get("accepted"):
            if initial_result.get("created"):
                state["crossings_logged"] += 1
            state["last_error"] = ""
        else:
            state["last_error"] = initial_result.get("message", "Unregistered visitor record could not be saved.")
            return

    if not analysis_frames:
        analysis_frames = [(snapshot_frame, window_payload["xyxy"])]

    vehicle_color, color_analysis = analyze_guest_vehicle_color(analysis_frames)

    if vehicle_color:
        color_payload = {
            **base_payload,
            "vehicle_image_path": (initial_result.get("body") or {}).get("snapshot_path"),
            "vehicle_color": vehicle_color,
            "detection_metadata": {
                **base_metadata,
                "analysis_status": "color_ready",
                "vehicle_color": vehicle_color,
                "color_analysis": color_analysis,
            },
        }
        color_result = laravel_client.submit_guest_observation(color_payload)

        with state["lock"]:
            state["track_overlays"][track_id] = color_result.get("overlay") or state["track_overlays"].get(track_id) or default_overlay()
            remember_recent_resolution_locked(state, track_id, window_payload.get("live_xyxy", window_payload["xyxy"]), state["track_overlays"][track_id], time.monotonic())

            if not color_result.get("accepted"):
                state["last_error"] = color_result.get("message", "Guest vehicle color could not be saved.")

    vote, detailed_vehicle_color, ocr_status, analysis_details, plate_crop = analyze_guest_vehicle_details(analysis_frames, window_payload["detected_vehicle_type"], plate_frames)
    plate_number = vote["plate"]
    vehicle_color = reconcile_guest_vehicle_color(vehicle_color, detailed_vehicle_color)

    print(
        f"{role.capitalize()} unregistered visitor analysis {window_payload['event_key']}: "
        f"plate={plate_number or 'unreadable'} (best guess {vote['best_guess'] or '-'}, {vote['reason']}) "
        f"color={vehicle_color or 'None'} "
        f"ocr_frames={analysis_details['frames_checked']} "
        f"ocr_elapsed={analysis_details['elapsed_seconds']}s",
        flush=True,
    )

    # Phase 5: the Unregistered Visitor record of this crossing gets the plate
    # (or "plate unreadable"), the plate image and the vote.
    submit_visitor_plate = getattr(laravel_client, "submit_visitor_plate", None)
    if submit_visitor_plate is not None:
        plate_image = encode_frame_snapshot(role, plate_crop, f"plate-{window_payload['event_key']}") if plate_crop is not None else None
        visitor_result = submit_visitor_plate(
            {
                "external_event_key": window_payload["event_key"],
                "camera_role": role,
                "event_time": window_payload["event_time"],
                "plate_number": plate_number,
                "plate_status": vote["status"],
                "plate_confidence": vote["confidence"],
                "best_guess": vote["best_guess"],
                "vehicle_color": vehicle_color,
                "detected_vehicle_type": window_payload["detected_vehicle_type"],
                "ocr_details": analysis_details,
            },
            plate_image["bytes"] if plate_image else None,
            plate_image["filename"] if plate_image else None,
        )
        if not visitor_result.get("accepted"):
            with state["lock"]:
                state["last_error"] = visitor_result.get("message", "Visitor plate could not be saved.")

    guest_payload = {
        **base_payload,
        "vehicle_image_path": (initial_result.get("body") or {}).get("snapshot_path"),
        "vehicle_color": vehicle_color,
        "plate_number": plate_number,
        "detection_metadata": {
            **base_metadata,
            "analysis_status": "complete",
            "plate_number": plate_number,
            "vehicle_color": vehicle_color,
            "ocr_runtime": ocr_status,
            "color_analysis": color_analysis,
            "analysis_details": analysis_details,
        },
    }
    result = laravel_client.submit_guest_observation(guest_payload)

    with state["lock"]:
        state["track_overlays"][track_id] = result.get("overlay") or state["track_overlays"].get(track_id) or default_overlay()
        remember_recent_resolution_locked(state, track_id, window_payload.get("live_xyxy", window_payload["xyxy"]), state["track_overlays"][track_id], time.monotonic())

        if not result.get("accepted"):
            state["last_error"] = result.get("message", "Unregistered visitor record could not be saved.")


def update_detection_windows(role, frame, results, state, laravel_client):
    """
    Keep each open pass's latest box, frames for the plate and type, and its
    clearest picture (the vehicle at its largest), while the workers handle
    the API I/O.
    """
    visible_boxes = current_track_boxes(results)
    now_monotonic = time.monotonic()

    with state["lock"]:
        # A track ID the tracker gave the vehicle later counts for its pass.
        for track_id, key in state.get("pass_alias", {}).items():
            if track_id in visible_boxes and key not in visible_boxes:
                visible_boxes[key] = visible_boxes[track_id]

        for track_id, window in list(state["pending_windows"].items()):
            visible_box = visible_boxes.get(track_id)
            if not visible_box or window.get("left_zone_at"):
                if window.get("snapshot_frame") is None:
                    window["snapshot_frame"] = frame.copy()
                continue

            x1, y1, x2, y2 = visible_box["xyxy"]
            window["xyxy"] = visible_box["xyxy"]
            window["confidence"] = visible_box["confidence"]
            window["snapshot_frame"] = frame.copy()
            area = float((x2 - x1) * (y2 - y1))
            # Phase 5: the best picture of the pass: big, the whole vehicle in
            # the picture, and sharp (not the biggest box, often cut off at the
            # edge or blurred by speed).
            score = picture_score(frame, visible_box["xyxy"])
            # Its speed in the live frame (pixels per second), to find it in the main stream's frames.
            previous = window.get("last_box_at")
            if previous and now_monotonic > previous[1]:
                elapsed = now_monotonic - previous[1]
                window["velocity"] = ((x1 - previous[0][0]) / elapsed, (y2 - previous[0][3]) / elapsed)
            window["last_box_at"] = (tuple(visible_box["xyxy"]), now_monotonic)
            if score > float(window.get("best_score") or 0.0):
                window.update(best_frame=frame.copy(), best_xyxy=tuple(visible_box["xyxy"]), best_area=area,
                              best_score=score, best_at=now_monotonic)
                # The full-resolution frame of this very moment, taken now (the
                # ring buffer only keeps the last second).
                window["best_hires"] = sharpest_hires_frame(role, now_monotonic, visible_box["xyxy"], frame.shape, window.get("velocity", (0.0, 0.0)))

            if now_monotonic - float(window.get("last_analysis_frame_at", 0.0)) >= GUEST_ANALYSIS_FRAME_INTERVAL_SECONDS:
                analysis_frames = window.setdefault("analysis_frames", [])
                analysis_frames.append((frame.copy(), tuple(visible_box["xyxy"])))
                del analysis_frames[:-MAX_GUEST_ANALYSIS_FRAMES]
                window["last_analysis_frame_at"] = now_monotonic
                # Phase 5: the full-resolution frame of the same moment, for
                # plate voting over several sharp frames (the grabber only
                # keeps the last ~1.6 s, so it is taken now).
                hires_frame = HIRES[role].frame_near(now_monotonic) if role in HIRES else None
                if hires_frame is not None:
                    hires_frames = window.setdefault("hires_frames", [])
                    hires_frames.append((hires_frame, scale_box(visible_box["xyxy"], frame.shape, hires_frame.shape)))
                    del hires_frames[:-MAX_GUEST_ANALYSIS_FRAMES]

            if now_monotonic - float(window.get("last_plate_frame_at", 0.0)) >= PLATE_FRAME_INTERVAL_SECONDS:
                window["last_plate_frame_at"] = now_monotonic
                keep_plate_frame_locked(role, window, frame, visible_box["xyxy"], now_monotonic)


def keep_plate_frame_locked(role, window, frame, xyxy, now_monotonic):
    """
    Gate direction work, Phase B: one more frame of the plate burst (the main
    stream's frame of this moment when it is open, else the live frame). The
    MAX_PLATE_FRAMES biggest views of the vehicle are kept.
    """
    source = HIRES[role].frame_near(now_monotonic) if role in HIRES else None
    if source is not None:
        entry = (source, scale_box(xyxy, frame.shape, source.shape))
    else:
        entry = (frame.copy(), tuple(xyxy))
    box = entry[1]
    area = float((box[2] - box[0]) * (box[3] - box[1])) / float(entry[0].shape[0] * entry[0].shape[1])
    frames = window.setdefault("plate_frames", [])
    frames.append((area, now_monotonic, entry))
    frames.sort(key=lambda item: item[0], reverse=True)
    del frames[MAX_PLATE_FRAMES:]


def plate_frames_locked(window):
    """The plate burst of a pass, biggest view first, with the best moment's full-resolution frame first of all."""
    frames = [entry for _, _, entry in window.get("plate_frames", [])]
    best_hires = window.get("best_hires")
    if best_hires and all(frame is not best_hires[0] for frame, _ in frames):
        frames.insert(0, (best_hires[0], best_hires[1]))
    return frames


def remember_track_point(state, track_id, point):
    """
    Store the track's newest position; return its previous one when it is
    recent enough to trust (a lost-and-found track does not jump the line).
    """
    now_monotonic = time.monotonic()

    with state["lock"]:
        entry = state["track_points"].get(track_id)
        previous = None

        # A3: kept through a short gap (TRACK_MEMORY_SECONDS), like ByteTrack keeps the ID.
        if entry and now_monotonic - entry["last_seen"] <= TRACK_MEMORY_SECONDS:
            previous = entry["points"][-1]
        elif entry:
            entry["points"] = []

        entry = entry or {"points": []}
        entry["points"].append((float(point[0]), float(point[1])))
        del entry["points"][:-TRACK_TRAIL_POINTS]
        entry["last_seen"] = now_monotonic
        state["track_points"][track_id] = entry

        for stale_id, stale in list(state["track_points"].items()):
            if now_monotonic - stale["last_seen"] > max(TRACK_MEMORY_SECONDS * 1.5, 6.0):
                state["track_points"].pop(stale_id, None)
                state["confirmed_tracks"].pop(stale_id, None)

    return previous


def pass_settings(perf, camera_config):
    """
    ROI work: frames in the zone before a vehicle is Detected, missed frames
    before it Left zone, and the share of the zone's height it must move up
    or down before IN / OUT is sure (per gate: Settings > Advanced; default
    in config/monitoring.php).

    Phase 4: Left zone is a time (pass_lost_seconds) turned into detection
    frames at the busy rate, the rate YOLO runs at while a vehicle is in the
    zone (an older export may still give pass_lost_frames).
    """
    settings = {**PERFORMANCE_DEFAULTS, **(perf or {})}
    min_move = (camera_config or {}).get("pass_min_move")
    if min_move in (None, ""):
        min_move = settings["pass_min_move"]
    try:
        min_move = float(min_move)
    except (TypeError, ValueError):
        min_move = float(PERFORMANCE_DEFAULTS["pass_min_move"])
    if settings.get("pass_lost_seconds") not in (None, ""):
        lost_frames = round(float(settings["pass_lost_seconds"]) * busy_detection_fps(settings))
    else:
        lost_frames = int(settings.get("pass_lost_frames") or 6)
    return (
        max(1, int(settings["pass_min_frames"])),
        max(2, lost_frames),
        min(0.9, max(0.02, min_move)),
    )


def busy_detection_fps(perf):
    """Phase 4: YOLO runs per second while something moves in the zone or a vehicle is in it."""
    return max(0.5, float((perf or {}).get("detection_fps") or PERFORMANCE_DEFAULTS["detection_fps"]))


def idle_detection_fps(perf):
    """Phase 4: YOLO runs per second while the zone is quiet (never more than the busy rate)."""
    idle = (perf or {}).get("detection_idle_fps")
    idle = float(PERFORMANCE_DEFAULTS["detection_idle_fps"] if idle in (None, "") else idle)
    return max(0.5, min(idle, busy_detection_fps(perf)))


# Busy for this long after the last movement in the zone.
MOTION_HOLD_SECONDS = 1.0


def note_motion(state, frame, camera_config, now):
    """Phase 4: called for every captured frame; remembers when something last moved in the zone."""
    gate = state.setdefault("motion_gate", MotionGate())
    if gate.moved(frame, camera_config.get("calibration_mask"), now):
        state["motion_at"] = now


def detection_busy(state, now):
    """Something moved in the zone just now, or a vehicle is in it (an open pass)."""
    return now - float(state.get("motion_at") or -1e9) <= MOTION_HOLD_SECONDS or bool(state.get("passes"))


def detection_interval(state, perf, now):
    """Phase 4: seconds between YOLO runs: busy rate, or idle rate while the zone is quiet."""
    return 1.0 / (busy_detection_fps(perf) if detection_busy(state, now) else idle_detection_fps(perf))


# Pass keys never collide with the tracker's IDs.
PASS_KEY_START = 1_000_000


def box_area(xyxy):
    return max(0.0, xyxy[2] - xyxy[0]) * max(0.0, xyxy[3] - xyxy[1])


def similar_size(left, right, ratio=0.5):
    """Phase 4: the same vehicle has about the same box (a car behind it is smaller)."""
    small, large = sorted((box_area(left), box_area(right)))
    return large > 0 and small / large >= ratio


def boxes_overlap_mostly(left, right):
    """
    YOLO's second box on the same vehicle: the boxes nearly coincide (IoU
    >= 0.5), or one is mostly inside the other. (A car close behind another
    may be swallowed this way while both are in view; when the front car
    leaves and the tracker hands its ID to the one behind,
    another_vehicle_took_the_id starts that car's own pass.)
    """
    x1, y1 = max(left[0], right[0]), max(left[1], right[1])
    x2, y2 = min(left[2], right[2]), min(left[3], right[3])
    inside = max(0.0, x2 - x1) * max(0.0, y2 - y1)
    union = box_area(left) + box_area(right) - inside
    smaller = min(box_area(left), box_area(right))
    if union <= 0 or smaller <= 0:
        return False
    return inside / union >= 0.5 or inside / smaller >= 0.7


def a_slice_of(part, whole):
    """
    Phase 4: a post or a person in front of a vehicle splits it in two: the
    tracker follows one part and YOLO boxes the other part on its own. That
    part is beside (or on) the vehicle's box, at the same height, with its
    bottom level with the vehicle's, and at most half as wide. Two vehicles
    side by side are about as wide as each other, so they are never joined.
    """
    part_w, whole_w = part[2] - part[0], whole[2] - whole[0]
    part_h, whole_h = part[3] - part[1], whole[3] - whole[1]
    if part_w <= 0 or whole_w <= 0 or part_w > 0.5 * whole_w:
        return False
    gap = max(part[0] - whole[2], whole[0] - part[2], 0.0)
    level = abs(part[3] - whole[3]) <= 0.15 * max(part_h, whole_h)
    rows = max(0.0, min(part[3], whole[3]) - max(part[1], whole[1])) >= 0.7 * part_h
    # The gap is what hides the rest of the vehicle: up to about a third of its width.
    return gap <= max(0.5 * part_w, 0.3 * whole_w) and level and rows


def reappears_on_its_path(vehicle_pass, xyxy):
    """
    Phase 4: a vehicle hidden for a moment (a person or a post in front of
    it) shows up again with a new track ID where it would be by now: its
    ground point near the one predicted from its speed, and about as big.
    """
    points = vehicle_pass.points
    last = getattr(vehicle_pass, "last_xyxy", None)
    if last is None or len(points) < 2:
        return False
    recent = points[-4:]
    step_x = (recent[-1][0] - recent[0][0]) / (len(recent) - 1)
    step_y = (recent[-1][1] - recent[0][1]) / (len(recent) - 1)
    frames = vehicle_pass.missed + 1
    predicted = (recent[-1][0] + step_x * frames, recent[-1][1] + step_y * frames)
    reach = max(30.0, 0.6 * (last[3] - last[1]), 0.5 * float(np.hypot(step_x, step_y)) * frames)
    if similar_size(xyxy, last, 0.4):
        here = bbox_bottom_center(xyxy)
        return float(np.hypot(here[0] - predicted[0], here[1] - predicted[1])) <= reach
    # Still partly hidden: only the visible part has a box. Its bottom is
    # where the vehicle's would be, and it lies within the vehicle's width.
    width = last[2] - last[0]
    left = last[0] + step_x * frames
    overlap = max(0.0, min(xyxy[2], left + width) - max(xyxy[0], left))
    return (
        box_area(xyxy) < box_area(last) * 1.5
        and abs(xyxy[3] - predicted[1]) <= reach
        and overlap >= 0.5 * max(1.0, xyxy[2] - xyxy[0])
    )


def adopt_pass_locked(state, track_id, xyxy, seen_keys=()):
    """
    One vehicle = one pass. A new track continues an open pass when:
    - the tracker gave the vehicle a new ID where that pass's vehicle just
      disappeared (missed this frame or the last ones), or
    - it is a second box YOLO put on the same vehicle (mostly overlapping
      the pass's box in this very frame).
    Returns that pass's key, or None.
    """
    for key, vehicle_pass in state["passes"].items():
        last = getattr(vehicle_pass, "last_xyxy", None)
        if last is None:
            continue
        if key in seen_keys:
            if boxes_overlap_mostly(xyxy, last) or a_slice_of(xyxy, last):
                return key
        elif vehicle_pass.missed > 0 and (bboxes_look_like_same_vehicle(xyxy, last) or reappears_on_its_path(vehicle_pass, xyxy)):
            return key
    return None


def another_vehicle_took_the_id(vehicle_pass, xyxy, mask_polygon):
    """
    Phase 4: the same track ID, but clearly another vehicle: the box jumped
    back against the way the vehicle was moving and got much smaller (the
    car in front left the picture; the tracker moved its ID to the car
    behind).
    """
    points = vehicle_pass.points
    last = getattr(vehicle_pass, "last_xyxy", None)
    if last is None or len(points) < 2:
        return False
    # The way it was moving over its last frames (its bottom may have stopped
    # at the edge of the picture for the last few).
    recent = points[-10:]
    step = (recent[-1][1] - recent[0][1]) / (len(recent) - 1)
    jump = bbox_bottom_center(xyxy)[1] - recent[-1][1]
    zone = zone_height(mask_polygon) or 1.0
    moving = abs(step) >= 0.005 * zone
    went_back = moving and jump * step < 0 and abs(jump) >= max(30.0, 0.08 * zone)
    if went_back and box_area(xyxy) <= 0.85 * box_area(last):
        return True
    # A jump no vehicle makes between two checks (over a quarter of the zone
    # per check) to a box of another size: another object took the ID.
    return abs(jump) >= 0.25 * zone * (vehicle_pass.missed + 1) and not similar_size(xyxy, last, 0.5)


def merge_duplicate_passes_locked(state, seen_keys):
    """
    Phase 4: two open passes on the SAME vehicle in this frame (the tracker
    gave a briefly hidden vehicle a new ID, then found the old one again):
    keep one. The other is dropped only while it is not Recorded yet (a
    record already sent stays); the one whose RFID check found the tag is
    kept.
    """
    keys = sorted(key for key in seen_keys if key in state["passes"])
    for index, older in enumerate(keys):
        for younger in keys[index + 1:]:
            if older not in state["passes"] or younger not in state["passes"]:
                continue
            a, b = state["passes"][older], state["passes"][younger]
            if not boxes_overlap_mostly(a.last_xyxy, b.last_xyxy):
                continue
            windows = state["pending_windows"]
            recorded = {key: bool((windows.get(key) or {}).get("recorded_at")) for key in (older, younger)}
            matched = {key: (windows.get(key) or {}).get("rfid_status") == "matched" for key in (older, younger)}
            if recorded[older] and recorded[younger]:
                continue
            drop, keep = (younger, older)
            if recorded[younger] or (matched[younger] and not matched[older]):
                drop, keep = (older, younger)
            if recorded[drop]:
                continue
            for alias, target in list(state["pass_alias"].items()):
                if target == drop:
                    state["pass_alias"][alias] = keep
            state["passes"].pop(drop, None)
            if windows.pop(drop, None) is not None:
                state["passes_detected"] = max(0, int(state.get("passes_detected", 0)) - 1)
            print(f"pass {drop}: same vehicle as pass {keep}, merged", flush=True)


def leave_zone_locked(state, key, vehicle_pass, mask_polygon, min_move, away_direction=None):
    """Left zone: the pass ends; an undecided one gets its final direction (UNKNOWN / STILL)."""
    state["passes"].pop(key, None)
    for alias, target in list(state["pass_alias"].items()):
        if target == key:
            state["pass_alias"].pop(alias, None)
    window = state["pending_windows"].get(key)
    if window is None or window.get("left_zone_at"):
        return
    if window.get("direction") is None:
        set_window_direction_locked(window, vehicle_pass.decide(mask_polygon, min_move, away_direction, final=True))
    window["left_zone_at"] = time.monotonic()


def process_results(role, frame, results, camera_config, state, laravel_client, vehicle_labels, perf=None):
    """
    ROI work: every vehicle in the zone is one pass (tracking.VehiclePass):

    - Detected after a few frames in the zone (detect_pass: RFID check starts);
    - its direction is decided as soon as it is clear it drives away from the
      camera or toward it (pass_worker then makes the record once the RFID
      window is over); the gate's setting says which of the two is IN;
    - Left zone when it is out of the zone, or not seen, for a few frames.
    """
    frame_height, frame_width = frame.shape[:2]
    mask_polygon = normalized_polygon_to_pixels(camera_config.get("calibration_mask"), frame_width, frame_height)
    # Which way is IN at this gate: a vehicle moving away from the camera is IN or OUT (None: not set).
    away_direction = normalized_away_direction(camera_config.get("away_direction"))
    min_frames, lost_frames, min_move = pass_settings(perf, camera_config)
    boxes = results.boxes if results is not None else None
    seen_keys = set()
    visible_roi_track_ids = set()
    active_detections = 0

    if mask_polygon and boxes is not None and boxes.id is not None:
        ids = boxes.id.int().cpu().tolist()
        classes = boxes.cls.int().cpu().tolist()
        confidences = boxes.conf.cpu().tolist()
        coordinates = boxes.xyxy.cpu().tolist()

        for track_id, class_id, confidence, xyxy in zip(ids, classes, confidences, coordinates):
            if class_id not in vehicle_labels:
                continue

            # The trail drawn in the debug view: where the vehicle touches the ground.
            remember_track_point(state, track_id, bbox_bottom_center(xyxy))

            if not bbox_inside_roi(xyxy, mask_polygon):
                continue

            visible_roi_track_ids.add(track_id)
            active_detections += 1
            now_monotonic = time.monotonic()

            with state["lock"]:
                key = state["pass_alias"].get(track_id)
                fresh = False
                if key in state["passes"] and key not in seen_keys and another_vehicle_took_the_id(state["passes"][key], xyxy, mask_polygon):
                    # Phase 4: the vehicle in front left and the tracker gave its
                    # ID to the one behind it: that vehicle is a new pass.
                    print(f"{role} pass track {key}: its track ID now follows another vehicle; Left zone", flush=True)
                    leave_zone_locked(state, key, state["passes"][key], mask_polygon, min_move, away_direction)
                    state["pass_alias"].pop(track_id, None)
                    key = None
                if key not in state["passes"]:
                    # A new pass (or this track's earlier pass ended): a fresh key,
                    # unless this is the vehicle of an open pass (new ID, second box).
                    key = adopt_pass_locked(state, track_id, xyxy, seen_keys)
                    if key is None:
                        state["pass_counter"] = state.get("pass_counter", PASS_KEY_START) + 1
                        key = state["pass_counter"]
                        state["passes"][key] = VehiclePass(xyxy, (frame_width, frame_height))
                        fresh = True
                    state["pass_alias"][track_id] = key
                vehicle_pass = state["passes"][key]
                if key in seen_keys:
                    # A second box on a vehicle already seen this frame: its label only.
                    state["track_last_seen"][track_id] = now_monotonic
                    if key in state["track_overlays"]:
                        state["track_overlays"][track_id] = state["track_overlays"][key]
                    continue
                if not fresh:
                    vehicle_pass.observe(xyxy)
                state["detections_seen"] += 1
                # A2: this frame's vote for the vehicle's type.
                state["track_votes"].setdefault(key, vtype.TrackVote()).add(
                    vehicle_labels[class_id], confidence, xyxy, (frame_width, frame_height)
                )
                state["track_last_seen"][track_id] = now_monotonic
                state["track_boxes"][track_id] = {
                    "class_id": class_id,
                    "confidence": confidence,
                    "xyxy": xyxy,
                    "last_seen": now_monotonic,
                }
                state["track_overlays"].setdefault(track_id, detection_overlay())

                vehicle_pass.last_xyxy = tuple(xyxy)
                seen_keys.add(key)

                if key in state["track_overlays"]:
                    # The pass's label ("checking RFID", registered, visitor) on the vehicle's box.
                    state["track_overlays"][track_id] = state["track_overlays"][key]

                detected_now = not vehicle_pass.opened and vehicle_pass.frames >= min_frames
                if detected_now:
                    vehicle_pass.opened = True

                window = state["pending_windows"].get(key)
                if window is not None and window.get("direction") is None:
                    decision = vehicle_pass.decide(mask_polygon, min_move, away_direction)
                    window["measure"] = decision
                    if decision["movement"]:
                        set_window_direction_locked(window, decision)

            if detected_now:
                detect_pass(role, frame, state, key, class_id, confidence, xyxy, camera_config, vehicle_labels, laravel_client, yolo_track_id=track_id)

    with state["lock"]:
        merge_duplicate_passes_locked(state, seen_keys)
        for key, vehicle_pass in list(state["passes"].items()):
            if key in seen_keys:
                continue
            # Out of the zone or not seen: Left zone after a few frames.
            if vehicle_pass.miss() >= lost_frames or not mask_polygon:
                leave_zone_locked(state, key, vehicle_pass, mask_polygon, min_move, away_direction)

    state["active_detections"] = active_detections
    update_detection_windows(role, frame, results, state, laravel_client)
    cleanup_tracks_outside_roi(state, visible_roi_track_ids)
    cleanup_stale_tracks(state)


def process_camera(role, camera_config, state, model_info, laravel_client):
    """
    Capture, detect, track, and submit one camera frame.
    """
    capture, capture_source = ensure_capture(camera_config, state)

    if capture is None or not capture.isOpened():
        release_capture(state)
        state["camera_running"] = False
        state["detection_ready"] = False
        state["retry_count"] = state["backoff"].failures
        state["last_error"] = camera_open_error(camera_config, capture_source, state)
        publish_status_frame(role, "Camera source unavailable", state["last_error"])
        return False

    has_frame, frame = read_fresh_frame(capture)

    if not has_frame or frame is None:
        release_capture(state)
        state["camera_running"] = False
        state["detection_ready"] = False
        state["retry_count"] += 1
        state["last_error"] = "Camera opened, but frame capture failed."
        publish_status_frame(role, "Frame capture failed", state["last_error"])
        return False

    state["camera_running"] = True
    state["last_capture_time"] = datetime.now().astimezone().isoformat()
    state["processed_frames"] += 1
    maybe_save_latest_frame(role, frame, state, time.monotonic())

    if not calibration_ready(camera_config):
        publish_stream_frame(role, frame)
        state["detection_ready"] = False
        state["retry_count"] = 0
        state["last_error"] = "A calibration zone (ROI) is required before automatic records start."
        return True

    vehicle_labels = model_info["vehicle_labels"]
    if not vehicle_labels:
        publish_stream_frame(role, frame)
        state["detection_ready"] = False
        state["retry_count"] = 0
        state["last_error"] = "The current detector model does not expose any supported vehicle classes."
        return True

    if state["processed_frames"] % DETECTION_FRAME_INTERVAL != 0:
        update_detection_windows(role, frame, None, state, laravel_client)
        live_frame = render_annotated_frame(role, frame, None, camera_config, state, vehicle_labels)
        publish_stream_frame(role, live_frame)
        return True

    preview_frame = render_annotated_frame(role, frame, None, camera_config, state, vehicle_labels)
    publish_stream_frame(role, preview_frame)

    try:
        results = model_info["model"].track(
            frame,
            persist=True,
            verbose=False,
            tracker=TRACKER_CONFIG,
            conf=DETECTION_CONFIDENCE_THRESHOLD,
            iou=DETECTION_IOU_THRESHOLD,
            classes=sorted(vehicle_labels.keys()),
            imgsz=YOLO_IMAGE_SIZE,
        )[0]
    except Exception as error:
        state["camera_running"] = False
        state["detection_ready"] = False
        state["retry_count"] += 1
        state["last_error"] = f"Detection failed: {error}"
        return False

    state["detection_ready"] = True
    state["retry_count"] = 0
    state["last_error"] = ""
    process_results(role, frame, results, camera_config, state, laravel_client, vehicle_labels)
    live_frame = render_annotated_frame(role, frame, results, camera_config, state, vehicle_labels)
    publish_stream_frame(role, live_frame)

    return True


def release_all(camera_states):
    """
    Release every open capture cleanly.
    """
    for state in camera_states.values():
        release_capture(state)


def build_models():
    """
    Keep one model instance per camera so tracker state does not mix across roles.
    """
    from ultralytics import YOLO

    detector_models = {}

    for role in camera_roles(load_runtime_config()):
        model = YOLO(MODEL_PATH)
        vehicle_labels = resolve_allowed_vehicle_classes(model)
        detector_models[role] = {
            "model": model,
            "vehicle_labels": vehicle_labels,
        }

    return detector_models


def limit_inference_threads():
    """
    Low latency: leave CPU cores free for video decoding.

    PyTorch uses every core by default. On a laptop that starves the camera
    reader threads, decoding falls behind the camera, and the live view drifts
    seconds behind again. YOLO detection may get slightly slower; the live
    stream stays real-time.
    """
    try:
        import torch

        cores = os.cpu_count() or 4
        torch.set_num_threads(max(1, min(4, cores // 2)))
    except Exception:
        pass


def ensure_detector_model_loaded(role, state, model_info):
    """
    Load YOLO only when a station viewer actually needs live detection.
    """
    if model_info.get("model") is not None and model_info.get("vehicle_labels"):
        return True

    try:
        from ultralytics import YOLO

        limit_inference_threads()
        model = YOLO(MODEL_PATH)
        vehicle_labels = resolve_allowed_vehicle_classes(model)
    except Exception as error:
        state["detection_ready"] = False
        state["retry_count"] += 1
        state["last_error"] = f"{role.capitalize()} detector model could not be loaded: {error}"
        return False

    model_info["model"] = model
    model_info["vehicle_labels"] = vehicle_labels

    if not vehicle_labels:
        state["detection_ready"] = False
        state["retry_count"] = 0
        state["last_error"] = "The current detector model does not expose any supported vehicle classes."
        return False

    return True


def camera_stream_worker(role, state, model_info, stop_event):
    """
    Read and publish camera frames independently from YOLO inference.
    """
    while not stop_event.is_set():
        try:
            runtime_config = load_runtime_config()
            camera_config = runtime_config["cameras"][role]
            perf = performance_settings(runtime_config)

            # Phase 1: the camera is no longer released when no Station page is
            # open. Capture keeps running so vehicle detection never stops;
            # only the MJPEG publishing below is skipped without a viewer.
            # Live view work: the browser now plays the camera through go2rtc
            # (WebRTC); JPEG frames are made only while an MJPEG client
            # (the fallback) is connected.
            viewer_active = STREAM_CLIENTS.get(role, 0) > 0
            capture, capture_source = ensure_capture(camera_config, state)

            if capture is None or not capture.isOpened():
                release_capture(state)
                state["camera_running"] = False
                state["detection_ready"] = False
                state["retry_count"] = state["backoff"].failures
                state["last_error"] = camera_open_error(camera_config, capture_source, state)
                if viewer_active:
                    publish_status_frame(role, "Camera source unavailable", state["last_error"])
                success = False
            else:
                has_frame, frame = read_fresh_frame(capture)

                if not has_frame or frame is None:
                    release_capture(state)
                    state["camera_running"] = False
                    state["detection_ready"] = False
                    state["backoff"].failed(time.monotonic())
                    state["retry_count"] = state["backoff"].failures
                    state["open_problem"] = {"code": "no_frames", "message": "The camera connected but sent no picture. It reconnects by itself."}
                    state["last_error"] = state["open_problem"]["message"]
                    if viewer_active:
                        publish_status_frame(role, "Frame capture failed", state["last_error"])
                    success = False
                else:
                    now_monotonic = time.monotonic()
                    frame_time = getattr(capture, "frame_time", 0.0) or now_monotonic

                    if state["backoff"].failures:
                        print(f"{role}: camera connected again after {state['backoff'].failures} attempt(s).", flush=True)
                    state["backoff"].reset()
                    state["open_problem"] = None
                    state["retry_count"] = 0

                    with state["lock"]:
                        state["latest_frame_at"] = frame_time
                        state["camera_running"] = True
                        state["error_code"] = None
                        state["last_capture_time"] = datetime.now().astimezone().isoformat()
                        state["processed_frames"] += 1
                        state["latest_frame"] = frame
                        state["latest_camera_config"] = camera_config.copy()
                        state["latest_frame_version"] += 1
                        with metrics.timed(role, "save"):
                            maybe_save_latest_frame(role, frame, state, now_monotonic)
                    # Phase 4: cheap motion check in the zone (at most 10 times per second).
                    note_motion(state, frame, camera_config, now_monotonic)

                    vehicle_labels = model_info.get("vehicle_labels", {})

                    # Phase 1: drawing + JPEG encoding only happen while a
                    # Station/Calibration page is watching the stream.
                    if not calibration_ready(camera_config):
                        state["detection_ready"] = False
                        state["retry_count"] = 0
                        state["last_error"] = "A calibration zone (ROI) is required before automatic records start."
                        if viewer_active and publish_due(state, perf):
                            if perf.get("debug_overlay"):
                                frame = frame.copy()
                                draw_debug_overlay(frame, camera_config, state, "No detection: draw the zone in Calibration.")
                            publish_stream_frame(role, frame, frame_time, perf)
                    elif not vehicle_labels:
                        state["detection_ready"] = False
                        state["retry_count"] = 0
                        state["last_error"] = (
                            "Detector model is loading."
                            if model_info.get("model") is None
                            else "The current detector model does not expose any supported vehicle classes."
                        )
                        if viewer_active and publish_due(state, perf):
                            publish_stream_frame(role, frame, frame_time, perf)
                    else:
                        refresh_pending_window_snapshots(frame, state)
                        # The live view gets the newest frame plus the last
                        # detection boxes, at its own rate; it never waits for YOLO.
                        if viewer_active and publish_due(state, perf):
                            with metrics.timed(role, "overlay"):
                                live_frame = render_annotated_frame(role, frame, None, camera_config, state, vehicle_labels)
                            publish_stream_frame(role, live_frame, frame_time, perf)

                    success = True
        except Exception as error:
            state["camera_running"] = False
            state["detection_ready"] = False
            state["backoff"].failed(time.monotonic())
            state["retry_count"] = state["backoff"].failures
            state["last_error"] = f"{role.capitalize()} stream worker error: {error}"
            publish_status_frame(role, "Stream worker error", state["last_error"])
            success = False

        # Low latency: read_latest() already waits for the next camera frame, so
        # the old fixed 0.04s sleep would only add delay after every frame.
        if success and (isinstance(state.get("capture"), LatestFrameReader) or getattr(state.get("capture"), "waits_for_frames", False)):
            delay = 0
        elif success:
            delay = CAPTURE_INTERVAL_SECONDS
        else:
            # A1: wake up when the next attempt is due (status stays fresh).
            delay = max(0.2, min(RECONNECT_DELAY_SECONDS, state["backoff"].seconds_left(time.monotonic())))
        stop_event.wait(delay)

    release_capture(state)


def detection_due(state, now, interval):
    """
    Phase 4: is a YOLO run due? The schedule adds up (next = last due +
    interval), so a 25 or 30 fps camera gets the set rate on average; before,
    "interval since the last run" rounded it down to whole frames (15 per
    second ran 12.5 times on a 25 fps camera, 8 ran 7.5 times on 30 fps).
    Busy again after a quiet spell: due at once, not after the idle wait.
    """
    due = state.get("detection_due_at")
    if due is None or now - due > interval or due - now > interval:
        due = now
    if now + 1e-6 < due:
        return False
    state["detection_due_at"] = due + interval
    state["last_detection_at"] = now
    return True


def next_detection_frame(state, min_interval=None):
    """
    Return the newest frame when detection is due (its own rate, detection_fps),
    skipping frames in between. The live view never waits for this.
    """
    with state["lock"]:
        latest_frame = state.get("latest_frame")
        camera_config = state.get("latest_camera_config")
        latest_frame_version = int(state.get("latest_frame_version", 0))
        last_detected_frame_version = int(state.get("last_detected_frame_version", 0))

        if latest_frame is None or camera_config is None:
            return None, None

        if min_interval is None:
            if latest_frame_version - last_detected_frame_version < DETECTION_FRAME_INTERVAL:
                return None, None
        elif latest_frame_version == last_detected_frame_version or \
                not detection_due(state, time.monotonic(), min_interval):
            return None, None

        state["last_detected_frame_version"] = latest_frame_version
        state["last_detection_at"] = time.monotonic()
        return latest_frame.copy(), camera_config.copy()


_YOLO_DEVICE = {"resolved": None}


def yolo_device(setting):
    """
    auto: NVIDIA GPU (cuda), then Apple Silicon GPU (mps), else CPU (e.g. Windows without CUDA).
    """
    if setting and setting != "auto":
        return setting
    if _YOLO_DEVICE["resolved"] is None:
        device = "cpu"
        try:
            import torch

            if torch.cuda.is_available():
                device = "cuda:0"
            elif getattr(torch.backends, "mps", None) and torch.backends.mps.is_available():
                device = "mps"
        except Exception:
            pass
        _YOLO_DEVICE["resolved"] = device
        print(f"YOLO device: {device}", flush=True)
    return _YOLO_DEVICE["resolved"]


def roi_crop_box(camera_config, frame, enabled):
    """
    Pixel box around the calibrated zone (+8% margin) when it is clearly
    smaller than the frame, else None. YOLO then sees the zone at a higher
    effective resolution and does less work.
    """
    if not enabled:
        return None
    height, width = frame.shape[:2]
    polygon = normalized_polygon_to_pixels(camera_config.get("calibration_mask"), width, height)
    if not polygon:
        return None
    xs = [point[0] for point in polygon]
    ys = [point[1] for point in polygon]
    margin_x, margin_y = int(width * 0.08), int(height * 0.08)
    x1, y1 = max(0, int(min(xs)) - margin_x), max(0, int(min(ys)) - margin_y)
    x2, y2 = min(width, int(max(xs)) + margin_x), min(height, int(max(ys)) + margin_y)
    if x2 - x1 < 32 or y2 - y1 < 32 or (x2 - x1) * (y2 - y1) > 0.8 * width * height:
        return None
    return x1, y1, x2, y2


def offset_results(results, x1, y1, full_shape=None):
    """
    Move boxes found in a crop back to full-frame pixel coordinates.

    YOLO returns inference-mode tensors that cannot be changed in place: the
    old in-place "+=" raised on EVERY frame with a vehicle, and that error
    was taken for a GPU failure. The boxes are rebuilt from a copy instead.
    """
    boxes = getattr(results, "boxes", None)
    if boxes is None or boxes.data is None or len(boxes.data) == 0:
        return results
    from ultralytics.engine.results import Boxes

    data = boxes.data.clone()
    data[:, [0, 2]] += x1
    data[:, [1, 3]] += y1
    shape = tuple(full_shape[:2]) if full_shape is not None else tuple(boxes.orig_shape)
    results.boxes = Boxes(data, shape)
    results.orig_shape = shape
    return results


def split_raw_detections(results, state, vehicle_labels):
    """
    YOLO reports every class down to RAW_CONFIDENCE_FLOOR. Keep vehicle
    classes at DETECTION_CONFIDENCE_THRESHOLD, or lower for a track that
    already passed it (one weak frame does not drop a vehicle). Returns the
    raw list for the debug view; `results.boxes` keeps only the vehicles.
    """
    boxes = getattr(results, "boxes", None)
    if boxes is None or boxes.data is None or len(boxes.data) == 0:
        return []
    from ultralytics.engine.results import Boxes

    names = getattr(results, "names", {}) or {}
    ids = boxes.id.int().cpu().tolist() if boxes.id is not None else [None] * len(boxes)
    classes = boxes.cls.int().cpu().tolist()
    confidences = boxes.conf.cpu().tolist()
    coordinates = boxes.xyxy.cpu().tolist()
    now_monotonic = time.monotonic()
    raw, keep = [], []

    with state["lock"]:
        confirmed = state["confirmed_tracks"]
        for index, (track_id, class_id, confidence, xyxy) in enumerate(zip(ids, classes, confidences, coordinates)):
            vehicle = class_id in vehicle_labels
            if vehicle and confidence >= DETECTION_CONFIDENCE_THRESHOLD and track_id is not None:
                confirmed[track_id] = now_monotonic
            kept = vehicle and (confidence >= DETECTION_CONFIDENCE_THRESHOLD or track_id in confirmed)
            if kept:
                keep.append(index)
            raw.append({
                "track_id": track_id,
                "class_id": class_id,
                "name": str(names.get(class_id, class_id)),
                "confidence": round(float(confidence), 3),
                "xyxy": [round(float(value), 1) for value in xyxy],
                "kept": kept,
                "reason": "vehicle" if kept else ("low confidence" if vehicle else "not a vehicle"),
            })

    if len(keep) != len(boxes.data):
        results.boxes = Boxes(boxes.data[keep].clone(), tuple(boxes.orig_shape))
    return raw


def vehicle_in_zone(results, camera_config, frame, vehicle_labels):
    boxes = getattr(results, "boxes", None)
    if boxes is None or boxes.cls is None or len(boxes) == 0:
        return False
    mask = normalized_polygon_to_pixels(camera_config.get("calibration_mask"), frame.shape[1], frame.shape[0])
    for class_id, xyxy in zip(boxes.cls.int().cpu().tolist(), boxes.xyxy.cpu().tolist()):
        if class_id in vehicle_labels and bbox_inside_roi(xyxy, mask):
            return True
    return False


def reset_tracker(model_info):
    """Drop ByteTrack state (new track ids from here on)."""
    model = model_info.get("model")
    predictor = getattr(model, "predictor", None)
    if predictor is not None and getattr(predictor, "trackers", None):
        for tracker in predictor.trackers:
            try:
                tracker.reset()
            except Exception:
                pass


def yolo_input_size(perf, device):
    """
    Phase 4: YOLO input size. "auto": 640 on a GPU (small, far vehicles are
    found in more frames; about 15 ms per run on an Apple M2), 480 on a CPU
    (640 takes about 40 ms there: two busy gates could not run 15 times a
    second). A number is used as it is.
    """
    value = str((perf or {}).get("yolo_imgsz", "auto")).strip().lower()
    if value.isdigit():
        return int(value)
    return 640 if device in ("cuda", "mps") or str(device).startswith("cuda") else 480


def hires_wanted(state, results, camera_config, frame, vehicle_labels, now=None):
    """
    Phase 5: keep the main stream open while something moves in the zone or
    a vehicle is in it, so its frames are there from the first moment (it
    takes about a second to open).
    """
    now = time.monotonic() if now is None else now
    return detection_busy(state, now) or vehicle_in_zone(results, camera_config, frame, vehicle_labels)


def run_yolo(role, model_info, frame, camera_config, perf, state):
    """
    YOLO + ByteTrack on the frame (or on the calibrated zone). Raises only
    when the model itself fails, so a GPU problem can fall back to CPU.
    Returns (results in full-frame pixels, crop box or None, input shape).
    """
    crop = roi_crop_box(camera_config, frame, perf["roi_crop"])
    # The tracker must always see the same kind of input: when the zone
    # (or the crop decision) changes, start tracking fresh.
    crop_key = (crop, frame.shape[:2])
    if state.get("crop_key") != crop_key:
        if state.get("crop_key") is not None:
            reset_tracker(model_info)
        state["crop_key"] = crop_key
    detect_input = frame if crop is None else frame[crop[1]:crop[3], crop[0]:crop[2]]
    device = yolo_device(perf["yolo_device"])
    with metrics.timed(role, "yolo"):
        results = model_info["model"].track(
            detect_input,
            persist=True,
            verbose=False,
            tracker=TRACKER_CONFIG,
            conf=RAW_CONFIDENCE_FLOOR,
            iou=DETECTION_IOU_THRESHOLD,
            imgsz=yolo_input_size(perf, device),
            device=device,
        )[0]
    metrics.value(role, "yolo_device", device)
    metrics.value(role, "yolo_input", f"{detect_input.shape[1]}x{detect_input.shape[0]}@{yolo_input_size(perf, device)}")
    return results, crop, detect_input.shape, device


def handle_detection(role, frame, results, crop, camera_config, perf, state, laravel_client, vehicle_labels, device=None):
    """
    Everything after YOLO, shared by the live worker and the video test:
    crop offset, vehicle filter, zone passes (Detected / Left zone), debug counters.
    """
    if crop is not None:
        results = offset_results(results, crop[0], crop[1], frame.shape)
    raw = split_raw_detections(results, state, vehicle_labels)

    with metrics.timed(role, "process"):
        process_results(role, frame, results, camera_config, state, laravel_client, vehicle_labels, perf)

    now_monotonic = time.monotonic()
    with state["lock"]:
        times = state["detection_times"]
        times.append(now_monotonic)
        del times[:-40]
        recent = [value for value in times if now_monotonic - value <= 5.0]
        fps = (len(recent) - 1) / (recent[-1] - recent[0]) if len(recent) > 1 and recent[-1] > recent[0] else 0.0
        state["debug"] = {
            "at": now_monotonic,
            "frame_size": [int(frame.shape[1]), int(frame.shape[0])],
            "crop": list(crop) if crop is not None else None,
            "raw": raw,
            "raw_count": len(raw),
            "vehicle_count": sum(1 for item in raw if item["kept"]),
            "in_roi": int(state.get("active_detections", 0)),
            "passes_detected": int(state["passes_detected"]),
            "passes_recorded": int(state["passes_recorded"]),
            "detection_fps": round(fps, 1),
            "device": device,
            "imgsz": yolo_input_size(perf, device),
            "tracks": {
                str(track_id): [list(point) for point in entry["points"]]
                for track_id, entry in state["track_points"].items()
                if now_monotonic - entry["last_seen"] <= TRACK_STALE_AFTER_SECONDS
            },
        }
    return results


def log_detection_error(state, message):
    """Detection errors go to the log (at most once a minute per message), not only the status."""
    now_monotonic = time.monotonic()
    last = state.get("logged_errors", {})
    if now_monotonic - last.get(message, -1e9) >= 60:
        print(f"Detection error: {message}", flush=True)
        last[message] = now_monotonic
        state["logged_errors"] = last


def camera_detection_worker(role, state, model_info, stop_event):
    """
    Run YOLO tracking in a separate worker so live MJPEG publishing stays smooth.
    """
    while not stop_event.is_set():
        # Phase 1: removed the "no Station page open" gate. Detection runs
        # whenever the detector is on.
        perf = performance_settings(load_runtime_config())
        state["debug_enabled"] = bool(perf.get("debug_overlay"))
        # Phase 4: busy rate while something moves in the zone or a vehicle is in it, idle rate otherwise.
        frame, camera_config = next_detection_frame(state, detection_interval(state, perf, time.monotonic()))

        if frame is None:
            stop_event.wait(0.01)
            continue

        if not calibration_ready(camera_config):
            stop_event.wait(CAPTURE_INTERVAL_SECONDS)
            continue

        if not ensure_detector_model_loaded(role, state, model_info):
            stop_event.wait(RECONNECT_DELAY_SECONDS)
            continue

        vehicle_labels = model_info.get("vehicle_labels", {})
        runtime_config = load_runtime_config()
        laravel_client = LaravelEventClient(runtime_config)

        with state["lock"]:
            frame_time = state.get("latest_frame_at") or time.monotonic()
        try:
            results, crop, _shape, device = run_yolo(role, model_info, frame, camera_config, perf, state)
        except Exception as error:
            device = _YOLO_DEVICE["resolved"]
            if device not in (None, "cpu") and perf["yolo_device"] == "auto":
                # A GPU backend that fails (driver, unsupported op) falls back to CPU.
                print(f"YOLO on {device} failed ({error}); using CPU.", flush=True)
                _YOLO_DEVICE["resolved"] = "cpu"
                reset_tracker(model_info)
                continue
            state["detection_ready"] = False
            state["retry_count"] += 1
            state["last_error"] = f"Detection failed: {error}"
            state["last_detection_error"] = state["last_error"]
            log_detection_error(state, state["last_error"])
            stop_event.wait(RECONNECT_DELAY_SECONDS)
            continue

        try:
            results = handle_detection(role, frame, results, crop, camera_config, perf, state, laravel_client, vehicle_labels, device)
        except Exception as error:
            # Never silent: the zone/line/event step failed for this frame.
            state["last_error"] = f"Detection processing failed: {error}"
            state["last_detection_error"] = state["last_error"]
            log_detection_error(state, state["last_error"])
            stop_event.wait(0.2)
            continue

        state["detection_ready"] = True
        state["retry_count"] = 0
        state["last_error"] = ""
        # A vehicle in the zone: have full-resolution frames ready for its snapshot.
        if perf["hires_on_trigger"] and camera_config.get("snapshot_source_value") and hires_wanted(state, results, camera_config, frame, vehicle_labels):
            hires_grabber(role, camera_config).trigger(camera_config)
        metrics.rate(role, "detection")
        metrics.timing(role, "detection_age", (time.monotonic() - frame_time) * 1000.0)


class _CombinedStop:
    """Stops a camera worker when the detector stops OR its gate is removed."""

    def __init__(self, *events):
        self.events = events

    def is_set(self):
        return any(event.is_set() for event in self.events)

    def wait(self, timeout=None):
        deadline = time.monotonic() + (timeout or 0)
        while not self.is_set():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                return False
            self.events[0].wait(min(remaining, 0.25))
        return True

    def set(self):
        for event in self.events:
            event.set()


# The code this process loaded, and when it started.
RUNNING_CODE = {"version": code_version(), "stamp": code_stamp(), "started_at": datetime.now().astimezone().isoformat()}
CODE_CHECK_INTERVAL_SECONDS = 5.0


def code_changed_on_disk():
    """
    The detector's files were updated after it started (a new version was
    installed or pulled): True when their content really differs.
    """
    try:
        stamp = code_stamp()
        if stamp == RUNNING_CODE["stamp"]:
            return False
        RUNNING_CODE["stamp"] = stamp
        return code_version() != RUNNING_CODE["version"]
    except OSError:
        return False


def run_detector_loop():
    """
    Start the dual-camera vehicle detector until the user stops it, or
    until its code is updated on disk: then it stops so that the new code
    starts (Laravel starts it again; on Windows the service does).
    """
    ensure_output_directories()
    stream_server = start_stream_server()

    # Low latency: if the stream port is taken, another detector is already
    # running. Previously this copy kept running headless, reading the same
    # cameras and running YOLO again, which slowed the live view down.
    if stream_server is None and take_over_stale_detector(
        MJPEG_STREAM_PORT, STATUS_FILE_PATH, Path(__file__).resolve().parent, log=lambda message: print(message, flush=True)
    ):
        stream_server = start_stream_server()

    if stream_server is None:
        print("Another detector already owns the stream port. Exiting this duplicate.", flush=True)
        return

    runtime_config = load_runtime_config()
    camera_states = {}
    detector_models = {}
    write_status(
        runtime_config,
        camera_states,
        detector_models,
        service_running=False,
        service_message="Detector service is starting.",
    )

    try:
        stop_event = threading.Event()
        workers = []
        role_stops = {}

        def sync_camera_workers(runtime_config):
            """Phase 1: a worker pair per gate camera; added or removed gates follow."""
            roles = camera_roles(runtime_config)
            for role in roles:
                if role in role_stops:
                    continue
                camera_states[role] = initial_camera_state()
                OVERLAY_STATES[role] = camera_states[role]
                detector_models[role] = {"model": None, "vehicle_labels": {}}
                role_stop = threading.Event()
                role_stops[role] = role_stop
                combined = _CombinedStop(stop_event, role_stop)
                for target in (camera_stream_worker, camera_detection_worker):
                    worker = threading.Thread(target=target, args=(role, camera_states[role], detector_models[role], combined), daemon=True)
                    worker.start()
                    workers.append(worker)
                print(f"Camera worker started for {role}", flush=True)
            for role in [role for role in role_stops if role not in roles]:
                role_stops.pop(role).set()
                state = camera_states.pop(role, None)
                OVERLAY_STATES.pop(role, None)
                detector_models.pop(role, None)
                STREAM_FRAMES.pop(role, None)
                if state is not None:
                    release_capture(state)
                print(f"Camera worker stopped for {role} (gate removed)", flush=True)

        last_metrics_log = time.monotonic()
        last_code_check = time.monotonic()
        print(f"Detector code {RUNNING_CODE['version']} started.", flush=True)
        while True:
            if time.monotonic() - last_code_check >= CODE_CHECK_INTERVAL_SECONDS:
                last_code_check = time.monotonic()
                if code_changed_on_disk():
                    print(f"Detector code changed on disk (running {RUNNING_CODE['version']}, now {code_version()}): restarting to load it.", flush=True)
                    RUNNING_CODE["restarting"] = True
                    raise KeyboardInterrupt("code updated")
            runtime_config = load_runtime_config()
            sync_camera_workers(runtime_config)
            write_status(
                runtime_config,
                camera_states,
                detector_models,
                service_running=True,
                service_message="Dual-camera detector running.",
            )
            if time.monotonic() - last_metrics_log >= 30:
                last_metrics_log = time.monotonic()
                print("METRICS " + metrics.summary_line(metrics.snapshot(), metrics.cpu_percent()), flush=True)
            time.sleep(STATUS_WRITE_INTERVAL_SECONDS)
    except KeyboardInterrupt:
        if 'stop_event' in locals():
            stop_event.set()

        for worker in locals().get("workers", []):
            worker.join(timeout=2.0)

        release_all(camera_states)
        if stream_server is not None:
            stream_server.shutdown()
        write_status(
            runtime_config,
            camera_states,
            detector_models,
            service_running=False,
            service_message="Detector code updated: restarting." if RUNNING_CODE.get("restarting") else "Detector service stopped by user.",
        )
        print("Detector service stopped.")
    except Exception as error:
        if 'stop_event' in locals():
            stop_event.set()

        for role in list(camera_states):
            camera_states[role]["camera_running"] = False
            camera_states[role]["detection_ready"] = False
            camera_states[role]["retry_count"] += 1
            camera_states[role]["last_error"] = f"Detector service error: {error}"

        release_all(camera_states)
        if stream_server is not None:
            stream_server.shutdown()
        write_status(
            runtime_config,
            camera_states,
            detector_models,
            service_running=False,
            service_message=f"Detector service error: {error}",
        )
        raise


def main():
    import argparse

    parser = argparse.ArgumentParser(description="PHILCST dual-camera vehicle detector")
    parser.add_argument("--video", help="run the detection pipeline on a video file instead of the cameras")
    parser.add_argument("--role", default=None, help="gate code (default: the first gate)")
    parser.add_argument("--use-calibration", action="store_true", help="use the station's saved zone and direction")
    parser.add_argument("--roi", help='zone polygon, normalized: "x,y x,y x,y ..."')
    parser.add_argument("--away", choices=["IN", "OUT"], help="a vehicle moving away from the camera is IN or OUT at this gate")
    parser.add_argument("--min-move", type=float, help="share of the zone's height a vehicle must move up or down for a sure IN / OUT (e.g. 0.2)")
    parser.add_argument("--detection-fps", type=float)
    parser.add_argument("--imgsz", type=int)
    parser.add_argument("--post", action="store_true", help="send events and alerts to Laravel (default: dry run)")
    parser.add_argument("--app-url", help="with --post: send to this Laravel instead (e.g. a test copy)")
    parser.add_argument("--out", help="write the annotated video (debug overlay) here")
    parser.add_argument("--fast", action="store_true", help="do not wait for real time")
    args = parser.parse_args()

    if args.video:
        import video_test

        raise SystemExit(video_test.run(args))
    run_detector_loop()


if __name__ == "__main__":
    main()
