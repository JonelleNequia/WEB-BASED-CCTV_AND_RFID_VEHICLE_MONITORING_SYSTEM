<?php

// The shared worker refuses to touch any database outside its private temp directory.
require __DIR__.'/ui-workspace.php';

$pending = app(App\Services\EventService::class)->createDetectedEvent([
    'external_event_key' => 'color-retirement-pending', 'camera_role' => 'gate-1', 'direction' => 'IN',
    'detected_vehicle_type' => 'Car', 'event_time' => now(), 'vehicle_image_path' => 'fixture/pending.jpg',
]);
$pending->forceFill(['vehicle_color' => 'HistoricalColorMarker'])->save();
$historical = App\Models\VehicleEvent::query()->create([
    'event_type' => 'ENTRY', 'event_origin' => 'manual', 'event_status' => 'completed',
    'plate_text' => 'CLR-OLD', 'vehicle_type' => 'Car', 'vehicle_color' => 'HistoricalColorMarker',
    'camera_id' => $pending->camera_id, 'event_time' => now(), 'match_status' => 'open',
]);
file_put_contents($directory.'/color-records.json', json_encode(['pending' => $pending->id, 'historical' => $historical->id]));
