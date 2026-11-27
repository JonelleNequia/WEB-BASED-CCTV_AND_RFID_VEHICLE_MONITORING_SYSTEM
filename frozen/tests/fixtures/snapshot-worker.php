<?php

// Separate processes, private SQLite/storage only. No external services/devices.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$work = $argv[1] ?? '';
if (! is_dir($work) || ! str_starts_with(basename($work), 'philcst-snapshot-race-')) {
    throw new RuntimeException('Private race directory required.');
}
$database = $work.'/db.sqlite';
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$database);
putenv('DB_URL=');
putenv('APP_CONFIG_CACHE='.$work.'/config.php');
putenv('APP_ROUTES_CACHE='.$work.'/routes.php');
putenv('CAMERA_FILES_PATH='.$work.'/camera');
putenv('DEVICE_FILES_PATH='.$work.'/devices');
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'APP_CONFIG_CACHE' => $work.'/config.php', 'APP_ROUTES_CACHE' => $work.'/routes.php',
    'CAMERA_FILES_PATH' => $work.'/camera', 'DEVICE_FILES_PATH' => $work.'/devices'] as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$completed = false;
register_shutdown_function(function () use (&$completed): void {
    if (! $completed) {
        exit(3);
    }
});
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->runningUnitTests() || config('database.connections.sqlite.database') !== $database) {
    throw new RuntimeException('Unsafe fixture configuration.');
}
$app->useStoragePath($work.'/storage');
config(['filesystems.disks.occurrences.root' => $work.'/media', 'cache.default' => 'array', 'services.detector.api_key' => 'fixture-snapshot-key']);
if (($argv[2] ?? '') === 'initialize') {
    touch($database);
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => Tests\fixtures\PortalSeeder::class, '--force' => true]);
    app(App\Services\VehicleRegistryService::class)->register(['plate_number' => '395ISX', 'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Fixture Registrar', 'vehicle_type' => 'Car']);
    $image = Illuminate\Http\UploadedFile::fake()->image('shared.jpg');
    copy($image->getRealPath(), $work.'/frame.jpg');
    $completed = true;
    exit;
}
$data = ['external_event_key' => 'concurrent-snapshot', 'camera_role' => 'gate-1', 'direction' => 'IN',
    'event_time' => now()->addMinute()->startOfSecond()->toIso8601String(), 'detection_metadata' => ['rfid_status' => 'no_pass']];
// Both workers use exactly the same occurrence time, independent of start order.
$data['event_time'] = file_get_contents($work.'/event-time.txt');
$request = Illuminate\Http\Request::create('/api/v1/integration/crossings', 'POST', $data);
$request->headers->set('X-Api-Key', 'fixture-snapshot-key');
$request->files->set('snapshot', new Illuminate\Http\UploadedFile($work.'/frame.jpg', 'frame.jpg', 'image/jpeg', null, true));
echo "ready\n";
flush();
$response = app()->call([app(App\Http\Controllers\Api\CrossingIntegrationController::class), 'store'], ['request' => $request]);
if (! in_array($response->getStatusCode(), [200, 201], true)) {
    exit(2);
}
app(App\Services\VisitorRecordService::class)->applyPlateReading(['external_event_key' => $data['external_event_key'],
    'camera_role' => 'gate-1', 'event_time' => $data['event_time'], 'plate_status' => 'read', 'plate_number' => '395ISX', 'plate_confidence' => .99]);
Tests\fixtures\ConfirmedActualPlate::for(App\Models\VisitorRecord::where('external_event_key', $data['external_event_key'])->sole(), '395ISX');
echo "accepted\n";
$completed = true;
