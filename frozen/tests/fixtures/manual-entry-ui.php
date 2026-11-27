<?php

require __DIR__.'/ui-workspace.php';

$registry = app(App\Services\VehicleRegistryService::class);
$now = now();
Illuminate\Support\Carbon::setTestNow($now->copy()->subDay());
$registered = $registry->register(['plate_number' => '395ISX', 'vehicle_type' => 'Car',
    'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Fixture Staff']);
$historical = app(App\Services\VisitorRecordService::class)->createManual(['gate' => 'gate-1', 'direction' => 'IN',
    'seen_at' => now()->copy()->subMinute(), 'plate_number' => 'HIS1001'], App\Models\User::where('role', 'admin')->firstOrFail());
Illuminate\Support\Carbon::setTestNow($now);
$registry->register(['plate_number' => 'HIS1001', 'vehicle_type' => 'Car', 'vehicle_owner_name' => 'Historical Owner', 'registered_by' => 'Fixture Staff']);
// The remaining Camera selector must work even when no ROI records are configured.
App\Models\Roi::query()->delete();
file_put_contents($directory.'/manual-entry.json', json_encode([
    'registered' => $registered->id, 'historical' => $historical->id,
    'camera' => App\Models\Camera::forRole('gate-1')->value('id'),
    'guest_time' => now()->subMinutes(5)->format('Y-m-d\TH:i'),
    'chosen_time' => '2026-11-03T08:17',
]));
