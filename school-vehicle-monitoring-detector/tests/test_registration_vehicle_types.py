"""Registration Phase 4: model metadata, honest classes and type-independent plates."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import config
import detector_service as detector
import vehicle_type as vtype
from plate_voting import vote_plate


class RegistrationTypeTests(unittest.TestCase):
    def test_mapping_uses_advertised_names_not_assumed_numeric_ids(self):
        class Model:
            names = {22: 'bus', 97: 'truck', 101: 'car', 202: 'motorcycle', 2: 'person', 3: 'bicycle', 5: 'spaceship'}
        self.assertEqual(detector.resolve_allowed_vehicle_classes(Model()), {22: 'Bus', 97: 'Truck', 101: 'Car', 202: 'Motorcycle'})

    def test_installed_model_metadata_has_no_electric_or_tricycle_classes(self):
        from ultralytics import YOLO
        for name in ('yolov8n.pt', 'yolov8s.pt'):
            path = config.model_file(name)
            if not path.is_file():
                self.skipTest('Local model not installed; no download is permitted.')
            model = YOLO(str(path), verbose=False)
            self.assertEqual(detector.resolve_allowed_vehicle_classes(model), {2: 'Car', 3: 'Motorcycle', 5: 'Bus', 7: 'Truck'})
            self.assertEqual(len(model.names), 80)
            self.assertFalse({'ebike', 'e-bike', 'etrike', 'tricycle', 'ecar'} & set(model.names.values()))

    def test_box_dimensions_cannot_invent_a_car_or_electric_subtype(self):
        for box in ((0, 0, 500, 50), (0, 0, 30, 400), (0, 0, 200, 200)):
            vote = vtype.TrackVote()
            vote.add('Truck', .9, box, (800, 600))
            self.assertEqual(vtype.decide(vote, zone_height=400)['type'], 'Truck')
        self.assertIsNone(vtype.decide(vtype.TrackVote(), fallback='spaceship')['type'])
        self.assertIsNone(vtype.decide(vtype.TrackVote())['type'])

    def test_uncertain_tied_votes_have_no_silent_car_bias(self):
        vote = vtype.TrackVote()
        for label in ('Car', 'Motorcycle'):
            vote.add(label, .8, (0, 0, 100, 100), (800, 600))
        self.assertIsNone(vtype.decide(vote)['type'])

    def test_all_manual_types_accept_the_same_supported_literal_plate_layouts(self):
        for label in vtype.TYPES:
            for plate in ('AB-00001', 'ABC-0001'):
                result = vote_plate([{'plate': plate, 'score': .95}], label)
                self.assertEqual((result['status'], result['plate']), ('read', plate), label)


if __name__ == '__main__':
    unittest.main()
