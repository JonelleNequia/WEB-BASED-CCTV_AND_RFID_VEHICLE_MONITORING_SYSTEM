<?php

require __DIR__.'/ui-workspace.php';

$vehicles = [];
$tags = [];
$guests = [];
// Private occurrence media uses the application's normal authenticated route.
$snapshot = Illuminate\Http\UploadedFile::fake()->image('snapshot.png', 480, 270)->storeAs('', Illuminate\Support\Str::uuid().'.png', 'occurrences');
$plateImage = Illuminate\Http\UploadedFile::fake()->image('plate.png', 240, 80)->storeAs('', Illuminate\Support\Str::uuid().'.png', 'occurrences');
foreach (range(1, 65) as $index) {
    $tag = App\Models\RfidTag::create(['uid' => '000'.str_pad((string) $index, 6, '0', STR_PAD_LEFT), 'tag_number' => $index,
        'status' => $index % 2 ? 'available' : 'assigned']);
    $vehicle = App\Models\Vehicle::create(['plate_number' => 'REP '.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
        'category' => 'faculty_staff', 'vehicle_type' => 'Car', 'vehicle_owner_name' => 'Ma. José Dela Cruz',
        'registered_by' => 'UI Registration Staff', 'rfid_tag_id' => $index % 2 ? null : $tag->id]);
    $vehicle->forceFill(['status' => $index % 3 ? 'active' : 'inactive', 'current_state' => $index % 2 ? 'OUTSIDE' : 'INSIDE'])->save();
    if ($index % 2 === 0) {
        $tag->update(['vehicle_id' => $vehicle->id]);
    }
    $record = App\Models\VisitorRecord::create(['external_event_key' => 'report-ui-'.$index,
        'gate' => $index === 65 ? $archived->code : 'gate-1', 'direction' => 'IN', 'seen_at' => now()->subMinutes($index),
        'status' => 'active', 'source' => 'manual', 'plate_status' => 'corrected', 'plate_number' => 'OBS '.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
        'registration_status' => 'guest', 'vehicle_type' => 'Car', 'corrected_by' => $user->id,
        'snapshot_path' => $index % 3 === 0 ? null : App\Support\OccurrenceMedia::PREFIX.$snapshot,
        'plate_image_path' => $index % 3 === 1 ? null : App\Support\OccurrenceMedia::PREFIX.$plateImage]);
    $vehicles[] = $vehicle->id;
    $tags[] = $tag->id;
    $guests[] = $record->id;
}
// An actual missing-image row and a historical Guest registered later.
$last = App\Models\VisitorRecord::findOrFail($guests[64]);
$last->update(['vehicle_id' => $vehicle->id, 'snapshot_path' => null, 'plate_image_path' => null]);
App\Models\VisitorRecord::create(['external_event_key' => 'report-ui-review', 'gate' => 'gate-1', 'direction' => 'UNKNOWN',
    'seen_at' => now(), 'source' => 'camera', 'plate_number' => null, 'plate_status' => 'unreadable',
    'registration_status' => 'review', 'registration_reason' => 'Confirm the identifier type.']);
file_put_contents($directory.'/list-reports.json', json_encode(['vehicles' => $vehicles, 'tags' => $tags, 'guests' => $guests,
    'archived_gate' => $archived->code, 'snapshot' => App\Support\OccurrenceMedia::PREFIX.$snapshot]));
