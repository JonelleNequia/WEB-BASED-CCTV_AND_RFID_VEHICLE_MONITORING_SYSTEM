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
