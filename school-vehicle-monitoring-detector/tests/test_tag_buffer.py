"""
RFID only with a vehicle: the UHF reads buffer (devices/tag_buffer.py).

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from devices.reader_link import ReaderLink  # noqa: E402
from devices.tag_buffer import TagBuffer  # noqa: E402

T0 = 1_790_000_000.0


class TagBufferTests(unittest.TestCase):
    def test_reads_of_one_pass_are_one_presence_with_its_rssi_peak(self):
        buffer = TagBuffer(window_seconds=15, absent_seconds=5, stationary_seconds=60)
        for offset, rssi in ((0.0, -72), (0.4, -65), (0.8, -58), (1.2, -63), (1.6, -70)):
            buffer.add("gate-1", "E2001", rssi, T0 + offset)
        snap = buffer.snapshot(T0 + 2)
        presence = snap["gates"]["gate-1"][0]
        self.assertEqual((presence["reads"], presence["max_rssi"], presence["peak_at"], presence["stationary"]), (5, -58, T0 + 0.8, False))
        self.assertEqual(snap["raw_reads"], {"gate-1": 5})

    def test_a_gap_starts_a_new_presence_and_old_ones_are_forgotten(self):
        buffer = TagBuffer(window_seconds=15, absent_seconds=5)
        buffer.add("gate-1", "E2001", -60, T0)
        buffer.add("gate-1", "E2001", -60, T0 + 9)           # gone 9 s: another pass
        self.assertEqual(buffer.snapshot(T0 + 9)["gates"]["gate-1"][0]["session"], round(T0 + 9, 3))
        self.assertEqual(len(buffer.snapshot(T0 + 9)["ended"]), 1)
        buffer.expire(T0 + 30)
        self.assertEqual(buffer.snapshot(T0 + 30)["gates"], {})
        self.assertEqual(len(buffer.snapshot(T0 + 30)["ended"]), 2)

    def test_a_tag_read_for_over_a_minute_is_stationary_until_it_leaves(self):
        buffer = TagBuffer(window_seconds=15, absent_seconds=5, stationary_seconds=60)
        for second in range(0, 75, 2):                         # parked near the gate
            buffer.add("gate-1", "PARKED", -70, T0 + second)
        self.assertTrue(buffer.snapshot(T0 + 75)["gates"]["gate-1"][0]["stationary"])
        buffer.add("gate-1", "PARKED", -70, T0 + 90)          # left, came back: a new presence
        self.assertFalse(buffer.snapshot(T0 + 90)["gates"]["gate-1"][0]["stationary"])

    def test_the_reader_puts_every_read_in_the_buffer(self):
        buffer = TagBuffer()
        posted = []

        class Poster:
            def post(self, payload):
                posted.append(payload)

        class Frame:
            kind, epc, rssi, protocol, antenna = "tag", "E2009", -61, "cc", 1

        link = ReaderLink("gate-1", Poster(), {"reader_link": {}}, None, lambda target: None, lambda message: None, buffer)
        link._handle([Frame(), Frame(), Frame()], "192.0.2.5", {"mac": "AA"})
        self.assertEqual(buffer.snapshot()["gates"]["gate-1"][0]["reads"], 3)
        self.assertEqual(len(posted), 1)  # one cooldown event (recorded only when the camera is offline)

    def test_a_deleted_reader_leaves_no_reads_of_its_gate(self):
        buffer = TagBuffer()
        buffer.add("gate-1", "E2001", -60, T0)
        buffer.add("gate-2", "E2002", -60, T0)
        buffer.add("gate-1", "E2001", -60, T0 + 10)  # a new presence; the first one ended

        buffer.clear_station("gate-1")

        snapshot = buffer.snapshot(T0 + 11)
        self.assertEqual(["gate-2"], list(snapshot["gates"]))
        self.assertEqual({"gate-2": 1}, snapshot["raw_reads"])
        self.assertEqual([], [item for item in snapshot["ended"] if item["station"] == "gate-1"])


if __name__ == "__main__":
    unittest.main()
