import json
import os
import platform
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
    RECONNECT_DELAY_SECONDS,
    RFID_DETECTION_WINDOW_SECONDS,
    RFID_LOOKBACK_SECONDS,
    RFID_MATCH_TIMEOUT_SECONDS,
    RFID_POLL_INTERVAL_SECONDS,
    SNAPSHOTS_DIR,
    STATION_ACTIVITY_PATH,
    STATION_VIEWER_IDLE_AFTER_SECONDS,
    STATUS_FILE_PATH,
    STATUS_WRITE_INTERVAL_SECONDS,
    STREAM_FRAME_MAX_WIDTH,
    TRACK_STALE_AFTER_SECONDS,
    TRACKER_CONFIG,
    YOLO_IMAGE_SIZE,
    annotated_frame_path,
    latest_frame_path,
    load_runtime_config,
    performance_settings,
    resolve_capture_source,
)
from laravel_client import LaravelEventClient
from tracking import (
    bbox_intersects_line,
    bbox_center,
    calibration_ready,
    crossed_line,
    normalized_line_to_pixels,
    normalized_polygon_to_pixels,
    point_in_polygon,
    point_side_of_line,
)
from anpr import detect_vehicle_color, ocr_runtime_status, read_license_plate
from camera_health import RtspDiagnosis, take_over_stale_detector
import metrics
from hires import HiResGrabber, scale_box

CAMERA_ROLES = ("entrance", "exit")
STREAM_FRAMES = {role: None for role in CAMERA_ROLES}
# When the frame behind each published JPEG was decoded (for latency metrics).
STREAM_FRAME_TIMES = {role: 0.0 for role in CAMERA_ROLES}
STREAM_CONDITION = threading.Condition()
# Open MJPEG connections per camera. Any page that shows the live view
# (Station, Gate Monitor, Calibration, Settings › Cameras) counts as a viewer.
STREAM_CLIENTS = {role: 0 for role in CAMERA_ROLES}
STREAM_CLIENTS_LOCK = threading.Lock()
RTSP_DIAGNOSIS = RtspDiagnosis()
# Full-resolution frames from the snapshot (main) stream, only around triggers.
HIRES = {}


def hires_grabber(role):
    if role not in HIRES:
        HIRES[role] = HiResGrabber(
            role,
            lambda camera_config: build_connection_source(camera_config, camera_config["snapshot_source_value"]),
        )
    return HIRES[role]
RESOLVED_OVERLAY_HOLD_SECONDS = 1.25
RESOLVED_DETECTION_COOLDOWN_SECONDS = 1.5
GUEST_TRACK_COOLDOWN_SECONDS = 10.0
DUPLICATE_TRACK_IOU_THRESHOLD = 0.18
DUPLICATE_TRACK_CENTER_DISTANCE_RATIO = 0.28
MAX_GUEST_ANALYSIS_FRAMES = 5
MAX_GUEST_OCR_ANALYSIS_FRAMES = 2
MAX_GUEST_COLOR_ANALYSIS_FRAMES = 3
GUEST_ANALYSIS_FRAME_INTERVAL_SECONDS = 0.45
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
        if len(role) != 2 or role[0] != "stream" or role[1] not in CAMERA_ROLES:
            self.send_error(404)
            return

        self.stream_role(role[1])

    def stream_role(self, role):
        self.send_response(200)
        self.send_header("Content-Type", "multipart/x-mixed-replace; boundary=frame")
        self.send_header("Cache-Control", "no-store, no-cache, must-revalidate, max-age=0")
        self.send_header("Pragma", "no-cache")
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()

        last_frame_id = None

        with STREAM_CLIENTS_LOCK:
            STREAM_CLIENTS[role] += 1
        try:
            self._send_frames(role, last_frame_id)
        finally:
            with STREAM_CLIENTS_LOCK:
                STREAM_CLIENTS[role] -= 1

    def _send_frames(self, role, last_frame_id):
        while True:
            with STREAM_CONDITION:
                STREAM_CONDITION.wait_for(
                    lambda: STREAM_FRAMES[role] is not None and id(STREAM_FRAMES[role]) != last_frame_id,
                    timeout=1.0,
                )
                frame = STREAM_FRAMES[role]

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

    for role in CAMERA_ROLES:
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


def publish_status_frame(role, title, detail):
    """
    Publish a simple diagnostic frame when a configured camera cannot provide
    live frames. This keeps station/calibration MJPEG clients connected.
    """
    frame = np.zeros((720, 1280, 3), dtype=np.uint8)
    frame[:, :] = (34, 25, 54)

    cv2.putText(
        frame,
        f"{role.upper()} CAMERA",
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
    for line in str(detail or "").split(". "):
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

        supported[int(class_id)] = display_vehicle_label(normalized_name)

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

    system_name = platform.system().lower()

    if system_name == "darwin" and hasattr(cv2, "CAP_AVFOUNDATION"):
        return cv2.VideoCapture(capture_source, cv2.CAP_AVFOUNDATION)

    if system_name == "windows" and hasattr(cv2, "CAP_DSHOW"):
        return cv2.VideoCapture(capture_source, cv2.CAP_DSHOW)

    return cv2.VideoCapture(capture_source)


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
    if state.get("source_validation_error"):
        state["error_code"] = "invalid_source"
        return state["source_validation_error"]

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

    state["error_code"] = "open_failed"
    return f"Could not open camera source: {capture_source}"


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
                if has_frame and frame is not None and self.role:
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
        metrics.timing(self.role, "read", (time.perf_counter() - started) * 1000.0)
        metrics.rate(self.role, "capture")
        try:
            position = float(self.capture.get(cv2.CAP_PROP_POS_MSEC))
        except Exception:
            position = 0.0
        if position > 0:
            if self._clock_start is None or position < self._clock_start[1]:
                self._clock_start = (now, position)
            backlog = (now - self._clock_start[0]) * 1000.0 - (position - self._clock_start[1])
            metrics.value(self.role, "decode_backlog_ms", round(backlog))
            if backlog > 1500 and self.decoder_threads == 1 and now - self._clock_start[0] > 3:
                self.too_slow = True
        metrics.value(self.role, "resolution", f"{frame.shape[1]}x{frame.shape[0]}")
        metrics.value(self.role, "decoder_threads", self.decoder_threads or "auto")
        self.frame_time = now

    def isOpened(self):
        return not self.failed and self.capture.isOpened()

    def read_latest(self, timeout=None):
        """
        Wait for a frame newer than the last one handed out, then return it.
        Frames that arrived in between are skipped on purpose.
        """
        if timeout is None:
            timeout = CAPTURE_FIRST_FRAME_SECONDS if self.sequence == 0 else CAPTURE_STALL_SECONDS
        deadline = time.monotonic() + timeout

        with self.condition:
            while self.sequence == self.consumed_sequence and not self.failed:
                remaining = deadline - time.monotonic()

                if remaining <= 0:
                    return False, None

                self.condition.wait(remaining)

            if self.sequence == self.consumed_sequence:
                return False, None

            self.consumed_sequence = self.sequence

            return True, self.frame

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
    capture = build_capture(camera_config["source_type"], connection_source, threads)

    try:
        capture.set(cv2.CAP_PROP_BUFFERSIZE, 1)
    except Exception:
        pass

    # Low latency: wrap the opened capture so a background thread always holds
    # the newest frame.
    if capture.isOpened():
        capture = LatestFrameReader(capture, camera_config.get("camera_role"), threads)

    return capture, capture_source


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
        "track_sides": {},
        "track_last_seen": {},
        "track_boxes": {},
        "crossed_track_ids": {},
        "processed_as_guest": {},
        "tracked_vehicles": {},
        "track_overlays": {},
        "pending_windows": {},
        "recent_resolutions": [],
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
    """
    signature = camera_signature(camera_config)
    now_monotonic = time.monotonic()
    capture_source = resolve_capture_source(camera_config)
    validation_error = validate_camera_source(camera_config, capture_source)

    if validation_error:
        release_capture(state)
        state["source_validation_error"] = validation_error
        state["retry_after"] = now_monotonic + CAMERA_RETRY_DELAY_SECONDS

        return None, capture_source

    state["source_validation_error"] = ""

    if state["capture"] is None and now_monotonic < state.get("retry_after", 0.0):
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
    capture, capture_source = open_capture(camera_config, state.get("decoder_threads_override", {}).get(signature))
    state["capture"] = capture
    state["signature"] = signature

    if not capture.isOpened():
        state["retry_after"] = now_monotonic + CAMERA_RETRY_DELAY_SECONDS

    return capture, capture_source


def read_fresh_frame(capture):
    """
    Drop queued camera frames before retrieving, reducing visible stream latency.
    """
    # Low latency: the background reader already discards old frames.
    if isinstance(capture, LatestFrameReader):
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

    for role in CAMERA_ROLES:
        camera_config = runtime_config["cameras"][role]
        state = camera_states[role]
        model_info = detector_models.get(role, {})

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
        }

    return payload


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
    Label when no registered tag or guest pass was read in the window.
    """
    return {
        "label": "NO PASS",
        "color": "red",
        "verification": "no_pass",
    }


# Overlays that are a final decision and stay on screen briefly after the box is lost.
RESOLVED_VERIFICATIONS = {"registered", "guest_pass", "pass_alert", "no_pass"}


def waiting_overlay():
    """
    Temporary label while the RFID detection window is still open.
    """
    return {
        "label": "CHECKING RFID...",
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
    Neutral label for vehicles YOLO sees before the RFID trigger window starts.
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

    return annotated


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


def start_detection_window(
    role,
    frame,
    state,
    track_id,
    class_id,
    confidence,
    xyxy,
    direction,
    camera_config,
    vehicle_labels,
    laravel_client,
):
    """
    Start one RFID matching window for a triggered vehicle.
    """
    now_monotonic = time.monotonic()
    event_key = f"{role}-track-{track_id}-{int(time.time() * 1000)}"
    event_time = datetime.now().astimezone().isoformat()
    display_label = vehicle_labels[class_id]

    with state["lock"]:
        tracked = ensure_tracked_vehicle_locked(state, track_id, now_monotonic)

        if tracked.get("status") in {"registered", "guest_pass", "no_pass", "processed"}:
            return

        tracked.update({
            "status": "checking",
            "event_key": event_key,
            "event_time": event_time,
        })
        state["pending_windows"][track_id] = {
            "event_key": event_key,
            "camera_role": role,
            "camera_id": camera_config.get("camera_id"),
            "track_id": track_id,
            "class_id": class_id,
            "detected_vehicle_type": display_label,
            "confidence": confidence,
            "xyxy": xyxy,
            "direction": direction,
            "event_time": event_time,
            "started_at": now_monotonic,
            "deadline_at": now_monotonic + RFID_DETECTION_WINDOW_SECONDS,
            "snapshot_frame": frame.copy(),
            "last_snapshot_refresh_at": now_monotonic,
            "analysis_frames": [(frame.copy(), tuple(xyxy))],
            "last_analysis_frame_at": now_monotonic,
            "last_message": "Waiting for RFID scan.",
        }
        state["track_overlays"][track_id] = waiting_overlay()

    worker = threading.Thread(
        target=rfid_detection_window_worker,
        args=(role, state, track_id, laravel_client),
        daemon=True,
    )
    worker.start()


def apply_rfid_match_result(state, track_id, match):
    """
    Resolve a pending detection as soon as Laravel finds a registered tag or
    guest pass read.
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
        status = "guest_pass" if match.get("status") == "guest_pass" else "registered"
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
        state["crossings_logged"] += 1
        state["pending_windows"].pop(track_id, None)
        state["last_error"] = ""

        return True


def rfid_detection_window_worker(role, state, track_id, laravel_client):
    """
    Poll Laravel for a pass read; on timeout send a no-pass alert, all outside
    the frame capture loop.
    """
    while True:
        with state["lock"]:
            window = state["pending_windows"].get(track_id)

            if not window:
                return

            event_time = window["event_time"]
            event_key = window["event_key"]
            deadline_at = window["deadline_at"]

        remaining = deadline_at - time.monotonic()
        if remaining <= 0:
            break

        if remaining < RFID_MATCH_TIMEOUT_SECONDS:
            time.sleep(remaining)
            break

        match = laravel_client.check_rfid_match(
            role,
            event_time,
            RFID_DETECTION_WINDOW_SECONDS,
            RFID_LOOKBACK_SECONDS,
            event_key,
        )

        if apply_rfid_match_result(state, track_id, match):
            return

        sleep_for = min(
            RFID_POLL_INTERVAL_SECONDS,
            max(0.0, deadline_at - time.monotonic()),
        )

        if sleep_for <= 0:
            break

        time.sleep(sleep_for)

    submit_guest_observation_for_window(role, state, track_id, laravel_client)


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


def analyze_guest_vehicle_details(analysis_frames):
    """
    Run plate/color analysis across several YOLO-aligned frames from one window.
    """
    plate_numbers = []
    vehicle_colors = []
    runtime_status = ocr_runtime_status()
    frame_results = []
    started_at = time.monotonic()

    for index, (frame, xyxy) in enumerate(analysis_frames[:MAX_GUEST_OCR_ANALYSIS_FRAMES]):
        plate_error = None
        color_error = None

        try:
            plate_number = read_license_plate(frame, xyxy)
        except Exception as error:
            plate_number = None
            plate_error = str(error)

        try:
            vehicle_color = detect_vehicle_color(frame, xyxy)
        except Exception as error:
            vehicle_color = None
            color_error = str(error)

        if plate_number:
            plate_numbers.append(plate_number)

        if vehicle_color:
            vehicle_colors.append(vehicle_color)

        frame_results.append({
            "index": index,
            "bbox_xyxy": [float(value) for value in xyxy],
            "plate_number": plate_number,
            "vehicle_color": vehicle_color,
            "plate_error": plate_error,
            "color_error": color_error,
        })

    return (
        most_common_value(plate_numbers),
        most_common_color(vehicle_colors),
        runtime_status,
        {
            "frames_checked": len(frame_results),
            "elapsed_seconds": round(time.monotonic() - started_at, 3),
            "frame_results": frame_results,
        },
    )


def submit_guest_observation_for_window(role, state, track_id, laravel_client):
    """
    Send one "Vehicle with no pass" alert (snapshot, then color/plate) after
    the RFID window expires without blocking video. Laravel no longer turns
    this into a guest record or an INSIDE guest session.
    """
    now_monotonic = time.monotonic()

    with state["lock"]:
        window = state["pending_windows"].get(track_id)

        if not window:
            return

        snapshot_frame = window.get("snapshot_frame")
        if snapshot_frame is not None:
            snapshot_frame = snapshot_frame.copy()

        analysis_frames = [
            (analysis_frame.copy(), tuple(analysis_xyxy))
            for analysis_frame, analysis_xyxy in window.get("analysis_frames", [])
            if analysis_frame is not None
        ]
        window_started_at = window.get("started_at", now_monotonic)
        window_payload = {
            "event_key": window["event_key"],
            "camera_id": window.get("camera_id"),
            "detected_vehicle_type": window["detected_vehicle_type"],
            "event_time": window["event_time"],
            "xyxy": tuple(window["xyxy"]),
            "confidence": window["confidence"],
            "direction": window["direction"],
        }
        tracked = ensure_tracked_vehicle_locked(state, track_id, now_monotonic)

        if tracked.get("status") != "checking":
            state["pending_windows"].pop(track_id, None)
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
        state["pending_windows"].pop(track_id, None)

    if snapshot_frame is None:
        with state["lock"]:
            state["last_error"] = f"{role.capitalize()} vehicle had no pass read, but no snapshot frame was available."
        return

    # A full-resolution frame from the moment of the crossing, when available:
    # sharper snapshot and plate reading than the small live-stream frame.
    hires_frame = HIRES[role].frame_near(window_started_at) if role in HIRES else None
    if hires_frame is not None:
        hires_box = scale_box(window_payload["xyxy"], snapshot_frame.shape, hires_frame.shape)
        analysis_frames = [(hires_frame, hires_box)] + analysis_frames
        snapshot_frame = hires_frame
        # Live-frame box kept for matching later live detections of this car.
        window_payload["live_xyxy"] = window_payload["xyxy"]
        window_payload["xyxy"] = hires_box
        metrics.rate(role, "hires_used")

    snapshot = encode_frame_snapshot(
        role,
        snapshot_frame,
        window_payload["event_key"],
    )

    if not snapshot:
        with state["lock"]:
            state["last_error"] = f"{role.capitalize()} vehicle had no pass read, but snapshot encoding failed."
        return

    base_metadata = {
        "track_id": track_id,
        "confidence": window_payload["confidence"],
        "direction": window_payload["direction"],
        "bbox_xyxy": list(window_payload["xyxy"]),
        "rfid_window_seconds": RFID_DETECTION_WINDOW_SECONDS,
        "rfid_lookback_seconds": RFID_LOOKBACK_SECONDS,
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
            state["last_error"] = initial_result.get("message", "No-pass alert could not be saved.")
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

    plate_number, detailed_vehicle_color, ocr_status, analysis_details = analyze_guest_vehicle_details(analysis_frames)
    vehicle_color = reconcile_guest_vehicle_color(vehicle_color, detailed_vehicle_color)

    print(
        f"{role.capitalize()} no-pass alert analysis {window_payload['event_key']}: "
        f"plate={plate_number or 'None'} color={vehicle_color or 'None'} "
        f"ocr_frames={analysis_details['frames_checked']} "
        f"ocr_elapsed={analysis_details['elapsed_seconds']}s",
        flush=True,
    )

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
            state["last_error"] = result.get("message", "No-pass alert could not be saved.")


def update_detection_windows(role, frame, results, state, laravel_client):
    """
    Refresh pending window snapshots while background workers handle API I/O.
    """
    visible_boxes = current_track_boxes(results)
    now_monotonic = time.monotonic()

    with state["lock"]:
        for track_id, window in list(state["pending_windows"].items()):
            visible_box = visible_boxes.get(track_id)
            if visible_box:
                window["xyxy"] = visible_box["xyxy"]
                window["confidence"] = visible_box["confidence"]
                window["snapshot_frame"] = frame.copy()

                if now_monotonic - float(window.get("last_analysis_frame_at", 0.0)) >= GUEST_ANALYSIS_FRAME_INTERVAL_SECONDS:
                    analysis_frames = window.setdefault("analysis_frames", [])
                    analysis_frames.append((frame.copy(), tuple(visible_box["xyxy"])))
                    del analysis_frames[:-MAX_GUEST_ANALYSIS_FRAMES]
                    window["last_analysis_frame_at"] = now_monotonic
            elif window.get("snapshot_frame") is None:
                window["snapshot_frame"] = frame.copy()


def process_results(role, frame, results, camera_config, state, laravel_client, vehicle_labels):
    """
    Filter detections to supported vehicle classes, track them, and log one
    event per valid crossing. Uses ANPR for license plate detection.
    """
    frame_height, frame_width = frame.shape[:2]
    mask_polygon = normalized_polygon_to_pixels(camera_config.get("calibration_mask"), frame_width, frame_height)
    line = normalized_line_to_pixels(camera_config.get("calibration_line"), frame_width, frame_height)
    boxes = results.boxes

    if not mask_polygon or not line:
        state["active_detections"] = 0
        cleanup_tracks_outside_roi(state, set())
        cleanup_stale_tracks(state)
        return

    if boxes is None or boxes.id is None:
        state["active_detections"] = 0
        update_detection_windows(role, frame, results, state, laravel_client)
        cleanup_tracks_outside_roi(state, set())
        cleanup_stale_tracks(state)
        return

    ids = boxes.id.int().cpu().tolist()
    classes = boxes.cls.int().cpu().tolist()
    confidences = boxes.conf.cpu().tolist()
    coordinates = boxes.xyxy.cpu().tolist()
    active_detections = 0
    visible_roi_track_ids = set()

    for track_id, class_id, confidence, xyxy in zip(ids, classes, confidences, coordinates):
        if class_id not in vehicle_labels:
            continue

        inside_roi = bbox_inside_roi(xyxy, mask_polygon)

        if not inside_roi:
            with state["lock"]:
                forget_track_locked(state, track_id)
            continue

        visible_roi_track_ids.add(track_id)
        active_detections += 1
        now_monotonic = time.monotonic()

        with state["lock"]:
            state["detections_seen"] += 1
            state["track_last_seen"][track_id] = now_monotonic
            state["track_boxes"][track_id] = {
                "class_id": class_id,
                "confidence": confidence,
                "xyxy": xyxy,
                "last_seen": now_monotonic,
            }
            state["track_overlays"].setdefault(track_id, detection_overlay())

        center_point = bbox_center(xyxy)

        with state["lock"]:
            if track_is_processed_as_guest_locked(state, track_id, inside_roi, now_monotonic):
                state["track_overlays"][track_id] = state["track_overlays"].get(track_id) or default_overlay()
                continue

        current_side = point_side_of_line(center_point, line)

        with state["lock"]:
            previous_side = state["track_sides"].get(track_id)
            state["track_sides"][track_id] = current_side

        line_touched = bbox_intersects_line(xyxy, line)
        triggered = crossed_line(previous_side, current_side) or line_touched

        if not triggered:
            continue

        with state["lock"]:
            already_handled = track_id in state["crossed_track_ids"] or track_id in state["pending_windows"]
            active_decision_overlay = None

            if not already_handled:
                active_decision_overlay = matching_active_decision_locked(state, xyxy, now_monotonic)

                if active_decision_overlay:
                    state["crossed_track_ids"][track_id] = now_monotonic
                    state["track_overlays"][track_id] = active_decision_overlay
                    already_handled = True

        if already_handled:
            continue

        if previous_side is not None and current_side is not None:
            if previous_side < 0 and current_side > 0:
                direction = "IN"
            elif previous_side > 0 and current_side < 0:
                direction = "OUT"
            else:
                direction = "IN"
        else:
            direction = "IN"

        start_detection_window(
            role,
            frame,
            state,
            track_id,
            class_id,
            confidence,
            xyxy,
            direction,
            camera_config,
            vehicle_labels,
            laravel_client,
        )

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
        if time.monotonic() >= state.get("retry_after", 0.0):
            state["retry_count"] += 1
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
        state["last_error"] = "Calibration ROI mask and trigger line are required before auto logging starts."
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
    for role in CAMERA_ROLES:
        release_capture(camera_states[role])


def build_models():
    """
    Keep one model instance per camera so tracker state does not mix across roles.
    """
    from ultralytics import YOLO

    detector_models = {}

    for role in CAMERA_ROLES:
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
            viewer_active = station_viewer_active(role)
            capture, capture_source = ensure_capture(camera_config, state)

            if capture is None or not capture.isOpened():
                release_capture(state)
                state["camera_running"] = False
                state["detection_ready"] = False
                if time.monotonic() >= state.get("retry_after", 0.0):
                    state["retry_count"] += 1
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
                    state["retry_count"] += 1
                    state["last_error"] = "Camera opened, but frame capture failed."
                    if viewer_active:
                        publish_status_frame(role, "Frame capture failed", state["last_error"])
                    success = False
                else:
                    now_monotonic = time.monotonic()
                    frame_time = getattr(capture, "frame_time", 0.0) or now_monotonic

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

                    vehicle_labels = model_info.get("vehicle_labels", {})

                    # Phase 1: drawing + JPEG encoding only happen while a
                    # Station/Calibration page is watching the stream.
                    if not calibration_ready(camera_config):
                        state["detection_ready"] = False
                        state["retry_count"] = 0
                        state["last_error"] = "Calibration ROI mask and trigger line are required before auto logging starts."
                        if viewer_active and publish_due(state, perf):
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
            state["retry_count"] += 1
            state["last_error"] = f"{role.capitalize()} stream worker error: {error}"
            publish_status_frame(role, "Stream worker error", state["last_error"])
            success = False

        # Low latency: read_latest() already waits for the next camera frame, so
        # the old fixed 0.04s sleep would only add delay after every frame.
        if success and isinstance(state.get("capture"), LatestFrameReader):
            delay = 0
        else:
            delay = CAPTURE_INTERVAL_SECONDS if success else RECONNECT_DELAY_SECONDS
        stop_event.wait(delay)

    release_capture(state)


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
                time.monotonic() - state.get("last_detection_at", 0.0) < min_interval:
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


def offset_results(results, x1, y1):
    """Move boxes found in a crop back to full-frame pixel coordinates."""
    boxes = getattr(results, "boxes", None)
    if boxes is None or boxes.data is None or len(boxes.data) == 0:
        return results
    data = boxes.data
    data[:, 0] += x1
    data[:, 2] += x1
    data[:, 1] += y1
    data[:, 3] += y1
    return results


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


def camera_detection_worker(role, state, model_info, stop_event):
    """
    Run YOLO tracking in a separate worker so live MJPEG publishing stays smooth.
    """
    while not stop_event.is_set():
        # Phase 1: removed the "no Station page open" gate. Detection runs
        # whenever the detector is on.
        perf = performance_settings(load_runtime_config())
        frame, camera_config = next_detection_frame(state, 1.0 / max(0.5, perf["detection_fps"]))

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
        try:
            with metrics.timed(role, "yolo"):
                results = model_info["model"].track(
                    detect_input,
                    persist=True,
                    verbose=False,
                    tracker=TRACKER_CONFIG,
                    conf=DETECTION_CONFIDENCE_THRESHOLD,
                    iou=DETECTION_IOU_THRESHOLD,
                    classes=sorted(vehicle_labels.keys()),
                    imgsz=int(perf["yolo_imgsz"]),
                    device=device,
                )[0]
            if crop is not None:
                results = offset_results(results, crop[0], crop[1])
            metrics.value(role, "yolo_device", device)
            metrics.value(role, "yolo_input", f"{detect_input.shape[1]}x{detect_input.shape[0]}@{int(perf['yolo_imgsz'])}")
        except Exception as error:
            if device != "cpu" and _YOLO_DEVICE["resolved"] == device:
                # A GPU backend that fails (driver, unsupported op) falls back to CPU.
                print(f"YOLO on {device} failed ({error}); using CPU.", flush=True)
                _YOLO_DEVICE["resolved"] = "cpu"
                reset_tracker(model_info)
                continue
            state["detection_ready"] = False
            state["retry_count"] += 1
            state["last_error"] = f"Detection failed: {error}"
            stop_event.wait(RECONNECT_DELAY_SECONDS)
            continue

        state["detection_ready"] = True
        state["retry_count"] = 0
        state["last_error"] = ""
        with metrics.timed(role, "process"):
            process_results(role, frame, results, camera_config, state, laravel_client, vehicle_labels)
        # A vehicle in the zone: have full-resolution frames ready for its snapshot.
        if perf["hires_on_trigger"] and camera_config.get("snapshot_source_value") and vehicle_in_zone(results, camera_config, frame, vehicle_labels):
            hires_grabber(role).trigger(camera_config)
        metrics.rate(role, "detection")
        metrics.timing(role, "detection_age", (time.monotonic() - frame_time) * 1000.0)


def run_detector_loop():
    """
    Start the dual-camera vehicle detector until the user stops it.
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
    camera_states = {role: initial_camera_state() for role in CAMERA_ROLES}
    detector_models = {role: {"model": None, "vehicle_labels": {}} for role in CAMERA_ROLES}
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

        for role in CAMERA_ROLES:
            stream_worker = threading.Thread(
                target=camera_stream_worker,
                args=(role, camera_states[role], detector_models[role], stop_event),
                daemon=True,
            )
            detection_worker = threading.Thread(
                target=camera_detection_worker,
                args=(role, camera_states[role], detector_models[role], stop_event),
                daemon=True,
            )
            stream_worker.start()
            detection_worker.start()
            workers.extend([stream_worker, detection_worker])

        last_metrics_log = time.monotonic()
        while True:
            runtime_config = load_runtime_config()
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
            service_message="Detector service stopped by user.",
        )
        print("Detector service stopped.")
    except Exception as error:
        if 'stop_event' in locals():
            stop_event.set()

        for role in CAMERA_ROLES:
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


if __name__ == "__main__":
    run_detector_loop()
