<?php

namespace Database\Seeders;

use App\Services\VehicleRegistryService;
use Illuminate\Database\Seeder;

class VehicleRegistrySeeder extends Seeder
{
    /**
     * Seed a small local vehicle registry with RFID-ready data for the prototype.
     */
    public function run(): void
    {
        /** @var VehicleRegistryService $vehicleRegistryService */
        $vehicleRegistryService = app(VehicleRegistryService::class);

        $records = [
            [
                'plate_number' => 'ABC-1234',
                'vehicle_owner_name' => 'Engr. Maria Santos',
                'category' => 'faculty_staff',
                'vehicle_type' => 'Car',
                'status' => 'active',
                'rfid_tag_uid' => 'RFID-ABC-1001',
                'tag_status' => 'active',
                'notes' => 'Sample registered faculty vehicle for entrance and exit testing.',
            ],
            [
                'plate_number' => 'DEF-5678',
                'vehicle_owner_name' => 'Mark Rivera',
                'category' => 'student',
                'vehicle_type' => 'Motorcycle',
                'status' => 'active',
                'rfid_tag_uid' => 'RFID-DEF-1002',
                'tag_status' => 'active',
                'notes' => 'Sample active motorcycle for simulated RFID verification.',
            ],
            [
                'plate_number' => 'GHI-9012',
                'vehicle_owner_name' => 'PhilCST Service Unit',
                'category' => 'guard',
                'vehicle_type' => 'Car',
                'status' => 'active',
                'rfid_tag_uid' => 'RFID-GHI-1003',
                'tag_status' => 'active',
                'notes' => 'Used for entry-session and RFID scan correlation tests.',
            ],
            [
                'plate_number' => 'LMN-3456',
                'vehicle_owner_name' => 'Campus Shuttle',
                'category' => 'parent',
                'vehicle_type' => 'Bus',
                'status' => 'active',
                'rfid_tag_uid' => 'RFID-LMN-1004',
                'tag_status' => 'active',
                'notes' => 'Sample transport vehicle for historical log checks.',
            ],
            [
                'plate_number' => 'XYZ-0001',
                'vehicle_owner_name' => 'Legacy Contractor Vehicle',
                'category' => 'faculty_staff',
                'vehicle_type' => 'Car',
                'status' => 'inactive',
                'rfid_tag_uid' => 'RFID-XYZ-1005',
                'tag_status' => 'inactive',
                'notes' => 'Inactive sample used to demonstrate attention states in RFID verification.',
            ],
        ];

        foreach ($records as $record) {
            $vehicleRegistryService->register($record);
        }
    }
}
