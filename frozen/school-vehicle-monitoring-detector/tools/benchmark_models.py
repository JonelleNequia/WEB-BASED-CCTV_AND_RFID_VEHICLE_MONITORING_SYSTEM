"""
A2 (detection): how fast yolov8n and yolov8s run on THIS PC (run it on the
Windows gate PC too). Speed only: accuracy comes from the live gates
(php artisan detection:accuracy).

    .venv\\Scripts\\python tools\\benchmark_models.py            (Windows)
    .venv/bin/python tools/benchmark_models.py                   (macOS)
    ... --device cpu       force the CPU (default: same as the detector, auto)

Prints milliseconds per run for the live detection (whole frame, 480 / 640)
and for the second type check (one vehicle crop, 640 / 960).
"""

import argparse
import statistics
import sys
import time
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))


def timed(model, image, imgsz, device, runs):
    model.predict(image, imgsz=imgsz, verbose=False, device=device)  # warm-up
    times = []
    for _ in range(runs):
        started = time.perf_counter()
        model.predict(image, imgsz=imgsz, verbose=False, device=device)
        times.append((time.perf_counter() - started) * 1000)
    return statistics.median(times)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--device", default="auto")
    parser.add_argument("--runs", type=int, default=15)
    args = parser.parse_args()

    from ultralytics import YOLO

    import detector_service

    device = detector_service.yolo_device(args.device)
    rng = np.random.default_rng(0)
    frame = rng.integers(0, 255, (1080, 1920, 3), dtype=np.uint8)
    crop = rng.integers(0, 255, (700, 900, 3), dtype=np.uint8)

    print(f"Device: {device}")
    print(f"{'model':<12}{'live 480':>10}{'live 640':>10}{'check 640':>11}{'check 960':>11}   (ms, median of {args.runs})")
    for name in ("yolov8n.pt", "yolov8s.pt"):
        path = ROOT / name
        if not path.exists():
            print(f"{name:<12} not found in {ROOT} (copy it there to compare)")
            continue
        model = YOLO(str(path))
        row = [timed(model, frame, 480, device, args.runs), timed(model, frame, 640, device, args.runs),
               timed(model, crop, 640, device, args.runs), timed(model, crop, 960, device, args.runs)]
        print(f"{name:<12}" + "".join(f"{value:>10.0f} " for value in row))


if __name__ == "__main__":
    main()
