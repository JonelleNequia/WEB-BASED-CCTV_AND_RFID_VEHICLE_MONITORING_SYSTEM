"""
Gate direction work, Phase B: the plate of a pass from its burst of frames
(plate_reader.py). The plate detector and EasyOCR are replaced by stand-ins,
so these run without the model files.

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import sys
import unittest
from pathlib import Path
from unittest import mock

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import plate_reader  # noqa: E402


def plate_image(width, blur=0):
    """A white plate with dark bars (text) at `width` px, optionally blurred."""
    height = int(width / 2.7)
    image = np.full((height, width, 3), 235, np.uint8)
    for x in range(int(width * 0.1), int(width * 0.9), max(4, width // 14)):
        cv2.rectangle(image, (x, int(height * 0.25)), (x + max(2, width // 28), int(height * 0.75)), (20, 20, 20), -1)
    if blur:
        image = cv2.blur(image, (blur, 1))
    return image


class FakeBoxes:
    def __init__(self, boxes):
        self.conf = mock.Mock(tolist=lambda: [box[0] for box in boxes])
        self.xyxy = mock.Mock(tolist=lambda: [box[1] for box in boxes])
        self._count = len(boxes)

    def __len__(self):
        return self._count


class FakeDetector:
    """Finds the plate where each test frame has it."""

    def __init__(self, plate_boxes):
        self.plate_boxes = plate_boxes

    def predict(self, regions, **_):
        return [mock.Mock(boxes=FakeBoxes(self.plate_boxes[index])) for index in range(len(regions))]


def frame_with_plate(plate, at=(400, 300)):
    frame = np.full((720, 1280, 3), 90, np.uint8)
    x, y = at
    frame[y:y + plate.shape[0], x:x + plate.shape[1]] = plate
    return frame, (x - 100, y - 150, x + plate.shape[1] + 100, y + plate.shape[0] + 50), (x, y, x + plate.shape[1], y + plate.shape[0])


class PlateReaderTests(unittest.TestCase):
    def setUp(self):
        plate_reader.plate_detector.cache_clear()

    def run_pass(self, plates, reads, vehicle_type="Car"):
        """plates: plate images, one per frame; reads: what the OCR stand-in says per call."""
        frames, boxes = [], []
        for plate in plates:
            frame, vehicle, plate_box = frame_with_plate(plate)
            left, top = max(0, int(vehicle[0] - (vehicle[2] - vehicle[0]) * 0.06)), max(0, int(vehicle[1] - (vehicle[3] - vehicle[1]) * 0.06))
            frames.append((frame, vehicle))
            boxes.append([(0.8, [plate_box[0] - left, plate_box[1] - top, plate_box[2] - left, plate_box[3] - top])])
        calls = []

        def fake_read(image):
            calls.append(image.shape)
            return reads(len(calls)) if callable(reads) else reads.pop(0) if reads else []

        # EasyOCR here (the plate OCR model has its own test below).
        with mock.patch.object(plate_reader, "plate_detector", return_value=FakeDetector(boxes)), \
                mock.patch.object(plate_reader, "plate_ocr", return_value=None), \
                mock.patch.object(plate_reader.anpr, "read_with_easyocr_candidates", side_effect=fake_read):
            return plate_reader.read_pass(frames, vehicle_type), calls

    def test_the_sharpest_biggest_plate_crop_is_read_first_and_kept(self):
        blurred, small, sharp = plate_image(160, blur=15), plate_image(70), plate_image(170)
        (vote, crop, details), _ = self.run_pass([blurred, small, sharp], lambda call: [("ABC-1234", 0.9)])

        self.assertEqual((vote["plate"], vote["status"]), ("ABC-1234", "read"))
        self.assertEqual(details["plates_found"], 3)
        self.assertEqual(details["best_crop"]["frame"], 2)
        self.assertGreater(details["crops"][0]["sharpness"], details["crops"][-1]["sharpness"])
        self.assertEqual(crop.shape[1], details["best_crop"]["width_px"])
        # Two crops agreeing is enough: the third is not read.
        self.assertEqual(details["crops_read"], 2)

    def test_crops_are_voted_character_by_character(self):
        plates = [plate_image(170), plate_image(165), plate_image(160)]
        answers = [[("ABC-1284", 0.7)], [("ABC-1234", 0.7)], [("ABC-1234", 0.7)]]
        (vote, _, details), _ = self.run_pass(plates, lambda call: answers[min(call, 3) - 1])

        self.assertEqual((vote["plate"], vote["status"]), ("ABC-1234", "read"))
        self.assertEqual(details["crops_read"], 3)

    def test_a_mirrored_picture_is_read_when_the_plain_one_is_not(self):
        # Call 1-2: the plain crop (two variants) reads nothing; call 3: the mirrored crop.
        (vote, _, _), calls = self.run_pass([plate_image(170)], lambda call: [("LAK-3767", 0.95)] if call == 3 else [])

        self.assertEqual(len(calls), 3)
        self.assertEqual(vote["best_guess"], "LAK-3767")

    def test_the_plate_ocr_model_reads_every_crop_into_a_ph_layout(self):
        class FakeEngine:
            config = mock.Mock(image_color_mode="rgb")

            def __init__(self):
                self.calls = 0

            def run(self, image, return_confidence=False):
                self.calls += 1
                # Read in visual order with one weak character, then right twice.
                text, probabilities = ("1234ABC", [0.9] * 7) if self.calls == 1 else ("ABC1234", [0.95] * 6 + [0.5])
                return [mock.Mock(plate=text, char_probs=probabilities)]

        engine = FakeEngine()
        frames, boxes = [], []
        for width in (170, 165, 160):
            frame, vehicle, plate_box = frame_with_plate(plate_image(width))
            left, top = max(0, int(vehicle[0] - (vehicle[2] - vehicle[0]) * 0.06)), max(0, int(vehicle[1] - (vehicle[3] - vehicle[1]) * 0.06))
            frames.append((frame, vehicle))
            boxes.append([(0.8, [plate_box[0] - left, plate_box[1] - top, plate_box[2] - left, plate_box[3] - top])])
        with mock.patch.object(plate_reader, "plate_detector", return_value=FakeDetector(boxes)), \
                mock.patch.object(plate_reader, "plate_ocr", return_value=engine):
            vote, _, details = plate_reader.read_pass(frames, "Car")

        # Every crop is read (it is fast), "1234ABC" is the plate ABC-1234 read right to left.
        self.assertEqual((engine.calls, details["crops_read"], details["ocr"]), (3, 3, plate_reader.PLATE_OCR_MODEL))
        self.assertEqual((vote["plate"], vote["status"]), ("ABC-1234", "read"))
        self.assertEqual([crop["read"] for crop in details["crops"]], ["ABC-1234"] * 3)
        # The weakest character pulls a read's confidence down (0.5 x mean + 0.5 x min).
        self.assertAlmostEqual(details["crops"][1]["score"], round(0.5 * (0.95 * 6 + 0.5) / 7 + 0.25, 3), places=3)

    def test_no_plate_found_is_unreadable_and_has_no_crop(self):
        frame, vehicle, _ = frame_with_plate(plate_image(150))
        with mock.patch.object(plate_reader, "plate_detector", return_value=FakeDetector([[]])):
            vote, crop, details = plate_reader.read_pass([(frame, vehicle)], "Car")

        self.assertEqual((vote["status"], crop, details["plates_found"]), ("unreadable", None, 0))

    def test_without_the_model_file_the_older_reader_is_used(self):
        with mock.patch.object(plate_reader, "plate_detector", return_value=None):
            self.assertFalse(plate_reader.available())
            self.assertIsNone(plate_reader.read_pass([], "Car"))

    def test_sharpness_compares_small_and_big_crops_at_one_height(self):
        self.assertGreater(plate_reader.sharpness(plate_image(80)), plate_reader.sharpness(plate_image(240, blur=21)))
        quality_big, _ = plate_reader.crop_quality(plate_image(170), 0.8)
        quality_small, _ = plate_reader.crop_quality(plate_image(60), 0.8)
        self.assertGreater(quality_big, quality_small)


if __name__ == "__main__":
    unittest.main()


class PlateBurstTests(unittest.TestCase):
    """The detector keeps a burst of frames per pass for the plate: the biggest views of the vehicle."""

    def test_the_biggest_views_are_kept_and_the_best_moment_comes_first(self):
        import detector_service as ds

        window = {}
        with mock.patch.dict(ds.HIRES, {}, clear=True):
            for index in range(12):
                frame = np.zeros((360, 640, 3), np.uint8)
                size = 40 + (index * 37) % 200  # sizes in no order
                ds.keep_plate_frame_locked("gate-1", window, frame, (10, 10, 10 + size, 10 + size // 2), float(index))

        kept = [entry[2][1] for entry in window["plate_frames"]]  # (area, time, (frame, box))
        widths = [box[2] - box[0] for box in kept]
        self.assertEqual(len(kept), ds.MAX_PLATE_FRAMES)
        self.assertEqual(widths, sorted(widths, reverse=True))
        self.assertEqual(widths[0], max(40 + (index * 37) % 200 for index in range(12)))

        best = (np.ones((1080, 1920, 3), np.uint8), (1, 2, 3, 4))
        window["best_hires"] = best
        frames = ds.plate_frames_locked(window)
        self.assertIs(frames[0][0], best[0])
        self.assertEqual(len(frames), ds.MAX_PLATE_FRAMES + 1)
