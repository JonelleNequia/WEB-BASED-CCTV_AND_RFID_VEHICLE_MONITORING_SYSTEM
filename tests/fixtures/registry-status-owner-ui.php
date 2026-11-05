<?php

// The shared fixture enforces a private testing database before any mutation.
require __DIR__.'/ui-workspace.php';
$ids = [];
foreach (['active', 'inactive'] as $status) {
    foreach (['INSIDE', 'OUTSIDE'] as $state) {
        $plate = strtoupper(substr($status, 0, 3)).'-'.($state === 'INSIDE' ? '1001' : '1002');
        $vehicle = App\Models\Vehicle::query()->create(['plate_number' => $plate, 'vehicle_owner_name' => 'Fixture Owner', 'vehicle_type' => 'Car', 'category' => 'faculty_staff']);
        $vehicle->forceFill(['status' => $status, 'current_state' => $state])->save();
        $ids[$status.'-'.$state] = $vehicle->id;
    }
}
foreach ([null, '3M & Sons, Inc.'] as $index => $owner) {
    $vehicle = App\Models\Vehicle::query()->create(['plate_number' => 'LEG-'.($index + 1), 'vehicle_owner_name' => $owner, 'vehicle_type' => 'Car', 'category' => 'faculty_staff']);
    $ids['legacy-'.$index] = $vehicle->id;
}
$profile = App\Models\PlateProfile::query()->create(['plate_number' => 'GST-1001', 'plate_key' => 'GST1001', 'vehicle_type' => 'Car']);
$ids['profile'] = $profile->id;
file_put_contents($directory.'/registry.json', json_encode($ids));
