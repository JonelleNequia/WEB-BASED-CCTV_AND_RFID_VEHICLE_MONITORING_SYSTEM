"""
Live pipeline metrics for the detector (shown in Settings › System Status
and written to the detector log every 30 seconds).

Per camera:
- rates (events per second over the last 5 s): capture, detection,
  stream_published (JPEGs made), stream_sent (JPEGs written to browsers)
- step timings in ms (average and p95 over the last 120 samples): decode,
  overlay, resize, encode, yolo, process, save
- latency in ms:
    decode_backlog  how far the decoder runs behind the camera's own clock
                    (wall time elapsed minus stream time elapsed since connect);
                    it grows when this PC cannot decode as fast as the camera sends
    pipeline        from the moment a frame was decoded until its JPEG was
                    written to a browser
    detection_age   how old a frame is when YOLO has finished with it
"""

import threading
import time
from collections import deque

RATE_WINDOW_SECONDS = 5.0
TIMING_SAMPLES = 120

_LOCK = threading.Lock()
_RATES = {}
_TIMINGS = {}
_VALUES = {}
_PROCESS = None


def rate(role, name):
    now = time.monotonic()
    with _LOCK:
        events = _RATES.setdefault((role, name), deque())
        events.append(now)
        while events and now - events[0] > RATE_WINDOW_SECONDS:
            events.popleft()


def timing(role, name, milliseconds):
    with _LOCK:
        samples = _TIMINGS.setdefault((role, name), deque(maxlen=TIMING_SAMPLES))
        samples.append(float(milliseconds))


def value(role, name, number):
    with _LOCK:
        _VALUES[(role, name)] = number


class timed:
    """with metrics.timed(role, "encode"): ..."""

    def __init__(self, role, name):
        self.role = role
        self.name = name

    def __enter__(self):
        self.started = time.perf_counter()
        return self

    def __exit__(self, *exc):
        timing(self.role, self.name, (time.perf_counter() - self.started) * 1000.0)
        return False


def cpu_percent():
    """Process CPU (100 = one full core) and whole-system CPU."""
    global _PROCESS
    try:
        import psutil

        if _PROCESS is None:
            _PROCESS = psutil.Process()
            _PROCESS.cpu_percent(None)
            psutil.cpu_percent(None)
        return {
            "process": round(_PROCESS.cpu_percent(None), 1),
            "system": round(psutil.cpu_percent(None), 1),
            "cores": psutil.cpu_count() or 0,
        }
    except Exception:
        return {"process": None, "system": None, "cores": None}


def snapshot():
    now = time.monotonic()
    result = {}
    with _LOCK:
        for (role, name), events in _RATES.items():
            while events and now - events[0] > RATE_WINDOW_SECONDS:
                events.popleft()
            result.setdefault(role, {}).setdefault("fps", {})[name] = round(len(events) / RATE_WINDOW_SECONDS, 1)
        for (role, name), samples in _TIMINGS.items():
            if not samples:
                continue
            ordered = sorted(samples)
            result.setdefault(role, {}).setdefault("ms", {})[name] = {
                "avg": round(sum(ordered) / len(ordered), 1),
                "p95": round(ordered[min(len(ordered) - 1, int(len(ordered) * 0.95))], 1),
            }
        for (role, name), number in _VALUES.items():
            result.setdefault(role, {}).setdefault("values", {})[name] = number
    return result


def summary_line(snap, cpu):
    parts = [f"CPU {cpu.get('process')}% (system {cpu.get('system')}%)"]
    for role, data in sorted(snap.items()):
        if role == "_":
            continue
        fps = data.get("fps", {})
        ms = data.get("ms", {})
        values = data.get("values", {})

        def avg(name):
            return ms.get(name, {}).get("avg")

        parts.append(
            f"{role}: capture {fps.get('capture')}fps, stream {fps.get('stream_published')}/{fps.get('stream_sent')}fps, "
            f"detect {fps.get('detection')}fps | decode {avg('decode')}ms overlay {avg('overlay')}ms "
            f"resize {avg('resize')}ms encode {avg('encode')}ms yolo {avg('yolo')}ms | "
            f"backlog {values.get('decode_backlog_ms')}ms pipeline {avg('pipeline')}ms "
            f"{values.get('resolution')}"
        )
    return " || ".join(parts)
