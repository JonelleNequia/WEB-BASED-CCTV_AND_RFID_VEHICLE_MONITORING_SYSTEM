"""Local images + fake OCR engines exercise the actual reader/vote/handoff code."""
import json
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import anpr
import detector_service as detector
import plate_identifier as identifiers
import plate_reader
from plate_voting import vote_plate
from test_plate_reader import FakeDetector, frame_with_plate, plate_image


class PlateContractTests(unittest.TestCase):
    def test_shared_php_browser_python_contract(self):
        cases = json.loads((Path(__file__).resolve().parents[2] / 'tests/fixtures/plate-contract.json').read_text())
        for case in cases:
            with self.subTest(value=case['input']):
                self.assertEqual(identifiers.normalize(case['input']), case['normalized'])
                self.assertEqual(identifiers.key(case['input']), case['key'])
                self.assertEqual(identifiers.display(case['input']), case['display'])
                self.assertEqual(identifiers.valid(case['input']), case['valid'])

    def test_examples_extra_format_and_lookalikes_are_voted_literally(self):
        for text in ['ABC 1234', 'ABC 123', '123ABC', 'A123BC', 'AB-00123', '395ISX', 'ISX395', 'AO0123', 'A00123', 'AI0123', 'A10123']:
            with self.subTest(text=text):
                vote = vote_plate([{'plate': text.lower(), 'score': .95}, {'plate': text.replace(' ', '-'), 'score': .9}])
                self.assertEqual(vote['status'], 'read')
                self.assertEqual(identifiers.key(vote['plate']), identifiers.key(text))
        self.assertNotEqual(identifiers.key('395ISX'), identifiers.key('ISX395'))
        self.assertNotEqual(identifiers.key('AO0123'), identifiers.key('A00123'))
        self.assertNotEqual(identifiers.key('AI0123'), identifiers.key('A10123'))
        self.assertEqual(anpr.select_consensus_plate_scored([('ABC/1234', .99)]), (None, 0.0))

    def fast_pass(self, text):
        image = plate_image(170)
        frame, vehicle, box = frame_with_plate(image)
        left, top = max(0, int(vehicle[0] - (vehicle[2] - vehicle[0]) * .06)), max(0, int(vehicle[1] - (vehicle[3] - vehicle[1]) * .06))
        found = [(0.95, [box[0] - left, box[1] - top, box[2] - left, box[3] - top])]
        engine = SimpleNamespace(config=SimpleNamespace(image_color_mode='gray'),
            run=lambda *args, **kwargs: [SimpleNamespace(plate=text, char_probs=[.99] * len(text))])
        frames = [(frame, vehicle), (frame.copy(), vehicle)]
        with mock.patch.object(plate_reader, 'plate_detector', return_value=FakeDetector([found, found])), \
                mock.patch.object(plate_reader, 'plate_ocr', return_value=engine), \
                mock.patch.object(plate_reader, 'inference_device', return_value='cpu'), \
                mock.patch.object(detector, 'ocr_runtime_status', return_value={'fixture': 'local'}):
            return detector.analyze_guest_vehicle_details(frames, 'Car')

    def test_real_fast_reader_and_detector_keep_mixed_plate_and_literal_order(self):
        for text in ['abc 1234', 'abc 123', '123abc', 'a123bc', 'ab-00123', '395isx']:
            vote, _, details, crop = self.fast_pass(text)
            self.assertEqual(vote['status'], 'read')
            self.assertEqual(identifiers.key(vote['plate']), identifiers.key(text))
            self.assertIsNotNone(crop)
            self.assertEqual(details['raw_reads'][0]['text'], text)

    def test_invalid_and_overlong_engine_output_is_kept_for_review_not_laundered(self):
        for text in ['ABC/1234', 'ABC_1234', '395\tISX', 'A' + '1' * 30]:
            vote, _, details, crop = self.fast_pass(text)
            self.assertEqual((vote['plate'], vote['status']), (None, 'unreadable'))
            self.assertEqual(details['raw_reads'][0]['text'], text)
            self.assertIsNotNone(crop)
            self.assertEqual(vote['best_guess'], identifiers.normalize(text) if len(text) <= 30 else None)

    def test_real_fallback_reader_carries_invalid_raw_output_into_detector_review(self):
        frame = np.zeros((100, 200, 3), dtype=np.uint8)
        engine = SimpleNamespace(readtext=lambda *args, **kwargs: [(None, '395/ISX', .99)])
        with mock.patch.object(plate_reader, 'available', return_value=False), \
                mock.patch.object(anpr, 'easyocr_reader', return_value=engine), \
                mock.patch.object(anpr, 'read_with_tesseract_candidates', return_value=[]), \
                mock.patch.object(anpr, 'plate_like_crops', return_value=[frame]), \
                mock.patch.object(anpr, 'heuristic_plate_regions', return_value=[]), \
                mock.patch.object(detector, 'ocr_runtime_status', return_value={'fixture': 'local'}):
            vote, _, details, crop = detector.analyze_guest_vehicle_details([(frame, (0, 0, 200, 100))], 'Car')
        self.assertEqual((vote['status'], vote['plate'], vote['best_guess']), ('unreadable', None, '395/ISX'))
        self.assertEqual(details['frame_results'][0]['raw_reads'][0]['text'], '395/ISX')
        self.assertIsNotNone(crop)

    def test_diagnostic_collection_is_bounded_without_truncating_into_a_candidate(self):
        evidence = []
        for _ in range(100):
            anpr.remember_read(evidence, 'A' * 300, .9, 'fixture')
        self.assertEqual(len(evidence), 20)
        self.assertTrue(evidence[0]['truncated'])
        self.assertEqual(len(evidence[0]['text']), 200)
        self.assertIsNone(anpr.review_guess({'plate': None, 'best_guess': None}, evidence)['best_guess'])


class DetectorHandoffTests(unittest.TestCase):
    def test_actual_submission_keeps_literal_plate_and_review_evidence(self):
        from test_vehicle_pass import Gate, RecordingClient, FIRST_PASS
        gate = Gate()
        gate.setUp()
        try:
            for bottom in [200, 230, 260, 290, 320]:
                gate.seen(bottom)
            client = RecordingClient()
            # Actual analyzer is covered above; capture its real result for the
            # worker's submission boundary without invoking a physical camera.
            actual = PlateContractTests().fast_pass('395isx')
            with mock.patch.object(detector, 'analyze_guest_vehicle_details', return_value=actual):
                detector.submit_guest_observation_for_window('gate-1', gate.state, FIRST_PASS, client)
            self.assertEqual(len(client.plates), 1)
            self.assertIsNone(client.plates[0]['plate_number'])
            self.assertEqual(client.plates[0]['best_guess'], '395ISX')
            self.assertEqual(client.plates[0]['plate_status'], 'unreadable')
            self.assertEqual(client.plates[0]['ocr_details']['raw_reads'][0]['text'], '395isx')
            self.assertIsNone(client.guest_observations[-1]['plate_number'])
        finally:
            gate.doCleanups()
