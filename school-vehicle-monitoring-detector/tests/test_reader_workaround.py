"""
Live view work, Phase 2: the temporary extra address for a reader that is
still on another subnet (development workaround). RFC 5737 addresses.

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from devices import workaround  # noqa: E402

LAN = {"name": "en7", "label": "USB 10/100 LAN", "ip": "203.0.113.2", "network": "203.0.113.0/24", "prefix": 24, "gateway": "203.0.113.1"}
READER = {"mac": "70:19:88:BF:D6:51", "ip": "198.51.100.116"}
MODULE = {"ip": "198.51.100.116", "mac": "70:19:88:BF:D6:51"}


class Workaround(unittest.TestCase):
    def setUp(self):
        self.commands = []
        self.answer = (0, "")
        patcher = mock.patch.object(workaround, "is_admin", return_value=False)
        patcher.start()
        self.addCleanup(patcher.stop)
        self.subject = workaround.ReaderWorkaround(log=lambda message: None, run=self.fake_run)

    def fake_run(self, command):
        self.commands.append(command)
        return self.answer

    def check(self, interfaces, enabled=True, now=1000.0, target=READER):
        return self.subject.check(enabled, {"gate-1": target}, interfaces, [], lambda mac: (LAN, MODULE), now)["gate-1"]

    def test_adds_an_address_in_the_readers_network_without_a_password_prompt(self):
        with mock.patch.object(workaround, "SYSTEM", "darwin"):
            status = self.check([LAN])
        self.assertEqual({"state": "active", "interface": "USB 10/100 LAN", "ip": "198.51.100.254", "reader_ip": "198.51.100.116"}, status)
        self.assertEqual(["sudo", "-n", "/sbin/ifconfig", "en7", "alias", "198.51.100.254", "255.255.255.0"], self.commands[0])

    def test_without_permission_it_says_so_and_does_not_retry_at_once(self):
        self.answer = (1, "sudo: a password is required")
        with mock.patch.object(workaround, "SYSTEM", "darwin"):
            self.assertEqual("needs_permission", self.check([LAN])["state"])
            self.assertEqual("needs_permission", self.check([LAN], now=1030.0)["state"])
        self.assertEqual(1, len(self.commands))

    def test_windows_adds_a_session_only_address(self):
        with mock.patch.object(workaround, "SYSTEM", "windows"):
            self.check([LAN])
        self.assertIn("New-NetIPAddress -InterfaceAlias 'USB 10/100 LAN' -IPAddress 198.51.100.254 -PrefixLength 24", self.commands[0][-1])
        self.assertIn("-PolicyStore ActiveStore", self.commands[0][-1])

    def test_not_needed_when_the_reader_is_in_the_pcs_network_and_the_added_address_is_removed(self):
        with mock.patch.object(workaround, "SYSTEM", "darwin"):
            self.check([LAN])
            alias = {**LAN, "ip": "198.51.100.254", "network": "198.51.100.0/24", "gateway": None}
            self.assertEqual("active", self.check([LAN, alias])["state"])
            # The reader moved to 203.0.113.250: the extra address goes.
            moved = self.check([LAN, alias], target={**READER, "ip": "203.0.113.250"})
        self.assertEqual({"state": "not_needed"}, moved)
        self.assertEqual(["sudo", "-n", "/sbin/ifconfig", "en7", "-alias", "198.51.100.254"], self.commands[-1])

    def test_switched_off_does_nothing(self):
        self.assertEqual({"state": "off"}, self.check([LAN], enabled=False))
        self.assertEqual([], self.commands)


if __name__ == "__main__":
    unittest.main()
