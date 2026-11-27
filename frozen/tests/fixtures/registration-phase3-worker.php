<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = $argv[1] ?? '';
if (! is_file($database) || ! str_starts_with(basename($database), 'philcst-phase3-race-')
    || ! str_starts_with(realpath($database), realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('An isolated temporary Phase 3 database is required.');
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
$service = app(App\Services\VehicleRegistryService::class);
$mode = $argv[2];
if (str_starts_with($mode, 'initialize')) {
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $user = App\Models\User::factory()->create(['role' => 'admin']);
    auth()->setUser($user);
    foreach ([1, 2] as $index) {
        $service->register(['plate_number' => 'RCE-000'.$index, 'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Concurrency Registrar', 'vehicle_type' => 'Car']);
    }
    foreach ([1, 2, 3] as $index) {
        App\Models\RfidTag::query()->create(['uid' => 'RACE-TAG-'.$index]);
    }
    if ($mode !== 'initialize-assign') {
        $vehicle = $service->replaceTag(App\Models\Vehicle::findOrFail(1), ['rfid_tag_id' => 1]);
        $crossing = App\Models\VehicleCrossing::query()->create([
            'external_event_key' => 'race-occurrence', 'gate' => 'gate-1', 'direction' => 'IN',
            'crossed_at' => now(), 'detection_metadata_json' => ['rfid_status' => 'no_pass'],
        ]);
        app(App\Services\VisitorRecordService::class)->createFromCrossing($crossing);
        app(App\Services\VisitorRecordService::class)->applyPlateReading([
            'external_event_key' => $crossing->external_event_key, 'camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(),
            'plate_status' => $mode === 'initialize-evidence' ? 'unreadable' : 'read',
            'plate_number' => $mode === 'initialize-evidence' ? null : $vehicle->plate_number, 'plate_confidence' => 0.95,
        ]);
        if ($mode !== 'initialize-evidence') {
            Tests\fixtures\ConfirmedActualPlate::for(App\Models\VisitorRecord::where('external_event_key', $crossing->external_event_key)->sole(), $vehicle->plate_number);
        }
    }
    exit(0);
}
auth()->setUser(App\Models\User::findOrFail(1));
$vehicle = App\Models\Vehicle::findOrFail((int) ($argv[3] ?? 1));
if ($mode === 'evidence-rfid') {
    app(App\Services\RfidTagMatcher::class)->addRead('gate-1', 'RACE-TAG-1', now());
}
echo "ready\n";
flush();
try {
    if ($mode === 'evidence-rfid') {
        app(App\Services\RfidIngestService::class)->forCrossing(App\Models\VehicleCrossing::findOrFail(1));
    } elseif ($mode === 'evidence-plate') {
        app(App\Services\VisitorRecordService::class)->correctPlate(App\Models\VisitorRecord::findOrFail(1), 'RCE-0001', auth()->user());
    } else {
        $service->replaceTag($vehicle, ['rfid_tag_id' => (int) $argv[4], 'old_tag_status' => 'lost']);
    }
    echo "committed\n";
} catch (Illuminate\Validation\ValidationException) {
    echo "rejected\n";
    exit(2);
}
