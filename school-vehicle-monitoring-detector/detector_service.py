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
from ultralytics import YOLO

from config import (
    ALLOWED_VEHICLE_CLASS_NAMES,
    CAPTURE_INTERVAL_SECONDS,
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
    RFID_POLL_INTERVAL_SECONDS,
    SNAPSHOTS_DIR,
    STATUS_FILE_PATH,
    TRACK_STALE_AFTER_SECONDS,
    TRACKER_CONFIG,
    annotated_frame_path,
    latest_frame_path,
    load_runtime_config,
    resolve_capture_source,
)
from laravel_client import LaravelEventClient
from tracking import (
    bbox_center,
    calibration_ready,
    crossed_line,
    normalized_line_to_pixels,
    normalized_rect_to_pixels,
    point_in_mask,
    point_side_of_line,
)
from anpr import read_license_plate

CAMERA_ROLES = ("entrance", "exit")
STREAM_FRAMES = {role: None for role in CAMERA_ROLES}
STREAM_CONDITION = threading.Condition()


class ReusableThreadingHTTPServer(ThreadingHTTPServer):
    allow_reuse_address = True
    daemon_threads = True


class MjpegStreamHandler(BaseHTTPRequestHandler):
    """
    Serve live detector frames from memory so station screens do not poll saved files.
    """

    def do_GET(self):
        if self.path == "/health":
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Access-Control-Allow-Origin", "*")
            self.end_headers()
            self.wfile.write(b'{"ok":true}')
            return

        role = self.path.strip("/").split("/")
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


def publish_stream_frame(role, frame):
    """
    Publish one live frame to connected MJPEG clients without saving it to disk.
    """
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
        "detections_seen": 0,
        "active_detections": 0,
        "crossings_logged": 0,
        "retry_after": 0.0,
        "track_sides": {},
        "track_last_seen": {},
        "crossed_track_ids": {},
        "track_overlays": {},
        "pending_windows": {},
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

    for track_id, last_seen in list(state["track_last_seen"].items()):
        if now_monotonic - last_seen <= TRACK_STALE_AFTER_SECONDS:
            continue

        state["track_last_seen"].pop(track_id, None)
        state["track_sides"].pop(track_id, None)
        state["crossed_track_ids"].pop(track_id, None)
        state["track_overlays"].pop(track_id, None)
        state["pending_windows"].pop(track_id, None)


def encode_vehicle_snapshot(role, frame, xyxy, event_key):
    """
    Encode a cropped vehicle snapshot for Laravel multipart upload.
    """
    frame_height, frame_width = frame.shape[:2]
    x1, y1, x2, y2 = [int(value) for value in xyxy]
    padding_x = max(int((x2 - x1) * 0.1), 8)
    padding_y = max(int((y2 - y1) * 0.1), 8)

    crop_x1 = max(x1 - padding_x, 0)
    crop_y1 = max(y1 - padding_y, 0)
    crop_x2 = min(x2 + padding_x, frame_width)
    crop_y2 = min(y2 + padding_y, frame_height)

    crop = frame[crop_y1:crop_y2, crop_x1:crop_x2]
    if crop.size == 0:
        crop = frame

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S_%f")
    filename = f"{role}_{timestamp}_{event_key}.jpg"

    encoded, buffer = cv2.imencode(
        ".jpg",
        crop,
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
        "label": "UNREGISTERED / GUEST",
        "color": "red",
        "verification": "guest",
    }


def waiting_overlay():
    """
    Temporary label while the 5-second RFID detection window is still open.
    """
    return {
        "label": "CHECKING RFID",
        "color": "amber",
        "verification": "pending",
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
    Draw the saved ROI and trigger line so station operators can see where detection starts.
    """
    frame_height, frame_width = frame.shape[:2]
    mask_rect = normalized_rect_to_pixels(camera_config.get("calibration_mask"), frame_width, frame_height)
    line = normalized_line_to_pixels(camera_config.get("calibration_line"), frame_width, frame_height)

    if mask_rect:
        x = int(mask_rect["x"])
        y = int(mask_rect["y"])
        width = int(mask_rect["width"])
        height = int(mask_rect["height"])
        cv2.rectangle(
            frame,
            (x, y),
            (x + width, y + height),
            (255, 255, 255),
            1,
        )

    if line:
        x1 = int(line["x1"])
        y1 = int(line["y1"])
        x2 = int(line["x2"])
        y2 = int(line["y2"])
        cv2.line(frame, (x1, y1), (x2, y2), (0, 165, 255), 2)
        draw_label(frame, "TRIGGER LINE", x1, y1, (0, 165, 255))


def render_annotated_frame(role, frame, results, camera_config, state, vehicle_labels):
    """
    Draw live YOLO detections, then upgrade the label when RFID/guest state resolves.
    """
    annotated = frame.copy()
    draw_calibration_guides(annotated, camera_config)
    boxes = results.boxes if results is not None else None

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


def start_detection_window(role, state, track_id, class_id, confidence, xyxy, direction, camera_config, vehicle_labels):
    """
    Start one 5-second RFID matching window for a triggered vehicle.
    """
    now_monotonic = time.monotonic()
    event_key = f"{role}-track-{track_id}-{int(time.time() * 1000)}"
    event_time = datetime.now().astimezone().isoformat()
    display_label = vehicle_labels[class_id]

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
        "next_poll_at": now_monotonic,
        "snapshot_frame": None,
        "last_message": "Waiting for RFID scan.",
    }
    state["track_overlays"][track_id] = waiting_overlay()


def update_detection_windows(role, frame, results, state, laravel_client):
    """
    Poll Laravel for each pending RFID window and create a guest observation on timeout.
    """
    now_monotonic = time.monotonic()
    visible_boxes = current_track_boxes(results)

    for track_id, window in list(state["pending_windows"].items()):
        visible_box = visible_boxes.get(track_id)
        if visible_box:
            window["xyxy"] = visible_box["xyxy"]
            window["confidence"] = visible_box["confidence"]
            window["snapshot_frame"] = frame.copy()
        elif window.get("snapshot_frame") is None:
            window["snapshot_frame"] = frame.copy()

        if now_monotonic >= window["next_poll_at"] and now_monotonic <= window["deadline_at"]:
            match = laravel_client.check_rfid_match(
                role,
                window["event_time"],
                RFID_DETECTION_WINDOW_SECONDS,
            )
            window["next_poll_at"] = now_monotonic + RFID_POLL_INTERVAL_SECONDS
            window["last_message"] = match.get("message", window["last_message"])

            if match.get("matched"):
                state["track_overlays"][track_id] = match.get("overlay") or {
                    "label": "REGISTERED",
                    "color": "green",
                    "verification": "registered",
                }
                state["crossed_track_ids"][track_id] = now_monotonic
                state["pending_windows"].pop(track_id, None)
                state["last_error"] = ""
                continue

        if now_monotonic < window["deadline_at"]:
            continue

        snapshot_frame = window.get("snapshot_frame") if window.get("snapshot_frame") is not None else frame
        snapshot = encode_vehicle_snapshot(
            role,
            snapshot_frame,
            window["xyxy"],
            window["event_key"],
        )

        if not snapshot:
            state["crossed_track_ids"][track_id] = now_monotonic
            state["pending_windows"].pop(track_id, None)
            state["last_error"] = f"{role.capitalize()} vehicle had no RFID match, but snapshot encoding failed."
            continue

        plate_number = None
        if window["direction"] == "OUT":
            plate_number = read_license_plate(snapshot_frame, tuple(window["xyxy"]))

        result = laravel_client.submit_guest_observation({
            "external_event_key": window["event_key"],
            "camera_role": role,
            "camera_id": window.get("camera_id"),
            "detected_vehicle_type": window["detected_vehicle_type"],
            "event_time": window["event_time"],
            "plate_number": plate_number,
            "detection_metadata": {
                "track_id": track_id,
                "confidence": window["confidence"],
                "direction": window["direction"],
                "rfid_window_seconds": RFID_DETECTION_WINDOW_SECONDS,
            },
        }, snapshot["bytes"], snapshot["filename"])

        state["track_overlays"][track_id] = result.get("overlay") or default_overlay()
        state["crossed_track_ids"][track_id] = now_monotonic
        state["pending_windows"].pop(track_id, None)

        if result.get("accepted"):
            if result.get("created"):
                state["crossings_logged"] += 1
            state["last_error"] = ""
        else:
            state["last_error"] = result.get("message", "Guest observation could not be saved.")


def process_results(role, frame, results, camera_config, state, laravel_client, vehicle_labels):
    """
    Filter detections to supported vehicle classes, track them, and log one
    event per valid crossing. Uses ANPR for license plate detection.
    """
    frame_height, frame_width = frame.shape[:2]
    mask_rect = normalized_rect_to_pixels(camera_config.get("calibration_mask"), frame_width, frame_height)
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
        state["detections_seen"] += 1
        state["track_last_seen"][track_id] = time.monotonic()

        center_point = bbox_center(xyxy)
        inside_roi = point_in_mask(center_point, mask_rect) if mask_rect else True
        if not inside_roi:
            continue

        current_side = point_side_of_line(center_point, line) if line else 0
        previous_side = state["track_sides"].get(track_id)
        state["track_sides"][track_id] = current_side

        triggered = crossed_line(previous_side, current_side) if line else previous_side is None

        if not triggered:
            continue

        if track_id in state["crossed_track_ids"] or track_id in state["pending_windows"]:
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
            state,
            track_id,
            class_id,
            confidence,
            xyxy,
            direction,
            camera_config,
            vehicle_labels,
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
        return False

    has_frame, frame = capture.read()

    if not has_frame or frame is None:
        release_capture(state)
        state["camera_running"] = False
        state["detection_ready"] = False
        state["retry_count"] += 1
        state["last_error"] = "Camera opened, but frame capture failed."
        return False

    state["camera_running"] = True
    state["last_capture_time"] = datetime.now().astimezone().isoformat()
    state["processed_frames"] += 1

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
        publish_stream_frame(role, frame)
        return True

    try:
        results = model_info["model"].track(
            frame,
            persist=True,
            verbose=False,
            tracker=TRACKER_CONFIG,
            conf=DETECTION_CONFIDENCE_THRESHOLD,
            iou=DETECTION_IOU_THRESHOLD,
            classes=sorted(vehicle_labels.keys()),
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
        laravel_client = LaravelEventClient(runtime_config)

        while True:
            runtime_config = load_runtime_config()
            laravel_client = LaravelEventClient(runtime_config)
            had_success = False

            for role in CAMERA_ROLES:
                had_success = process_camera(
                    role,
                    runtime_config["cameras"][role],
                    camera_states[role],
                    detector_models[role],
                    laravel_client,
                ) or had_success

            write_status(
                runtime_config,
                camera_states,
                detector_models,
                service_running=True,
                service_message="Dual-camera detector running.",
            )
            time.sleep(CAPTURE_INTERVAL_SECONDS if had_success else RECONNECT_DELAY_SECONDS)
    except KeyboardInterrupt:
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
