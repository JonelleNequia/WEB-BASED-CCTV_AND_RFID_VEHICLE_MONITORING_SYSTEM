"""
Startup checks: the detector reports the code it loaded (and restarts when
its files change), and a saved zone it cannot use is reported, not ignored.

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import code_version  # noqa: E402
from tracking import calibration_problem, calibration_ready  # noqa: E402


class CodeVersionTests(unittest.TestCase):
    def test_the_version_follows_the_detector_files_only(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / "detector_service.py").write_text("print('v1')\n")
            (root / "tracking.py").write_text("x = 1\n")
            (root / "device_service.py").write_text("not the detector\n")
            first = code_version.code_version(root)
            self.assertRegex(first, r"^[0-9a-f]{12}$")

            (root / "device_service.py").write_text("changed, still not the detector\n")
            self.assertEqual(code_version.code_version(root), first)

            (root / "tracking.py").write_text("x = 2\n")
            self.assertNotEqual(code_version.code_version(root), first)
            self.assertEqual([path.name for path in code_version.code_files(root)], ["detector_service.py", "tracking.py"])

    def test_the_running_detector_knows_its_version(self):
        import detector_service

        self.assertEqual(detector_service.RUNNING_CODE["version"], code_version.code_version())
        self.assertFalse(detector_service.code_changed_on_disk())


class CalibrationProblemTests(unittest.TestCase):
    SQUARE = [{"x": 0.1, "y": 0.1}, {"x": 0.9, "y": 0.1}, {"x": 0.9, "y": 0.9}, {"x": 0.1, "y": 0.9}]

    def test_a_normalized_zone_is_ready_without_a_line_or_arrow(self):
        self.assertIsNone(calibration_problem({"calibration_mask": self.SQUARE}))
        self.assertTrue(calibration_ready({"calibration_mask": self.SQUARE, "away_direction": None}))

    def test_zones_the_detector_cannot_use_are_named(self):
        self.assertEqual(calibration_problem({}), "No detection zone yet.")
        self.assertIn("outside the picture", calibration_problem({"calibration_mask": [{"x": 120, "y": 80}, {"x": 1800, "y": 80}, {"x": 1800, "y": 900}]}))
        self.assertIn("fewer than 3", calibration_problem({"calibration_mask": self.SQUARE[:2]}))
        self.assertIn("covers only", calibration_problem({"calibration_mask": [{"x": 0.5, "y": 0.5}, {"x": 0.52, "y": 0.5}, {"x": 0.51, "y": 0.52}]}))
        bow = [self.SQUARE[0], self.SQUARE[2], self.SQUARE[1], self.SQUARE[3]]
        self.assertIn("cross", calibration_problem({"calibration_mask": bow}))
        self.assertIn("not a list of points", calibration_problem({"calibration_mask": [1, 2, 3]}))
        self.assertFalse(calibration_ready({"calibration_mask": bow}))


if __name__ == "__main__":
    unittest.main()
