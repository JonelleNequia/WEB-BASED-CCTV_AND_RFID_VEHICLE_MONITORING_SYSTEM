<?php

// Separate-process regression fixtures only; no installation database is accepted.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = $argv[1] ?? '';
if (! is_file($database) || ! str_starts_with(basename($database), 'philcst-literal-race-')
    || ! str_starts_with(realpath($database), realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('A private temporary literal-plate database is required.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->runningUnitTests()) {
    throw new RuntimeException('APP_ENV=testing is required.');
}
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
    'database.connections.sqlite.url' => null, 'cache.default' => 'array']);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout=5000');
Illuminate\Support\Carbon::setTestNow('2026-11-03 12:00:00');
$mode = $argv[2];
if ($mode === 'initialize') {
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $user = App\Models\User::factory()->create(['role' => 'admin']);
    auth()->setUser($user);
    app(App\Services\VehicleRegistryService::class)->register(['plate_number' => '395ISX', 'vehicle_type' => 'Motorcycle',
        'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Race Fixture Registrar', 'rfid_uid' => 'LITERAL-RACE-TAG', 'auto_register_tag' => true]);
    app(App\Services\VehicleRegistryService::class)->register(['plate_number' => 'ISX395', 'vehicle_type' => 'Car', 'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Other Registrar']);
    $camera = App\Models\Camera::create(['camera_name' => 'Race camera', 'camera_role' => 'gate-1', 'source_type' => 'none', 'source_value' => '']);
    $crossing = App\Models\VehicleCrossing::create(['external_event_key' => 'literal-race-occurrence', 'camera_id' => $camera->id,
        'gate' => 'gate-1', 'direction' => 'IN', 'crossed_at' => now(), 'snapshot_path' => 'retained/race.jpg',
        'detection_metadata_json' => ['rfid_status' => 'no_pass']]);
    app(App\Services\VisitorRecordService::class)->createFromCrossing($crossing);
    App\Models\VehicleEvent::create(['external_event_key' => $crossing->external_event_key, 'camera_id' => $camera->id,
        'event_time' => now(), 'event_type' => 'ENTRY', 'event_origin' => 'guest_cctv', 'event_status' => 'completed',
        'vehicle_image_path' => 'retained/race.jpg']);
    exit(0);
}
auth()->setUser(App\Models\User::findOrFail(1));
if ($mode === 'rfid') {
    app(App\Services\RfidTagMatcher::class)->addRead('gate-1', 'LITERAL-RACE-TAG', now());
}
echo "ready\n";
flush();
try {
    if ($mode === 'rfid') {
        app(App\Services\RfidIngestService::class)->forCrossing(App\Models\VehicleCrossing::findOrFail(1));
    } elseif ($mode === 'ocr') {
        app(App\Services\VisitorRecordService::class)->applyPlateReading(['external_event_key' => 'literal-race-occurrence',
            'camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(), 'plate_status' => 'read',
            'plate_number' => 'ISX395', 'plate_confidence' => .99]);
    } else {
        $record = new App\Models\VisitorRecord;
        $record->id = 1;
        app(App\Services\VisitorRecordService::class)->correctPlate($record, '395ISX', auth()->user());
    }
    echo "committed\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
