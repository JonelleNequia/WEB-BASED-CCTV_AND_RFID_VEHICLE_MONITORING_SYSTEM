<?php

require __DIR__.'/ui-workspace.php';

$ids = [];
$inside = [];
foreach (['2026-11-03', '2026-11-04', '2026-11-05'] as $day) {
    foreach (['07:59:59', '08:00:00', '09:00:00', '10:00:00', '10:00:59', '10:01:00', ...array_fill(0, 8, '08:30:00')] as $index => $time) {
        $plate = 'WIN'.str_replace('-', '', $day).'X'.$index;
        $event = App\Models\VehicleEvent::create(['event_type' => 'ENTRY', 'event_status' => 'completed', 'event_origin' => 'manual',
            'plate_text' => $plate, 'event_time' => "$day $time", 'camera_id' => App\Models\Camera::forRole('gate-1')->value('id'),
            'vehicle_category' => 'faculty_staff', 'match_status' => 'open', 'vehicle_type' => 'Car']);
        $ids[$plate] = $event->id;
        if ($index !== 0 && $index !== 5) {
            $inside[] = $event->id;
        }
    }
}
foreach (['2026-11-03 00:00:00', '2026-11-03 23:59:59', '2026-11-06 08:30:00'] as $index => $at) {
    $event = App\Models\VehicleEvent::create(['event_type' => 'EXIT', 'event_status' => 'completed', 'event_origin' => 'manual',
        'plate_text' => 'OTHER'.$index, 'event_time' => $at, 'camera_id' => App\Models\Camera::forRole('gate-2')->value('id')]);
    $ids['OTHER'.$index] = $event->id;
}
file_put_contents($directory.'/activity-time.json', json_encode(['ids' => $ids, 'inside' => $inside]));
