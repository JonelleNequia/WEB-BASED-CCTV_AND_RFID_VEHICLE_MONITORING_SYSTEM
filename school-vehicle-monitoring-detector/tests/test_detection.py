"""
Detection pipeline: crop offset, vehicle filter, line crossing, debug view.

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import sys
import unittest
from pathlib import Path

import numpy as np
import torch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import detector_service as detector  # noqa: E402
from tracking import path_crosses_line  # noqa: E402

LINE = {"x1": 100, "y1": 300, "x2": 600, "y2": 300}
ZONE = [{"x": 0.0, "y": 0.3}, {"x": 1.0, "y": 0.3}, {"x": 1.0, "y": 1.0}, {"x": 0.0, "y": 1.0}]
CAMERA = {"calibration_mask": ZONE, "calibration_line": {"x1": 0.1, "y1": 0.6, "x2": 0.9, "y2": 0.6}, "camera_id": 1}


def tracked_results(rows, shape=(416, 736)):
    """YOLO-like results: rows of (x1, y1, x2, y2, track_id, conf, cls), made in inference mode like YOLO does."""
    from ultralytics.engine.results import Results

    with torch.inference_mode():
        data = torch.tensor(rows, dtype=torch.float32) if rows else torch.zeros((0, 7))
    results = Results(np.zeros((shape[0], shape[1], 3), dtype=np.uint8), path="", names={0: "person", 2: "car", 7: "truck"})
    from ultralytics.engine.results import Boxes

    results.boxes = Boxes(data, shape)
    return results


class FakeClient:
    def check_rfid_match(self, *args, **kwargs):
        return {"matched": False}

    def submit_guest_observation(self, *args, **kwargs):
        return {"accepted": True, "created": True, "duplicate": False, "message": "", "body": {}, "overlay": None}


class CropOffsetTests(unittest.TestCase):
    def test_boxes_from_a_crop_move_back_without_the_inference_tensor_error(self):
        # The old in-place "+=" raised "Inplace update to inference tensor
        # outside InferenceMode" on every frame with a vehicle.
        results = tracked_results([[10, 20, 110, 90, 5, 0.8, 2]], shape=(262, 736))
        results = detector.offset_results(results, 0, 154, (416, 736, 3))
        self.assertEqual(results.boxes.xyxy.tolist(), [[10.0, 174.0, 110.0, 244.0]])
        self.assertEqual(results.boxes.id.tolist(), [5.0])
        self.assertEqual(tuple(results.boxes.orig_shape), (416, 736))


class VehicleFilterTests(unittest.TestCase):
    def test_raw_list_keeps_everything_and_results_keep_vehicles(self):
        state = detector.initial_camera_state()
        labels = {2: "Car", 7: "Truck"}
        results = tracked_results([
            [10, 10, 50, 50, 1, 0.90, 2],    # car
            [60, 10, 90, 50, 2, 0.20, 2],    # weak car, never confirmed
            [100, 10, 140, 50, 3, 0.95, 0],  # person
        ])
        raw = detector.split_raw_detections(results, state, labels)
        self.assertEqual([item["reason"] for item in raw], ["vehicle", "low confidence", "not a vehicle"])
        self.assertEqual(results.boxes.id.int().tolist(), [1])

        # The confirmed car dips to 0.2 for one frame: still kept (track not lost).
        results = tracked_results([[12, 12, 52, 52, 1, 0.20, 2]])
        detector.split_raw_detections(results, state, labels)
        self.assertEqual(results.boxes.id.int().tolist(), [1])


class LineCrossingTests(unittest.TestCase):
    def test_segment_crossing_counts_a_jump_over_the_line(self):
        self.assertEqual(path_crosses_line((300, 200), (300, 420), LINE), 1)   # jumped from above to far below
        self.assertEqual(path_crosses_line((300, 420), (300, 200), LINE), -1)  # other direction
        self.assertEqual(path_crosses_line((300, 200), (300, 280), LINE), 0)   # not yet
        self.assertEqual(path_crosses_line((50, 200), (50, 420), LINE), 0)     # beside the drawn line
        self.assertEqual(path_crosses_line(None, (300, 420), LINE), 0)

    def test_crossing_survives_a_missed_detection(self):
        state = detector.initial_camera_state()
        labels = {2: "Car"}
        frame = np.zeros((416, 736, 3), dtype=np.uint8)
        line_y = 0.6 * 416

        def step(rows):
            results = tracked_results(rows)
            detector.handle_detection("entrance", frame, results, None, CAMERA, {"yolo_imgsz": 480}, state, FakeClient(), labels, "cpu")

        step([[300, line_y - 90, 380, line_y - 40, 7, 0.9, 2]])   # above the line
        step([])                                                   # YOLO missed it this frame
        step([[300, line_y + 30, 380, line_y + 90, 7, 0.9, 2]])   # already below
        self.assertEqual(state["line_crossings"], 1)
        self.assertIn(7, state["pending_windows"])
        debug = state["debug"]
        self.assertEqual((debug["raw_count"], debug["vehicle_count"], debug["in_roi"]), (1, 1, 1))
        state["pending_windows"].clear()

    def test_debug_overlay_draws_on_the_frame(self):
        state = detector.initial_camera_state()
        frame = np.zeros((416, 736, 3), dtype=np.uint8)
        results = tracked_results([[300, 200, 380, 260, 3, 0.9, 2]])
        detector.handle_detection("entrance", frame, results, None, CAMERA, {"yolo_imgsz": 480}, state, FakeClient(), {2: "Car"}, "cpu")
        drawn = frame.copy()
        detector.draw_debug_overlay(drawn, CAMERA, state)
        self.assertGreater(int(np.count_nonzero(drawn)), 1000)


if __name__ == "__main__":
    unittest.main()


class GateConfigTests(unittest.TestCase):
    """Phase 1: the detector and device service follow Laravel's gate list."""

    def test_cameras_follow_the_exported_gate_order(self):
        import json
        import tempfile

        import config

        payload = {
            "gates": [{"code": "gate-2", "name": "Back Gate"}, {"code": "gate-1", "name": "Main Gate"}, {"code": "gate-3", "name": "Service"}],
            "cameras": {role: {"camera_role": role, "source_type": "webcam", "source_value": 0} for role in ("gate-1", "gate-3", "gate-2")},
        }
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "camera_runtime_config.json"
            path.write_text(json.dumps(payload), encoding="utf-8")
            original = config.RUNTIME_CONFIG_PATH
            config.RUNTIME_CONFIG_PATH = path
            try:
                loaded = config._load_runtime_config_file()
            finally:
                config.RUNTIME_CONFIG_PATH = original

        self.assertEqual(config.camera_roles(loaded), ["gate-2", "gate-1", "gate-3"])
        self.assertEqual(loaded["gates"][0]["name"], "Back Gate")

    def test_device_service_has_a_reader_link_per_gate(self):
        import device_service

        runtime = {"stations": {"gate-1": {}, "gate-2": {}, "gate-3": {}}}
        self.assertEqual(device_service.station_codes(runtime), ["gate-1", "gate-2", "gate-3"])
        self.assertEqual(device_service.station_codes({}), ["gate-1", "gate-2"])
        self.assertEqual(device_service.LEGACY_STATIONS["entrance"], "gate-1")
