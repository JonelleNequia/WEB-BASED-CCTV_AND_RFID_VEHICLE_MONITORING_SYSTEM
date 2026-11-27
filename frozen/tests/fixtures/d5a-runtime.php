<?php

// CLI/private HTTP fixture only. tests/ never enters the packaged application.
if (getenv('PHILCST_D5A_FIXTURE') !== '1' || ! str_contains((string) getenv('DB_DATABASE'), 'd5a-')) exit(90);
require_once dirname(__DIR__,2).'/vendor/autoload.php';
$app = require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$mode = $argv[1] ?? 'inspect';
if ($mode === 'seed') {
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate',['--force'=>true]);
    (new \Tests\fixtures\PortalSeeder)->run();
    \App\Models\SystemSetting::where('setting_key','rfid_offline_fallback')->update(['setting_value'=>'0']);
    app(\App\Services\VehicleRegistryService::class)->register(['plate_number'=>'395ISX','vehicle_type'=>'Car',
        'vehicle_owner_name'=>'Fixture Owner','registered_by'=>'Fixture Registrar','rfid_uid'=>'000012345678ABCD','display_number'=>'0007','auto_register_tag'=>true]);
} elseif ($mode === 'bind') {
    app(\App\Services\LifecycleBarrier::class)->checkIdentity();
} elseif ($mode === 'marker') {
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->call('system:delivery-init');
} elseif ($mode === 'reassign') {
    app(\App\Services\LifecycleBarrier::class)->run(fn()=>\App\Models\Camera::forRole('gate-1')->firstOrFail()->update(['camera_role'=>'gate-2']));
} elseif ($mode === 'trigger') {
    \Illuminate\Support\Facades\DB::unprepared('CREATE TRIGGER fixture_pause BEFORE INSERT ON delivery_receipts BEGIN SELECT d5_pause(); END');
}
$record=\App\Models\VehicleCrossing::first();
echo json_encode(['crossings'=>\App\Models\VehicleCrossing::count(),'receipts'=>\Illuminate\Support\Facades\DB::table('delivery_receipts')->count(),
    'events'=>\App\Models\VehicleEvent::count(),'scans'=>\App\Models\RfidScanLog::count(),'alerts'=>\App\Models\VehicleEvent::where('event_status','anomaly')->count(),
    'plate'=>\App\Models\Vehicle::where('plate_number','395ISX')->first()?->plate_number,
    'state'=>\App\Models\Vehicle::where('plate_number','395ISX')->first()?->current_state,
    'entries'=>\App\Models\Vehicle::where('plate_number','395ISX')->first()?->entries_today_count,
    'camera_id'=>\App\Models\Camera::forRole('gate-1')->value('id'),
    'first_snapshot_sha256'=>$record?->snapshot_path ? hash_file('sha256',\Illuminate\Support\Facades\Storage::disk('occurrences')->path(basename($record->snapshot_path))) : null,
    'first_crossing'=>$record?->only(['id','external_event_key','camera_id','gate','direction','crossed_at','snapshot_path'])],JSON_THROW_ON_ERROR),PHP_EOL;
