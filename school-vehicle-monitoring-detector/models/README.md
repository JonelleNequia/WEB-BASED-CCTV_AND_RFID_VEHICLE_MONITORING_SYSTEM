# Detector models (not in git)

The detector loads its models from this folder, offline:

| File | Used for |
|---|---|
| `yolov8n.pt` | Vehicle detection (every frame) |
| `yolov8s.pt` | Second check of the vehicle type (once per vehicle) |
| `easyocr/craft_mlt_25k.pth`, `easyocr/english_g2.pth` | Plate reading (EasyOCR) |

The Windows install kit puts them here. For development, `php artisan runtime:fetch`
downloads them (checked by SHA-256). Files in this folder's parent (the old place)
and EasyOCR's own folder in your home still work.

Other folders: set `DETECTOR_MODELS_DIR` / `EASYOCR_MODEL_DIR`.
