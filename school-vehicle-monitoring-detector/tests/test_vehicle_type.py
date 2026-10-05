"""
A2 (detection): Car / Motorcycle / Truck/Bus from every frame of a vehicle,
a second check on its sharpest crop, and the size/shape rule for backs of
SUVs, AUVs and pickups that the model calls "truck".

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import sys
import unittest
from pathlib import Path
from unittest import mock

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import detector_service as detector  # noqa: E402
import vehicle_type as vtype  # noqa: E402
from test_detection import CAMERA, FakeClient, tracked_results  # noqa: E402

FRAME = (736, 416)  # width, height
ZONE_HEIGHT = 0.7 * 416  # CAMERA's zone: y 0.3 .. 1.0
LABELS = {2: vtype.CAR, 3: vtype.MOTORCYCLE, 5: vtype.TRUCK_BUS, 7: vtype.TRUCK_BUS}


def vote_with(*frames):
    vote = vtype.TrackVote()
    for vehicle_type, confidence, xyxy in frames:
        vote.add(vehicle_type, confidence, xyxy, FRAME)
    return vote


class TypeListTests(unittest.TestCase):
    def test_three_types_and_no_bicycles(self):
        self.assertEqual([vtype.type_for_label(label) for label in ("pickup", "SUV", "van", "car")], [vtype.CAR] * 4)
        self.assertEqual([vtype.type_for_label(label) for label in ("motorcycle", "e-bike", "tricycle")], [vtype.MOTORCYCLE] * 3)
        self.assertEqual([vtype.type_for_label(label) for label in ("truck", "bus", "jeepney")], [vtype.TRUCK_BUS] * 3)
        self.assertIsNone(vtype.type_for_label("bicycle"))

        class Coco:
            names = {0: "person", 1: "bicycle", 2: "car", 3: "motorcycle", 5: "bus", 7: "truck"}

        self.assertEqual(detector.resolve_allowed_vehicle_classes(Coco()), LABELS)


class DecisionTests(unittest.TestCase):
    WIDE_LOW_BACK = (250, 200, 450, 330)    # SUV / AUV from behind: 200 x 130, 45% of the zone
    TALL_BACK = (250, 100, 450, 360)        # truck from behind: 200 x 260, 89% of the zone

    def test_one_odd_frame_does_not_decide(self):
        vote = vote_with(
            (vtype.TRUCK_BUS, 0.45, (300, 230, 380, 290)),    # far, small, unsure
            (vtype.CAR, 0.8, self.WIDE_LOW_BACK),
            (vtype.CAR, 0.85, self.WIDE_LOW_BACK),
        )
        decision = vtype.decide(vote, None, ZONE_HEIGHT)
        self.assertEqual((decision["type"], decision["frames"]), (vtype.CAR, 3))

    def test_a_car_sized_back_called_truck_is_a_car(self):
        decision = vtype.decide(vote_with((vtype.TRUCK_BUS, 0.8, self.WIDE_LOW_BACK)), None, ZONE_HEIGHT)
        self.assertEqual(decision["type"], vtype.CAR)
        self.assertIn("size/shape", decision["rule"])

    def test_a_real_truck_stays_a_truck(self):
        tall = vtype.decide(vote_with((vtype.TRUCK_BUS, 0.8, self.TALL_BACK)), None, ZONE_HEIGHT)
        narrow = vtype.decide(vote_with((vtype.TRUCK_BUS, 0.8, (300, 200, 400, 320))), None, ZONE_HEIGHT)  # 100 x 120: a jeepney's back
        self.assertEqual((tall["type"], narrow["type"]), (vtype.TRUCK_BUS, vtype.TRUCK_BUS))

    def test_the_limits_are_settings(self):
        vote = vote_with((vtype.TRUCK_BUS, 0.8, self.WIDE_LOW_BACK))
        self.assertEqual(vtype.decide(vote, None, ZONE_HEIGHT, {"type_truck_min_height": 0.4})["type"], vtype.TRUCK_BUS)
        self.assertEqual(vtype.decide(vote, None, ZONE_HEIGHT, {"type_car_min_aspect": 1.8})["type"], vtype.TRUCK_BUS)

    def test_the_second_check_counts_as_much_as_all_frames(self):
        vote = vote_with((vtype.MOTORCYCLE, 0.4, (330, 230, 370, 300)))
        decision = vtype.decide(vote, {"type": vtype.CAR, "confidence": 0.9}, ZONE_HEIGHT)
        self.assertEqual(decision["type"], vtype.CAR)
        self.assertIn("second check", decision["rule"])

    def test_no_votes_keeps_the_trigger_frame_type(self):
        self.assertEqual(vtype.decide(vtype.TrackVote(), None, ZONE_HEIGHT, fallback=vtype.MOTORCYCLE)["type"], vtype.MOTORCYCLE)


class SecondCheckTests(unittest.TestCase):
    def test_the_second_check_reads_a_full_size_crop(self):
        import ultralytics

        image = cv2.imread(str(Path(ultralytics.__file__).resolve().parent / "assets" / "bus.jpg"))
        result = detector.second_pass_type(image, {"type_model": "yolov8n.pt", "type_second_pass_imgsz": 640})
        self.assertEqual((result["type"], result["model"], result["imgsz"]), (vtype.TRUCK_BUS, "yolov8n.pt", 640))
        self.assertIsNone(detector.second_pass_type(np.zeros((0, 0, 3), dtype=np.uint8), {}))


class PipelineTests(unittest.TestCase):
    """Every frame of the track votes; the crossing carries the final type and why."""

    def setUp(self):
        self.state = detector.initial_camera_state()
        self.frame = np.zeros((416, 736, 3), dtype=np.uint8)

    def tearDown(self):
        self.state["pending_windows"].clear()

    def step(self, top, bottom, class_id, confidence=0.8, left=250, right=450):
        rows = [[left, top, right, bottom, 7, confidence, class_id]]
        detector.handle_detection("gate-1", self.frame, tracked_results(rows), None, CAMERA, {"yolo_imgsz": 480},
                                  self.state, FakeClient(), LABELS, "cpu")

    def test_the_crossing_gets_the_voted_type_not_the_trigger_frame(self):
        line_y = 0.6 * 416
        self.step(line_y - 140, line_y - 60, 2)          # Car, above the line
        self.step(line_y - 100, line_y - 10, 2)          # Car
        self.step(line_y - 20, line_y + 70, 7, 0.55)     # "truck" just past the line (the trigger frame)
        self.step(line_y + 20, line_y + 120, 2)          # Car, below
        window = self.state["pending_windows"][7]
        self.assertEqual(window["detected_vehicle_type"], vtype.TRUCK_BUS)  # the trigger frame alone said truck
        self.assertEqual(window["vote"].frames, 4)

        sent = []

        class Client(FakeClient):
            def submit_crossing(self, payload, image_bytes=None, filename=None):
                sent.append(payload)
                return {"accepted": True}

        window["deadline_at"] = 0
        with mock.patch.object(detector, "performance_settings", return_value={**vtype.DEFAULTS, "type_second_pass": 0}):
            detector.submit_crossing_for_window("gate-1", self.state, 7, window, "no_pass", Client())

        self.assertEqual(sent[0]["detected_vehicle_type"], vtype.CAR)
        self.assertEqual(sent[0]["detection_metadata"]["vehicle_type"]["frames"], 4)
        self.assertEqual(window["detected_vehicle_type"], vtype.CAR)  # the visitor record gets it too


if __name__ == "__main__":
    unittest.main()
