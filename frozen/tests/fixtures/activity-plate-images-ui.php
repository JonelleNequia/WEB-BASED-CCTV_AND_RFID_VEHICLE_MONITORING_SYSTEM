<?php

require __DIR__.'/ui-workspace.php';

use App\Models\Camera;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\VisitorRecordService;
use App\Support\OccurrenceMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\fixtures\ConfirmedActualPlate;

// Distinct synthetic images make an accidental cross-occurrence swap visible.
$image = function (string $label, bool $crop): string {
    $gd = imagecreatetruecolor($crop ? 240 : 320, $crop ? 70 : 180);
    $background = imagecolorallocate($gd, $crop ? 246 : 214, $crop ? 235 : 231, $crop ? 194 : 249);
    imagefill($gd, 0, 0, $background);
    imagestring($gd, 5, 12, 25, $label, imagecolorallocate($gd, 25, 30, 40));
    ob_start();
    imagejpeg($gd);
    $bytes = ob_get_clean();
    $file = Str::uuid().'.jpg';
    Storage::disk('occurrences')->put($file, $bytes);

    return OccurrenceMedia::PREFIX.$file;
};
$expected = [];
foreach (['vehicle_event', 'visitor_record'] as $source) {
    foreach ([[true, true], [true, false], [false, true], [false, false]] as $index => [$snapshot, $crop]) {
        $plate = 'IMG'.($source === 'vehicle_event' ? '1' : '2').'00'.$index;
        $photoPath = $snapshot ? $image($plate.' vehicle', false) : null;
        $cropPath = $crop ? $image($plate.' crop', true) : null;
        if ($source === 'vehicle_event') {
            $record = VehicleEvent::create(['event_type' => 'ENTRY', 'event_status' => 'completed', 'event_origin' => 'manual',
                'plate_text' => $plate, 'event_time' => '2026-11-03 08:30:00', 'vehicle_image_path' => $photoPath, 'plate_image_path' => $cropPath]);
        } else {
            $record = VisitorRecord::create(['external_event_key' => $plate, 'gate' => 'gate-1', 'direction' => 'IN',
                'seen_at' => '2026-11-03 08:30:00', 'plate_number' => $plate, 'plate_status' => 'read', 'registration_status' => 'guest',
                'snapshot_path' => $photoPath, 'plate_image_path' => $cropPath]);
        }
        $expected[$plate] = ['id' => $record->id, 'record_type' => $source,
            'image_url' => OccurrenceMedia::url($photoPath), 'plate_image_url' => OccurrenceMedia::url($cropPath)];
    }
}
// The service path covers correction, retries, and two movements of the same plate.
Vehicle::create(['plate_number' => '395ISX', 'category' => 'faculty_staff', 'vehicle_type' => 'Car'])
    ->forceFill(['created_at' => now()->subDay()])->save();
$corrected = [];
foreach (['IN', 'OUT'] as $index => $direction) {
    $at = now()->subMinutes(5 - $index);
    $key = 'crop-service-'.$index;
    $service = app(VisitorRecordService::class);
    $record = $service->applyPlateReading(['external_event_key' => $key, 'camera_role' => 'gate-1',
        'direction' => $direction, 'event_time' => $at->toIso8601String(), 'plate_status' => 'read',
        'plate_number' => '3951SX', 'plate_confidence' => .95], UploadedFile::fake()->image('crop.jpg', 240, 70));
    $record->forceFill(['snapshot_path' => $image('395ISX '.$direction.' vehicle', false), 'direction' => $direction])->save();
    $record = ConfirmedActualPlate::for($record, '395ISX');
    ConfirmedActualPlate::for($record, '395ISX');
    $service->applyPlateReading(['external_event_key' => $key, 'camera_role' => 'gate-1', 'event_time' => $at->toIso8601String(),
        'plate_status' => 'read', 'plate_number' => 'WRONG123', 'plate_confidence' => .99]);
    $event = $record->fresh()->movementEvent;
    if (! $event) {
        throw new RuntimeException('Corrected occurrence did not retain its event.');
    }
    $corrected[$event->id] = ['image_url' => OccurrenceMedia::url($event->vehicle_image_path),
        'plate_image_url' => OccurrenceMedia::url($event->plate_image_path), 'direction' => $direction];
}
// Enough large image rows to verify repeated headings and page flow in Print.
foreach (range(1, 36) as $index) {
    $plate = 'PRT'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    VehicleEvent::create(['event_type' => 'ENTRY', 'event_status' => 'completed', 'event_origin' => 'manual',
        'plate_text' => $plate, 'event_time' => '2026-11-03 08:30:00', 'camera_id' => Camera::forRole('gate-1')->value('id'),
        'vehicle_image_path' => $image($plate.' vehicle', false), 'plate_image_path' => $image($plate.' crop', true)]);
}
file_put_contents($directory.'/activity-plate-images.json', json_encode(compact('expected', 'corrected')));
