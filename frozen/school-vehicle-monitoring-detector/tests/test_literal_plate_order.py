import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from anpr import normalize_plate_text, plate_layout_rank, select_consensus_plate_scored
from plate_voting import plate_layout, vote_plate


class LiteralPlateOrderTests(unittest.TestCase):
    def test_normalization_preserves_alphanumeric_sequence(self):
        for value, expected in [('395isx', '395ISX'), (' 395\u00a0 isx ', '395ISX'), ('395-ISX', '395ISX'),
                                ('isx395', 'ISX-395'), ('0012 ab', '0012AB')]:
            with self.subTest(value=value):
                self.assertEqual(normalize_plate_text(value), expected)
        for value in ['395/ISX', '395_ISX', '395\tISX']:
            self.assertIsNone(normalize_plate_text(value))

        self.assertEqual(normalize_plate_text("prefix395ISXsuffix"), "PREFIX395ISXSUFFIX")  # Never extract an enclosed identity.

    def test_voting_keeps_both_orders_in_separate_layouts(self):
        self.assertNotEqual(plate_layout('395ISX'), plate_layout('ISX-395'))
        result = vote_plate([{'plate': '395ISX', 'score': .95}, {'plate': '395ISX', 'score': .9},
                             {'plate': 'ISX-395', 'score': .8}], 'Motorcycle')
        self.assertEqual((result['status'], result['plate']), ('read', '395ISX'))
        self.assertEqual(result['candidates']['395ISX']['count'], 2)

    def test_existing_confidence_policy_is_kept_for_both_orders(self):
        self.assertEqual(plate_layout_rank('395ISX'), plate_layout_rank('ISX-395'))
        self.assertEqual(select_consensus_plate_scored([('395ISX', .45)])[0], '395ISX')
        self.assertIsNone(vote_plate([{'plate': '395ISX', 'score': .2}])['plate'])
