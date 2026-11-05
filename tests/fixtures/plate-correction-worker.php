<?php

// Independent process for temporary SQLite contention tests only.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = $argv[1] ?? '';
if (! is_file($database) || ! str_starts_with(basename($database), 'philcst-plate-race-')
    || ! str_starts_with(realpath($database), realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('A private temporary plate-race database is required.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->runningUnitTests()) {
    throw new RuntimeException('This fixture requires APP_ENV=testing.');
}
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
    'database.connections.sqlite.url' => null, 'cache.default' => 'array']);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout=5000');
if (($argv[2] ?? '') === 'initialize') {
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $user = App\Models\User::factory()->create(['role' => 'admin']);
    app(App\Services\VehicleRegistryService::class)->register([
        'plate_number' => 'RCE-0001', 'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Race Fixture Registrar',
        'vehicle_type' => 'Car', 'auto_register_tag' => true, 'rfid_uid' => 'RACE-TAG',
    ]);
    $crossing = App\Models\VehicleCrossing::query()->create([
        'external_event_key' => 'race-crossing', 'gate' => 'gate-1', 'direction' => 'IN',
        'crossed_at' => now()->addMinute(), 'detection_metadata_json' => ['rfid_status' => 'no_pass'],
    ]);
    app(App\Services\VisitorRecordService::class)->createFromCrossing($crossing);
    app(App\Services\VisitorRecordService::class)->applyPlateReading([
        'external_event_key' => $crossing->external_event_key, 'camera_role' => 'gate-1',
        'event_time' => $crossing->crossed_at->toIso8601String(), 'plate_status' => 'unreadable', 'best_guess' => 'RCE-0007',
    ]);
    exit(0);
}
// Do not read the row until the service has acquired its first write lock.
// The caller deliberately supplies only the record's ID, like stale form data.
$record = new App\Models\VisitorRecord;
$record->id = 1;
echo "ready\n";
flush();
app(App\Services\VisitorRecordService::class)->correctPlate($record, 'rce 0001', App\Models\User::findOrFail(1));
echo "corrected\n";
