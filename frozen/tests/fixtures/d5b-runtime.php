<?php

// Only synthetic, private fixture paths may reach this seeder/controller harness.
if (getenv('PHILCST_D5B_FIXTURE') !== '1' || ! str_contains((string) getenv('DB_DATABASE'), 'd5b-')) exit(90);
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useStoragePath((string) getenv('PHILCST_D5B_STORAGE'));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$mode = $argv[1] ?? 'inspect';
if ($mode === 'seed') {
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    (new Database\Seeders\InstallSeeder)->run();
    App\Models\User::create(['name' => 'Recovery Fixture', 'email' => 'recovery.fixture@school.test',
        'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1]),
        'role' => 'admin', 'account_status' => 'active']);
    Illuminate\Support\Facades\DB::table('account_security_state')->where('id', 1)->update(['setup_completed_at' => now(), 'version' => 1]);
    $vehicle = app(App\Services\VehicleRegistryService::class)->register(['plate_number' => 'REG001', 'vehicle_type' => 'Car',
        'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Fixture Registrar'])->fresh();
    $vehicle->forceFill(['created_at' => now()->subHour()])->save();
    $camera = App\Models\Camera::forRole('gate-1')->firstOrFail();
    $camera->source_password = 'synthetic-camera-secret'; $camera->save();
    $name = '11111111-1111-4111-8111-111111111111.png';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lp8AAAAASUVORK5CYII=');
    Illuminate\Support\Facades\Storage::disk('occurrences')->put($name, $png);
    $crossing = App\Models\VehicleCrossing::create(['external_event_key' => 'd5b-original-pass', 'gate' => 'gate-1',
        'camera_id' => $camera->id, 'direction' => 'IN', 'crossed_at' => now()->startOfSecond(),
        'snapshot_path' => 'occurrence-media/'.$name, 'detection_metadata_json' => ['rfid_status' => 'no_pass']]);
    app(App\Services\VisitorRecordService::class)->createFromCrossing($crossing);
    app(App\Services\VisitorRecordService::class)->applyPlateReading(['external_event_key' => $crossing->external_event_key,
        'camera_role' => 'gate-1', 'camera_id' => $camera->id, 'event_time' => $crossing->crossed_at->toIso8601String(),
        'plate_status' => 'read', 'plate_number' => 'REG001', 'plate_confidence' => .99]);
} elseif ($mode === 'request') {
    $packet = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $request = Illuminate\Http\Request::create('/api/v1/integration/deliveries', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_API_KEY' => 'synthetic-d5b-key', 'REMOTE_ADDR' => '127.0.0.1',
    ], json_encode($packet));
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request); $kernel->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]), PHP_EOL;
    exit;
}
$crossing = App\Models\VehicleCrossing::first();
echo json_encode(['events' => App\Models\VehicleEvent::count(), 'crossings' => App\Models\VehicleCrossing::count(),
    'receipts' => Illuminate\Support\Facades\DB::table('delivery_receipts')->count(),
    'entries' => App\Models\Vehicle::first()?->entries_today_count,
    'context' => $crossing ? ['crossed_at' => $crossing->crossed_at->toIso8601String(), 'seen_at' => App\Models\VisitorRecord::first()?->seen_at?->toIso8601String(), 'camera' => $crossing->camera_id, 'record_camera' => App\Models\VisitorRecord::first()?->camera_id] : null,
    'payload' => $crossing ? ['external_event_key' => $crossing->external_event_key, 'camera_role' => $crossing->gate,
        'camera_id' => $crossing->camera_id, 'event_time' => $crossing->crossed_at->toIso8601String(), 'direction' => $crossing->direction,
        'detection_metadata' => ['rfid_status' => 'no_pass']] : null], JSON_THROW_ON_ERROR), PHP_EOL;
