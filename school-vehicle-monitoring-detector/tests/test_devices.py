"""
Unit tests for device detection (no network needed).

    cd school-vehicle-monitoring-detector
    .venv/bin/python -m unittest discover -s tests -v
"""

import asyncio
import socket
import sys
import threading
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from devices import netinfo, probes, scanner, tempip, uhf  # noqa: E402
from devices.oui import is_randomized, mac_from_uuid, normalize_mac, vendor_for  # noqa: E402
from devices.paths import load_profiles  # noqa: E402

EPC = "E2000017221101441890ABCD"


def r2000_tag_frame(epc_hex=EPC, rssi=0x55):
    epc = bytes.fromhex(epc_hex)
    pc = ((len(epc) // 2) << 11).to_bytes(2, "big")
    data = bytes([0x01]) + pc + epc + bytes([rssi])
    body = bytes([0xA0, len(data) + 3, 0x01, 0x89]) + data
    return body + bytes([uhf.r2000_checksum(body)])


def chafon_active_frame(epc_hex=EPC):
    body = bytes([0, 0x00, 0xEE, 0x00]) + bytes.fromhex(epc_hex)
    body = bytes([len(body) + 1]) + body[1:]
    crc = uhf.chafon_crc(body)
    return body + bytes([crc & 0xFF, crc >> 8])


def bb7e_tag_frame(epc_hex=EPC):
    epc = bytes.fromhex(epc_hex)
    pc = ((len(epc) // 2) << 11).to_bytes(2, "big")
    payload = bytes([0xC9]) + pc + epc + b"\x12\x34"
    body = bytes([0x02, 0x22, 0x00, len(payload)]) + payload
    return bytes([0xBB]) + body + bytes([uhf.bb7e_checksum(body), 0x7E])


class ChecksumTests(unittest.TestCase):
    def test_chafon_crc_matches_published_commands(self):
        # Published UHFReader18 commands: get info = 04 FF 21 19 95, inventory = 04 FF 01 1B B4.
        self.assertEqual(uhf.build_command("chafon", "21").hex(), "04ff211995")
        self.assertEqual(uhf.build_command("chafon", "01").hex(), "04ff011bb4")

    def test_r2000_and_bb7e_builders(self):
        self.assertEqual(uhf.build_command("r2000", "72").hex(), "a003ff72ec")
        self.assertEqual(uhf.build_command("bb7e", "22").hex(), "bb00220000227e")


class DecoderTests(unittest.TestCase):
    def test_r2000_tag(self):
        frames, protocol = uhf.decode_once(r2000_tag_frame())
        self.assertEqual(protocol, "r2000")
        self.assertEqual([frame.epc for frame in frames], [EPC])
        self.assertEqual(frames[0].rssi, 0x55)

    def test_chafon_active_tag(self):
        frames, protocol = uhf.decode_once(chafon_active_frame())
        self.assertEqual(protocol, "chafon")
        self.assertEqual(frames[0].epc, EPC)

    def test_bb7e_tag(self):
        frames, protocol = uhf.decode_once(bb7e_tag_frame())
        self.assertEqual(protocol, "bb7e")
        self.assertEqual(frames[0].epc, EPC)

    def test_text_lines(self):
        frames, protocol = uhf.decode_once(b"EPC:E2 00 00 17 22 11 01 44 18 90 AB CD,RSSI:-55\r\n")
        self.assertEqual(protocol, "text")
        self.assertEqual(frames[0].epc, EPC)

    def test_frames_split_across_reads_and_noise(self):
        decoder = uhf.StreamDecoder()
        data = b"\x00\x13\x37" + r2000_tag_frame() + r2000_tag_frame("E28011606000020A1B2C3D4E")
        frames = decoder.feed(data[:10]) + decoder.feed(data[10:])
        self.assertEqual([frame.epc for frame in frames], [EPC, "E28011606000020A1B2C3D4E"])

    def test_binary_length_byte_equal_to_carriage_return(self):
        body = bytes([0x0D, 0x00, 0x21, 0x00, 0x03, 0x01, 0x09, 0x02, 0x4E, 0x00, 0x1E, 0x0A])
        crc = uhf.chafon_crc(body)
        frames, protocol = uhf.decode_once(body + bytes([crc & 0xFF, crc >> 8]))
        self.assertEqual(protocol, "chafon")
        self.assertEqual(frames[0].kind, "reply")

    def test_corrupt_checksum_is_rejected(self):
        frame = bytearray(r2000_tag_frame())
        frame[-1] ^= 0xFF
        frames, _ = uhf.decode_once(bytes(frame), "r2000")
        self.assertEqual(frames, [])

    def test_web_banner_is_not_a_reader(self):
        result = probes._reader_result("tcp", 1, "text", "active", [uhf.Frame("text", "tag", epc=EPC)], b"HTTP/1.1 200 OK\r\nETag: E2000017221101441890ABCD")
        self.assertFalse(result["confirmed"])


class MacTests(unittest.TestCase):
    def test_normalize(self):
        self.assertEqual(normalize_mac("b8:9f:cc:1:2:3"), "B8:9F:CC:01:02:03")
        self.assertEqual(normalize_mac("B8-9F-CC-01-02-03"), "B8:9F:CC:01:02:03")
        self.assertIsNone(normalize_mac("ff-ff-ff-ff-ff-ff"))
        self.assertIsNone(normalize_mac("1:0:5e:7f:ff:fa"))  # multicast

    def test_vendor_and_randomized(self):
        self.assertIn("TP-LINK", vendor_for("34:F7:16:00:00:01").upper())
        self.assertTrue(is_randomized("CA:79:E5:39:D0:CC"))
        self.assertEqual(mac_from_uuid("urn:uuid:2419d68a-2dd2-21b2-a205-34F716000001"), "34:F7:16:00:00:01")


class NetworkParsingTests(unittest.TestCase):
    def test_arp_parsing_macos(self):
        output = (
            "? (192.0.2.1) at b8:9f:cc:bf:1e:72 on en0 ifscope [ethernet]\n"
            "? (192.0.2.9) at (incomplete) on en0 ifscope [ethernet]\n"
            "? (192.0.2.255) at ff:ff:ff:ff:ff:ff on en0 ifscope [ethernet]\n"
        )
        with mock.patch.object(netinfo, "SYSTEM", "darwin"), mock.patch.object(netinfo, "run", return_value=output):
            self.assertEqual(netinfo.arp_table(), {"192.0.2.1": "B8:9F:CC:BF:1E:72"})

    def test_arp_parsing_windows(self):
        output = (
            "Interface: 192.0.2.10 --- 0x5\n"
            "  Internet Address      Physical Address      Type\n"
            "  192.0.2.1             b8-9f-cc-bf-1e-72     dynamic\n"
            "  192.0.2.255           ff-ff-ff-ff-ff-ff     static\n"
            "  224.0.0.22            01-00-5e-00-00-16     static\n"
        )
        with mock.patch.object(netinfo, "SYSTEM", "windows"), mock.patch.object(netinfo, "run", return_value=output):
            self.assertEqual(netinfo.arp_table(), {"192.0.2.1": "B8:9F:CC:BF:1E:72"})

    def test_windows_gateway(self):
        output = (
            "IPv4 Route Table\n===\nActive Routes:\n"
            "Network Destination        Netmask          Gateway       Interface  Metric\n"
            "          0.0.0.0          0.0.0.0      192.0.2.1     192.0.2.10     25\n"
            "===\nPersistent Routes:\n  None\n"
        )
        with mock.patch.object(netinfo, "SYSTEM", "windows"), mock.patch.object(netinfo, "run", return_value=output):
            self.assertEqual(netinfo.gateways(), {"192.0.2.10": "192.0.2.1"})

    def test_host_planning_limits_big_networks(self):
        small = {"ip": "198.51.100.20", "network": "198.51.100.0/24", "link_local": False}
        self.assertEqual(len(scanner.plan_hosts(small, 1024)), 253)
        big = {"ip": "10.20.30.40", "network": "10.0.0.0/8", "link_local": False}
        hosts = scanner.plan_hosts(big, 1024)
        self.assertLessEqual(len(hosts), 1024)
        self.assertIn("10.20.30.41", hosts)
        link_local = {"ip": "169.254.3.4", "network": "169.254.0.0/16", "link_local": True}
        self.assertEqual(scanner.plan_hosts(link_local, 1024), [])

    def test_temporary_ip_plan_uses_device_subnet(self):
        plan = tempip.plan("203.0.113.60", {"203.0.113.254"})
        self.assertEqual(plan["network"], "203.0.113.0/24")
        self.assertEqual(plan["ip"], "203.0.113.253")
        commands = tempip.commands({"name": "en5", "label": "USB LAN"}, plan)
        self.assertIn("alias 203.0.113.253", commands["darwin"]["add"])
        self.assertIn('name="USB LAN"', commands["windows"]["add"])

    def test_onvif_probe_match(self):
        xml = (
            '<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" '
            'xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery" xmlns:a="http://schemas.xmlsoap.org/ws/2004/08/addressing">'
            "<s:Body><d:ProbeMatches><d:ProbeMatch><a:EndpointReference><a:Address>urn:uuid:1-34F716000001</a:Address></a:EndpointReference>"
            "<d:Scopes>onvif://www.onvif.org/name/VIGI_C340 onvif://www.onvif.org/hardware/C340 onvif://www.onvif.org/mfr/TP-Link</d:Scopes>"
            "<d:XAddrs>http://198.51.100.60:2020/onvif/device_service</d:XAddrs></d:ProbeMatch></d:ProbeMatches></s:Body></s:Envelope>"
        ).encode()
        match = probes.parse_probe_match(xml)[0]
        self.assertEqual(match["ip"], "198.51.100.60")
        self.assertEqual(match["name"], "VIGI C340")
        self.assertEqual(match["manufacturer"], "TP-Link")


class FakeReader:
    """TCP reader on localhost that answers the chafon info command and pushes nothing."""

    def __init__(self, reply):
        self.server = socket.socket()
        self.server.bind(("127.0.0.1", 0))
        self.server.listen(1)
        self.port = self.server.getsockname()[1]
        self.reply = reply
        threading.Thread(target=self._serve, daemon=True).start()

    def _serve(self):
        connection, _ = self.server.accept()
        connection.settimeout(5)
        try:
            while True:
                data = connection.recv(64)
                if not data:
                    return
                if data == uhf.build_command("chafon", "21"):
                    connection.sendall(self.reply)
        except OSError:
            return


class ReaderProbeTests(unittest.TestCase):
    def test_answer_mode_reader_is_confirmed_by_its_reply(self):
        body = bytes([0x0D, 0x00, 0x21, 0x00, 0x03, 0x01, 0x09, 0x02, 0x4E, 0x00, 0x1E, 0x0A])
        body = bytes([len(body) + 1]) + body[1:]
        crc = uhf.chafon_crc(body)
        reader = FakeReader(body + bytes([crc & 0xFF, crc >> 8]))
        profiles = load_profiles()
        profiles["scan"]["reader_passive_listen_seconds"] = 0.2
        result = asyncio.run(probes.reader_tcp_probe("127.0.0.1", reader.port, profiles))
        self.assertTrue(result["confirmed"])
        self.assertEqual(result["protocol"], "chafon")
        self.assertEqual(result["work_mode"], "answer")


if __name__ == "__main__":
    unittest.main()


class ReaderLinkTests(unittest.TestCase):
    """The station link reads an active reader and sends each tag once."""

    def test_active_reader_tags_are_posted_once_with_the_station(self):
        from devices.reader_link import CaptureLog, ReaderLink

        epc_frames = r2000_tag_frame() * 3
        server = socket.socket()
        server.bind(("127.0.0.1", 0))
        server.listen(1)
        port = server.getsockname()[1]

        def serve():
            connection, _ = server.accept()
            for _ in range(3):
                connection.sendall(epc_frames)
                time_module.sleep(0.2)
            time_module.sleep(1)
            connection.close()

        import time as time_module
        threading.Thread(target=serve, daemon=True).start()

        posted = []
        poster = mock.Mock()
        poster.post.side_effect = posted.append
        capture = mock.Mock(spec=CaptureLog)
        profiles = load_profiles()
        link = ReaderLink("entrance", poster, profiles, capture, lambda target: None, lambda message: None)
        link.set_target({"ip": "127.0.0.1", "port": port, "transport": "tcp", "mac": "D8:A0:1D:00:00:02"})
        link.start()

        deadline = time_module.monotonic() + 3
        while time_module.monotonic() < deadline and not posted:
            time_module.sleep(0.05)
        time_module.sleep(0.8)

        self.assertEqual(len(posted), 1)
        self.assertEqual(posted[0]["tag_uid"], EPC)
        self.assertEqual(posted[0]["scan_location"], "entrance")
        self.assertEqual(posted[0]["payload_json"]["protocol"], "r2000")
        self.assertEqual(link.snapshot()["protocol"], "r2000")
        self.assertEqual(link.snapshot()["work_mode"], "active")


class DiagnosticsTests(unittest.TestCase):
    """The Devices panel explains why nothing was found."""

    def build(self, interfaces, arp, access="ok", firewall=None, arp_stats=None):
        from devices import diagnostics

        snap = {"interfaces": interfaces}
        sweep = {item["name"]: ["x"] * 253 for item in interfaces}
        return diagnostics.build(
            snap, sweep, dict(arp), arp, {}, {}, arp_stats or {"sent": 253, "errors": {}}, [],
            {"result": access, "checks": []}, firewall or {"enabled": False}, False,
        )

    @staticmethod
    def lan(ip="198.51.100.2", gateway="198.51.100.1", link_local=False, kind="ethernet"):
        return {"name": "en7", "label": "USB LAN", "kind": kind, "ip": ip, "network": ip.rsplit(".", 1)[0] + ".0/24",
                "gateway": gateway, "link_local": link_local}

    def codes(self, result):
        return [warning["code"] for warning in result["warnings"]]

    def test_only_the_router_answered_on_the_lan(self):
        result = self.build([self.lan()], {"198.51.100.1": "F4:2D:06:A2:2F:70"})
        self.assertIn("lan_only_router", self.codes(result))
        self.assertEqual(result["interfaces"][0]["hosts_swept"], 253)
        self.assertEqual(result["interfaces"][0]["os_hosts"][0]["ip"], "198.51.100.1")

    def test_wifi_only_and_link_local_and_blocked(self):
        self.assertIn("no_ethernet", self.codes(self.build([self.lan(kind="wifi")], {})))
        self.assertIn("link_local", self.codes(self.build([self.lan(ip="169.254.3.4", gateway=None, link_local=True)], {})))
        self.assertIn("local_network_blocked", self.codes(self.build([self.lan()], {}, access="blocked")))
        blocked = self.build([self.lan()], {}, arp_stats={"sent": 253, "errors": {"No route to host": 253}})
        self.assertIn("sends_failed", self.codes(blocked))
        self.assertIn("firewall_block_all", self.codes(self.build([self.lan()], {}, firewall={"enabled": True, "block_all": True})))


class IdentifyReaderTests(unittest.TestCase):
    """Identify mode finds the device that sends tag data (answer-mode reader)."""

    def test_answer_mode_reader_is_identified_by_its_tags(self):
        from devices.identify import ReaderIdentifier

        server = socket.socket()
        server.bind(("127.0.0.1", 0))
        server.listen(4)
        port = server.getsockname()[1]
        inventory = uhf.build_command("chafon", "01")

        def serve():
            while True:
                try:
                    connection, _ = server.accept()
                except OSError:
                    return
                def handle(conn):
                    conn.settimeout(5)
                    try:
                        while True:
                            data = conn.recv(256)
                            if not data:
                                return
                            if inventory in data:
                                conn.sendall(chafon_active_frame())
                    except OSError:
                        return
                threading.Thread(target=handle, args=(connection,), daemon=True).start()

        threading.Thread(target=serve, daemon=True).start()
        profiles = load_profiles()
        profiles["uhf_reader"]["tcp_ports"] = []
        profiles["uhf_reader"]["udp_ports"] = []
        profiles["uhf_reader"]["identify_port_ranges"] = [[port, port]]
        identifier = ReaderIdentifier(profiles, [{"ip": "127.0.0.1", "mac": None}], 6, lambda message: None)
        result = identifier.run()
        server.close()

        self.assertFalse(result["running"])
        self.assertEqual(result["open_ports"]["127.0.0.1"], [port])
        self.assertEqual(result["found"][0]["port"], port)
        self.assertEqual(result["found"][0]["tags"], [EPC])
        self.assertIn("Reader found", result["message"])


class DetectorReleaseTests(unittest.TestCase):
    """A capture is never freed while read() is still running (FFmpeg crash)."""

    def test_release_waits_for_a_blocked_read(self):
        import importlib
        detector = importlib.import_module("detector_service")

        events = []

        class SlowCapture:
            def __init__(self):
                self.calls = 0

            def read(self):
                self.calls += 1
                if self.calls > 1:
                    time.sleep(3.0)  # a network read that outlives the 2 s join
                    events.append("read-returned")
                return True, object()

            def isOpened(self):
                return True

            def release(self):
                events.append("released")

        import time
        reader = detector.LatestFrameReader(SlowCapture())
        time.sleep(0.2)
        reader.release()
        self.assertNotIn("released", events)  # not freed while read() runs
        reader.thread.join(timeout=5)
        self.assertEqual(events, ["read-returned", "released"])


class LiveLatencyTests(unittest.TestCase):
    """Live view rate, detection crop and full-resolution box mapping."""

    def test_live_view_keeps_15_fps_from_a_25_fps_camera(self):
        import detector_service as detector

        state = {}
        clock = [100.0]
        with mock.patch.object(detector.time, "monotonic", lambda: clock[0]):
            published = 0
            for _ in range(250):  # 10 seconds of 25 fps frames
                published += detector.publish_due(state, {"stream_fps": 15})
                clock[0] += 0.04
        self.assertTrue(148 <= published <= 152, published)

    def test_detection_crop_maps_boxes_back_to_the_full_frame(self):
        import numpy as np
        import torch
        import detector_service as detector

        frame = np.zeros((416, 736, 3), dtype=np.uint8)
        zone = {"calibration_mask": [{"x": 0.4, "y": 0.4}, {"x": 0.6, "y": 0.4}, {"x": 0.6, "y": 0.6}, {"x": 0.4, "y": 0.6}]}
        crop = detector.roi_crop_box(zone, frame, True)
        self.assertIsNotNone(crop)
        self.assertIsNone(detector.roi_crop_box(zone, frame, False))
        full = {"calibration_mask": [{"x": 0.01, "y": 0.01}, {"x": 0.99, "y": 0.01}, {"x": 0.99, "y": 0.99}, {"x": 0.01, "y": 0.99}]}
        self.assertIsNone(detector.roi_crop_box(full, frame, True))  # zone ~ whole frame: no crop

        boxes = mock.Mock()
        boxes.data = torch.tensor([[10.0, 20.0, 30.0, 40.0, 1.0, 0.9, 2.0]])
        results = mock.Mock(boxes=boxes)
        detector.offset_results(results, crop[0], crop[1])
        self.assertEqual(boxes.data[0, :4].tolist(), [10.0 + crop[0], 20.0 + crop[1], 30.0 + crop[0], 40.0 + crop[1]])

    def test_live_box_scales_to_the_full_resolution_frame(self):
        from hires import scale_box

        box = scale_box((100, 100, 200, 150), (416, 736, 3), (1440, 2560, 3), pad=0)
        self.assertEqual(box, (347, 346, 695, 519))
