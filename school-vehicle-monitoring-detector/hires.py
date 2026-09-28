"""
Full-resolution frames on demand (live-latency work).

The live view and YOLO use the camera's sub stream. The main stream is
opened only while a vehicle is in the calibrated zone, so the snapshot and
plate reading of a no-pass alert can use a sharp 2560x1440 frame instead of
the small live frame. The connection closes a few seconds after the last
vehicle, so the main stream is not decoded all the time.
"""

import threading
import time
from collections import deque

import cv2

import metrics
from config import HIRES_DECODE_DELAY_SECONDS

KEEP_FRAMES = 8
KEEP_EVERY_SECONDS = 0.2
MATCH_WITHIN_SECONDS = 0.8


class HiResGrabber:
    def __init__(self, role, connection_source):
        self.role = role
        self.connection_source = connection_source  # callable(camera_config) -> url with login
        self.lock = threading.Lock()
        self.frames = deque(maxlen=KEEP_FRAMES)
        self.until = 0.0
        self.busy = False
        self.url = None

    def trigger(self, camera_config, seconds=3.0):
        url = camera_config.get("snapshot_source_value")
        if not url:
            return
        with self.lock:
            self.until = max(self.until, time.monotonic() + seconds)
            if self.busy and self.url == url:
                return
            if self.busy:
                return
            self.busy = True
            self.url = url
        threading.Thread(target=self._run, args=(camera_config,), daemon=True).start()

    def _run(self, camera_config):
        started = time.monotonic()
        capture = None
        try:
            capture = cv2.VideoCapture(self.connection_source(camera_config), cv2.CAP_FFMPEG)
            if not capture.isOpened():
                metrics.value(self.role, "hires_error", "could not open the snapshot stream")
                return
            first = True
            last_kept = 0.0
            while True:
                with self.lock:
                    if time.monotonic() > self.until:
                        break
                ok, frame = capture.read()
                if not ok or frame is None:
                    break
                now = time.monotonic()
                if first:
                    metrics.timing(self.role, "hires_open", (now - started) * 1000.0)
                    metrics.value(self.role, "hires_resolution", f"{frame.shape[1]}x{frame.shape[0]}")
                    first = False
                if now - last_kept >= KEEP_EVERY_SECONDS:
                    last_kept = now
                    with self.lock:
                        # When the camera actually saw this frame (decoder delay removed).
                        self.frames.append((now - HIRES_DECODE_DELAY_SECONDS, frame))
                    metrics.rate(self.role, "hires")
        except Exception as error:
            metrics.value(self.role, "hires_error", str(error))
        finally:
            # Released on the reading thread itself: never under a running read().
            if capture is not None:
                capture.release()
            with self.lock:
                self.busy = False

    def frame_near(self, moment):
        """The kept full-resolution frame closest in time to `moment` (monotonic), or None."""
        with self.lock:
            best = min(self.frames, key=lambda item: abs(item[0] - moment), default=None)
        if best is None or abs(best[0] - moment) > MATCH_WITHIN_SECONDS:
            return None
        return best[1].copy()


def scale_box(xyxy, from_shape, to_shape, pad=0.2):
    """Map a box from the live frame to the full-resolution frame, with a margin."""
    from_h, from_w = from_shape[:2]
    to_h, to_w = to_shape[:2]
    sx, sy = to_w / float(from_w), to_h / float(from_h)
    x1, y1, x2, y2 = xyxy
    pad_x, pad_y = (x2 - x1) * pad, (y2 - y1) * pad
    return (
        max(0, int((x1 - pad_x) * sx)), max(0, int((y1 - pad_y) * sy)),
        min(to_w, int((x2 + pad_x) * sx)), min(to_h, int((y2 + pad_y) * sy)),
    )
