<?php

require __DIR__.'/auto-filters-ui.php'; // Private test directory + database guards run before any writes.

$inactive = App\Models\Gate::where('code', 'gate-3')->firstOrFail();
$inactive->forceFill(['is_active' => false])->save();
$archived = App\Models\Gate::whereNotNull('archived_at')->firstOrFail();
$camera = App\Models\Camera::create(['camera_name' => 'Historical fixture camera', 'camera_role' => $archived->code,
    'source_type' => 'none', 'source_value' => '', 'is_active' => false]);
$event = App\Models\VehicleEvent::create(['camera_id' => $camera->id, 'plate_text' => 'HST-9001',
    'event_type' => 'ENTRY', 'event_status' => 'completed', 'event_origin' => 'manual', 'event_time' => now(), 'vehicle_type' => 'Car']);
$guest = App\Models\VisitorRecord::create(['external_event_key' => 'phase5-archived', 'gate' => $archived->code,
    'camera_id' => $camera->id, 'plate_status' => 'read', 'plate_number' => 'HST-9002', 'plate_key' => 'HST9002',
    'direction' => 'IN', 'seen_at' => now(), 'status' => 'active', 'registration_status' => 'guest',
    'status_note' => 'Visitor wording supplied in historical free text']);
App\Models\RfidScanLog::create(['tag_uid' => 'HST-READ', 'scan_location' => $archived->code, 'scan_time' => now(),
    'verification_status' => 'unknown_tag', 'fusion_status' => 'scan_only']);
file_put_contents($directory.'/phase5.json', json_encode(['archived' => $archived->code, 'inactive' => $inactive->code,
    'event_id' => $event->id, 'guest_id' => $guest->id]));
