# Python Dual-Camera Detection Module

This folder contains the Python runtime for the Laravel capstone project **Web-Based CCTV Vehicle Monitoring System for PHILCST**.

The module is now:

- dual-camera ready
- calibration-aware
- vehicle-only
- multi-object tracking capable
- able to create Laravel `pending_details` events automatically

## What It Does

Per camera role (`entrance` and `exit`), the detector:

1. loads the source configuration exported by Laravel
2. opens the configured webcam, RTSP stream, or URL stream
3. loads the saved mask rectangle and trigger line
4. detects and tracks vehicles only
5. ignores people completely
6. creates an event only when a tracked vehicle crosses the saved line inside the saved mask
7. saves a vehicle snapshot
8. posts the event back to Laravel through `/api/v1/integration/events`

## Files

- `config.py` - project paths, runtime config loading, detection settings
- `tracking.py` - calibration math and line-crossing helpers
- `laravel_client.py` - small HTTP client for Laravel event ingestion
- `detector_service.py` - main dual-camera detector and tracker loop
- `camera_service.py` - backward-compatible wrapper that starts the detector
- `requirements.txt` - Python dependencies

## Dependencies

Install inside a virtual environment:

```bash
cd school-vehicle-monitoring-detector
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

Dependencies:

- `opencv-python`
- `requests`
- `ultralytics`

## Detector Model

The default model path is:

```text
yolov8n.pt
```

Ultralytics may download the weights the first time it runs. If internet access is unavailable, place the weights locally in the Python module folder or another accessible path and update `MODEL_PATH` in `config.py`.

## Allowed and Ignored Classes

Allowed vehicle classes:

- `car`
- `motorcycle`
- `bus`
- `truck`

Ignored classes:

- `person`
- `bicycle`
- animals
- all non-vehicle classes

Notes:

- vans are usually detected under the `car` class in the default pretrained model

## Laravel Runtime Config

Laravel writes the detector runtime file here:

```text
../storage/app/camera/camera_runtime_config.json
```

That JSON includes:

- system settings
- Laravel event ingest URL
- API key
- Entrance camera config
- Exit camera config
- saved mask and line calibration

## Output Files

The detector writes:

```text
../public/camera/camera_status.json
../public/camera/entrance_latest_frame.jpg
../public/camera/exit_latest_frame.jpg
../storage/app/public/detected-vehicle-images/...
```

## Automatic Startup

The preferred demo flow is:

1. start Laravel with `php artisan serve`
2. open `/monitoring`
3. let Laravel try to auto-start the detector in the background

If auto-start does not work in the local environment, you can still run the detector manually:

```bash
cd school-vehicle-monitoring-detector
source .venv/bin/activate
python camera_service.py
```

## Calibration Dependency

The detector will not create events until both are saved per camera:

- mask rectangle
- trigger line

If calibration is missing, `camera_status.json` will report that detection is waiting for saved calibration.

## Event Payload Sent to Laravel

Each accepted crossing posts fields like:

- `external_event_key`
- `camera_role`
- `detected_vehicle_type`
- `event_time`
- `vehicle_image_path`
- `roi_name`
- `detection_metadata`

Laravel stores the event as:

- `event_status = pending_details`
- `match_status = pending_details`

## Local Limitation

On some systems, browser preview and OpenCV cannot open the same webcam at the same time. If that happens:

- browser monitoring may still work
- Python may report the camera as busy
- RTSP/IP cameras are the better long-term mode for simultaneous preview and detection

## Troubleshooting

### The detector starts but no events are created

- confirm calibration is saved for that camera
- confirm the tracked vehicle actually crosses the saved line
- confirm the snapshot directory exists under `storage/app/public`

### The detector cannot post to Laravel

- confirm `APP_URL` is correct in `.env`
- confirm Laravel is running
- confirm `python_api_key` in Laravel Settings matches the runtime JSON

### Ultralytics cannot load the model

- make sure `pip install -r requirements.txt` completed successfully
- if needed, manually place `yolov8n.pt` in a reachable location and change `MODEL_PATH`
