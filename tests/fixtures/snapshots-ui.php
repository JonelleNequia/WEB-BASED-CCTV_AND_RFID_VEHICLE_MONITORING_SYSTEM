<?php

require __DIR__.'/ui-workspace.php'; // Guard the private DB and initialize synthetic accounts only.
Illuminate\Support\Facades\Storage::disk('occurrences')->makeDirectory('');
$registry = app(App\Services\VehicleRegistryService::class);
$currentTime = now();
Illuminate\Support\Carbon::setTestNow($currentTime->copy()->subHour());
$registry->register(['plate_number' => 'RFV-1001', 'vehicle_type' => 'Car', 'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Fixture Registrar',
    'rfid_uid' => 'SNAPSHOT-BROWSER-UID', 'auto_register_tag' => true]);
$registry->register(['plate_number' => '395ISX', 'vehicle_type' => 'E-bike', 'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Fixture Registrar']);
Illuminate\Support\Carbon::setTestNow($currentTime);
$ids = [];
foreach ([['rfid-photo', 'IN', null, true], ['plate-photo', 'OUT', '395ISX', true], ['review-photo', 'UNKNOWN', null, true], ['guest-photo', 'IN', 'GST2002', true], ['no-photo', 'IN', 'GST1001', false]] as $index => [$key, $direction, $plate, $photo]) {
    $at = $currentTime->copy()->subMinutes(10 - $index)->startOfSecond();
    Illuminate\Support\Carbon::setTestNow($at->copy()->addSecond());
    if ($key === 'rfid-photo') {
        app(App\Services\RfidTagMatcher::class)->addRead('gate-1', 'SNAPSHOT-BROWSER-UID', $at);
    }
    $request = Illuminate\Http\Request::create('/api/v1/integration/crossings', 'POST', [
        'external_event_key' => $key, 'camera_role' => 'gate-1', 'direction' => $direction, 'event_time' => $at->toIso8601String(),
        'detection_metadata' => ['rfid_status' => 'no_pass', 'picture' => ['score' => 100, 'selected_at' => $at->toIso8601String()]],
    ]);
    $request->headers->set('X-Api-Key', config('services.detector.api_key'));
    if ($photo) {
        $image = Illuminate\Http\UploadedFile::fake()->image('fixture.jpg', 320 + 20 * $index, 180);
        $request->files->set('snapshot', $image);
    }
    $response = app()->call([app(App\Http\Controllers\Api\CrossingIntegrationController::class), 'store'], ['request' => $request]);
    if ($response->getStatusCode() !== 201) {
        throw new RuntimeException('Snapshot fixture failed.');
    }
    if ($plate) {
        app(App\Services\VisitorRecordService::class)->applyPlateReading([
            'external_event_key' => $key, 'camera_role' => 'gate-1', 'event_time' => $at->toIso8601String(),
            'plate_status' => 'read', 'plate_number' => $plate, 'plate_confidence' => .99,
        ], Illuminate\Http\UploadedFile::fake()->image('crop.jpg', 160, 50));
        Tests\fixtures\ConfirmedActualPlate::for(App\Models\VisitorRecord::where('external_event_key', $key)->sole(), $plate);
    }
    if ($key === 'review-photo') {
        app(App\Services\VisitorRecordService::class)->applyPlateReading([
            'external_event_key' => $key, 'camera_role' => 'gate-1', 'event_time' => $at->toIso8601String(),
            'plate_status' => 'unreadable', 'plate_confidence' => 0,
        ]);
    }
    $crossing = App\Models\VehicleCrossing::where('external_event_key', $key)->sole();
    $ids[$key] = ['crossing' => $crossing->id, 'url' => $crossing->snapshot_url, 'at' => $at->toIso8601String()];
}
Illuminate\Support\Carbon::setTestNow($currentTime);
$clearer = Illuminate\Http\UploadedFile::fake()->image('clearer.jpg', 640, 360);
copy($clearer->getRealPath(), $directory.'/clearer.jpg');
file_put_contents($directory.'/snapshots.json', json_encode($ids));
