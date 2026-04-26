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
];
