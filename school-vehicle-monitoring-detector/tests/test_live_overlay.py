"""
Live view work: the detector no longer draws on the video the browser plays
(WebRTC from go2rtc); it serves /overlay/{gate} JSON the browser draws.

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import sys
import time
import unittest
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import detector_service as detector  # noqa: E402


class OverlayPayload(unittest.TestCase):
    def test_boxes_are_shares_of_the_frame_with_label_color_zone_and_line(self):
        state = detector.initial_camera_state()
        now = time.monotonic()
        state["latest_frame"] = np.zeros((416, 736, 3), dtype=np.uint8)
        state["track_boxes"] = {7: {"xyxy": (73.6, 41.6, 368.0, 208.0), "last_seen": now}}
        state["track_overlays"] = {7: {"label": "REGISTERED - ABC 1234", "color": "green", "verification": "registered"}}
        state["recent_crossings"] = [{"track_id": 7, "direction": "IN", "at": now - 2}]
        camera = {"calibration_mask": [[0.1, 0.3], [0.9, 0.3], [0.9, 1], [0.1, 1]], "calibration_line": [[0.1, 0.6], [0.9, 0.6]]}

        payload = detector.overlay_payload(state, camera, now=now)

        self.assertTrue(payload["ready"])
        self.assertEqual([0.1, 0.1, 0.5, 0.5], payload["tracks"][0]["box"])
        self.assertEqual(("REGISTERED - ABC 1234", "#16a34a"), (payload["tracks"][0]["label"], payload["tracks"][0]["color"]))
        self.assertEqual(camera["calibration_line"], payload["line"])
        self.assertEqual([{"direction": "IN", "seconds_ago": 2.0}], payload["crossings"])

    def test_old_boxes_are_dropped_and_no_frame_means_not_ready(self):
        state = detector.initial_camera_state()
        self.assertEqual({"tracks": [], "ready": False}, detector.overlay_payload(state, {}))

        now = time.monotonic()
        state["latest_frame"] = np.zeros((416, 736, 3), dtype=np.uint8)
        state["track_boxes"] = {3: {"xyxy": (0, 0, 10, 10), "last_seen": now - 5}}
        self.assertEqual([], detector.overlay_payload(state, {}, now=now)["tracks"])


if __name__ == "__main__":
    unittest.main()
