"""
RFID only with a vehicle: every UHF read goes into this in-memory buffer
(per gate), never straight into the database. Laravel takes a tag from it
only when the camera sees a vehicle cross the trigger line.

A "presence" is one continuous stay of a tag in front of a reader: reads
less than `absent_seconds` apart belong to the same presence. For each one:
first and last read, number of reads, the strongest RSSI and when it was
read (the vehicle is closest to the antenna then). A presence that lasts
longer than `stationary_seconds` is a parked vehicle near the gate: it is
marked stationary and never given to a passing vehicle. Presences are
forgotten `window_seconds` after their last read.

The buffer is written to rfid_buffer.json (atomically) for Laravel.
"""

import threading
import time
from collections import Counter, deque

MAX_SAMPLES = 60


class TagBuffer:
    def __init__(self, window_seconds=15.0, absent_seconds=5.0, stationary_seconds=60.0):
        self.window_seconds = float(window_seconds)
        self.absent_seconds = float(absent_seconds)
        self.stationary_seconds = float(stationary_seconds)
        self.lock = threading.Lock()
        self.presences = {}            # (station, epc) -> dict
        self.raw_reads = Counter()     # per station, since the service started
        self.presences_started = Counter()
        self.ended = deque(maxlen=200)  # recently ended presences (diagnostics)
        self.version = 0

    def configure(self, window_seconds=None, absent_seconds=None, stationary_seconds=None):
        with self.lock:
            if window_seconds is not None:
                self.window_seconds = max(5.0, float(window_seconds))
            if absent_seconds is not None:
                self.absent_seconds = max(1.0, float(absent_seconds))
            if stationary_seconds is not None:
                self.stationary_seconds = max(10.0, float(stationary_seconds))

    def add(self, station, epc, rssi=None, now=None):
        """One read of a tag at a gate's reader (wall-clock seconds)."""
        now = time.time() if now is None else float(now)
        key = (station, epc)
        with self.lock:
            self.raw_reads[station] += 1
            presence = self.presences.get(key)
            if presence is None or now - presence["last_seen"] > self.absent_seconds:
                if presence is not None:
                    self._end(station, presence)
                presence = {
                    "epc": epc, "session": round(now, 3), "first_seen": now, "last_seen": now, "reads": 0,
                    "max_rssi": None, "peak_at": now, "samples": deque(maxlen=MAX_SAMPLES),
                }
                self.presences[key] = presence
                self.presences_started[station] += 1
            presence["last_seen"] = now
            presence["reads"] += 1
            presence["samples"].append((round(now, 3), rssi))
            if rssi is not None and (presence["max_rssi"] is None or rssi > presence["max_rssi"]):
                presence["max_rssi"] = rssi
                presence["peak_at"] = now
            self.version += 1

    def _end(self, station, presence):
        self.ended.append({"station": station, "epc": presence["epc"], "session": presence["session"],
                           "ended_at": round(presence["last_seen"], 3), "reads": presence["reads"]})

    def expire(self, now=None):
        """Forget presences whose last read is older than the window."""
        now = time.time() if now is None else float(now)
        with self.lock:
            for key, presence in list(self.presences.items()):
                if now - presence["last_seen"] > self.window_seconds:
                    self._end(key[0], presence)
                    del self.presences[key]
                    self.version += 1

    def clear_station(self, station):
        """Delete device work: forget a gate's reads (its reader was deleted)."""
        with self.lock:
            for key in [key for key in self.presences if key[0] == station]:
                del self.presences[key]
            self.raw_reads.pop(station, None)
            self.presences_started.pop(station, None)
            self.ended = type(self.ended)((item for item in self.ended if item["station"] != station), maxlen=self.ended.maxlen)
            self.version += 1

    def is_stationary(self, presence, now=None):
        now = time.time() if now is None else now
        # Still being read, and read for longer than a passing vehicle can be.
        return now - presence["last_seen"] <= self.absent_seconds and \
            presence["last_seen"] - presence["first_seen"] > self.stationary_seconds

    def snapshot(self, now=None):
        now = time.time() if now is None else float(now)
        with self.lock:
            gates = {}
            for (station, _epc), presence in self.presences.items():
                gates.setdefault(station, []).append({
                    "epc": presence["epc"],
                    "session": presence["session"],
                    "first_seen": round(presence["first_seen"], 3),
                    "last_seen": round(presence["last_seen"], 3),
                    "reads": presence["reads"],
                    "max_rssi": presence["max_rssi"],
                    "peak_at": round(presence["peak_at"], 3),
                    "stationary": self.is_stationary(presence, now),
                    "samples": [list(sample) for sample in presence["samples"]][-20:],
                })
            return {
                "generated_at": round(now, 3),
                "window_seconds": self.window_seconds,
                "absent_seconds": self.absent_seconds,
                "stationary_seconds": self.stationary_seconds,
                "gates": gates,
                "raw_reads": dict(self.raw_reads),
                "presences_started": dict(self.presences_started),
                "ended": list(self.ended),
            }
