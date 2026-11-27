"""
Live view work, Phase 2: moving the reader to the PC's network through its
module's setup protocol. A fake module answers like the real gate reader
(its settings block was measured on 2026-10-06; RFC 5737 addresses here).

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import ipaddress
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from devices import module_config as mc  # noqa: E402

MAC = "70:19:88:BF:D6:51"

# The measured block (first answer to "read"), with the private addresses
# swapped for documentation ones: 192.168.2.x -> 198.51.100.x (module) and
# the unused client-mode server -> 203.0.113.100.
MEASURED = bytes.fromhex(
    "00 00 52 C0 00 00 50 00 00 74 02 A8 C0 01 02 A8 C0 00 FF FF FF 41 44 2D 4E 55 5F 44 36 35 31 00 00 00 00 00 00"
    " 61 64 6D 69 6E 00 61 64 6D 69 6E 00 14 01 00 00 70 19 88 BF D6 51 04 04 08 08 03 00 00 00 00 E1 00 00 08 01"
    " 01 00 00 00 00 00 00 C0 01 C0 31 39 32 2E 31 36 38 2E 32 2E 31 30 30 00 00 00 00 00 00 00 00 00 00 00 00 00"
    " 00 00 00 00 64 02 A8 C0 04 03 01 01 00 00 00 91 00 00 00 00 00"
    .replace("74 02 A8 C0", "74 64 33 C6").replace("01 02 A8 C0", "01 64 33 C6").replace("64 02 A8 C0", "64 71 00 CB")
    .replace(" ", "")
).replace(b"192.168.2.100", b"203.0.113.100")
assert len(MEASURED) == 130


class FakeModule:
    def __init__(self, block=MEASURED, username="admin", password="admin"):
        self.block = bytearray(block)
        self.login = mc.login(MAC, username, password)
        self.pending = None
        self.packets = []
        self.answer_after_restart = True

    @property
    def ip(self):
        return mc._ip_le(self.block, 9)

    def __call__(self, packet, wait):
        self.packets.append(packet)
        assert packet[0] == 0xFF and mc.checksum(packet[1:-1]) == packet[-1], "bad checksum"
        command, payload = packet[2], packet[3:-1]
        if command == mc.CMD_SEARCH:
            if not self.answer_after_restart:
                return []
            body = bytes([0x24, 0x01, 0, 0]) + ipaddress.IPv4Address(self.ip).packed + mc.mac_bytes(MAC) \
                + b"\0\0\x05\x02" + b"AD-NU_D651".ljust(16, b"\0")
            return [(self.ip, b"\xff" + body + bytes([mc.checksum(body)]))]
        if payload[:18] != self.login:
            return [(self.ip, bytes([0xFF, 0x01, command, ord("P")]))]
        if command == mc.CMD_READ:
            return [(self.ip, bytes(self.block))]
        if command == mc.CMD_WRITE_BASIC:
            assert len(payload) == 18 + mc.BASIC_SIZE
            self.pending = payload[18:]
            return [(self.ip, bytes([0xFF, 0x01, command, ord("K")]))]
        if command == mc.CMD_RESTART:
            if self.pending:
                self.block[:mc.BASIC_SIZE] = self.pending
            return [(self.ip, bytes([0xFF, 0x01, command, ord("K")]))]
        return [(self.ip, bytes([0xFF, 0x01, command, ord("E")]))]


def setup(module, wait=1):
    return mc.ModuleSetup({"restart_wait_seconds": wait}, transport=module, log=lambda message: None)


class Protocol(unittest.TestCase):
    def test_frames_match_the_manual_examples(self):
        self.assertEqual(bytes.fromhex("FF010102"), mc.frame(mc.CMD_SEARCH))
        # Manual 4.6.1: read configuration of MAC 00:71:77:7C:42:2F with admin/admin ends with FD.
        self.assertEqual(
            bytes.fromhex("FF1303007177 7C422F61646D696E0061646D696E00FD".replace(" ", "")),
            mc.frame(mc.CMD_READ, mc.login("00:71:77:7C:42:2F", "admin", "admin")),
        )

    def test_search_and_read_decode_the_reader(self):
        module = FakeModule()
        self.assertEqual({"ip": "198.51.100.116", "mac": MAC, "version": "0502", "name": "AD-NU_D651"}, setup(module).search()[MAC])

        settings = setup(module).read(MAC)
        self.assertEqual(
            ["198.51.100.116", "198.51.100.1", "255.255.255.0", False, "TCP server", 49152, 57600, "none", 1],
            [settings["ip"], settings["gateway"], settings["netmask"], settings["dhcp"], settings["work_mode"],
             settings["local_port"], settings["baud_rate"], settings["parity"], settings["max_clients"]],
        )
        self.assertNotIn("61 64 6D 69 6E", mc.masked_hex(MEASURED), "Logs never show the module login.")

    def test_move_changes_only_the_address_fields_and_finds_the_reader_again(self):
        module = FakeModule()
        target = {"ip": "203.0.113.250", "netmask": "255.255.255.0", "gateway": "203.0.113.1", "network": "203.0.113.0/24"}

        result = setup(module).move(MAC, "198.51.100.116", 49152, "static", target)

        self.assertEqual("203.0.113.250", result["after_ip"])
        changed = [index for index, (old, new) in enumerate(zip(MEASURED, module.block)) if old != new]
        self.assertTrue(set(changed) <= set(range(9, 21)), f"only IP, gateway and mask change (changed {changed})")
        self.assertEqual(MEASURED[mc.BASIC_SIZE:], bytes(module.block[mc.BASIC_SIZE:]), "port block (mode, port, serial) untouched")
        self.assertEqual([mc.CMD_READ, mc.CMD_WRITE_BASIC, mc.CMD_RESTART, mc.CMD_SEARCH], [packet[2] for packet in module.packets])

    def test_dhcp_clears_only_the_static_flag(self):
        module = FakeModule()
        module_ip_after = "203.0.113.57"

        class Dhcp(FakeModule):
            def __call__(self, packet, wait):
                replies = super().__call__(packet, wait)
                if packet[2] == mc.CMD_RESTART and not (self.block[3] & mc.FLAG_STATIC_IP):
                    self.block[9:13] = mc._le_ip(module_ip_after)  # the router gives an address
                return replies

        module = Dhcp()
        result = setup(module).move(MAC, "198.51.100.116", 49152, "dhcp", {"network": "203.0.113.0/24"})
        self.assertEqual(module_ip_after, result["after_ip"])
        self.assertEqual(MEASURED[3] & ~mc.FLAG_STATIC_IP & 0xFF, module.block[3])

    def test_nothing_is_written_when_the_settings_do_not_match_the_reader(self):
        module = FakeModule()
        with self.assertRaises(mc.ModuleError) as wrong_port:
            setup(module).move(MAC, "198.51.100.116", 6000, "static", {"ip": "203.0.113.250", "netmask": "255.255.255.0", "network": "203.0.113.0/24"})
        self.assertEqual("layout", wrong_port.exception.code)
        self.assertNotIn(mc.CMD_WRITE_BASIC, [packet[2] for packet in module.packets])

        locked = FakeModule(password="secret")
        with self.assertRaises(mc.ModuleError) as login:
            setup(locked).read(MAC)
        self.assertEqual("wrong_login", login.exception.code)
        self.assertEqual("198.51.100.116", setup(locked).read(MAC, "admin", "secret")["ip"])

    def test_a_reader_that_does_not_come_back_is_reported(self):
        module = FakeModule()
        original = module.__call__

        def silent_after_restart(packet, wait):
            replies = original(packet, wait)
            if packet[2] == mc.CMD_RESTART:
                module.answer_after_restart = False
            return replies

        module_setup = mc.ModuleSetup({"restart_wait_seconds": 0.1}, transport=silent_after_restart)
        with self.assertRaises(mc.ModuleError) as gone:
            module_setup.move(MAC, "198.51.100.116", 49152, "static", {"ip": "203.0.113.250", "netmask": "255.255.255.0", "network": "203.0.113.0/24"})
        self.assertEqual("not_found", gone.exception.code)

    def test_free_address_skips_known_and_answering_hosts(self):
        interface = {"ip": "203.0.113.2", "gateway": "203.0.113.1", "network": "203.0.113.0/24"}
        busy = {"203.0.113.254", "203.0.113.252"}
        self.assertEqual("203.0.113.251", mc.free_address(interface, {"203.0.113.253"}, lambda ip: ip in busy))



class OnePerMac(unittest.TestCase):
    def test_a_moved_reader_keeps_its_new_address_not_the_stale_one(self):
        from devices.scanner import one_per_mac

        stale = {"mac": MAC, "ip": "198.51.100.116", "subnet": None, "kind": "unknown", "discovered_by": ["arp"], "open_ports": {"tcp": []}}
        moved = {"mac": MAC, "ip": "203.0.113.3", "subnet": "203.0.113.0/24", "kind": "rfid_reader",
                 "discovered_by": ["arp", "reader-broadcast"], "open_ports": {"tcp": [49152]}, "module": {"module": "x"}}
        for rows in ([stale, moved], [moved, stale]):
            self.assertEqual(["203.0.113.3"], [device["ip"] for device in one_per_mac(rows)])


if __name__ == "__main__":
    unittest.main()
