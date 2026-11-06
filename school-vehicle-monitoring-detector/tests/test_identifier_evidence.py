"""Character recognition and physical identifier type are separate decisions."""
import sys
import unittest
from pathlib import Path
from unittest import mock

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import anpr
from plate_identifier import review_submission
from plate_voting import vote_plate
import test_plate_contract as contract


class IdentifierEvidenceTests(unittest.TestCase):
    def test_clear_supported_formats_are_review_evidence_not_type_proof(self):
        for text in ['ABC 1234', 'ABC 123', '123ABC', 'A123BC', 'AB-00123', '395ISX', 'ISX395', 'AO0123', 'A00123', 'AI0123', 'A10123']:
            vote = vote_plate([{'plate': text, 'score': .99}])
            details = {'raw_reads': [{'text': text, 'confidence': .99}]}
            payload = review_submission(vote, details)
            self.assertEqual((payload['plate_number'], payload['plate_status']), (None, 'unreadable'))
            self.assertEqual(payload['best_guess'], vote['plate'])
            self.assertEqual(payload['ocr_details']['vote'], vote)
            self.assertEqual(payload['ocr_details']['identifier_evidence']['kind'], 'uncertain')
            self.assertNotIn('identifier_evidence', details)  # No mutation of original vote/evidence.

    def test_weak_incomplete_invalid_and_conflicting_reads_keep_diagnostics(self):
        for reads, raw in [([{'plate': 'ABC1234', 'score': .2}], 'ABC1234'),
                           ([], 'ABC/1234'), ([], '123'),
                           ([{'plate': 'ABC1234', 'score': .8}, {'plate': 'ABC1284', 'score': .8}], 'ABC1284')]:
            vote = vote_plate(reads)
            payload = review_submission(vote, {'raw_reads': [{'text': raw}]})
            self.assertIsNone(payload['plate_number'])
            self.assertEqual(payload['ocr_details']['raw_reads'][0]['text'], raw)
            self.assertEqual(payload['ocr_details']['vote']['reason'], vote['reason'])

    def test_equal_strength_different_layouts_are_ambiguous(self):
        vote = vote_plate([{'plate': '395ISX', 'score': .95}, {'plate': 'ISX395', 'score': .95}])
        self.assertEqual(vote['status'], 'unreadable')
        self.assertIsNone(vote['plate'])
        self.assertIn('layout', vote['reason'])
        self.assertEqual(len(vote['candidates']), 2)

    def test_the_real_reader_keeps_a_crop_and_raw_text_before_review_submission(self):
        for text in ['395ISX', '395/ISX', 'MV FILE 001234']:
            vote, _, details, crop = contract.PlateContractTests().fast_pass(text)
            payload = review_submission(vote, details)
            self.assertIsNotNone(crop)
            self.assertIsNone(payload['plate_number'])
            self.assertEqual(payload['ocr_details']['raw_reads'][0]['text'], text)

    def test_fallback_reader_retains_a_crop_even_when_text_is_invalid(self):
        image = np.zeros((60, 180, 3), dtype=np.uint8)
        engine = type('OCR', (), {'readtext': lambda *args, **kwargs: [(None, '395/ISX', .99)]})()
        with mock.patch.object(anpr, 'plate_like_crops', return_value=[image]), \
             mock.patch.object(anpr, 'heuristic_plate_regions', return_value=[]), \
             mock.patch.object(anpr, 'easyocr_reader', return_value=engine), \
             mock.patch.object(anpr, 'read_with_tesseract_candidates', return_value=[]):
            result = anpr.read_license_plate_details(image, (0, 0, 180, 60))
        self.assertIsNone(result['plate'])
        self.assertIsNotNone(result['crop'])
        self.assertEqual(result['raw_reads'][0]['text'], '395/ISX')
