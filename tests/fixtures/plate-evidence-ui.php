<?php

require __DIR__.'/ui-workspace.php';

use App\Models\Camera;
use App\Models\VehicleCrossing;
use App\Services\VisitorRecordService;
use Illuminate\Http\UploadedFile;

$registry = app(App\Services\VehicleRegistryService::class);
$vehicle = $registry->register(['plate_number' => '395ISX', 'registered_by' => 'Fixture Registrar',
    'vehicle_owner_name' => 'Fixture Owner', 'vehicle_type' => 'Car']);
$vehicle->fresh()->forceFill(['created_at' => now()->subHour()])->save();
$records = [];
foreach (['actual', 'temporary', 'conduction', 'mv', 'no_plate', 'dismiss', 'direction'] as $index => $kind) {
    $image = imagecreatetruecolor(640, 240);
    $background = imagecolorallocate($image, 245, 245, 245);
    imagefill($image, 0, 0, $background);
    $ink = imagecolorallocate($image, 30, 30, 30);
    imagestring($image, 5, 30, 50, 'ISOLATED FIXTURE: '.strtoupper($kind), $ink);
    imagestring($image, 5, 30, 100, $kind === 'conduction' ? 'CONDUCTION STICKER 395ISX' : '395ISX', $ink);
    $path = $directory.'/evidence-'.$kind.'.png';
    imagepng($image, $path);
    imagedestroy($image);
    $at = now()->subMinutes(30 - $index * 2)->startOfSecond();
    $crossing = VehicleCrossing::create(['external_event_key' => 'phase3-ui-'.$kind, 'gate' => 'gate-1',
        'camera_id' => Camera::forRole('gate-1')->value('id'), 'direction' => 'UNKNOWN', 'crossed_at' => $at,
        'detection_metadata_json' => ['rfid_status' => 'no_pass']]);
    app(VisitorRecordService::class)->createFromCrossing($crossing);
    $record = app(VisitorRecordService::class)->applyPlateReading(['external_event_key' => $crossing->external_event_key,
        'camera_role' => 'gate-1', 'event_time' => $at->toIso8601String(), 'plate_status' => 'read',
        'plate_number' => '395ISX', 'plate_confidence' => .99,
        'ocr_details' => ['raw_reads' => [['text' => $kind === 'conduction' ? 'CONDUCTION STICKER 395ISX' : '395ISX', 'confidence' => .99]]],
    ], new UploadedFile($path, 'evidence.png', 'image/png', null, true));
    $records[$kind] = $record->id;
}
file_put_contents($directory.'/plate-evidence.json', json_encode($records));
