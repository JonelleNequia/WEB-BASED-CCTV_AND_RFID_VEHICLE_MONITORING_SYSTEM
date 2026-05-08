import json
import os
import platform
import threading
import time
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import quote, urlparse, urlunparse

import cv2
import numpy as np
from ultralytics import YOLO

from config import (
    ALLOWED_VEHICLE_CLASS_NAMES,
    CAPTURE_INTERVAL_SECONDS,
    CAPTURE_DRAIN_FRAMES,
    CAMERA_RETRY_DELAY_SECONDS,
    DETECTED_IMAGE_DIR,
    DETECTION_FRAME_INTERVAL,
    DETECTION_CONFIDENCE_THRESHOLD,
    DETECTION_IOU_THRESHOLD,
    JPEG_QUALITY,
    MJPEG_STREAM_HOST,
    MJPEG_STREAM_PORT,
    MODEL_PATH,
    PUBLIC_CAMERA_DIR,
    RECONNECT_DELAY_SECONDS,
    RFID_DETECTION_WINDOW_SECONDS,
    RFID_MATCH_TIMEOUT_SECONDS,
    RFID_POLL_INTERVAL_SECONDS,
    SNAPSHOTS_DIR,
    STATUS_FILE_PATH,
    STATUS_WRITE_INTERVAL_SECONDS,
    STREAM_FRAME_MAX_WIDTH,
    TRACK_STALE_AFTER_SECONDS,
    TRACKER_CONFIG,
    YOLO_IMAGE_SIZE,
    annotated_frame_path,
    latest_frame_path,
    load_runtime_config,
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

CAMERA_ROLES = ("entrance", "exit")
STREAM_FRAMES = {role: None for role in CAMERA_ROLES}
STREAM_CONDITION = threading.Condition()
RESOLVED_OVERLAY_HOLD_SECONDS = RFID_DETECTION_WINDOW_SECONDS + 2.0
RESOLVED_DETECTION_COOLDOWN_SECONDS = 45.0
GUEST_TRACK_COOLDOWN_SECONDS = 10.0
DUPLICATE_TRACK_IOU_THRESHOLD = 0.18
DUPLICATE_TRACK_CENTER_DISTANCE_RATIO = 0.28
MAX_GUEST_ANALYSIS_FRAMES = 5
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

            try:
                self.wfile.write(b"--frame\r\n")
                self.wfile.write(b"Content-Type: image/jpeg\r\n")
                self.wfile.write(f"Content-Length: {len(frame)}\r\n\r\n".encode("ascii"))
                self.wfile.write(frame)
                self.wfile.write(b"\r\n")
                self.wfile.flush()
            except (BrokenPipeError, ConnectionResetError):
                return

    def log_message(self, format, *args):
        return


def ensure_output_directories():
    """
    Create the folders that Laravel and the detector both read from.
    """
    PUBLIC_CAMERA_DIR.mkdir(parents=True, exist_ok=True)
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


def publish_stream_frame(role, frame):
    """
    Publish one live frame to connected MJPEG clients without saving it to disk.
    """
    frame = resize_frame_for_stream(frame)
    encoded, buffer = cv2.imencode(
        ".jpg",
        frame,
        [cv2.IMWRITE_JPEG_QUALITY, JPEG_QUALITY],
    )

    if not encoded:
        return False

    with STREAM_CONDITION:
        STREAM_FRAMES[role] = buffer.tobytes()
        STREAM_CONDITION.notify_all()

    return True


def resize_frame_for_stream(frame):
    """
    Bound MJPEG frame size so browser display stays responsive on low-end CPUs.
    """
    height, width = frame.shape[:2]

    if STREAM_FRAME_MAX_WIDTH <= 0 or width <= STREAM_FRAME_MAX_WIDTH:
        return frame

    scale = STREAM_FRAME_MAX_WIDTH / float(width)
    target_size = (STREAM_FRAME_MAX_WIDTH, max(1, int(height * scale)))

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
                (MJPEG_STREAM_HOST, MJPEG_STREAM_PORT),
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
    print(f"MJPEG stream server running at http://{MJPEG_STREAM_HOST}:{MJPEG_STREAM_PORT}", flush=True)

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


def build_capture(source_type, capture_source):
    """
    Use the most practical OpenCV backend for the configured source.
    """
    if source_type in {"rtsp", "url"}:
        if hasattr(cv2, "CAP_FFMPEG"):
            return cv2.VideoCapture(capture_source, cv2.CAP_FFMPEG)

        return cv2.VideoCapture(capture_source)

    system_name = platform.system().lower()

    if system_name == "darwin" and hasattr(cv2, "CAP_AVFOUNDATION"):
        return cv2.VideoCapture(capture_source, cv2.CAP_AVFOUNDATION)

    if system_name == "windows" and hasattr(cv2, "CAP_DSHOW"):
        return cv2.VideoCapture(capture_source, cv2.CAP_DSHOW)

    return cv2.VideoCapture(capture_source)


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


def open_capture(camera_config):
    """
    Open one configured camera source.
    """
    capture_source = resolve_capture_source(camera_config)
    connection_source = build_connection_source(camera_config, capture_source)
    capture = build_capture(camera_config["source_type"], connection_source)

    try:
        capture.set(cv2.CAP_PROP_BUFFERSIZE, 1)
    except Exception:
        pass

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

    if state["capture"] is None and now_monotonic < state.get("retry_after", 0.0):
        return None, resolve_capture_source(camera_config)

    if state["capture"] is not None and state["signature"] == signature and state["capture"].isOpened():
        return state["capture"], resolve_capture_source(camera_config)

    release_capture(state)
    capture, capture_source = open_capture(camera_config)
    state["capture"] = capture
    state["signature"] = signature

    if not capture.isOpened():
        state["retry_after"] = now_monotonic + CAMERA_RETRY_DELAY_SECONDS

    return capture, capture_source


def read_fresh_frame(capture):
    """
    Drop queued camera frames before retrieving, reducing visible stream latency.
    """
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
    payload = {
        "service_running": service_running,
        "service_message": service_message,
        "updated_at": datetime.now().astimezone().isoformat(),
        "detector_model_path": MODEL_PATH,
        "stream_server": {
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
    Fallback label before a detection is matched with a verified RFID scan.
    """
    return {
        "label": "GUEST",
        "color": "red",
        "verification": "guest",
    }


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

        hold_seconds = (
            RESOLVED_OVERLAY_HOLD_SECONDS
            if overlay.get("verification") in {"registered", "guest"}
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

        if tracked.get("status") in {"registered", "guest", "processed"}:
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
    Promote a pending detection to REGISTERED as soon as Laravel finds a scan.
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
        state["track_overlays"][track_id] = overlay
        state["crossed_track_ids"][track_id] = now_monotonic
        state.setdefault("tracked_vehicles", {}).setdefault(track_id, {
            "first_seen": time.time(),
            "first_seen_monotonic": now_monotonic,
        })
        state["tracked_vehicles"][track_id].update({
            "status": "registered",
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
    Poll Laravel and upload the guest snapshot outside the frame capture loop.
    """
    while True:
        with state["lock"]:
            window = state["pending_windows"].get(track_id)

            if not window:
                return

            event_time = window["event_time"]
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


def analyze_guest_vehicle_details(analysis_frames):
    """
    Run plate/color analysis across several YOLO-aligned frames from one window.
    """
    plate_numbers = []
    vehicle_colors = []
    runtime_status = ocr_runtime_status()

    for frame, xyxy in analysis_frames[:MAX_GUEST_ANALYSIS_FRAMES]:
        try:
            plate_number = read_license_plate(frame, xyxy)
        except Exception:
            plate_number = None

        try:
            vehicle_color = detect_vehicle_color(frame, xyxy)
        except Exception:
            vehicle_color = None

        if plate_number:
            plate_numbers.append(plate_number)

        if vehicle_color:
            vehicle_colors.append(vehicle_color)

    return most_common_value(plate_numbers), most_common_value(vehicle_colors), runtime_status


def submit_guest_observation_for_window(role, state, track_id, laravel_client):
    """
    Save one guest capture after the RFID window expires without blocking video.
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
            "status": "guest",
            "guest_declared_at": time.time(),
            "guest_declared_at_monotonic": now_monotonic,
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
            state["last_error"] = f"{role.capitalize()} vehicle had no RFID match, but no snapshot frame was available."
        return

    snapshot = encode_frame_snapshot(
        role,
        snapshot_frame,
        window_payload["event_key"],
    )

    if not snapshot:
        with state["lock"]:
            state["last_error"] = f"{role.capitalize()} vehicle had no RFID match, but snapshot encoding failed."
        return

    base_metadata = {
        "track_id": track_id,
        "confidence": window_payload["confidence"],
        "direction": window_payload["direction"],
        "bbox_xyxy": list(window_payload["xyxy"]),
        "rfid_window_seconds": RFID_DETECTION_WINDOW_SECONDS,
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
        remember_recent_resolution_locked(state, track_id, window_payload["xyxy"], state["track_overlays"][track_id], time.monotonic())

        if initial_result.get("accepted"):
            if initial_result.get("created"):
                state["crossings_logged"] += 1
            state["last_error"] = ""
        else:
            state["last_error"] = initial_result.get("message", "Guest observation could not be saved.")
            return

    if not analysis_frames:
        analysis_frames = [(snapshot_frame, window_payload["xyxy"])]

    plate_number, vehicle_color, ocr_status = analyze_guest_vehicle_details(analysis_frames)

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
        },
    }
    result = laravel_client.submit_guest_observation(guest_payload)

    with state["lock"]:
        state["track_overlays"][track_id] = result.get("overlay") or state["track_overlays"].get(track_id) or default_overlay()
        remember_recent_resolution_locked(state, track_id, window_payload["xyxy"], state["track_overlays"][track_id], time.monotonic())

        if not result.get("accepted"):
            state["last_error"] = result.get("message", "Guest observation could not be saved.")


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

    if boxes is None or boxes.id is None:
        state["active_detections"] = 0
        update_detection_windows(role, frame, results, state, laravel_client)
        cleanup_stale_tracks(state)
        return

    ids = boxes.id.int().cpu().tolist()
    classes = boxes.cls.int().cpu().tolist()
    confidences = boxes.conf.cpu().tolist()
    coordinates = boxes.xyxy.cpu().tolist()
    active_detections = 0

    for track_id, class_id, confidence, xyxy in zip(ids, classes, confidences, coordinates):
        if class_id not in vehicle_labels:
            continue

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
        inside_roi = point_in_polygon(center_point, mask_polygon) if mask_polygon else True

        with state["lock"]:
            if track_is_processed_as_guest_locked(state, track_id, inside_roi, now_monotonic):
                state["track_overlays"][track_id] = state["track_overlays"].get(track_id) or default_overlay()
                continue

        if not inside_roi:
            continue

        current_side = point_side_of_line(center_point, line) if line else 0

        with state["lock"]:
            previous_side = state["track_sides"].get(track_id)
            state["track_sides"][track_id] = current_side

        line_touched = bbox_intersects_line(xyxy, line) if line else False
        triggered = (crossed_line(previous_side, current_side) or line_touched) if line else previous_side is None

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

        if line and previous_side is not None and current_side is not None:
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
        state["last_error"] = f"Could not open camera source: {capture_source}"
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
        state["last_error"] = "Calibration mask or trigger line is missing. Save calibration before auto logging starts."
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
    detector_models = {}

    for role in CAMERA_ROLES:
        model = YOLO(MODEL_PATH)
        vehicle_labels = resolve_allowed_vehicle_classes(model)
        detector_models[role] = {
            "model": model,
            "vehicle_labels": vehicle_labels,
        }

    return detector_models


def camera_stream_worker(role, state, model_info, stop_event):
    """
    Read and publish camera frames independently from YOLO inference.
    """
    while not stop_event.is_set():
        try:
            runtime_config = load_runtime_config()
            camera_config = runtime_config["cameras"][role]
            capture, capture_source = ensure_capture(camera_config, state)

            if capture is None or not capture.isOpened():
                release_capture(state)
                state["camera_running"] = False
                state["detection_ready"] = False
                if time.monotonic() >= state.get("retry_after", 0.0):
                    state["retry_count"] += 1
                state["last_error"] = f"Could not open camera source: {capture_source}"
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
                    publish_status_frame(role, "Frame capture failed", state["last_error"])
                    success = False
                else:
                    now_monotonic = time.monotonic()

                    with state["lock"]:
                        state["camera_running"] = True
                        state["last_capture_time"] = datetime.now().astimezone().isoformat()
                        state["processed_frames"] += 1
                        state["latest_frame"] = frame
                        state["latest_camera_config"] = camera_config.copy()
                        state["latest_frame_version"] += 1
                        maybe_save_latest_frame(role, frame, state, now_monotonic)

                    vehicle_labels = model_info.get("vehicle_labels", {})

                    if not calibration_ready(camera_config):
                        state["detection_ready"] = False
                        state["retry_count"] = 0
                        state["last_error"] = "Calibration mask or trigger line is missing. Save calibration before auto logging starts."
                        publish_stream_frame(role, frame)
                    elif not vehicle_labels:
                        state["detection_ready"] = False
                        state["retry_count"] = 0
                        state["last_error"] = "The current detector model does not expose any supported vehicle classes."
                        publish_stream_frame(role, frame)
                    else:
                        refresh_pending_window_snapshots(frame, state)
                        live_frame = render_annotated_frame(role, frame, None, camera_config, state, vehicle_labels)
                        publish_stream_frame(role, live_frame)

                    success = True
        except Exception as error:
            state["camera_running"] = False
            state["detection_ready"] = False
            state["retry_count"] += 1
            state["last_error"] = f"{role.capitalize()} stream worker error: {error}"
            publish_status_frame(role, "Stream worker error", state["last_error"])
            success = False

        delay = CAPTURE_INTERVAL_SECONDS if success else RECONNECT_DELAY_SECONDS
        stop_event.wait(delay)

    release_capture(state)


def next_detection_frame(state):
    """
    Return the latest frame only when enough new stream frames have arrived.
    """
    with state["lock"]:
        latest_frame = state.get("latest_frame")
        camera_config = state.get("latest_camera_config")
        latest_frame_version = int(state.get("latest_frame_version", 0))
        last_detected_frame_version = int(state.get("last_detected_frame_version", 0))

        if latest_frame is None or camera_config is None:
            return None, None

        if latest_frame_version - last_detected_frame_version < DETECTION_FRAME_INTERVAL:
            return None, None

        state["last_detected_frame_version"] = latest_frame_version
        return latest_frame.copy(), camera_config.copy()


def camera_detection_worker(role, state, model_info, stop_event):
    """
    Run YOLO tracking in a separate worker so live MJPEG publishing stays smooth.
    """
    while not stop_event.is_set():
        frame, camera_config = next_detection_frame(state)

        if frame is None:
            stop_event.wait(CAPTURE_INTERVAL_SECONDS)
            continue

        vehicle_labels = model_info.get("vehicle_labels", {})

        if not calibration_ready(camera_config) or not vehicle_labels:
            stop_event.wait(CAPTURE_INTERVAL_SECONDS)
            continue

        runtime_config = load_runtime_config()
        laravel_client = LaravelEventClient(runtime_config)

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
            state["detection_ready"] = False
            state["retry_count"] += 1
            state["last_error"] = f"Detection failed: {error}"
            stop_event.wait(RECONNECT_DELAY_SECONDS)
            continue

        state["detection_ready"] = True
        state["retry_count"] = 0
        state["last_error"] = ""
        process_results(role, frame, results, camera_config, state, laravel_client, vehicle_labels)

        stop_event.wait(CAPTURE_INTERVAL_SECONDS)


def run_detector_loop():
    """
    Start the dual-camera vehicle detector until the user stops it.
    """
    ensure_output_directories()
    stream_server = start_stream_server()

    runtime_config = load_runtime_config()
    camera_states = {role: initial_camera_state() for role in CAMERA_ROLES}
    detector_models = {role: {"vehicle_labels": {}} for role in CAMERA_ROLES}
    write_status(
        runtime_config,
        camera_states,
        detector_models,
        service_running=False,
        service_message="Detector service is starting.",
    )

    try:
        detector_models = build_models()
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

        while True:
            runtime_config = load_runtime_config()
            write_status(
                runtime_config,
                camera_states,
                detector_models,
                service_running=True,
                service_message="Dual-camera detector running.",
            )
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
