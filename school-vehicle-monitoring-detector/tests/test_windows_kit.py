"""
Windows install kit (Phase 1): the detector finds its models in a local
folder and never goes online; Windows scripts keep CRLF line endings.

Run: .venv/bin/python -m unittest discover -s tests -p "test_*.py"
"""

import importlib
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import config  # noqa: E402

REPO = Path(__file__).resolve().parents[2]


class Models(unittest.TestCase):
    def test_models_come_from_the_local_models_folder_with_the_old_place_as_fallback(self):
        with tempfile.TemporaryDirectory() as folder:
            (Path(folder) / "yolov8n.pt").write_bytes(b"model")
            (Path(folder) / "easyocr").mkdir()
            with mock.patch.dict(os.environ, {"DETECTOR_MODELS_DIR": folder}):
                reloaded = importlib.reload(config)
                self.assertEqual(str(Path(folder) / "yolov8n.pt"), reloaded.MODEL_PATH)
                self.assertEqual(str(Path(folder) / "easyocr"), reloaded.EASYOCR_MODEL_DIR)
                # Not in models/: the detector folder (development), else models/ (reported missing).
                self.assertEqual(Path(folder) / "missing.pt", reloaded.model_file("missing.pt"))
        importlib.reload(config)

    def test_ultralytics_stays_offline_with_a_writable_settings_folder(self):
        self.assertEqual("1", os.environ.get("YOLO_OFFLINE"))
        self.assertTrue(os.environ.get("YOLO_CONFIG_DIR", "").endswith(str(Path("storage") / "app" / "ultralytics")))


class Scripts(unittest.TestCase):
    def test_windows_scripts_are_checked_out_with_crlf(self):
        attributes = (REPO / ".gitattributes").read_text(encoding="utf-8")
        for pattern in ("*.bat", "*.cmd", "*.ps1"):
            self.assertIn(f"{pattern} text eol=crlf", attributes)


if __name__ == "__main__":
    unittest.main()
