<?php

require __DIR__.'/ui-workspace.php';
$old = Illuminate\Support\Carbon::getTestNow();
Illuminate\Support\Carbon::setTestNow(now()->subMinutes(5));
foreach (['395ISX', 'ISX395', 'AO0123', 'A00123', 'AI0123', 'A10123'] as $plate) {
    app(App\Services\VehicleRegistryService::class)->register(['plate_number' => $plate,
        'registered_by' => 'Fixture Registrar', 'vehicle_owner_name' => 'Fixture Owner', 'category' => 'faculty_staff', 'vehicle_type' => 'Car']);
}
Illuminate\Support\Carbon::setTestNow($old);
$crossing = App\Models\VehicleCrossing::query()->create(['external_event_key' => 'phase2-review', 'gate' => 'gate-1',
    'camera_id' => App\Models\Camera::query()->forRole('gate-1')->value('id'), 'direction' => 'IN', 'crossed_at' => now(),
    'detection_metadata_json' => ['rfid_status' => 'no_pass']]);
$service = app(App\Services\VisitorRecordService::class);
$service->createFromCrossing($crossing);
$record = $service->applyPlateReading(['external_event_key' => $crossing->external_event_key, 'camera_role' => 'gate-1',
    'event_time' => $crossing->crossed_at->toIso8601String(), 'plate_status' => 'unreadable', 'plate_number' => null,
    'best_guess' => '3951SX', 'plate_confidence' => .2]);
file_put_contents($directory.'/plate-phase2.json', json_encode(['record' => $record->id]));
