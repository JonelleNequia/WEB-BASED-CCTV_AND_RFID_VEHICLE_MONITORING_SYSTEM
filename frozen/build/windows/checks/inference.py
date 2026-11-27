"""Offline CPU readiness on packaged bytes. No camera, reader or live database."""
import argparse
import json
import os
from pathlib import Path
import socket
import tempfile


def run(root, inference=True):
    root = Path(root).resolve()
    os.environ.update(YOLO_OFFLINE='true', YOLO_AUTOINSTALL='false', ULTRALYTICS_AUTOINSTALL='false', PHILCST_PACKAGED_OFFLINE='1')
    # Fail any hidden download rather than accepting an internet-assisted check.
    def blocked(*args, **kwargs):
        raise RuntimeError('Network access is prohibited during packaged model readiness')
    socket.create_connection = blocked
    socket.socket.connect = blocked
    import cv2
    import numpy
    import onnxruntime
    import torch
    import torchvision
    import psutil
    import lap
    import easyocr
    from fast_plate_ocr import LicensePlateRecognizer
    from ultralytics import YOLO
    if torch.version.cuda is not None:
        raise RuntimeError('This package requires the verified CPU-only Torch build, not CUDA')
    torch.set_num_threads(2)
    models = root / 'app/school-vehicle-monitoring-detector/models'
    required = ['yolov8n.pt', 'yolov8s.pt', 'license_plate_detector.pt', 'easyocr/craft_mlt_25k.pth', 'easyocr/english_g2.pth',
                'plate_ocr/cct_s_v2_global.onnx', 'plate_ocr/cct_s_v2_global_plate_config.yaml']
    for name in required:
        if not (models / name).is_file():
            raise RuntimeError('Required model missing: ' + name + '; re-extract the verified package. No download attempted.')
    result = {'device': 'cpu', 'torch': torch.__version__, 'torchvision': torchvision.__version__, 'onnxruntime': onnxruntime.__version__, 'opencv': cv2.__version__, 'lap': lap.__version__,
              'optional': {'tesseract': 'unavailable in D2', 'standalone_ffmpeg_transcoding': 'unavailable in D2'}}
    if inference:
        image = cv2.imread(str(root / 'checks/fixtures/vehicle.jpg'))
        if image is None:
            raise RuntimeError('Recorded inference fixture missing or unreadable')
        with tempfile.TemporaryDirectory(prefix='philcst-model-readiness-') as work:
            os.environ['YOLO_CONFIG_DIR'] = work
            detections = []
            crop = image
            tracker = root / 'app/school-vehicle-monitoring-detector/trackers/bytetrack_gate.yaml'
            if not tracker.is_file():raise RuntimeError('Required local ByteTrack configuration missing; re-extract the verified package')
            for filename in ['yolov8n.pt', 'yolov8s.pt', 'license_plate_detector.pt']:
                model = YOLO(str(models / filename))
                prediction = model.predict(image, device='cpu', imgsz=480, verbose=False, save=False)[0]
                detections.append(int(len(prediction.boxes)))
                if filename == 'yolov8n.pt':
                    ids=[]
                    for _ in range(2):
                        tracked=model.track(image, tracker=str(tracker), persist=True, device='cpu', imgsz=480, conf=0.35, classes=[2,3,5,7], verbose=False)[0]
                        ids.append(set(tracked.boxes.id.int().tolist()) if tracked.boxes.id is not None else set())
                    if not ids[0] or ids[0] != ids[1]:raise RuntimeError('Recorded CPU ByteTrack fixture failed stable vehicle IDs')
                if filename == 'license_plate_detector.pt' and len(prediction.boxes):
                    x1, y1, x2, y2 = [int(x) for x in prediction.boxes.xyxy[0].tolist()]
                    crop = image[max(0, y1):max(1, y2), max(0, x1):max(1, x2)]
            if not detections[0] or not detections[1]:
                raise RuntimeError('Recorded vehicle fixture failed positive YOLO inference')
            reader = easyocr.Reader(['en'], gpu=False, download_enabled=False, verbose=False, model_storage_directory=str(models / 'easyocr'))
            reader.readtext(image)
            recognizer = LicensePlateRecognizer(onnx_model_path=str(models / 'plate_ocr/cct_s_v2_global.onnx'),
                plate_config_path=str(models / 'plate_ocr/cct_s_v2_global_plate_config.yaml'), device='cpu')
            recognizer.run(crop)
            result['inference'] = 'three YOLO models, two-frame ByteTrack stable IDs, EasyOCR and plate ONNX executed on recorded fixture; not a plate-accuracy claim'
    print(json.dumps(result))


if __name__ == '__main__':
    parser = argparse.ArgumentParser();parser.add_argument('root');parser.add_argument('--imports-only', action='store_true')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='philcst-inference-settings-') as work:
        os.environ['YOLO_CONFIG_DIR'] = work
        os.environ['MPLCONFIGDIR'] = work
        run(args.root, not args.imports_only)
