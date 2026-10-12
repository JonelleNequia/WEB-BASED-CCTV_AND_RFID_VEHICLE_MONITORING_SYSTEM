"""
ROI work: no trigger line. A vehicle is one pass through the zone:
Detected (a few frames in the zone: RFID check starts), Recorded (moved far
enough along the IN arrow and the RFID window is over), Left zone. One
track = one pass = one record, updated (never doubled) until it leaves.

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import sys
import threading
import time
import unittest
from pathlib import Path
from unittest import mock

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import detector_service as detector  # noqa: E402
from test_detection import FakeClient, tracked_results  # noqa: E402
from tracking import VehiclePass  # noqa: E402

# The frame-loop tests patch threading.Thread (no pass workers); helpers here use the real one.
RealThread = threading.Thread

H, W = 416, 736
ZONE = [{"x": 0.0, "y": 0.3}, {"x": 1.0, "y": 0.3}, {"x": 1.0, "y": 1.0}, {"x": 0.0, "y": 1.0}]
DOWN = {"x1": 0.5, "y1": 0.45, "x2": 0.5, "y2": 0.85}       # IN = driving down the picture
CAMERA = {"calibration_mask": ZONE, "calibration_direction": DOWN, "camera_id": 1}
ZONE_PX = [(0, int(0.3 * H)), (W, int(0.3 * H)), (W, H), (0, H)]
ARROW_PX = {"x1": 368, "y1": 0.45 * H, "x2": 368, "y2": 0.85 * H}
ZONE_LEN = 0.7 * H                                            # the zone's length along the arrow: 291 px
FIRST_PASS = detector.PASS_KEY_START + 1                      # the key of a fresh state's first pass


def box(bottom, height=60, x=340, width=80):
    return [x - width / 2, bottom - height, x + width / 2, bottom]


class PassDecisionTests(unittest.TestCase):
    def path(self, bottoms, heights=None):
        heights = heights or [60] * len(bottoms)
        vehicle_pass = VehiclePass(box(bottoms[0], heights[0]))
        for bottom, height in zip(bottoms[1:], heights[1:]):
            vehicle_pass.observe(box(bottom, height))
        return vehicle_pass

    def test_moving_far_enough_along_the_arrow_is_in_against_it_out(self):
        down = self.path([200, 230, 260, 290, 320])               # 120 px = 41% of the zone
        decision = down.decide(ARROW_PX, ZONE_PX, 0.20)
        self.assertEqual((decision["direction"], decision["confidence"]), ("IN", "high"))
        self.assertEqual(decision["reason"], "moved 41% of the zone with the IN arrow")
        up = self.path([320, 290, 260, 230, 200])
        self.assertEqual(up.decide(ARROW_PX, ZONE_PX, 0.20)["direction"], "OUT")
        self.assertIn("against the IN arrow", up.decide(ARROW_PX, ZONE_PX, 0.20)["reason"])

    def test_the_share_is_of_the_zone_along_the_arrow_not_its_height(self):
        right = {"x1": 100, "y1": 300, "x2": 500, "y2": 300}       # arrow across the picture: zone length = 736 px
        vehicle_pass = VehiclePass([100, 240, 180, 300])
        for x in (200, 300):
            vehicle_pass.observe([x, 240, x + 80, 300])
        decision = vehicle_pass.decide(right, ZONE_PX, 0.20)
        self.assertEqual(decision["along"], round(200 / 736, 3))
        self.assertEqual(decision["direction"], "IN")

    def test_not_far_enough_waits_then_is_unknown_and_a_still_vehicle_is_not_counted(self):
        short = self.path([300, 310, 320, 330])                   # 30 px = 10%
        self.assertIsNone(short.decide(ARROW_PX, ZONE_PX, 0.20)["direction"])
        final = short.decide(ARROW_PX, ZONE_PX, 0.20, final=True)
        self.assertEqual(final["direction"], "UNKNOWN")
        self.assertTrue(final["reason"].startswith("Direction unknown: moved only 10%"))
        still = self.path([300, 303, 299, 302, 300, 301])
        self.assertEqual(still.decide(ARROW_PX, ZONE_PX, 0.20, final=True)["direction"], "STILL")

    def test_a_clear_matching_size_change_makes_a_shorter_move_medium_confidence(self):
        closer = self.path([280, 295, 310, 325], [60, 70, 80, 90])  # 15% down, box grew 50%
        decision = closer.decide(ARROW_PX, ZONE_PX, 0.20)
        self.assertEqual((decision["direction"], decision["confidence"]), ("IN", "medium"))
        self.assertIn("box grew 50%", decision["reason"])
        odd = self.path([280, 295, 310, 325], [90, 80, 70, 60])     # went down but got smaller: not trusted
        self.assertIsNone(odd.decide(ARROW_PX, ZONE_PX, 0.20)["direction"])


class Gate(unittest.TestCase):
    """The detector's frame loop with passes; the pass workers are not started (tested below)."""

    def setUp(self):
        self.state = detector.initial_camera_state()
        self.frame = np.zeros((H, W, 3), dtype=np.uint8)
        patcher = mock.patch("detector_service.threading.Thread")
        self.thread = patcher.start()
        self.addCleanup(patcher.stop)

    def seen(self, bottom, track_id=7, camera=None, height=60):
        rows = [box(bottom, height) + [track_id, 0.9, 2]] if bottom is not None else []
        detector.handle_detection("gate-1", self.frame, tracked_results(rows), None, camera or CAMERA, {"yolo_imgsz": 480},
                                  self.state, FakeClient(), {2: "Car"}, "cpu")

    def window(self, key=None):
        """The first pass's record window (passes have their own keys, not the tracker's ID)."""
        return self.state["pending_windows"].get(FIRST_PASS if key is None else key)


class PassLifecycleTests(Gate):
    def test_detected_after_three_frames_in_the_zone_and_the_rfid_check_starts(self):
        self.seen(200)
        self.seen(205)
        self.assertIsNone(self.window())
        self.seen(210)
        self.assertEqual(self.state["passes_detected"], 1)
        self.assertEqual(self.state["track_overlays"][7], detector.waiting_overlay())
        self.assertEqual(self.thread.call_count, 1)                # the pass worker (RFID, record)

    def test_one_vehicle_driving_through_is_one_pass_with_its_direction_then_left_zone(self):
        for bottom in range(200, 400, 25):
            self.seen(bottom)
        self.assertEqual((self.window()["direction"], self.window()["direction_confidence"]), ("IN", "high"))
        self.assertIsNone(self.window()["left_zone_at"])
        for _ in range(6):                                         # out of view: Left zone after 6 frames
            self.seen(None)
        self.assertIsNotNone(self.window()["left_zone_at"])
        self.assertEqual((self.state["passes_detected"], self.thread.call_count), (1, 1))
        self.assertEqual(self.state["passes"], {})

    def test_a_new_track_id_in_the_middle_of_the_zone_continues_the_same_pass(self):
        for bottom in (200, 220, 240):
            self.seen(bottom)
        self.seen(None)                                            # lost for a frame...
        for bottom in (265, 290, 315, 340):
            self.seen(bottom, track_id=12)                         # ...and found with a new ID
        self.assertEqual(self.state["passes_detected"], 1)
        self.assertEqual(self.state["pass_alias"], {7: FIRST_PASS, 12: FIRST_PASS})
        self.assertEqual(self.window()["direction"], "IN")
        self.assertEqual(len(self.state["pending_windows"]), 1)

    def test_a_second_box_on_the_same_vehicle_is_not_a_second_pass(self):
        for bottom in range(200, 340, 20):
            rows = [box(bottom) + [7, 0.9, 2], box(bottom - 4, height=54, width=70) + [8, 0.6, 2]]
            detector.handle_detection("gate-1", self.frame, tracked_results(rows), None, CAMERA, {"yolo_imgsz": 480},
                                      self.state, FakeClient(), {2: "Car"}, "cpu")
        self.assertEqual((self.state["passes_detected"], len(self.state["pending_windows"])), (1, 1))
        self.assertEqual(self.state["track_overlays"][8], self.state["track_overlays"][7])

    def test_the_tracker_reusing_an_id_later_is_a_new_pass_with_its_own_key(self):
        for bottom in range(200, 400, 25):
            self.seen(bottom)
        for _ in range(6):
            self.seen(None)
        for bottom in range(400, 200, -25):                        # another vehicle, same tracker ID
            self.seen(bottom)
        self.assertEqual(self.state["passes_detected"], 2)
        self.assertEqual(self.window()["direction"], "IN")
        self.assertEqual(self.window(FIRST_PASS + 1)["direction"], "OUT")

    def test_a_parked_vehicle_is_detected_but_never_gets_a_direction_and_is_still_when_it_goes(self):
        for step in range(40):
            self.seen(300 + (3 if step % 2 else -3))
        self.assertIsNone(self.window()["direction"])
        for _ in range(6):
            self.seen(None)
        self.assertEqual(self.window()["direction"], "STILL")

    def test_a_flicker_of_two_frames_is_not_a_pass(self):
        self.seen(300)
        self.seen(310)
        for _ in range(6):
            self.seen(None)
        self.assertEqual((self.state["passes_detected"], self.thread.call_count), (0, 0))

    def test_outside_the_zone_is_ignored(self):
        for bottom in (40, 60, 80, 100):                           # centre above the zone (y < 0.3)
            self.seen(bottom)
        self.assertEqual(self.state["passes_detected"], 0)

    def test_the_minimum_movement_is_a_gate_setting(self):
        strict = {**CAMERA, "pass_min_move": 0.6}
        for bottom in (200, 230, 260, 290, 320):                   # 41%: enough at 20%, not at 60%
            self.seen(bottom, camera=strict)
        self.assertIsNone(self.window()["direction"])


class RecordingClient(FakeClient):
    def __init__(self, matched=False):
        self.crossings = []
        self.matched = matched
        self.plates = []

    def check_rfid_match(self, *args, **kwargs):
        return {"matched": self.matched, "body": {"vehicle": {"plate_number": "ABC 123"}}}

    def submit_crossing(self, payload, image_bytes=None, filename=None):
        self.crossings.append((payload, bool(image_bytes)))
        return {"accepted": True, "created": True}

    def submit_visitor_plate(self, payload, image_bytes=None, filename=None):
        self.plates.append(payload)
        return {"accepted": True}


class PassWorkerTests(Gate):
    """pass_worker run directly: Recorded once, updated at Left zone, never twice."""

    def drive(self, bottoms, height=60):
        for bottom in bottoms:
            self.seen(bottom, height=height)
        window = self.window()
        window["deadline_at"] = time.monotonic()                  # the RFID window is over
        return window

    def run_worker(self, client):
        with mock.patch("detector_service.analyze_guest_vehicle_details",
                        return_value=({"plate": None, "status": "unreadable", "confidence": 0, "best_guess": None, "reason": ""}, None, {}, {"frames_checked": 0, "elapsed_seconds": 0}, None)), \
             mock.patch("detector_service.analyze_guest_vehicle_color", return_value=(None, {})), \
             mock.patch("detector_service.second_pass_type", return_value=None):
            detector.pass_worker("gate-1", self.state, FIRST_PASS, client)

    def test_recorded_with_its_direction_then_updated_at_left_zone_with_a_clearer_picture(self):
        window = self.drive([200, 230, 260, 290, 320])
        window["deadline_at"] = time.monotonic() + 0.8           # time for one RFID poll: the tag matches
        client = RecordingClient(matched=True)

        def leave_later():
            time.sleep(0.5)
            with self.state["lock"]:
                window.update(best_area=window["best_area"] * 2, left_zone_at=time.monotonic())

        RealThread(target=leave_later).start()
        self.run_worker(client)

        self.assertEqual(len(client.crossings), 2)                 # Recorded + the update at Left zone
        first, second = client.crossings[0][0], client.crossings[1][0]
        self.assertEqual(first["external_event_key"], second["external_event_key"])
        self.assertEqual((first["direction"], first["direction_reason"]), ("IN", "moved 31% of the zone with the IN arrow"))  # decided as soon as it was sure
        self.assertEqual(first["detection_metadata"]["direction_confidence"], "high")
        self.assertEqual(first["detection_metadata"]["rfid_status"], "matched")
        self.assertTrue(second["detection_metadata"]["pass"]["left_zone"])
        self.assertEqual(self.state["passes_recorded"], 1)
        self.assertNotIn(7, self.state["pending_windows"])

    def test_lost_before_sure_is_recorded_as_direction_unknown(self):
        window = self.drive([300, 310, 320, 330])                  # 10% only
        for _ in range(6):
            self.seen(None)
        client = RecordingClient(matched=True)
        self.run_worker(client)
        payload = client.crossings[0][0]
        self.assertEqual(payload["direction"], "UNKNOWN")
        self.assertTrue(payload["direction_reason"].startswith("Direction unknown"))
        self.assertEqual(len(client.crossings), 1)

    def test_a_vehicle_that_never_moved_is_not_recorded(self):
        self.drive([300, 302, 299, 301, 300])
        for _ in range(6):
            self.seen(None)
        client = RecordingClient()
        self.run_worker(client)
        self.assertEqual(client.crossings, [])
        self.assertEqual(self.state["passes_recorded"], 0)


if __name__ == "__main__":
    unittest.main()
