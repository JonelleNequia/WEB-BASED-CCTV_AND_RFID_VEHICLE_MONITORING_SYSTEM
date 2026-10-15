"""
Shared camera work: one camera used by several gates opens each stream once
(the VIGI-C240 refuses a 4th session per stream with "RTSP 429"); each gate
gets its own view of it; a full camera is reported as "busy", not "Offline".

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import socket
import sys
import threading
import time
import unittest
from pathlib import Path
from unittest import mock

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import detector_service as detector  # noqa: E402
from camera_health import rtsp_play_check  # noqa: E402
from capture_hub import CaptureHub  # noqa: E402


class FakeReader:
    """A camera connection that makes a new frame on demand."""

    opened_count = 0

    def __init__(self, role="gate-1", decoder_threads=1):
        FakeReader.opened_count += 1
        self.roles = {role}
        self.decoder_threads = decoder_threads
        self.too_slow = False
        self.frame_time = 0.0
        self.sequence = 0
        self.opened = True
        self.released = False
        self.condition = threading.Condition()

    def push(self):
        with self.condition:
            self.sequence += 1
            self.frame = np.full((4, 4, 3), self.sequence, np.uint8)
            self.condition.notify_all()

    def wait_newer(self, after, timeout=None):
        with self.condition:
            self.condition.wait_for(lambda: self.sequence != after or not self.opened, timeout or 0.5)
            return (self.sequence, self.frame) if self.sequence != after and self.opened else (after, None)

    def isOpened(self):
        return self.opened and not self.released

    def release(self):
        self.released = True


class CaptureHubTests(unittest.TestCase):
    def setUp(self):
        FakeReader.opened_count = 0
        self.hub = CaptureHub()

    def test_gates_on_one_stream_share_one_connection_and_each_gets_every_new_frame(self):
        first = self.hub.acquire("rtsp|cam/stream2", "gate-1", lambda: FakeReader("gate-1"))
        second = self.hub.acquire("rtsp|cam/stream2", "gate-2", lambda: FakeReader("gate-2"))
        other_stream = self.hub.acquire("rtsp|cam/stream1", "gate-1", lambda: FakeReader("gate-1"))

        self.assertEqual(FakeReader.opened_count, 2)  # sub once, main once
        self.assertIs(first.reader, second.reader)
        self.assertEqual(first.reader.roles, {"gate-1", "gate-2"})

        first.reader.push()
        self.assertTrue(first.read_latest()[0])
        self.assertTrue(second.read_latest()[0])  # its own "last frame taken"
        self.assertFalse(first.read_latest(timeout=0.05)[0])  # nothing newer yet

        # The connection closes only when the last gate lets go.
        reader = first.reader
        first.release()
        self.assertFalse(reader.released)
        self.assertEqual(reader.roles, {"gate-2"})
        second.release()
        self.assertTrue(reader.released)
        self.assertEqual([item["users"] for item in self.hub.connections()], [1])
        other_stream.release()
        self.assertEqual(self.hub.connections(), [])

    def test_a_dropped_connection_is_opened_again_not_shared(self):
        first = self.hub.acquire("key", "gate-1", lambda: FakeReader("gate-1"))
        first.reader.opened = False  # camera dropped
        again = self.hub.acquire("key", "gate-2", lambda: FakeReader("gate-2"))
        self.assertIsNot(again.reader, first.reader)
        self.assertEqual(FakeReader.opened_count, 2)

    def test_a_failed_open_is_returned_as_it_is_and_not_kept(self):
        class Closed:
            def isOpened(self):
                return False

        failed = self.hub.acquire("key", "gate-1", Closed)
        self.assertIsInstance(failed, Closed)
        self.assertEqual(self.hub.connections(), [])

    def test_a_gate_asking_for_other_decoder_threads_after_a_slow_decoder_gets_its_own(self):
        slow = self.hub.acquire("key", "gate-1", lambda: FakeReader("gate-1", 1))
        slow.reader.too_slow = True
        threaded = self.hub.acquire("key", "gate-1", lambda: FakeReader("gate-1", 0), decoder_threads=0)
        self.assertIsNot(threaded.reader, slow.reader)


class DetectorSharingTests(unittest.TestCase):
    def camera(self, role, sub="rtsp://cam.test:554/stream2"):
        return {"camera_role": role, "source_type": "rtsp", "source_value": sub, "snapshot_source_value": "rtsp://cam.test:554/stream1",
                "source_username": "admin", "source_password": "secret", "decoder_threads": 1}

    def test_open_capture_shares_the_stream_between_gates(self):
        opened = []

        class Capture:
            def __init__(self, *args, **kwargs):
                opened.append(args[0])

            def isOpened(self):
                return True

            def set(self, *args):
                return True

            def read(self):
                time.sleep(0.01)
                return True, np.zeros((4, 4, 3), np.uint8)

            def get(self, prop):
                return 0.0

            def release(self):
                pass

        with mock.patch.object(detector.cv2, "VideoCapture", Capture), mock.patch.object(detector, "CAPTURE_HUB", CaptureHub()):
            gate_1, _ = detector.open_capture(self.camera("gate-1"))
            gate_2, _ = detector.open_capture(self.camera("gate-2"))
            self.assertEqual(len(opened), 1)
            self.assertTrue(gate_1.read_latest()[0] and gate_2.read_latest()[0])
            self.assertTrue(detector.read_fresh_frame(gate_2)[0])
            gate_1.release()
            gate_2.release()

    def test_one_full_resolution_grabber_per_camera(self):
        with mock.patch.dict(detector.HIRES, {}, clear=True), mock.patch.dict(detector.SHARED_HIRES, {}, clear=True):
            one = detector.hires_grabber("gate-1", self.camera("gate-1"))
            two = detector.hires_grabber("gate-2", self.camera("gate-2"))
            self.assertIs(one, two)
            self.assertEqual(one.roles, {"gate-1", "gate-2"})
            # Gate 2 gets another camera: it leaves the shared grabber.
            other = detector.hires_grabber("gate-2", dict(self.camera("gate-2"), snapshot_source_value="rtsp://other.test/stream1"))
            self.assertIsNot(other, one)
            self.assertEqual(one.roles, {"gate-1"})


class FakeRtspCamera(threading.Thread):
    """Answers DESCRIBE and SETUP, then PLAY with the given status (429 = full)."""

    def __init__(self, play_status):
        super().__init__(daemon=True)
        self.play_status = play_status
        self.server = socket.socket()
        self.server.bind(("127.0.0.1", 0))
        self.server.listen(1)
        self.port = self.server.getsockname()[1]

    def run(self):
        connection, _ = self.server.accept()
        self.server.close()
        with connection:
            buffer = b""
            while True:
                data = connection.recv(4096)
                if not data:
                    return
                buffer += data
                while b"\r\n\r\n" in buffer:
                    request, buffer = buffer.split(b"\r\n\r\n", 1)
                    method = request.split(b" ", 1)[0]
                    cseq = [line.split(b":", 1)[1].strip() for line in request.split(b"\r\n") if line.lower().startswith(b"cseq")][0]
                    if method == b"DESCRIBE":
                        body = b"v=0\r\nm=video 0 RTP/AVP 96\r\na=control:trackID=1\r\n"
                        connection.sendall(b"RTSP/1.0 200 OK\r\nCSeq: " + cseq + b"\r\nContent-Length: " + str(len(body)).encode() + b"\r\n\r\n" + body)
                    elif method == b"SETUP":
                        connection.sendall(b"RTSP/1.0 200 OK\r\nCSeq: " + cseq + b"\r\nSession: 1234;timeout=60\r\n\r\n")
                    elif method == b"PLAY":
                        connection.sendall(f"RTSP/1.0 {self.play_status} X\r\nCSeq: ".encode() + cseq + b"\r\n\r\n")
                    elif method == b"TEARDOWN":
                        return


class BusyCameraTests(unittest.TestCase):
    def check(self, play_status):
        camera = FakeRtspCamera(play_status)
        camera.start()
        return rtsp_play_check(f"rtsp://127.0.0.1:{camera.port}/stream1")

    def test_a_full_camera_is_busy_with_the_real_reason(self):
        busy = self.check(429)
        self.assertEqual(busy["code"], "busy")
        self.assertIn("too many connections to this stream (RTSP 429)", busy["message"])
        self.assertEqual(self.check(200)["code"], "ok")

    def test_the_gate_status_says_busy_and_what_to_do(self):
        state = detector.initial_camera_state()
        state["backoff"].failed(time.monotonic())
        config = {"camera_role": "gate-2", "source_type": "rtsp", "source_value": "rtsp://cam.test/stream2", "source_username": "", "source_password": "",
                  "calibration_mask": [{"x": 0, "y": 0}, {"x": 1, "y": 0}, {"x": 1, "y": 1}]}
        ok = {"code": "ok", "message": "fine"}
        busy = {"code": "busy", "message": "The camera refused the connection: too many connections to this stream (RTSP 429). It allows only a few at once: close other viewers (VIGI app, recorder, another PC), or use one camera per gate."}
        with mock.patch.object(detector.RTSP_DIAGNOSIS, "get", return_value=ok), mock.patch.object(detector.RTSP_PLAY_CHECK, "get", return_value=busy):
            state["last_error"] = detector.camera_open_error(config, "rtsp://cam.test/stream2", state)
        self.assertEqual(state["error_code"], "busy")
        status = detector.detection_status("gate-2", state, config, {"model": object(), "vehicle_labels": {2: "Car"}})
        self.assertEqual(status["code"], "camera_offline")
        self.assertIn("too many connections", status["message"])
        self.assertIn("one camera per gate", status["next_step"])


if __name__ == "__main__":
    unittest.main()


class LanHealthTests(unittest.TestCase):
    def test_the_dhcp_lease_is_read_on_macos(self):
        from devices import netinfo

        packet = "op = BOOTREPLY\nyiaddr = 192.0.2.2\nlease_time (uint32): 0x3c\nrouter (ip_mult): {192.0.2.1}\n"
        with mock.patch.object(netinfo, "SYSTEM", "darwin"), mock.patch.object(netinfo, "run", return_value=packet):
            self.assertEqual(netinfo.dhcp_lease_seconds("en7"), 60)
        with mock.patch.object(netinfo, "SYSTEM", "windows"):
            self.assertIsNone(netinfo.dhcp_lease_seconds("Ethernet"))

    def test_only_a_lan_address_counts_not_link_local_or_wifi(self):
        from devices import netinfo

        network = {"interfaces": [
            {"name": "en0", "kind": "wifi", "link_local": False},
            {"name": "en7", "kind": "ethernet", "link_local": True},
        ]}
        self.assertIsNone(netinfo.wired_address(network))
        network["interfaces"][1]["link_local"] = False
        self.assertEqual(netinfo.wired_address(network)["name"], "en7")
