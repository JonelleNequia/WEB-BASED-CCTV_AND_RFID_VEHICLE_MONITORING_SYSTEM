"""
Run the detector pipeline on a video file: no camera and no real vehicle
needed. Same code as the live detector (YOLO + ByteTrack -> zone -> line
crossing -> RFID window -> "no pass" alert), at the same detection rate.

    python detector_service.py --video FILE [options]

Zone and line (normalized 0-1, like Settings > Calibration):
    --use-calibration          the station's saved zone and line (--role)
    --roi "x,y x,y x,y ..."    zone polygon
    --line "x1,y1,x2,y2"       trigger line
    (default: the whole frame and a horizontal line at 60% height)

    --post        send events/alerts to Laravel for real (default: dry run)
    --app-url URL with --post: another Laravel (e.g. a test copy), not the live one
    --out FILE    write the video with the debug overlay
    --fast        do not wait for real time (default: real time, so the
                  4 s RFID window and track timeouts behave like live)
"""

import json
import threading
import time

import cv2

import detector_service as detector
import metrics
from config import load_runtime_config, performance_settings


class DryRunClient:
    """Stands in for Laravel: no RFID read ever matches, alerts are recorded."""

    def __init__(self):
        self.lock = threading.Lock()
        self.rfid_checks = 0
        self.alerts = []
        self.events = []

    def check_rfid_match(self, camera_role, event_time, window_seconds=4, lookback_seconds=10, event_key=None):
        with self.lock:
            self.rfid_checks += 1
        return {"matched": False, "message": "Dry run: no RFID read."}

    def submit_guest_observation(self, payload, image_bytes=None, filename=None):
        with self.lock:
            self.alerts.append({"event_key": payload.get("external_event_key"), **{key: payload.get(key) for key in ("camera_role", "detected_vehicle_type", "plate_number", "vehicle_color")}})
        return {"accepted": True, "created": True, "duplicate": False, "message": "Dry run.", "body": {}, "overlay": None}

    def submit_event(self, payload):
        with self.lock:
            self.events.append(payload)
        return {"accepted": True, "created": True, "duplicate": False, "message": "Dry run.", "body": {}}


def parse_points(text):
    points = []
    for pair in text.replace(";", " ").split():
        x, y = (float(value) for value in pair.split(","))
        points.append({"x": x, "y": y})
    return points


def parse_line(text):
    x1, y1, x2, y2 = (float(value) for value in text.split(","))
    return {"x1": x1, "y1": y1, "x2": x2, "y2": y2}


def with_app_url(runtime, app_url):
    """Send to another Laravel (e.g. a copy of the database) instead of the live one."""
    settings = dict(runtime.get("system_settings") or {})
    old = str(settings.get("app_url") or "").rstrip("/")
    for key, value in list(settings.items()):
        if key.endswith("_url") and isinstance(value, str) and old and value.startswith(old):
            settings[key] = app_url.rstrip("/") + value[len(old):]
    return {**runtime, "system_settings": settings}


def run(args):
    runtime = load_runtime_config()
    if args.app_url:
        runtime = with_app_url(runtime, args.app_url)
    perf = performance_settings(runtime)
    if args.detection_fps:
        perf["detection_fps"] = float(args.detection_fps)
    if args.imgsz:
        perf["yolo_imgsz"] = int(args.imgsz)
    perf["hires_on_trigger"] = 0

    camera_config = dict((runtime.get("cameras") or {}).get(args.role) or {})
    if not args.use_calibration:
        camera_config["calibration_mask"] = parse_points(args.roi) if args.roi else parse_points("0.02,0.02 0.98,0.02 0.98,0.98 0.02,0.98")
        camera_config["calibration_line"] = parse_line(args.line) if args.line else parse_line("0.02,0.6,0.98,0.6")
    camera_config["snapshot_source_value"] = None
    if not detector.calibration_ready(camera_config):
        print(f"The {args.role} station has no saved zone and line. Draw them in Calibration, or pass --roi and --line.")
        return 2

    capture = cv2.VideoCapture(args.video)
    if not capture.isOpened():
        print(f"Cannot open {args.video}")
        return 2
    video_fps = capture.get(cv2.CAP_PROP_FPS) or 25.0
    width, height = int(capture.get(cv2.CAP_PROP_FRAME_WIDTH)), int(capture.get(cv2.CAP_PROP_FRAME_HEIGHT))

    state = detector.initial_camera_state()
    state["debug_enabled"] = True
    model_info = {"model": None, "vehicle_labels": {}}
    if not detector.ensure_detector_model_loaded(args.role, state, model_info):
        print(state["last_error"])
        return 1
    vehicle_labels = model_info["vehicle_labels"]
    client = detector.LaravelEventClient(runtime) if args.post else DryRunClient()

    writer = None
    if args.out:
        writer = cv2.VideoWriter(args.out, cv2.VideoWriter_fourcc(*"mp4v"), video_fps, (width, height))

    print(f"Video {width}x{height} @ {video_fps:.1f} fps; detection {perf['detection_fps']} per second, "
          f"input {perf['yolo_imgsz']}, zone crop {'on' if perf['roi_crop'] else 'off'}, "
          f"{'POSTING to Laravel' if args.post else 'dry run'}", flush=True)

    interval = 1.0 / max(0.5, perf["detection_fps"])
    next_detection = 0.0
    started = time.monotonic()
    frame_index = detections = raw_total = vehicle_total = 0
    track_ids = set()
    crossings = []
    errors = {}
    results = None

    while True:
        ok, frame = capture.read()
        if not ok:
            break
        video_time = frame_index / video_fps
        frame_index += 1
        if not args.fast:
            delay = started + video_time - time.monotonic()
            if delay > 0:
                time.sleep(delay)

        if video_time + 1e-9 >= next_detection:
            next_detection += interval
            if next_detection < video_time:
                next_detection = video_time + interval
            try:
                results, crop, _shape, device = detector.run_yolo(args.role, model_info, frame, camera_config, perf, state)
                before = state["line_crossings"]
                results = detector.handle_detection(args.role, frame, results, crop, camera_config, perf, state, client, vehicle_labels, device)
            except Exception as error:  # reported, never hidden
                errors[str(error)] = errors.get(str(error), 0) + 1
                results = None
                continue
            detections += 1
            debug = state["debug"] or {}
            raw_total += debug.get("raw_count", 0)
            vehicle_total += debug.get("vehicle_count", 0)
            track_ids.update(item["track_id"] for item in debug.get("raw", []) if item["kept"] and item["track_id"] is not None)
            if state["line_crossings"] > before:
                with state["lock"]:
                    windows = {tid: dict(window) for tid, window in state["pending_windows"].items()}
                for track_id, window in windows.items():
                    if not any(item["track"] == track_id for item in crossings):
                        crossings.append({"t": round(video_time, 1), "track": track_id,
                                          "class": window.get("detected_vehicle_type"), "direction": window.get("direction")})
                        print(f"  {video_time:5.1f}s  CROSSING  track {track_id}  {window.get('detected_vehicle_type')}  {window.get('direction')}", flush=True)

        if writer is not None:
            writer.write(detector.render_annotated_frame(args.role, frame, results, camera_config, state, vehicle_labels))

    capture.release()
    if writer is not None:
        writer.release()

    # Let the RFID windows finish (no match -> "no pass" alert).
    deadline = time.monotonic() + detector.RFID_DETECTION_WINDOW_SECONDS + 30
    while state["pending_windows"] and time.monotonic() < deadline:
        time.sleep(0.2)

    summary = {
        "frames": frame_index,
        "video_seconds": round(frame_index / video_fps, 1),
        "detection_runs": detections,
        "raw_detections": raw_total,
        "vehicle_detections": vehicle_total,
        "vehicle_track_ids": len(track_ids),
        "line_crossings": state["line_crossings"],
        "crossings": crossings,
        "rfid_checks": getattr(client, "rfid_checks", None),
        # One alert per vehicle (sent as snapshot, then color, then plate updates).
        "no_pass_vehicles": len({item["event_key"] for item in getattr(client, "alerts", [])}) if not args.post else "sent to Laravel",
        "no_pass_api_calls": len(getattr(client, "alerts", [])) if not args.post else None,
        "windows_finished": state["crossings_logged"],
        "errors": errors,
        "yolo": metrics.snapshot().get(args.role, {}).get("values", {}),
    }
    print(json.dumps(summary, indent=2, default=str))
    return 0
