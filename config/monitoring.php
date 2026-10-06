<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Offline Deployment Profile
    |--------------------------------------------------------------------------
    |
    | This capstone is intentionally designed for offline-only deployment on
    | the client PC. Media, runtime files, backups, and logs stay on local
    | storage so the system can be demonstrated without cloud services.
    |
    */

    'offline_only' => true,

    /*
    |--------------------------------------------------------------------------
    | Media Storage
    |--------------------------------------------------------------------------
    |
    | Files that need to be visible in the browser use the public disk so the
    | standard `storage:link` flow can expose them locally.
    |
    */

    'media_disk' => env('MONITORING_MEDIA_DISK', 'public'),

    'media_directories' => [
        'vehicle_images' => env('MONITORING_VEHICLE_IMAGES_DIR', 'vehicle-images'),
        'plate_images' => env('MONITORING_PLATE_IMAGES_DIR', 'plate-images'),
        'detected_vehicle_images' => env('MONITORING_DETECTED_IMAGES_DIR', 'detected-vehicle-images'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Archive Storage
    |--------------------------------------------------------------------------
    |
    | Simulation payload exports and local backups use the private local disk.
    | These files stay on the workstation hard drive and do not need public
    | URLs during this development stage.
    |
    */

    'archive_disk' => env('MONITORING_ARCHIVE_DISK', 'local'),

    'archive_directories' => [
        'rfid_exports' => env('MONITORING_RFID_EXPORTS_DIR', 'rfid-scan-exports'),
        'backups' => env('MONITORING_BACKUP_DIR', 'backups'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Camera Runtime Files (Phase 6)
    |--------------------------------------------------------------------------
    |
    | camera_status.json and the latest/annotated frames the detector writes.
    | They live outside public/ and are served only through signed-in routes.
    |
    */

    'camera_files_path' => env('CAMERA_FILES_PATH', storage_path('app/camera')),

    /*
    |--------------------------------------------------------------------------
    | Detector Live Stream
    |--------------------------------------------------------------------------
    |
    | The Python detector serves the live MJPEG view on this PC. The port is
    | set here once (and exported to Python), not repeated in the code.
    |
    */

    'stream' => [
        'host' => env('DETECTOR_STREAM_HOST', '127.0.0.1'),
        'port' => (int) env('DETECTOR_STREAM_PORT', 8765),
    ],

    /*
    |--------------------------------------------------------------------------
    | RFID Only With a Vehicle
    |--------------------------------------------------------------------------
    |
    | UHF reads wait in the device service's memory buffer and are recorded
    | only when the camera sees a vehicle cross the line. A tag read again
    | within absent_seconds is the same pass; the buffer keeps passes for
    | buffer_seconds after their last read. Settings › Timing has the
    | stationary time and the camera-offline fallback.
    |
    */

    'rfid' => [
        'buffer_seconds' => (float) env('RFID_BUFFER_SECONDS', 15),
        'absent_seconds' => (float) env('RFID_ABSENT_SECONDS', 5),
        // The buffer file is ignored when the device service stopped writing it.
        'buffer_stale_seconds' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Live View (go2rtc)
    |--------------------------------------------------------------------------
    |
    | go2rtc passes each camera's main stream to the browser over WebRTC
    | without re-encoding. Its API listens on this PC only; pages reach it
    | through signed-in Laravel routes. The video itself uses the WebRTC port.
    | The binaries are bundled in tools/go2rtc and checked against these
    | SHA-256 hashes before they are unpacked (offline install).
    |
    */

    'live' => [
        'enabled' => (bool) env('LIVE_VIEW_WEBRTC', true),
        'api_port' => (int) env('GO2RTC_API_PORT', 1984),
        'webrtc_port' => (int) env('GO2RTC_WEBRTC_PORT', 8555),
        'version' => '1.9.14',
        'bundles' => [
            'mac_arm64' => ['file' => 'go2rtc_mac_arm64.zip', 'sha256' => '919b78adc759d6b3883d1e1b2ac915ac0985bb903ff1897b4d228527bd64690c'],
            'mac_amd64' => ['file' => 'go2rtc_mac_amd64.zip', 'sha256' => '9b0b9a27a4dc3a5b8b93376e7e8fc2787c6af624a512842622be84aec0171c7a'],
            'win64' => ['file' => 'go2rtc_win64.zip', 'sha256' => 'dd4167d75cb04abe618855b7c71f8658bd009f60c1a71835d134d2c11c939907'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Detection Tuning (not shown to users)
    |--------------------------------------------------------------------------
    |
    | Proven values from the detection work (A2-A4, 2026-10): exported to the
    | Python detector as system_settings.performance. Change them here only
    | with a measurement (php artisan detection:accuracy).
    |
    */

    'detection' => [
        // The MJPEG fallback view (the main live view is WebRTC).
        'stream_fps' => 15.0,
        'stream_width' => 960,
        'jpeg_quality' => 75,
        // Detection on each camera's sub stream.
        'detection_fps' => 8.0,
        'yolo_imgsz' => 480,
        'yolo_device' => env('DETECTOR_YOLO_DEVICE', 'auto'),
        'roi_crop' => 1,
        'hires_on_trigger' => 1,
        // A2/A4: vehicle type (truck rule measured on rear views of pickups).
        'type_second_pass' => 1,
        'type_model' => 'yolov8s.pt',
        'type_truck_min_height' => 0.35,
        'type_car_min_aspect' => 1.05,
        // A3: one vehicle = one event.
        'cross_margin' => 0.05,
        'cross_min_points' => 3,
        'cross_min_move' => 0.10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Plug-and-detect Devices
    |--------------------------------------------------------------------------
    |
    | The device service (school-vehicle-monitoring-detector/device_service.py)
    | finds cameras and UHF readers on the network. Ports, discovery packets
    | and camera stream paths live in the editable profiles file.
    |
    */

    'devices' => [
        'files_path' => env('DEVICE_FILES_PATH', storage_path('app/devices')),
        'profiles_path' => env('DEVICE_PROFILES_PATH', base_path('school-vehicle-monitoring-detector/devices/data/discovery_profiles.json')),
        'status_stale_after_seconds' => 20,
        'probe_timeout_seconds' => 3,
    ],

    // Runtime logs of the Python services are rotated past this size.
    'runtime_log_max_bytes' => (int) env('RUNTIME_LOG_MAX_BYTES', 5 * 1024 * 1024),
];
