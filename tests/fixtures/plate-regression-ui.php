<?php

require __DIR__.'/ui-workspace.php'; // Guards the private testing database before writes.

$vehicle = app(App\Services\VehicleRegistryService::class)->register([
    'plate_number' => 'ISX-395', 'vehicle_type' => 'Car', 'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Fixture Registrar',
])->fresh();
$at = now()->addMinutes(2);
$camera = App\Models\Camera::query()->forRole('gate-1')->firstOrFail();
$crossing = App\Models\VehicleCrossing::create(['external_event_key' => 'regression-ui-occurrence', 'camera_id' => $camera->id,
    'gate' => 'gate-1', 'direction' => 'IN', 'crossed_at' => $at, 'vehicle_type' => 'Motorcycle',
    'snapshot_path' => 'retained/incident.jpg', 'detection_metadata_json' => ['rfid_status' => 'no_pass']]);
$record = app(App\Services\VisitorRecordService::class)->createFromCrossing($crossing);
$record->forceFill(['ocr_plate_number' => '3951SX', 'plate_status' => 'unreadable', 'registration_status' => 'review'])->save();
$event = App\Models\VehicleEvent::create(['external_event_key' => $crossing->external_event_key, 'camera_id' => $camera->id,
    'event_type' => 'ENTRY', 'event_origin' => 'guest_cctv', 'event_status' => 'completed', 'event_time' => $at,
    'vehicle_category' => 'guest', 'vehicle_image_path' => 'retained/incident.jpg']);
file_put_contents($directory.'/plate-regression.json', json_encode(['record' => $record->id, 'event' => $event->id, 'vehicle' => $vehicle->id]));

foreach (['conflict', 'failure'] as $index => $kind) {
    $key = 'regression-ui-'.$kind;
    $time = $at->copy()->addMinutes(2 + $index);
    $extraCrossing = App\Models\VehicleCrossing::create(['external_event_key' => $key, 'camera_id' => $camera->id,
        'gate' => 'gate-1', 'direction' => 'IN', 'crossed_at' => $time, 'detection_metadata_json' => ['rfid_status' => 'no_pass']]);
    $extraRecord = app(App\Services\VisitorRecordService::class)->createFromCrossing($extraCrossing);
    $extraRecord->forceFill(['plate_status' => 'unreadable', 'registration_status' => 'review', 'ocr_plate_number' => '3951SX'])->save();
    App\Models\VehicleEvent::create(['external_event_key' => $key, 'camera_id' => $camera->id, 'event_type' => 'ENTRY',
        'event_origin' => 'guest_cctv', 'event_status' => 'completed', 'event_time' => $kind === 'conflict' ? $time->copy()->subDay() : $time]);
    $extraIds[$kind] = $extraRecord->id;
}
Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER fixture_correction_failure BEFORE UPDATE OF visitor_record_id ON vehicle_events
    WHEN NEW.external_event_key = 'regression-ui-failure' AND NEW.visitor_record_id IS NOT NULL
    BEGIN SELECT RAISE(ABORT, 'SQLSTATE fixture failure /private/fixture/database.sqlite'); END");
file_put_contents($directory.'/plate-regression-errors.json', json_encode($extraIds));
