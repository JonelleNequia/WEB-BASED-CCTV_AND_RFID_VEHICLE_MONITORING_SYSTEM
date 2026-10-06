"""
B2 (Settings): the two reasons a plugged-in reader stayed hidden (2026-10-05):
the module search left only through the default (Wi-Fi) card, and an extra
address added without a netmask was not scanned at all.

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import socket
import sys
import unittest
from collections import namedtuple
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from devices import netinfo, probes  # noqa: E402

LAN = {"name": "en7", "ip": "192.168.1.2", "broadcast": "192.168.1.255"}
WIFI = {"name": "en0", "ip": "192.168.137.43", "broadcast": "192.168.137.255"}
PROFILES = {"uhf_reader": {"broadcast_discovery": [{"name": "USR IOT module search", "port": 1500, "payload_hex": "ff010102"}]},
            "scan": {"udp_reply_seconds": 0.05}}


class FakeSocket:
    made = []

    def __init__(self, *_args):
        self.options, self.bound, self.sent = [], None, []
        FakeSocket.made.append(self)

    def setsockopt(self, *args):
        self.options.append(args)

    def setblocking(self, _flag):
        pass

    def bind(self, address):
        self.bound = address

    def sendto(self, payload, address):
        self.sent.append((payload, address))

    def fileno(self):
        return -1

    def close(self):
        pass


class SearchEveryCardTests(unittest.TestCase):
    def setUp(self):
        FakeSocket.made = []

    def search(self, platform):
        with mock.patch.object(probes.socket, "socket", FakeSocket), mock.patch.object(probes.sys, "platform", platform), \
                mock.patch.object(probes.socket, "if_nametoindex", side_effect=lambda name: {"en7": 19, "en0": 11}[name]), \
                mock.patch.object(probes.select, "select", return_value=([], [], [])):
            probes.broadcast_discovery([LAN, WIFI], PROFILES, [])
        return FakeSocket.made

    def test_the_search_leaves_through_each_card_on_macos(self):
        made = self.search("darwin")
        self.assertEqual(len(made), 2)
        self.assertIn((socket.IPPROTO_IP, probes.IP_BOUND_IF, 19), made[0].options)   # the LAN card
        self.assertIn((socket.IPPROTO_IP, probes.IP_BOUND_IF, 11), made[1].options)   # the Wi-Fi card
        self.assertEqual({address for _payload, address in made[0].sent}, {("255.255.255.255", 1500), ("192.168.1.255", 1500)})

    def test_other_systems_bind_to_the_cards_own_address(self):
        made = self.search("win32")
        self.assertEqual([sock.bound for sock in made], [("192.168.1.2", 0), ("192.168.137.43", 0)])


class AliasWithoutNetmaskTests(unittest.TestCase):
    def test_an_extra_address_without_a_netmask_is_scanned_as_a_24(self):
        address = namedtuple("snicaddr", "family address netmask broadcast ptp")
        stats = namedtuple("snicstats", "isup")
        addrs = {"en7": [
            address(socket.AF_INET, "192.168.1.2", "255.255.255.0", "192.168.1.255", None),
            address(socket.AF_INET, "192.168.2.1", None, "255.255.255.0", None),   # `alias 192.168.2.1 255.255.255.0`
        ]}
        with mock.patch.object(netinfo.psutil, "net_if_addrs", return_value=addrs), \
                mock.patch.object(netinfo.psutil, "net_if_stats", return_value={"en7": stats(True)}), \
                mock.patch.object(netinfo, "interface_label", return_value="USB LAN"), \
                mock.patch.object(netinfo, "interface_kind", return_value="ethernet"):
            networks = [item["network"] for item in netinfo.interfaces({})]
        self.assertEqual(networks, ["192.168.1.0/24", "192.168.2.0/24"])
        self.assertIsNone(netinfo._assumed_netmask("8.8.8.8"))


if __name__ == "__main__":
    unittest.main()
