"""
Phase 5: a clear picture for every record. The best moment of the pass
(big, the whole vehicle, sharp), and the main stream's frame of that very
moment (the sharpest of the frames around it), not the biggest box of the
small live frame.

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import sys
import time
import unittest
from pathlib import Path

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import detector_service as detector  # noqa: E402
import hires  # noqa: E402


def scene(box, blur=0, size=(540, 960)):
    """A grey road with a textured 'vehicle' in the box, blurred by `blur` px of motion."""
    frame = np.full((*size, 3), 90, np.uint8)
    x1, y1, x2, y2 = box
    rng = np.random.default_rng(4)
    patch = rng.integers(0, 255, (y2 - y1, x2 - x1, 3), dtype=np.uint8)
    if blur > 1:
        kernel = np.zeros((blur, blur), np.float32)
        kernel[:, blur // 2] = 1.0 / blur
        patch = cv2.filter2D(patch, -1, kernel)
    frame[max(0, y1):y2, max(0, x1):x2] = patch[max(0, -y1):, max(0, -x1):]
    return frame


class PictureScoreTests(unittest.TestCase):
    def test_a_whole_sharp_vehicle_beats_a_bigger_one_cut_off_or_a_blurred_one(self):
        whole = detector.picture_score(scene((300, 150, 600, 400)), (300, 150, 600, 400))
        cut_off = detector.picture_score(scene((250, 200, 650, 540)), (250, 200, 650, 540))   # bigger, at the bottom edge
        blurred = detector.picture_score(scene((300, 150, 600, 400), blur=25), (300, 150, 600, 400))
        self.assertGreater(whole, cut_off)
        self.assertGreater(whole, blurred)


class FakeGrabber:
    def __init__(self, frames):
        self.frames = frames                                         # [(time, frame)]

    def frames_near(self, moment, within=0.15):
        return [(at, frame) for at, frame in self.frames if abs(at - moment) <= within]

    def frame_near(self, moment):
        near = self.frames_near(moment, hires.MATCH_WITHIN_SECONDS)
        return min(near, key=lambda item: abs(item[0] - moment))[1] if near else None


class MainStreamTests(unittest.TestCase):
    def tearDown(self):
        detector.HIRES.pop("gate-9", None)

    def test_the_sharpest_main_frame_of_the_moment_is_taken_with_the_vehicle_where_it_is_then(self):
        # Main stream 1920x1080 (live 960x540); the vehicle moves down 400 live px per second.
        moment = 100.0
        frames = [
            (moment - 0.1, scene((600, 220, 1200, 720), blur=30, size=(1080, 1920))),
            (moment, scene((600, 300, 1200, 800), blur=30, size=(1080, 1920))),
            (moment + 0.1, scene((600, 380, 1200, 880), size=(1080, 1920))),      # the sharp one
        ]
        detector.HIRES["gate-9"] = FakeGrabber(frames)
        frame, box = detector.sharpest_hires_frame("gate-9", moment, (300, 150, 600, 400), (540, 960), velocity=(0.0, 400.0))
        self.assertEqual(frame.shape[:2], (1080, 1920))
        for got, want in zip(box, (600, 380, 1200, 880)):                         # moved by its speed: 40 live px later
            self.assertAlmostEqual(got, want, delta=2)
        self.assertGreater(detector.vehicle_sharpness(frame, box), detector.vehicle_sharpness(frames[1][1], box))

    def test_no_main_stream_frame_of_that_moment_means_the_live_picture(self):
        detector.HIRES["gate-9"] = FakeGrabber([(50.0, scene((600, 300, 1200, 800), size=(1080, 1920)))])
        self.assertIsNone(detector.sharpest_hires_frame("gate-9", 100.0, (300, 150, 600, 400), (540, 960)))
        self.assertLessEqual(hires.MATCH_WITHIN_SECONDS, 0.25)                   # never a frame of another moment
        self.assertLessEqual(hires.KEEP_EVERY_SECONDS, 0.1)

    def test_the_record_gets_the_main_stream_picture_and_says_so(self):
        live = scene((300, 150, 600, 400))
        main = scene((600, 300, 1200, 800), size=(1080, 1920))
        window = {"best_frame": live, "best_xyxy": (300, 150, 600, 400), "best_hires": (main, (600, 300, 1200, 800)), "best_score": 0.4}
        frame, box = detector.best_picture_locked(window, full_resolution=True)
        self.assertEqual((frame.shape[:2], box), ((1080, 1920), (600, 300, 1200, 800)))
        self.assertEqual(detector.best_picture_locked(window)[0].shape[:2], (540, 960))   # the live one for the type votes
        details = detector.picture_details(window, frame, box)
        self.assertEqual((details["source"], details["size"], details["whole"]), ("main", [1920, 1080], True))
        window["best_hires"] = None
        self.assertEqual(detector.picture_details(window, live, (300, 150, 600, 400))["source"], "live")


class BestMomentTests(unittest.TestCase):
    def test_the_pass_keeps_its_best_moment_not_its_biggest_box(self):
        from test_detection import tracked_results

        state = detector.initial_camera_state()
        state["pending_windows"][7] = {"started_at": time.monotonic(), "best_score": 0.0}
        for box, blur in (((300, 150, 600, 400), 0), ((260, 200, 700, 540), 0), ((300, 160, 620, 420), 30)):
            frame = scene(box, blur)
            detector.update_detection_windows("gate-1", frame, tracked_results([list(box) + [7, 0.9, 2]]), state, None)
        self.assertEqual(state["pending_windows"][7]["best_xyxy"], (300, 150, 600, 400))   # not the cut-off or the blurred one


if __name__ == "__main__":
    unittest.main()
