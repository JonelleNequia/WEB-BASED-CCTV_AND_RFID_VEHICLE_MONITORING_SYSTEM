<?php

require __DIR__.'/ui-workspace.php'; // Verifies the private temporary database before any writes.

for ($index = 1; $index <= 28; $index++) {
    $tag = App\Models\RfidTag::query()->create(['uid' => '0000F17E2'.$index, 'status' => 'available']);
    $vehicle = app(App\Services\VehicleRegistryService::class)->register([
        'registered_by' => 'UI Test Registrar',
        'rfid_tag_id' => $tag->id, 'plate_number' => ($index <= 24 ? 'ZED-' : 'ABC-').str_pad((string) $index, 4, '0', STR_PAD_LEFT),
        'vehicle_owner_name' => 'José', 'category' => $index <= 24 ? 'faculty_staff' : 'registered_visitor', 'vehicle_type' => 'Car',
    ]);
    $vehicle->fresh()->forceFill(['current_state' => $index <= 12 ? 'INSIDE' : 'OUTSIDE', 'status' => $index <= 2 ? 'inactive' : 'active'])->save();
}
App\Models\RfidTag::query()->create(['uid' => 'SPARE-001', 'status' => 'available']);
App\Models\RfidTag::query()->create(['uid' => 'LOST-001', 'status' => 'lost']);

for ($index = 1; $index <= 25; $index++) {
    $plate = 'VIS-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    $profile = App\Models\PlateProfile::query()->create([
        'plate_key' => App\Support\PlateNumber::key($plate), 'plate_number' => $plate,
        'visit_count' => $index, 'last_seen_at' => now()->subMinutes($index), 'vehicle_type' => 'Car',
    ]);
    App\Models\VisitorRecord::query()->create([
        'external_event_key' => 'filter-visitor-'.$index, 'gate' => $index % 2 ? 'gate-1' : 'gate-2', 'direction' => 'IN',
        'seen_at' => now()->subMinutes($index), 'plate_status' => 'read', 'plate_number' => $plate,
        'plate_key' => $profile->plate_key, 'plate_profile_id' => $profile->id, 'status' => 'active', 'vehicle_type' => 'Car',
    ]);
}
for ($index = 1; $index <= 45; $index++) {
    App\Models\VehicleEvent::query()->create([
        'event_type' => $index % 2 ? 'ENTRY' : 'EXIT', 'event_status' => 'completed', 'event_origin' => 'manual',
        'plate_text' => 'LOG-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT), 'vehicle_type' => 'Car',
        'vehicle_color' => 'HistoricalColorMarker', 'camera_id' => App\Models\Camera::query()->forRole($index % 2 ? 'gate-1' : 'gate-2')->value('id'),
        'event_time' => now()->subSeconds($index), 'match_status' => $index % 2 ? 'open' : 'unmatched',
    ]);
}
foreach (['UID0', 'RFIDXY', 'RFIDZZ'] as $index => $uid) {
    App\Models\RfidScanLog::query()->create([
        'tag_uid' => $uid, 'scan_time' => now()->subSeconds($index), 'scan_location' => $index === 1 ? 'gate-2' : 'gate-1',
        'verification_status' => $index === 1 ? 'inactive_tag' : 'unknown_tag', 'is_anomaly' => true, 'fusion_status' => 'scan_only',
    ]);
}
file_put_contents($directory.'/filter-records.json', json_encode(['vehicles' => 28, 'profiles' => 25]));
