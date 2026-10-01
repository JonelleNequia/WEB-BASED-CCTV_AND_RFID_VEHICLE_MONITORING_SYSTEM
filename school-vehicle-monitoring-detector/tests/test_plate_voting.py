"""
Phase 5: one plate per vehicle from several frames.

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from anpr import normalize_plate_text  # noqa: E402
from plate_voting import frames_agree, vote_plate  # noqa: E402


def reads(*items):
    return [{"plate": plate, "score": score} for plate, score in items]


class PlateVotingTests(unittest.TestCase):
    def test_a_single_misread_character_is_outvoted(self):
        vote = vote_plate(reads(("ABC-1234", 0.6), ("ABC-1284", 0.5), ("ABC-1234", 0.55), (None, 0.0)))
        self.assertEqual((vote["plate"], vote["status"]), ("ABC-1234", "read"))
        self.assertEqual((vote["frames_checked"], vote["frames_with_plate"]), (4, 3))
        self.assertEqual(vote["candidates"]["ABC-1234"]["count"], 2)

    def test_different_misreads_in_different_frames_are_outvoted(self):
        # One frame misread a digit, another a letter: each character still has a majority.
        vote = vote_plate(reads(("ABC-1284", 0.6), ("ABD-1234", 0.6), ("ABC-1234", 0.3), ("ABC-1234", 0.3)))
        self.assertEqual(vote["plate"], "ABC-1234")

    def test_no_plate_in_any_frame_is_unreadable(self):
        vote = vote_plate(reads((None, 0.0), (None, 0.0)))
        self.assertEqual((vote["plate"], vote["status"], vote["best_guess"]), (None, "unreadable", None))
        self.assertIn("No plate found in 2 frame(s)", vote["reason"])

    def test_two_frames_that_disagree_are_unreadable_but_keep_a_best_guess(self):
        vote = vote_plate(reads(("ABC-1234", 0.6), ("ABC-1284", 0.5)))
        self.assertEqual((vote["plate"], vote["status"], vote["best_guess"]), (None, "unreadable", "ABC-1234"))
        self.assertEqual(vote["reason"], "Frames disagree on the plate.")

    def test_one_weak_read_is_unreadable_and_one_clear_read_counts(self):
        self.assertEqual(vote_plate(reads(("NBC-123", 0.5), (None, 0.0)))["status"], "unreadable")
        self.assertEqual(vote_plate(reads(("NBC-123", 0.9)))["plate"], "NBC-123")

    def test_the_layout_with_most_weight_wins(self):
        # A motorcycle plate read twice beats one car-layout misread.
        vote = vote_plate(reads(("AB-12345", 0.6), ("AB-12345", 0.6), ("ABC-1234", 0.7)))
        self.assertEqual(vote["plate"], "AB-12345")

    def test_a_car_needs_a_3_letter_plate_and_a_motorcycle_may_have_2(self):
        # Measured on Gate 1: "LAK 3767" read as AK-3767 and IK-3767 (first letter missed).
        missed = reads(("AK-3767", 0.9), ("IK-3767", 0.587))
        vote = vote_plate(missed, "Car")
        self.assertEqual((vote["plate"], vote["status"], vote["best_guess"]), (None, "unreadable", "AK-3767"))
        self.assertIn("letter was probably missed", vote["reason"])

        # A 3-letter read on the same car wins over 2-letter misreads.
        self.assertEqual(vote_plate(missed + reads(("LAK-3767", 0.85)), "Car")["plate"], "LAK-3767")
        self.assertEqual(vote_plate(reads(("AB-12345", 0.9)), "Motorcycle")["plate"], "AB-12345")
        self.assertEqual(vote_plate(reads(("AB-12345", 0.9)))["plate"], "AB-12345")  # type unknown

    def test_two_agreeing_frames_stop_ocr_early(self):
        self.assertFalse(frames_agree(reads(("ABC-1234", 0.5), ("ABC-1284", 0.5))))
        self.assertTrue(frames_agree(reads(("ABC-1234", 0.5), (None, 0.0), ("ABC-1234", 0.4))))


class PhilippinePlateFormatTests(unittest.TestCase):
    """PH layouts and position-safe OCR corrections (letters first, then digits)."""

    def test_layouts_and_corrections(self):
        self.assertEqual(normalize_plate_text("abc 1234"), "ABC-1234")
        self.assertEqual(normalize_plate_text("ABC1Z34"), "ABC-1234")   # Z in a digit position -> 2
        self.assertEqual(normalize_plate_text("A8C1234"), "ABC-1234")   # 8 in a letter position -> B
        self.assertEqual(normalize_plate_text("1234ABC"), "ABC-1234")   # read in visual order
        self.assertEqual(normalize_plate_text("NBC 123"), "NBC-123")
        self.assertEqual(normalize_plate_text("AB 12345"), "AB-12345")
        self.assertIsNone(normalize_plate_text("HELLO"))


if __name__ == "__main__":
    unittest.main()
