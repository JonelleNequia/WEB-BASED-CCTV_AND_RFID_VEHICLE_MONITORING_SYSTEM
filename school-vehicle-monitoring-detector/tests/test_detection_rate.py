"""
Phase 4: faster, steadier detection. YOLO runs at the busy rate while
something moves in the zone or a vehicle is in it, at the idle rate
otherwise, on a schedule that reaches the set rate on any camera; and the
pass rules keep one record per vehicle through ID changes, a vehicle close
behind another, and a post in front of it.

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import sys
import unittest
from pathlib import Path

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import detector_service as detector  # noqa: E402
from motion import MotionGate  # noqa: E402
from tracking import VehiclePass  # noqa: E402

ZONE = [{"x": 0.0, "y": 0.3}, {"x": 1.0, "y": 0.3}, {"x": 1.0, "y": 1.0}, {"x": 0.0, "y": 1.0}]
ZONE_PX = [(0, 162), (960, 162), (960, 540), (0, 540)]
PERF = {"detection_fps": 15.0, "detection_idle_fps": 5.0}


class ScheduleTests(unittest.TestCase):
    def runs_per_second(self, camera_fps, rate):
        state, runs = {}, 0
        for frame in range(int(camera_fps * 10)):
            if detector.detection_due(state, frame / camera_fps, 1.0 / rate):
                runs += 1
        return runs / 10

    def test_the_set_rate_is_reached_on_25_and_30_fps_cameras(self):
        # Before: "a whole interval since the last run" gave 12.5 on 25 fps and 7.5 for 8 on 30 fps.
        self.assertAlmostEqual(self.runs_per_second(25, 15), 15, delta=0.2)
        self.assertAlmostEqual(self.runs_per_second(30, 15), 15, delta=0.2)
        self.assertAlmostEqual(self.runs_per_second(30, 8), 8, delta=0.2)

    def test_busy_while_something_moves_or_a_vehicle_is_in_the_zone_idle_otherwise(self):
        state = {"passes": {}}
        self.assertAlmostEqual(detector.detection_interval(state, PERF, 100.0), 1 / 5)
        state["motion_at"] = 99.5
        self.assertAlmostEqual(detector.detection_interval(state, PERF, 100.0), 1 / 15)
        self.assertAlmostEqual(detector.detection_interval(state, PERF, 101.0), 1 / 5)      # 1 s after the movement
        state["passes"] = {1: object()}
        self.assertAlmostEqual(detector.detection_interval(state, PERF, 200.0), 1 / 15)
        self.assertEqual(detector.idle_detection_fps({"detection_fps": 4, "detection_idle_fps": 5}), 4)  # never above busy

    def test_left_zone_is_a_time(self):
        self.assertEqual(detector.pass_settings({"detection_fps": 15, "pass_lost_seconds": 0.75}, {})[1], 11)
        self.assertEqual(detector.pass_settings({"detection_fps": 8, "pass_lost_seconds": 0.75}, {})[1], 6)
        self.assertEqual(detector.pass_settings({"detection_fps": 15, "pass_lost_seconds": None, "pass_lost_frames": 6}, {})[1], 6)

    def test_the_input_size_follows_the_device(self):
        self.assertEqual(detector.yolo_input_size({"yolo_imgsz": "auto"}, "mps"), 640)
        self.assertEqual(detector.yolo_input_size({"yolo_imgsz": "auto"}, "cuda:0"), 640)
        self.assertEqual(detector.yolo_input_size({"yolo_imgsz": "auto"}, "cpu"), 480)
        self.assertEqual(detector.yolo_input_size({"yolo_imgsz": 512}, "mps"), 512)


class MotionTests(unittest.TestCase):
    def frame(self, block_x=None, block_y=400):
        frame = np.full((540, 960, 3), 80, np.uint8)
        if block_x is not None:
            frame[block_y - 60:block_y, block_x:block_x + 80] = 200
        return frame

    def test_a_moving_vehicle_in_the_zone_is_seen_not_noise_or_the_outside(self):
        gate = MotionGate()
        self.assertFalse(gate.moved(self.frame(400), ZONE, 0.0))                 # first frame: nothing to compare
        self.assertTrue(gate.moved(self.frame(440), ZONE, 0.2))
        noisy = self.frame(440).astype(np.int16) + np.random.default_rng(1).integers(-4, 5, (540, 960, 3))
        self.assertFalse(gate.moved(np.clip(noisy, 0, 255).astype(np.uint8), ZONE, 0.4))
        gate = MotionGate()
        gate.moved(self.frame(400, block_y=100), ZONE, 0.0)
        self.assertFalse(gate.moved(self.frame(440, block_y=100), ZONE, 0.2))  # above the zone
        self.assertIsNone(gate.moved(self.frame(480), ZONE, 0.25))             # checked at most every 0.1 s


class PassRuleTests(unittest.TestCase):
    def moving_pass(self, bottoms, height=120, x1=400, x2=560):
        vehicle_pass = VehiclePass([x1, bottoms[0] - height, x2, bottoms[0]], (960, 540))
        for bottom in bottoms[1:]:
            vehicle_pass.observe([x1, bottom - height, x2, bottom])
        vehicle_pass.last_xyxy = (x1, bottoms[-1] - height, x2, bottoms[-1])
        return vehicle_pass

    def test_the_car_behind_gets_its_own_pass_when_the_tracker_hands_it_the_id(self):
        front = self.moving_pass([300, 330, 360, 390, 420, 450], height=200)
        behind = [420, 300, 560, 400]                                # higher up and smaller: the next car
        self.assertTrue(detector.another_vehicle_took_the_id(front, behind, ZONE_PX))
        self.assertFalse(detector.another_vehicle_took_the_id(front, [400, 280, 560, 480], ZONE_PX))  # the same car, a bit further

    def test_a_vehicle_seen_again_after_a_post_hid_it_keeps_its_pass(self):
        vehicle_pass = self.moving_pass([200, 220, 240])
        for _ in range(4):
            vehicle_pass.miss()
        self.assertTrue(detector.reappears_on_its_path(vehicle_pass, [400, 220, 560, 340]))   # where it would be now
        self.assertTrue(detector.reappears_on_its_path(vehicle_pass, [500, 230, 560, 340]))   # only its right part shows yet
        self.assertFalse(detector.reappears_on_its_path(vehicle_pass, [400, 360, 560, 480]))  # far from there

    def test_a_slice_beside_the_vehicle_is_the_same_vehicle_a_car_beside_it_is_not(self):
        car = (537, 5, 960, 317)
        self.assertTrue(detector.a_slice_of((348, 22, 464, 350), car))      # the part left of a post
        self.assertFalse(detector.a_slice_of((100, 5, 500, 317), car))      # a car of its own size beside it
        self.assertTrue(detector.boxes_overlap_mostly((400, 100, 560, 300), (405, 110, 555, 290)))


if __name__ == "__main__":
    unittest.main()
