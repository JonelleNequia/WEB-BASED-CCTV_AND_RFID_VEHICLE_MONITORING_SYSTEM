"""
ROI work: no trigger line and no IN arrow. A vehicle is one pass through
the zone: Detected (a few frames in the zone: RFID check starts), Recorded
(clearly away from the camera or toward it, and the RFID window is over),
Left zone. Each gate says whether away from the camera is IN or OUT. One
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
# Away from the camera (up the picture) is OUT at this gate, so IN = driving down toward it.
CAMERA = {"calibration_mask": ZONE, "away_direction": "OUT", "camera_id": 1}
ZONE_PX = [(0, int(0.3 * H)), (W, int(0.3 * H)), (W, H), (0, H)]   # 291 px high
FIRST_PASS = detector.PASS_KEY_START + 1                      # the key of a fresh state's first pass


def box(bottom, height=60, x=340, width=80):
    return [x - width / 2, bottom - height, x + width / 2, bottom]


class PassDecisionTests(unittest.TestCase):
    def path(self, bottoms, heights=None, x=340):
        heights = heights or [60] * len(bottoms)
        vehicle_pass = VehiclePass(box(bottoms[0], heights[0], x), (W, H))
        for bottom, height in zip(bottoms[1:], heights[1:]):
            vehicle_pass.observe(box(bottom, height, x))
        return vehicle_pass

    def test_toward_the_camera_and_growing_is_sure_and_the_gate_setting_says_in_or_out(self):
        toward = self.path([200, 230, 260, 290, 320], [60, 70, 80, 90, 100])   # down 41%, box grew 67%
        decision = toward.decide(ZONE_PX, 0.20, "OUT")
        self.assertEqual((decision["direction"], decision["confidence"], decision["movement"]), ("IN", "high", "toward"))
        self.assertEqual(decision["reason"], "toward the camera: went down 41% of the zone; box grew 67%")
        self.assertEqual(toward.decide(ZONE_PX, 0.20, "IN")["direction"], "OUT")
        away = self.path([320, 290, 260, 230, 200], [100, 90, 80, 70, 60])
        self.assertEqual((away.decide(ZONE_PX, 0.20, "IN")["direction"], away.decide(ZONE_PX, 0.20, "OUT")["direction"]), ("IN", "OUT"))

    def test_without_the_gate_setting_every_pass_is_direction_unknown(self):
        decision = self.path([200, 230, 260, 290, 320], [60, 70, 80, 90, 100]).decide(ZONE_PX, 0.20, None)
        self.assertEqual((decision["direction"], decision["movement"]), ("UNKNOWN", "toward"))
        self.assertEqual(decision["reason"], "Direction unknown: toward the camera, but this gate's direction is not set")

    def test_far_enough_but_the_size_does_not_tell_is_medium(self):
        same = self.path([200, 230, 260, 290, 320])                # box size the same
        decision = same.decide(ZONE_PX, 0.20, "OUT")
        self.assertEqual((decision["direction"], decision["confidence"]), ("IN", "medium"))
        self.assertIn("box size changed only 0%", decision["reason"])
        cut_off = self.path([200, 230, 260, 290, 320], [60, 40, 30, 20, 10], x=40)   # at the left edge: size not used
        decision = cut_off.decide(ZONE_PX, 0.20, "OUT")
        self.assertEqual((decision["direction"], decision["confidence"], decision["size"]), ("IN", "medium", None))
        self.assertIn("cut off by the edge", decision["reason"])

    def test_movement_and_size_that_disagree_wait_then_are_unknown(self):
        odd = self.path([200, 230, 260, 290, 320], [100, 90, 80, 70, 60])   # went down (toward) but shrank (away)
        self.assertIsNone(odd.decide(ZONE_PX, 0.20, "OUT")["direction"])
        final = odd.decide(ZONE_PX, 0.20, "OUT", final=True)
        self.assertEqual(final["direction"], "UNKNOWN")
        self.assertEqual(final["reason"], "Direction unknown: went down 41% of the zone but the box shrank 40% (they disagree)")

    def test_a_shorter_move_counts_only_when_the_size_clearly_agrees(self):
        closer = self.path([280, 295, 310, 325], [60, 70, 80, 90])  # down 15%, box grew 50%
        decision = closer.decide(ZONE_PX, 0.20, "OUT")
        self.assertEqual((decision["direction"], decision["confidence"]), ("IN", "medium"))
        short = self.path([300, 310, 320, 330])                    # down 10%, same size
        self.assertIsNone(short.decide(ZONE_PX, 0.20, "OUT")["direction"])
        final = short.decide(ZONE_PX, 0.20, "OUT", final=True)
        self.assertEqual(final["direction"], "UNKNOWN")
        self.assertTrue(final["reason"].startswith("Direction unknown: went down 10% of the zone"))
        still = self.path([300, 303, 299, 302, 300, 301])
        self.assertEqual(still.decide(ZONE_PX, 0.20, "OUT", final=True)["direction"], "STILL")

    def test_the_share_is_of_the_zone_height(self):
        decision = self.path([200, 260]).decide([(0, 100), (W, 100), (W, 400), (0, 400)], 0.20, "OUT")
        self.assertEqual(decision["rise"], round(-60 / 300, 3))


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
        self.assertEqual((self.window()["direction"], self.window()["direction_confidence"]), ("IN", "medium"))  # same box size
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

    def test_a_gate_without_its_direction_set_records_direction_unknown(self):
        unset = {**CAMERA, "away_direction": None}
        for bottom in (200, 230, 260, 290, 320):
            self.seen(bottom, camera=unset)
        self.assertEqual(self.window()["direction"], "UNKNOWN")
        self.assertIn("direction is not set", self.window()["direction_reason"])

    def test_the_minimum_movement_is_a_gate_setting(self):
        strict = {**CAMERA, "pass_min_move": 0.6}
        for bottom in (200, 230, 260, 290, 320):                   # 41% of the zone's height: enough at 20%, not at 60%
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
        # Decided as soon as it was sure (4th frame), while still in the zone.
        self.assertEqual((first["direction"], first["direction_reason"]), ("IN", "toward the camera: went down 31% of the zone; box size changed only 0%"))
        self.assertEqual(first["detection_metadata"]["direction_confidence"], "medium")
        self.assertEqual(first["detection_metadata"]["movement"]["movement"], "toward")
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

    def test_a_tag_read_after_the_window_is_still_found_until_the_pass_is_decided(self):
        """ROI work, Phase 3: the RFID check runs from Detected until the pass is ready to be Recorded."""
        for bottom in (300, 302, 304):                             # Detected, not decided yet
            self.seen(bottom)
        window = self.window()
        window["deadline_at"] = time.monotonic()                  # the 2 s window is already over
        client = LateTagClient(found_on_call=3)

        def decide_later():
            time.sleep(1.4)
            with self.state["lock"]:
                detector.set_window_direction_locked(window, {"direction": "IN", "confidence": "high", "reason": "toward the camera"})
                window["left_zone_at"] = time.monotonic()

        RealThread(target=decide_later).start()
        self.run_worker(client)

        self.assertGreaterEqual(client.calls, 3)
        self.assertTrue(all(call["until"] for call in client.seen))   # the pass so far, not the old window
        self.assertEqual(client.crossings[0][0]["detection_metadata"]["rfid_status"], "matched")

    def test_every_detected_pass_gets_an_rfid_check_even_when_it_is_ready_at_once(self):
        window = self.drive([200, 230, 260, 290, 320])            # decided, window over
        client = LateTagClient(found_on_call=99)
        for _ in range(6):
            self.seen(None)
        self.run_worker(client)
        self.assertEqual(client.calls, 1)
        self.assertEqual(client.crossings[0][0]["detection_metadata"]["rfid_status"], "no_pass")
        self.assertIsNotNone(window.get("decided_at"))

    def test_open_passes_are_reported_until_recorded(self):
        for bottom in (300, 302, 304):
            self.seen(bottom)
        passes = detector.open_passes(self.state)
        self.assertEqual([(p["event_key"], p["rfid"]) for p in passes], [(self.window()["event_key"], "checking")])
        self.window()["rfid_status"] = "matched"
        self.assertEqual(detector.open_passes(self.state)[0]["rfid"], "matched")
        self.window()["recorded_at"] = "2026-10-12T10:00:00+08:00"
        self.assertEqual(detector.open_passes(self.state), [])


class LateTagClient(RecordingClient):
    """The vehicle's tag is found only on the Nth RFID check."""

    def __init__(self, found_on_call):
        super().__init__(matched=False)
        self.found_on_call = found_on_call
        self.calls = 0
        self.seen = []

    def check_rfid_match(self, camera_role, event_time, window_seconds=4, lookback_seconds=10, event_key=None, until=None):
        self.calls += 1
        self.seen.append({"event_key": event_key, "until": until})
        matched = self.calls >= self.found_on_call
        return {"matched": matched, "body": {"vehicle": {"plate_number": "LAT 1234"}} if matched else {}}


if __name__ == "__main__":
    unittest.main()
