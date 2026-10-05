"""
A3 (detection): one vehicle = one event. Real crossings only: a vehicle that
stops or backs up on the line, a parked vehicle, a flickering detection or
a short gap behind another vehicle must not add or lose an event.

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import sys
import time
import unittest
from pathlib import Path
from unittest import mock

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import detector_service as detector  # noqa: E402
from test_detection import CAMERA, FakeClient, tracked_results  # noqa: E402
from tracking import LineCrossing  # noqa: E402

LINE_Y = 0.6 * 416          # CAMERA's trigger line (pixels)
MARGIN = 0.05 * 0.7 * 416   # 5% of the zone's height = 14.6 px


class Gate(unittest.TestCase):
    def setUp(self):
        self.state = detector.initial_camera_state()
        self.frame = np.zeros((416, 736, 3), dtype=np.uint8)
        self.clock = [time.monotonic()]
        patcher = mock.patch("detector_service.time.monotonic", side_effect=lambda: self.clock[0])
        patcher.start()
        self.addCleanup(patcher.stop)

    def tearDown(self):
        self.state["pending_windows"].clear()  # stops the background RFID workers

    def seen(self, centre_y, track_id=7, seconds=0.125, perf=None, x=340):
        """One detection (about 8 per second) with the vehicle's centre at centre_y."""
        self.clock[0] += seconds
        rows = [[x - 40, centre_y - 30, x + 40, centre_y + 30, track_id, 0.9, 2]] if centre_y is not None else []
        detector.handle_detection("gate-1", self.frame, tracked_results(rows), None, CAMERA, perf or {"yolo_imgsz": 480},
                                  self.state, FakeClient(), {2: "Car"}, "cpu")

    def events(self):
        return self.state["line_crossings"]


class OneVehicleOneEventTests(Gate):
    def test_a_vehicle_driving_through_is_one_event_with_its_direction(self):
        for y in range(int(LINE_Y - 90), int(LINE_Y + 100), 20):
            self.seen(y)
        self.assertEqual(self.events(), 1)
        self.assertEqual(self.state["pending_windows"][7]["direction"], "IN")

    def test_stopping_and_rocking_on_the_line_is_not_an_event(self):
        for y in (LINE_Y - 80, LINE_Y - 40, LINE_Y - 5):
            self.seen(y)
        for step in range(30):
            self.seen(LINE_Y + (8 if step % 2 else -8))   # rocks across the line, inside the margin
        self.assertEqual(self.events(), 0)

    def test_crossing_backing_up_and_crossing_again_is_still_one_event(self):
        for y in (LINE_Y - 80, LINE_Y - 40, LINE_Y + 30):
            self.seen(y)
        for y in (LINE_Y - 40, LINE_Y - 60, LINE_Y + 30, LINE_Y + 60):  # backed up, drove through again
            self.seen(y)
        self.assertEqual(self.events(), 1)

    def test_a_parked_vehicle_is_never_counted(self):
        for step in range(60):
            self.seen(LINE_Y + 25 + (3 if step % 2 else -3))   # parked just past the line, box jitters
        self.assertEqual(self.events(), 0)

    def test_a_parked_vehicle_whose_id_keeps_changing_is_never_counted(self):
        for step in range(60):
            self.seen(LINE_Y + 25 + (3 if step % 2 else -3), track_id=100 + step // 5)
        self.assertEqual(self.events(), 0)

    def test_a_short_gap_behind_another_vehicle_does_not_lose_the_crossing(self):
        for y in (LINE_Y - 90, LINE_Y - 60, LINE_Y - 30):
            self.seen(y)
        for _ in range(20):                       # hidden for 2.5 s while it crosses
            self.seen(None)
        self.seen(LINE_Y + 60)
        self.assertEqual(self.events(), 1)

    def test_a_long_gap_starts_over_and_needs_a_real_crossing(self):
        for y in (LINE_Y - 90, LINE_Y - 60, LINE_Y - 30):
            self.seen(y)
        self.seen(None, seconds=6.0)               # gone for 6 s: a different visit
        for y in (LINE_Y + 60, LINE_Y + 80, LINE_Y + 100):
            self.seen(y)
        self.assertEqual(self.events(), 0)

    def test_the_limits_are_settings(self):
        strict = {"yolo_imgsz": 480, "cross_margin": 0.3}        # 87 px past the line
        for y in (LINE_Y - 80, LINE_Y - 40, LINE_Y + 40, LINE_Y + 60):
            self.seen(y, perf=strict)
        self.assertEqual(self.events(), 0)
        self.seen(LINE_Y + 100, perf=strict)
        self.assertEqual(self.events(), 1)


class LineCrossingTests(unittest.TestCase):
    LINE = {"x1": 100, "y1": 300, "x2": 600, "y2": 300}

    def test_needs_sightings_and_movement_and_the_drawn_span(self):
        crossing = LineCrossing()
        self.assertEqual([crossing.update(point, self.LINE, 10, 3, 30) for point in ((300, 280), (300, 330))], [0, 0])  # 2 sightings
        self.assertEqual(crossing.update((300, 340), self.LINE, 10, 3, 30), 1)
        self.assertEqual(crossing.update((300, 380), self.LINE, 10, 3, 30), 0)  # counted once

        beside = LineCrossing()  # moving past the end of the drawn line
        self.assertEqual([beside.update(point, self.LINE, 10, 3, 30) for point in ((700, 260), (700, 290), (700, 340))], [0, 0, 0])

        slow = LineCrossing()    # crept over: not enough movement since first seen
        self.assertEqual([slow.update(point, self.LINE, 10, 3, 60) for point in ((300, 285), (300, 300), (300, 312))], [0, 0, 0])


if __name__ == "__main__":
    unittest.main()
