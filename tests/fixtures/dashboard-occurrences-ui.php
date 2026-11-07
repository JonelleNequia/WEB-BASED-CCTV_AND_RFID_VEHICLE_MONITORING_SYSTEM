<?php

use App\Models\Camera;
use App\Models\Gate;
use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;

$mode = $argv[2] ?? 'seed';
if ($mode === 'seed') {
    require __DIR__.'/ui-workspace.php';
} else {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $directory = $argv[1] ?? '';
    if (! is_dir($directory) || ! str_starts_with(basename($directory), 'philcst-ui-')) {
        throw new RuntimeException('An isolated UI workspace is required.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! $app->runningUnitTests() || config('database.connections.sqlite.database') !== $directory.'/ui.sqlite' || ! is_file($directory.'/dashboard-occurrences.json')) {
        throw new RuntimeException('Refusing to update fixtures outside their private database.');
    }
    if ($mode === 'gates') {
        Gate::where('code', 'gate-1')->update(['name' => 'Renamed Main Gate']);
        Gate::where('code', 'gate-3')->firstOrFail()->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        Gate::create(['code' => 'gate-5', 'name' => 'New Zero Gate', 'is_active' => true, 'sort_order' => 5]);
    } elseif ($mode === 'no-gates') {
        Gate::query()->update(['is_active' => false]);
    } else {
        throw new RuntimeException('Unknown isolated fixture action.');
    }
    echo "Private gate fixtures updated.\n";
    exit;
}

Gate::where('code', 'gate-1')->update(['name' => 'Main Gate']);
Gate::where('code', 'gate-2')->update(['is_active' => false]);
$camera = Camera::forRole('gate-1')->sole();
$vehicle = Vehicle::create(['plate_number' => '395ISX', 'category' => 'faculty_staff', 'vehicle_type' => 'Car', 'vehicle_owner_name' => 'Fixture Owner']);
$vehicle->forceFill(['created_at' => now()->subYear()])->save();
$at = now()->startOfMinute();
$visitor = fn (string $key, array $values = []) => VisitorRecord::create($values + ['external_event_key' => $key,
    'gate' => 'gate-1', 'camera_id' => $camera->id, 'direction' => 'IN', 'seen_at' => $at,
    'plate_number' => 'GST123', 'plate_status' => 'read', 'plate_confidence' => .99, 'registration_status' => 'guest']);
$event = fn (array $values = []) => VehicleEvent::create($values + ['event_type' => 'ENTRY', 'event_status' => 'completed',
    'event_origin' => 'plate_review', 'plate_text' => '395ISX', 'event_time' => $at,
    'camera_id' => $camera->id, 'vehicle_id' => $vehicle->id, 'vehicle_type' => 'Car', 'vehicle_category' => 'faculty_staff', 'match_status' => 'open']);
$record = $visitor('linked-pair', ['plate_number' => '395ISX', 'vehicle_id' => $vehicle->id, 'registration_status' => 'registered']);
$first = $event(['external_event_key' => 'linked-pair', 'visitor_record_id' => $record->id]);
$second = $event(); // Same visible content/time, but a separate event identity.
GuestVehicleObservation::create(['external_event_key' => 'linked-pair', 'observed_at' => $at, 'plate_number' => '395ISX', 'location' => 'gate-1']);
$rfidVehicle = Vehicle::create(['plate_number' => 'RFV1001', 'category' => 'faculty_staff', 'vehicle_type' => 'Car']);
$scan = RfidScanLog::create(['tag_uid' => 'FIXTURE-UID', 'vehicle_id' => $rfidVehicle->id, 'scan_time' => $at,
    'scan_location' => 'gate-1', 'verification_status' => 'verified']);
$evidence = $visitor('rfid-linked', ['plate_number' => 'RFV1001', 'vehicle_id' => $rfidVehicle->id, 'registration_status' => 'registered']);
$rfid = $event(['vehicle_id' => $rfidVehicle->id, 'plate_text' => 'RFV1001', 'visitor_record_id' => $evidence->id,
    'rfid_scan_log_id' => $scan->id, 'external_event_key' => 'rfid-linked', 'event_origin' => 'rfid_hardware']);
$scan->forceFill(['correlated_vehicle_event_id' => $rfid->id])->save();
GuestVehicleObservation::create(['external_event_key' => 'rfid-linked', 'observed_at' => $at, 'plate_number' => 'RFV1001', 'location' => 'gate-1']);
$review = $visitor('review-only', ['plate_number' => null, 'direction' => 'UNKNOWN', 'plate_status' => 'unreadable', 'registration_status' => 'review']);
GuestVehicleObservation::create(['external_event_key' => 'review-only', 'observed_at' => $at, 'plate_number' => 'OCR123', 'location' => 'gate-1']);
$guest = $visitor('guest-only');
$legacy = GuestVehicleObservation::create(['observed_at' => $at, 'location' => 'gate-1', 'plate_number' => 'LEG123', 'observation_source' => 'cctv']);
GuestVehicleObservation::create(['observed_at' => $at, 'location' => 'gate-1', 'plate_number' => 'LEG456',
    'observation_source' => 'cctv', 'detection_metadata_json' => ['direction' => 'OUT']]);
$unknown = $event(['plate_text' => 'UNKNOWN123', 'event_type' => 'UNKNOWN', 'match_status' => 'manual_review']);
$archivedCamera = Camera::create(['camera_role' => 'gate-4', 'camera_name' => 'Historical camera']);
foreach (['today', 'week', 'month', 'year'] as $index => $period) {
    $window = App\Support\PhilippineTime::periodWindow($period);
    $old = $event(['plate_text' => 'PER'.(1000 + $index), 'event_time' => $window['local_start']->copy()->addMinutes(5),
        'vehicle_id' => null, 'event_origin' => 'manual']);
    $old->forceFill(['created_at' => now()->subYears(2)])->save();
}
$historical = $event(['plate_text' => 'ARCH123', 'camera_id' => $archivedCamera->id, 'vehicle_id' => null, 'event_origin' => 'manual']);
$historical->forceFill(['created_at' => now()->subYears(2)])->save();
$inactive = $event(['plate_text' => 'INA123', 'event_type' => 'EXIT', 'camera_id' => Camera::forRole('gate-2')->value('id'),
    'vehicle_id' => null, 'event_origin' => 'manual', 'match_status' => 'closed']);
$inactive->forceFill(['created_at' => now()->subYears(2)])->save();
file_put_contents($directory.'/dashboard-occurrences.json', json_encode([
    'pair' => [$first->id, $second->id], 'rfid' => $rfid->id, 'review' => $review->id, 'unknown' => $unknown->id,
    'guest' => $guest->id, 'legacy' => $legacy->id,
    'review_url' => route('visitors.records.review', $review, absolute: false),
    'direction_url' => route('vehicle-events.direction', $unknown, absolute: false),
]));
