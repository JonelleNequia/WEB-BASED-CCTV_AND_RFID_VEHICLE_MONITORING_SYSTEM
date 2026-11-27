<?php

require __DIR__.'/ui-workspace.php'; // Refuses every database outside the private temporary directory.

$register = static function (string $plate, string $category): App\Models\Vehicle {
    $tag = App\Models\RfidTag::query()->create(['tag_number' => App\Models\RfidTag::count() + 1, 'uid' => 'P4-'.$plate, 'status' => 'available']);

    return app(App\Services\VehicleRegistryService::class)->register([
        'registered_by' => 'UI Test Registrar',
        'rfid_tag_id' => $tag->id, 'display_number' => $tag->number_label, 'plate_number' => $plate, 'vehicle_owner_name' => 'Classification Owner',
        'category' => $category, 'vehicle_type' => 'Car',
    ])->fresh();
};
$faculty = $register('FAC-0401', 'faculty_staff');
$visitor = $register('REG-0402', 'registered_visitor');
$registered = [];
foreach ([$faculty, $visitor] as $vehicle) {
    $registered[] = App\Models\RfidScanLog::query()->create([
        'vehicle_id' => $vehicle->id, 'vehicle_rfid_tag_id' => $vehicle->rfid_tag_id, 'tag_uid' => $vehicle->rfid_tag_uid,
        'vehicle_category' => $vehicle->category, 'verification_status' => 'verified', 'fusion_status' => 'scan_only',
        'scan_time' => now(), 'scan_location' => 'gate-1',
    ])->id;
}
$unknown = App\Models\RfidScanLog::query()->create([
    'tag_uid' => 'P4-UNKNOWN', 'verification_status' => 'unknown_tag', 'fusion_status' => 'scan_only', 'is_anomaly' => true,
    'vehicle_category' => 'registered_visitor', 'payload_json' => ['vehicle_plate_number' => 'READ-0403'],
    'scan_time' => now(), 'scan_location' => 'gate-1',
]);
$unassigned = App\Models\RfidScanLog::query()->create([
    'tag_uid' => 'P4-UNASSIGNED', 'verification_status' => 'unassigned_tag', 'fusion_status' => 'scan_only', 'is_anomaly' => true,
    'scan_time' => now(), 'scan_location' => 'gate-1',
]);
$cameraRecord = static function (string $key, ?string $plate, $at): App\Models\VisitorRecord {
    $crossing = App\Models\VehicleCrossing::query()->create([
        'external_event_key' => $key, 'gate' => 'gate-1', 'direction' => 'IN', 'crossed_at' => $at, 'vehicle_type' => 'Car',
        'detection_metadata_json' => ['rfid_status' => 'no_pass'],
    ]);
    app(App\Services\VisitorRecordService::class)->createFromCrossing($crossing);

    return app(App\Services\VisitorRecordService::class)->applyPlateReading([
        'external_event_key' => $key, 'camera_role' => 'gate-1', 'event_time' => $at->toIso8601String(),
        'plate_status' => $plate === null ? 'unreadable' : 'read', 'plate_number' => $plate, 'detected_vehicle_type' => 'Car', 'plate_confidence' => 0.95,
    ]);
};
$readable = $cameraRecord('p4-readable', 'UNR-0403', now());
$unreadable = $cameraRecord('p4-unreadable', null, now());
$plateOnly = $cameraRecord('p4-plate-only', $faculty->plate_number, now());
$historical = $cameraRecord('p4-historical', 'OLD-0404', now()->subDay());
$register('OLD-0404', 'faculty_staff'); // Existing history transfer must not rewrite event-time classification.
file_put_contents($directory.'/classification-records.json', json_encode([
    'registered_scans' => $registered, 'unknown' => $unknown->id, 'unassigned' => $unassigned->id,
    'readable' => $readable->id, 'unreadable' => $unreadable->id, 'plate_only' => $plateOnly->id, 'historical' => $historical->id,
]));
