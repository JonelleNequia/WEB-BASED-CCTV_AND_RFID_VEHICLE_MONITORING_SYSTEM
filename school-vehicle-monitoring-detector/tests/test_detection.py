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

        step([[300, line_y - 130, 380, line_y - 80, 7, 0.9, 2]])  # above the line
        step([[300, line_y - 90, 380, line_y - 40, 7, 0.9, 2]])   # closer (A3: 3 sightings needed)
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


class GateConfigTests(unittest.TestCase):
    """Phase 1: the detector and device service follow Laravel's gate list."""

    def test_cameras_follow_the_exported_gate_order(self):
        import json
        import tempfile

        import config

        payload = {
            "gates": [{"code": "gate-2", "name": "Back Gate"}, {"code": "gate-1", "name": "Main Gate"}, {"code": "gate-3", "name": "Service"}],
            "cameras": {role: {"camera_role": role, "source_type": "none", "source_value": ""} for role in ("gate-1", "gate-3", "gate-2")},
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


class DirectionTests(unittest.TestCase):
    """Phase 2: IN / OUT from which side of the line the vehicle moved to."""

    LINE_Y = 0.6 * 416

    def setUp(self):
        self.state = detector.initial_camera_state()
        self.frame = np.zeros((416, 736, 3), dtype=np.uint8)

    def tearDown(self):
        self.state["pending_windows"].clear()  # stops the background RFID workers

    def camera(self, in_side=1):
        return {**CAMERA, "calibration_line": {**CAMERA["calibration_line"], "in_side": in_side}}

    def step(self, camera, top, bottom, track_id=7):
        rows = [[300, top, 380, bottom, track_id, 0.9, 2]] if top is not None else []
        detector.handle_detection("gate-1", self.frame, tracked_results(rows), None, camera, {"yolo_imgsz": 480}, self.state, FakeClient(), {2: "Car"}, "cpu")

    def window(self, track_id=7):
        return self.state["pending_windows"][track_id]

    def test_helpers(self):
        from tracking import crossing_direction, line_in_side, trail_direction

        self.assertEqual(line_in_side({"calibration_line": {"x1": 0, "y1": 0, "x2": 1, "y2": 0}}), 1)  # lines saved before Phase 2
        self.assertEqual(line_in_side(self.camera(-1)), -1)
        self.assertEqual((crossing_direction(1, 1), crossing_direction(-1, 1), crossing_direction(1, -1)), ("IN", "OUT", "OUT"))
        self.assertIsNone(crossing_direction(0, 1))
        self.assertEqual(trail_direction([(300, 200), (300, 280), (300, 420)], LINE, 1), "IN")
        self.assertIsNone(trail_direction([(300, 200)], LINE, 1))

    def test_fast_vehicle_jumping_the_line_gets_its_direction_at_once(self):
        camera = self.camera(1)
        self.step(camera, self.LINE_Y - 130, self.LINE_Y - 80)  # above
        self.step(camera, self.LINE_Y - 90, self.LINE_Y - 40)   # above, not touching
        self.step(camera, self.LINE_Y + 30, self.LINE_Y + 90)   # already below
        self.assertEqual((self.window()["direction"], self.window()["direction_reason"]), ("IN", "crossed the line"))

    def test_same_gate_records_in_and_out_and_the_arrow_flips_it(self):
        for camera, expected in ((self.camera(1), "OUT"), (self.camera(-1), "IN")):
            self.state = detector.initial_camera_state()
            self.step(camera, self.LINE_Y + 70, self.LINE_Y + 130)  # below
            self.step(camera, self.LINE_Y + 30, self.LINE_Y + 90)
            self.step(camera, self.LINE_Y - 90, self.LINE_Y - 40)   # moved up
            self.assertEqual(self.window()["direction"], expected)

    def test_a_box_touching_the_line_is_not_a_crossing_until_it_is_clearly_past(self):
        # A3: touching, or the centre just over the line, is not counted yet.
        camera = self.camera(1)
        self.step(camera, self.LINE_Y - 90, self.LINE_Y - 30)
        self.step(camera, self.LINE_Y - 50, self.LINE_Y + 10)   # touches; centre above
        self.step(camera, self.LINE_Y - 28, self.LINE_Y + 32)   # centre 2 px below: inside the margin
        self.assertNotIn(7, self.state["pending_windows"])
        self.step(camera, self.LINE_Y - 10, self.LINE_Y + 50)   # centre 20 px below (margin 15 px)
        self.assertEqual((self.window()["direction"], self.window()["direction_reason"]), ("IN", "crossed the line"))

    def test_a_track_seen_once_on_the_line_is_not_counted(self):
        # A3: one sighting is not a crossing (it was an "UNKNOWN" crossing before).
        self.step(self.camera(1), self.LINE_Y - 50, self.LINE_Y + 10)
        self.step(self.camera(1), None, None)
        self.assertNotIn(7, self.state["pending_windows"])
        self.assertEqual(self.state["line_crossings"], 0)

    def test_a_crossing_whose_direction_is_still_open_is_sent_as_unknown(self):
        class CrossingClient(FakeClient):
            def __init__(self):
                self.crossings = []

            def submit_crossing(self, payload, image_bytes=None, filename=None):
                self.crossings.append((payload, bool(image_bytes)))
                return {"accepted": True, "created": True}

        # Older windows could start without a direction (Phase 2); the sending code still handles it.
        self.step(self.camera(1), self.LINE_Y - 130, self.LINE_Y - 80)
        self.step(self.camera(1), self.LINE_Y - 90, self.LINE_Y - 40)
        self.step(self.camera(1), self.LINE_Y + 30, self.LINE_Y + 90)
        window = self.window()
        window.update({"direction": None, "start_side": None, "trail_length": 0})
        window["deadline_at"] = 0
        client = CrossingClient()
        detector.submit_crossing_for_window("gate-1", self.state, 7, window, "no_pass", client)

        payload, has_snapshot = client.crossings[0]
        self.assertEqual((payload["direction"], payload["direction_reason"]), ("UNKNOWN", "track too short"))
        self.assertEqual((payload["camera_role"], payload["track_id"], payload["confidence"]), ("gate-1", 7, 0.9))
        self.assertTrue(has_snapshot)
        self.assertEqual(self.state["direction_counts"]["UNKNOWN"], 1)
        self.assertNotIn(7, self.state["open_crossings"])


class FusionWindowTests(unittest.TestCase):
    """Phase 3: the RFID window follows Settings > Gates & Readers."""

    def test_window_lengths_come_from_the_exported_gate_settings(self):
        state = detector.initial_camera_state()
        frame = np.zeros((416, 736, 3), dtype=np.uint8)
        camera = {**CAMERA, "rfid_window_seconds": 6, "rfid_lookback_seconds": 12}
        line_y = 0.6 * 416
        for top in (line_y - 130, line_y - 90, line_y + 30):  # A3: 3 sightings
            detector.handle_detection("gate-1", frame, tracked_results([[300, top, 380, top + 60, 7, 0.9, 2]]), None, camera,
                                      {"yolo_imgsz": 480}, state, FakeClient(), {2: "Car"}, "cpu")
        window = state["pending_windows"][7]
        self.assertEqual((window["window_seconds"], window["lookback_seconds"]), (6.0, 12.0))
        self.assertAlmostEqual(window["deadline_at"] - window["started_at"], 6.0, places=3)
        state["pending_windows"].clear()

        self.assertEqual(detector.rfid_seconds({}, "rfid_window_seconds", 4.0), 4.0)  # older export
        self.assertEqual(detector.rfid_seconds({"rfid_window_seconds": 99}, "rfid_window_seconds", 4.0), 15.0)


if __name__ == "__main__":
    unittest.main()
