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
        # Windows install kit: an extra address next to DHCP (never "set address static").
        self.assertIn("New-NetIPAddress -InterfaceAlias 'USB LAN' -IPAddress 203.0.113.253 -PrefixLength 24", commands["windows"]["add"])
        self.assertNotIn("static", commands["windows"]["add"])

    def test_windows_temporary_address_runs_without_replacing_dhcp(self):
        plan = tempip.plan("203.0.113.60", set())
        with mock.patch.object(tempip, "SYSTEM", "windows"):
            add = tempip.TemporaryAddress({"name": "Ethernet 2", "label": "Ethernet 2"}, plan, lambda message: None)._command("add")
        self.assertEqual(["powershell", "-NoProfile", "-NonInteractive", "-Command"], add[:4])
        self.assertIn("New-NetIPAddress -InterfaceAlias 'Ethernet 2' -IPAddress 203.0.113.254 -PrefixLength 24 -SkipAsSource $true -PolicyStore ActiveStore", add[4])

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

        # A real inference-mode tensor, like YOLO returns (a plain tensor here
        # hid the "Inplace update to inference tensor" crash before).
        from ultralytics.engine.results import Boxes

        with torch.inference_mode():
            data = torch.tensor([[10.0, 20.0, 30.0, 40.0, 1.0, 0.9, 2.0]])
        results = mock.Mock(boxes=Boxes(data, (crop[3] - crop[1], crop[2] - crop[0])))
        detector.offset_results(results, crop[0], crop[1], frame.shape)
        self.assertEqual(results.boxes.data[0, :4].tolist(), [10.0 + crop[0], 20.0 + crop[1], 30.0 + crop[0], 40.0 + crop[1]])

    def test_live_box_scales_to_the_full_resolution_frame(self):
        from hires import scale_box

        box = scale_box((100, 100, 200, 150), (416, 736, 3), (1440, 2560, 3), pad=0)
        self.assertEqual(box, (347, 346, 695, 519))


class FindMyReaderTests(unittest.TestCase):
    """Before/after wizard: new MACs, passive packets, verdicts."""

    def test_passive_parser_reads_arp_dhcp_and_broadcasts(self):
        from devices.passive import PassiveListener

        listener = PassiveListener("en7", {"DC:32:62:56:1E:02"}, lambda message: None)
        listener.parse("1727561234.1 d8:a0:1d:00:00:02 > ff:ff:ff:ff:ff:ff, ethertype ARP (0x0806), length 60: Request who-has 203.0.113.190 tell 203.0.113.190, length 46")
        listener.parse("1727561234.2 d8:a0:1d:00:00:02 > ff:ff:ff:ff:ff:ff, ethertype IPv4 (0x0800), length 60: 203.0.113.190.4001 > 255.255.255.255.1500: UDP, length 16")
        listener.parse("1727561234.3 d8:a0:1d:00:00:03 > ff:ff:ff:ff:ff:ff, ethertype IPv4 (0x0800), length 342: 0.0.0.0.68 > 255.255.255.255.67: BOOTP/DHCP, Request from d8:a0:1d:00:00:03, length 300")
        listener.parse("1727561234.4 dc:32:62:56:1e:02 > ff:ff:ff:ff:ff:ff, ethertype ARP (0x0806), length 42: Request who-has 198.51.100.9 tell 198.51.100.2, length 28")
        heard = listener.snapshot()
        reader = heard["D8:A0:1D:00:00:02"]
        self.assertEqual(reader["ips"], ["203.0.113.190"])
        self.assertEqual(reader["arp_announces"], 1)
        self.assertEqual(reader["broadcast_ports"], [1500])
        self.assertEqual(heard["D8:A0:1D:00:00:03"]["dhcp_requests"], 1)
        self.assertEqual(heard["D8:A0:1D:00:00:03"]["ips"], [])
        self.assertNotIn("DC:32:62:56:1E:02", heard)  # this PC itself

    def wizard(self, sweeps, scan_ports):
        """Run the wizard on a fake LAN whose ARP sweeps return `sweeps` in turn."""
        from devices import find

        lan = {"name": "en7", "label": "USB LAN", "kind": "ethernet", "ip": "127.0.0.2", "network": "127.0.0.0/8",
               "gateway": None, "link_local": False, "mac": "DC:32:62:56:1E:02"}
        wizard = find.FindReaderWizard(load_profiles(), 3, lambda message: None, passive=False, listen_seconds=4)
        calls = iter(sweeps)

        async def fake_scan(ip, timeout=0.4, concurrency=800):
            return scan_ports

        with mock.patch.object(find.netinfo, "interfaces", return_value=[lan]), \
                mock.patch.object(find.FindReaderWizard, "_sweep", lambda self, interfaces: next(calls, sweeps[-1])), \
                mock.patch.object(find, "full_tcp_scan", fake_scan):
            return wizard.run()

    def _run_with_fake_listener(self, wizard, passive):
        """Passive listening that hears nothing during the baseline, then `passive`."""
        from devices import find

        calls = {"count": 0}

        def snapshot():
            calls["count"] += 1
            return {} if calls["count"] == 1 else passive

        with mock.patch.object(find, "availability", return_value=(True, "test")), \
                mock.patch.object(find, "PassiveListener", lambda *args: mock.Mock(start=lambda: None, stop=lambda: None, snapshot=snapshot)):
            wizard.passive_wanted = True
            return wizard.run()

    def test_new_reader_on_this_network_is_found_by_its_tags(self):
        server = socket.socket()
        server.bind(("127.0.0.1", 0))
        server.listen(4)
        port = server.getsockname()[1]
        inventory = uhf.build_command("r2000", "89", "01")

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
                                conn.sendall(r2000_tag_frame())
                    except OSError:
                        return
                threading.Thread(target=handle, args=(connection,), daemon=True).start()

        threading.Thread(target=serve, daemon=True).start()
        result = self.wizard([{}, {"127.0.0.1": "D8:A0:1D:00:00:02"}], scan_ports=[port])
        server.close()
        self.assertEqual(result["result"], "reader_found", result["message"])
        self.assertEqual(result["new_devices"][0]["mac"], "D8:A0:1D:00:00:02")
        self.assertIn(EPC, result["message"])

    def test_reader_with_a_fixed_ip_elsewhere_is_reported_with_the_fix(self):
        heard = {"D8:A0:1D:00:00:02": {"mac": "D8:A0:1D:00:00:02", "packets": 3, "ips": ["203.0.113.190"], "arp_announces": 1,
                                       "dhcp_requests": 0, "dhcp_replies": 0, "broadcast_ports": [], "sample": None}}
        result = self._run_with_fake_listener(self._make(), heard)
        self.assertEqual(result["result"], "other_subnet", result["message"])
        self.assertEqual(result["other_subnet"]["network"], "203.0.113.0/24")
        self.assertIn("DHCP", result["message"])

    def test_dhcp_without_address_and_silence(self):
        heard = {"D8:A0:1D:00:00:03": {"mac": "D8:A0:1D:00:00:03", "packets": 4, "ips": [], "arp_announces": 0,
                                       "dhcp_requests": 4, "dhcp_replies": 0, "broadcast_ports": [], "sample": None}}
        self.assertEqual(self._run_with_fake_listener(self._make(), heard)["result"], "dhcp_no_address")
        self.assertEqual(self._run_with_fake_listener(self._make(), {})["result"], "nothing")

    def _make(self):
        from devices import find

        lan = {"name": "en7", "label": "USB LAN", "kind": "ethernet", "ip": "198.51.100.2", "network": "198.51.100.0/24",
               "gateway": "198.51.100.1", "link_local": False, "mac": "DC:32:62:56:1E:02"}
        wizard = find.FindReaderWizard(load_profiles(), 2, lambda message: None, listen_seconds=2)
        # Each call stops its own patches (a second call used to leave the
        # first netinfo.interfaces patch running for every later test).
        patches = [mock.patch.object(find.netinfo, "interfaces", return_value=[lan]),
                   mock.patch.object(find.FindReaderWizard, "_sweep", lambda self, interfaces: {})]
        for patch in patches:
            patch.start()
            self.addCleanup(patch.stop)
        return wizard


# Real frames from the entrance reader (WCH module, TCP server port from the profiles).
CC_SAMPLES = [
    "CCFFFF200510003000E280689400005031D6458CE8BAA9",
    "CCFFFF200510003000E280689400005031D6458CE8BEA5",
    "CCFFFF200510003000E280689400005031D6458CE8BFA4",
]
CC_EPC = "E280689400005031D6458CE8"


def cc_frame(command, payload, flag=0x05):
    body = bytes([0xCC, 0xFF, 0xFF, command, flag, len(payload)]) + bytes(payload)
    return body + bytes([(-sum(body)) & 0xFF])


class CcReaderTests(unittest.TestCase):
    """CC FF FF readers: parser, debounce, signature and the station link."""

    def test_real_samples_split_into_single_bytes(self):
        data = b"".join(bytes.fromhex(item) for item in CC_SAMPLES)
        decoder = uhf.StreamDecoder()
        frames = []
        for index in range(len(data)):
            frames += decoder.feed(data[index:index + 1])
        self.assertEqual([frame.epc for frame in frames], [CC_EPC] * 3)
        self.assertEqual([frame.rssi for frame in frames], [-70, -66, -65])
        self.assertEqual(decoder.protocol, "cc")
        self.assertEqual(decoder.take_discarded(), b"")

    def test_epc_length_comes_from_the_pc_word(self):
        epc = bytes.fromhex("3005FB63AC1F3841")  # 4 words
        frame = cc_frame(0x20, bytes([0x00, 0x20, 0x00]) + epc + bytes([0xC4]))
        frames, protocol = uhf.decode_once(frame)
        self.assertEqual((protocol, frames[0].epc, frames[0].rssi), ("cc", epc.hex().upper(), -60))

    def test_broken_frame_is_discarded_and_the_next_one_still_decodes(self):
        broken = bytearray(bytes.fromhex(CC_SAMPLES[0]))
        broken[12] ^= 0x01
        decoder = uhf.StreamDecoder()
        frames = decoder.feed(b"\x00\x13" + bytes(broken) + bytes.fromhex(CC_SAMPLES[1]))
        self.assertEqual([(frame.epc, frame.rssi) for frame in frames], [(CC_EPC, -66)])
        self.assertTrue(decoder.take_discarded().startswith(b"\x00\x13\xCC\xFF\xFF"))

    def test_unknown_command_is_a_reply_not_a_tag(self):
        frames, _ = uhf.decode_once(cc_frame(0x21, b"\x01\x02"))
        self.assertEqual((frames[0].kind, frames[0].command), ("reply", 0x21))

    def test_debounce_one_event_per_cooldown_and_never_while_the_tag_stays(self):
        from devices.reader_link import TagFilter

        tags = TagFilter(cooldown_seconds=60, absent_seconds=5)
        self.assertTrue(tags.should_send(CC_EPC, now=0))
        # Read continuously for two minutes: still one event.
        self.assertFalse(any(tags.should_send(CC_EPC, now=second) for second in range(1, 120)))
        # Left at 119 s, back at 130 s: more than 60 s since the event.
        self.assertTrue(tags.should_send(CC_EPC, now=130))
        # Left and back within the cooldown: no new event.
        self.assertFalse(tags.should_send(CC_EPC, now=150))
        self.assertTrue(tags.should_send("E2000000000000000000AAAA", now=150))

    def test_signature_confirms_a_silent_active_reader(self):
        scan = scanner.Scanner(load_profiles())
        port = load_profiles()["uhf_reader"]["signatures"][0]["tcp_port"]
        reader = scan._with_signature({"port": port, "confirmed": False}, "70:19:88:BF:D6:51", [port])
        self.assertEqual((reader["protocol"], reader["work_mode"], reader["confirmed"], reader["confirmed_by"]),
                         ("cc", "active", True, "signature"))
        self.assertIsNone(scan._with_signature(None, "70:19:88:BF:D6:51", []))
        self.assertIsNone(scan._with_signature(None, "00:11:22:33:44:55", [port]))
        tag = {"port": port, "protocol": "cc", "confirmed": True, "sample_tags": [CC_EPC]}
        self.assertEqual(scan._with_signature(tag, "70:19:88:BF:D6:51", [port])["confirmed_by"], "frame")

    def test_reader_behind_an_extra_address_gets_a_warning(self):
        link_local = {"name": "en7", "label": "USB LAN", "ip": "169.254.9.9", "network": "169.254.0.0/16", "gateway": None, "link_local": True}
        extra = {"name": "en7", "label": "USB LAN", "ip": "198.51.100.1", "network": "198.51.100.0/24", "gateway": None, "link_local": False}
        dhcp = {"name": "en7", "label": "USB LAN", "ip": "203.0.113.5", "network": "203.0.113.0/24", "gateway": "203.0.113.1", "link_local": False}
        warning = scanner.Scanner._extra_address(extra, [link_local, extra])
        self.assertEqual((warning["pc_ip"], warning["no_dhcp"]), ("198.51.100.1", True))
        warning = scanner.Scanner._extra_address(extra, [dhcp, extra])
        self.assertEqual((warning["lan_network"], warning["lan_gateway"], warning["no_dhcp"]), ("203.0.113.0/24", "203.0.113.1", False))
        self.assertIsNone(scanner.Scanner._extra_address(extra, [extra]))
        self.assertIsNone(scanner.Scanner._extra_address(dhcp, [dhcp, extra]))

    def test_light_scan_does_not_probe_a_reader_the_link_holds(self):
        scan = scanner.Scanner(load_profiles())
        known = {"70:19:88:BF:D6:51": {"mac": "70:19:88:BF:D6:51", "ip": "198.51.100.116", "reader": {"transport": "tcp", "port": 49152}}}
        with mock.patch.object(probes, "tcp_open", mock.AsyncMock(return_value=False)) as tcp_open:
            carried = scan._recheck_known(known, {"198.51.100.116": "70:19:88:BF:D6:51"}, {"198.51.100.116": "70:19:88:BF:D6:51"})
        self.assertTrue(carried["70:19:88:BF:D6:51"]["online"])
        tcp_open.assert_not_called()

    def test_station_link_posts_the_epc_once_and_logs_unknown_frames(self):
        import time as time_module

        from devices.reader_link import CaptureLog, ReaderLink

        data = b"".join(bytes.fromhex(item) for item in CC_SAMPLES) * 3 + cc_frame(0x21, b"\x01")
        server = socket.socket()
        server.bind(("127.0.0.1", 0))
        server.listen(1)
        port = server.getsockname()[1]

        def serve():
            connection, _ = server.accept()
            # Odd chunk sizes: frames never line up with socket reads.
            for index in range(0, len(data), 7):
                connection.sendall(data[index:index + 7])
                time_module.sleep(0.01)
            time_module.sleep(1.5)
            connection.close()

        threading.Thread(target=serve, daemon=True).start()
        posted, logs = [], []
        poster = mock.Mock()
        poster.post.side_effect = posted.append
        link = ReaderLink("entrance", poster, load_profiles(), mock.Mock(spec=CaptureLog), lambda target: None, logs.append)
        link.set_target({"ip": "127.0.0.1", "port": port, "transport": "tcp", "mac": "70:19:88:BF:D6:51",
                         "protocol": "cc", "work_mode": "active", "cooldown_seconds": 60})
        link.start()

        deadline = time_module.monotonic() + 4
        while time_module.monotonic() < deadline and link.snapshot().get("tags_read", 0) < 9:
            time_module.sleep(0.05)
        time_module.sleep(0.3)

        snap = link.snapshot()
        self.assertEqual([item["tag_uid"] for item in posted], [CC_EPC])
        self.assertEqual(posted[0]["scan_location"], "entrance")
        self.assertEqual(posted[0]["payload_json"]["rssi"], -70)
        self.assertEqual((snap["state"], snap["tags_read"], snap["events_sent"], snap["last_tag"], snap["last_rssi"]),
                         ("connected", 9, 1, CC_EPC, -65))
        self.assertEqual(snap["recent_tags"][0]["epc"], CC_EPC)
        self.assertEqual(snap["unknown_frames"], 1)
        self.assertTrue(any("unknown cc frame cmd=0x21" in line for line in logs))
