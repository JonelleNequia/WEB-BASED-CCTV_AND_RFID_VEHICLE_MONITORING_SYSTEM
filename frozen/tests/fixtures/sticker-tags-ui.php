<?php

require __DIR__.'/ui-workspace.php';

App\Models\RfidTag::create(['uid' => 'LEGACY-UID', 'tag_number' => 41]);
App\Models\RfidTag::create(['uid' => 'HISTORICAL-NUMBER-UID', 'tag_number' => 42, 'display_number' => '000043']);
App\Models\RfidTag::create(['uid' => 'LOST-FIXTURE-UID', 'tag_number' => 50, 'display_number' => '000050', 'status' => 'lost']);
App\Models\RfidTag::create(['uid' => 'HISTORICAL-LABEL-UID', 'tag_number' => 52, 'display_number' => 'G-01', 'status' => 'disabled']);
$vehicle = app(App\Services\VehicleRegistryService::class)->register(['plate_number' => '395ISX', 'vehicle_type' => 'Car',
    'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Fixture Staff']);
file_put_contents($directory.'/sticker-tags.json', json_encode(['vehicle' => $vehicle->id]));
