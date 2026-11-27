<?php

require __DIR__.'/ui-workspace.php'; // Verifies the private temporary database before writes.

$vehicle = app(App\Services\VehicleRegistryService::class)->register([
    'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Fixture Registrar', 'plate_number' => 'AB-00001', 'vehicle_type' => 'Motorcycle',
    'rfid_uid' => 'PIPELINE-FIXTURE', 'display_number' => '1', 'auto_register_tag' => true,
])->fresh();
$make = static function (string $key, ?string $plate, float $confidence, string $status): App\Models\VisitorRecord {
    $at = now()->addMinutes(2);
    $crossing = App\Models\VehicleCrossing::query()->create([
        'external_event_key' => $key, 'gate' => 'gate-1', 'direction' => 'IN',
        'crossed_at' => $at, 'detection_metadata_json' => ['rfid_status' => 'no_pass'],
    ]);
    app(App\Services\VisitorRecordService::class)->createFromCrossing($crossing);

    return app(App\Services\VisitorRecordService::class)->applyPlateReading([
        'external_event_key' => $key, 'camera_role' => 'gate-1', 'event_time' => $at->toIso8601String(),
        'plate_status' => $status, 'plate_number' => $plate, 'best_guess' => $plate, 'plate_confidence' => $confidence,
    ]);
};
$readable = Tests\fixtures\ConfirmedActualPlate::for($make('pipeline-readable', 'ab  00001', 0.95, 'read'), 'ab 00001');
$review = $make('pipeline-review', 'A8-00001', 0.2, 'unreadable');
$unmatched = $make('pipeline-unmatched', 'XYZ-9876', 0.4, 'read');
file_put_contents($directory.'/plate-records.json', json_encode([
    'readable' => $readable->id, 'review' => $review->id, 'unmatched' => $unmatched->id, 'vehicle' => $vehicle->id,
]));
