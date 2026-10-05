"""
A1 (detection): reconnect with a growing wait, a time limit on connecting,
and one status line per gate (what it is doing, why, what to do).

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import sys
import threading
import time
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import detector_service as detector  # noqa: E402
from camera_health import ReconnectBackoff, strip_credentials  # noqa: E402

ZONE = [{"x": 0.0, "y": 0.3}, {"x": 1.0, "y": 0.3}, {"x": 1.0, "y": 1.0}, {"x": 0.0, "y": 1.0}]
LINE = {"x1": 0.1, "y1": 0.6, "x2": 0.9, "y2": 0.6}


def camera(role="gate-2", source="rtsp://camera.test:554/stream1", calibrated=True):
    return {
        "camera_role": role, "camera_id": 2, "camera_name": "Gate 2 Camera", "source_type": "rtsp", "source_value": source,
        "source_username": "admin", "source_password": "secret", "decoder_threads": 1,
        "calibration_mask": ZONE if calibrated else None, "calibration_line": LINE if calibrated else None,
    }


class FakeCapture:
    def __init__(self, opened=True):
        self.opened = opened
        self.released = False

    def isOpened(self):
        return self.opened

    def release(self):
        self.released = True


UNREACHABLE = {"code": "unreachable", "message": "The camera is not reachable from this PC (Host is down). Check its cable and power, or scan again in Settings › Devices."}
OK = {"code": "ok", "message": "Camera answers and the login is accepted."}


class BackoffTests(unittest.TestCase):
    def test_waits_longer_after_each_failure_and_starts_over_after_a_success(self):
        backoff = ReconnectBackoff()
        self.assertEqual([backoff.failed(100.0) for _ in range(6)], [2.0, 4.0, 8.0, 16.0, 30.0, 30.0])
        self.assertFalse(backoff.ready(110.0))
        self.assertTrue(backoff.ready(130.0))
        self.assertIsNotNone(backoff.offline_since)
        backoff.reset()
        self.assertEqual((backoff.failures, backoff.offline_since), (0, None))
        self.assertTrue(backoff.ready(0.0))

    def test_camera_login_never_appears_in_messages(self):
        self.assertEqual(strip_credentials("rtsp://admin:secret@camera.test:554/stream1"), "rtsp://camera.test:554/stream1")
        state = detector.initial_camera_state()
        config = {**camera(source="http://admin:secret@camera.test/video"), "source_type": "url"}
        self.assertNotIn("secret", detector.camera_open_error(config, config["source_value"], state))


class ReconnectTests(unittest.TestCase):
    def test_an_unreachable_camera_is_not_opened_and_waits_before_the_next_try(self):
        state = detector.initial_camera_state()
        with mock.patch.object(detector.RTSP_DIAGNOSIS, "get", return_value=UNREACHABLE) as precheck, \
                mock.patch.object(detector, "open_capture") as opener:
            self.assertEqual(detector.ensure_capture(camera(), state)[0], None)
            detector.ensure_capture(camera(), state)  # too early: no new attempt

        opener.assert_not_called()  # OpenCV is not left to time out on a dead camera
        self.assertEqual(precheck.call_count, 1)
        self.assertEqual(state["backoff"].failures, 1)
        self.assertEqual(detector.camera_open_error(camera(), "", state), UNREACHABLE["message"])
        self.assertEqual(state["error_code"], "unreachable")

    def test_a_new_camera_setting_is_tried_at_once(self):
        state = detector.initial_camera_state()
        with mock.patch.object(detector.RTSP_DIAGNOSIS, "get", return_value=UNREACHABLE):
            detector.ensure_capture(camera(), state)
        with mock.patch.object(detector.RTSP_DIAGNOSIS, "get", return_value=OK), \
                mock.patch.object(detector, "open_capture", return_value=(FakeCapture(), "rtsp://camera.test:554/stream2")):
            capture, _ = detector.ensure_capture(camera(source="rtsp://camera.test:554/stream2"), state)

        self.assertTrue(capture.isOpened())
        self.assertEqual(state["backoff"].failures, 0)

    def test_a_hanging_camera_does_not_freeze_the_worker(self):
        state = detector.initial_camera_state()
        late = FakeCapture()

        def hang(*_args, **_kwargs):
            time.sleep(0.6)
            return late, "rtsp://camera.test:554/stream1"

        started = time.monotonic()
        with mock.patch.object(detector.RTSP_DIAGNOSIS, "get", return_value=OK), \
                mock.patch.object(detector, "open_capture", side_effect=hang), \
                mock.patch.object(detector, "CAMERA_OPEN_TIMEOUT_SECONDS", 0.1):
            capture, _ = detector.ensure_capture(camera(), state)

        self.assertIsNone(capture)
        self.assertLess(time.monotonic() - started, 0.5)
        self.assertEqual(state["open_problem"]["code"], "timeout")
        time.sleep(0.8)
        self.assertTrue(late.released)  # the late connection is closed, not leaked

    def test_one_gate_waiting_on_its_camera_does_not_hold_up_the_other(self):
        offline, live = detector.initial_camera_state(), detector.initial_camera_state()

        def open_for(config, *_args):
            if config["camera_role"] == "gate-2":
                time.sleep(0.6)
            return FakeCapture(), config["source_value"]

        with mock.patch.object(detector.RTSP_DIAGNOSIS, "get", return_value=OK), \
                mock.patch.object(detector, "open_capture", side_effect=open_for), \
                mock.patch.object(detector, "CAMERA_OPEN_TIMEOUT_SECONDS", 2.0):
            slow = threading.Thread(target=detector.ensure_capture, args=(camera("gate-2"), offline))
            slow.start()
            started = time.monotonic()
            capture, _ = detector.ensure_capture(camera("gate-1"), live)
            elapsed = time.monotonic() - started
            slow.join()

        self.assertTrue(capture.isOpened())
        self.assertLess(elapsed, 0.3)


class DetectionStatusTests(unittest.TestCase):
    def status(self, state, config=None, model=None):
        return detector.detection_status("gate-1", state, config or camera("gate-1"), model if model is not None else {"model": object(), "vehicle_labels": {2: "Car"}})

    def test_each_reason_has_a_label_and_a_next_step(self):
        state = detector.initial_camera_state()
        self.assertEqual(self.status(state)["code"], "connecting")

        state["backoff"].failed(time.monotonic())
        state["last_error"] = "Camera login rejected (RTSP 401). Enter the camera username and password in Settings."
        state["error_code"] = "unauthorized"
        offline = self.status(state)
        self.assertEqual([offline["code"], offline["message"]], ["camera_offline", "Camera login rejected (RTSP 401)."])
        self.assertIn("username and password", offline["next_step"])
        self.assertLessEqual(offline["retry_in"], 2)

        state["camera_running"] = True
        self.assertEqual(self.status(state, camera("gate-1", calibrated=False))["code"], "no_zone")
        self.assertEqual(self.status(state, model={"model": None})["code"], "model_loading")

        state["debug"] = {"at": time.monotonic(), "detection_fps": 7.6}
        running = self.status(state)
        self.assertEqual([running["code"], running["message"]], ["running", "Watching for vehicles (7.6 checks per second)."])

        state["debug"]["at"] = time.monotonic() - 60
        self.assertEqual(self.status(state)["code"], "stalled")

    def test_status_file_has_the_line_and_the_log_gets_each_change_once(self):
        state = detector.initial_camera_state()
        runtime = {"cameras": {"gate-1": camera("gate-1")}, "gates": [{"code": "gate-1", "name": "Gate 1"}]}
        with mock.patch.object(detector, "camera_roles", return_value=["gate-1"]), \
                mock.patch("builtins.print") as printed:
            first = detector.status_payload(runtime, {"gate-1": state}, {"gate-1": {}}, True, "running")
            detector.status_payload(runtime, {"gate-1": state}, {"gate-1": {}}, True, "running")

        self.assertEqual(first["cameras"]["gate-1"]["detection_status"]["code"], "connecting")
        self.assertEqual(printed.call_count, 1)


if __name__ == "__main__":
    unittest.main()
