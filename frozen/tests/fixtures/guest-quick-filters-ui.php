<?php

require __DIR__.'/ui-workspace.php';

$ids = [];
$record = function (string $key, array $attributes = []) use (&$ids) {
    $row = App\Models\VisitorRecord::create($attributes + ['external_event_key' => 'guest-filter-'.$key,
        'gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now()->subDay(), 'status' => 'active',
        'source' => 'camera', 'plate_status' => 'read', 'plate_number' => 'WIN 0001', 'plate_confidence' => 0.95,
        'registration_status' => 'guest', 'vehicle_type' => 'Car']);
    $ids[$key] = $row->id;

    return $row;
};
$vehicle = App\Models\Vehicle::create(['plate_number' => 'LATE 456', 'category' => 'faculty_staff', 'vehicle_owner_name' => 'Later Owner']);
$record('historical-guest', ['vehicle_id' => $vehicle->id, 'plate_number' => $vehicle->plate_number]);
foreach (range(1, 24) as $index) {
    $record('camera-'.$index, ['plate_number' => 'WIN '.str_pad((string) $index, 4, '0', STR_PAD_LEFT)]);
}
$record('camera-corrected', ['plate_number' => 'FIX 1234', 'plate_status' => 'corrected', 'corrected_by' => $user->id, 'reviewed_at' => now()]);
$profile = App\Models\PlateProfile::create(['plate_number' => 'MAN 0001', 'plate_key' => 'MAN0001', 'visit_count' => 1]);
$record('manual-1', ['source' => 'manual', 'plate_status' => 'corrected', 'plate_number' => 'MAN 0001', 'plate_profile_id' => $profile->id, 'corrected_by' => $user->id]);
$record('manual-2', ['source' => 'manual', 'plate_status' => 'corrected', 'plate_number' => 'MAN 0002', 'gate' => 'gate-2']);
$record('manual-archived', ['source' => 'manual', 'plate_status' => 'corrected', 'plate_number' => 'MAN 0003', 'gate' => $archived->code]);
$record('registered-1', ['registration_status' => 'registered', 'registration_category' => 'faculty_staff', 'vehicle_id' => $vehicle->id, 'plate_number' => $vehicle->plate_number]);
$record('registered-2', ['registration_status' => 'registered', 'registration_category' => 'registered_visitor', 'plate_number' => 'REG 0002', 'source' => 'manual']);
$record('review', ['registration_status' => 'review', 'registration_reason' => 'Confirm the identifier type.', 'plate_number' => 'WIN 0099']);
$record('duplicate', ['source' => 'manual', 'plate_status' => 'corrected', 'status' => 'duplicate', 'plate_number' => 'MAN 0004']);
$record('dismissed', ['source' => 'manual', 'plate_status' => 'corrected', 'status' => 'dismissed', 'plate_number' => 'MAN 0005']);
file_put_contents($directory.'/guest-filters.json', json_encode(['ids' => $ids, 'archived_gate' => $archived->code]));
