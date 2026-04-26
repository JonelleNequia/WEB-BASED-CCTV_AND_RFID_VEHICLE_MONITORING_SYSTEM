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
                'owner_name' => 'Engr. Maria Santos',
                'category' => 'faculty_staff',
                'vehicle_type' => 'Car',
                'vehicle_color' => 'White',
                'status' => 'active',
                'tag_uid' => 'RFID-ABC-1001',
                'tag_label' => 'Faculty Car Tag',
                'tag_status' => 'active',
                'notes' => 'Sample registered faculty vehicle for entrance and exit testing.',
            ],
            [
                'plate_number' => 'DEF-5678',
                'owner_name' => 'Mark Rivera',
                'category' => 'student',
                'vehicle_type' => 'Motorcycle',
                'vehicle_color' => 'Blue',
                'status' => 'active',
                'tag_uid' => 'RFID-DEF-1002',
                'tag_label' => 'Student Rider Tag',
                'tag_status' => 'active',
                'notes' => 'Sample active motorcycle for simulated RFID verification.',
            ],
            [
                'plate_number' => 'GHI-9012',
                'owner_name' => 'PhilCST Service Unit',
                'category' => 'guard',
                'vehicle_type' => 'Van',
                'vehicle_color' => 'Silver',
                'status' => 'active',
                'tag_uid' => 'RFID-GHI-1003',
                'tag_label' => 'Campus Service Van Tag',
                'tag_status' => 'active',
                'notes' => 'Used for entry-session and RFID scan correlation tests.',
            ],
            [
                'plate_number' => 'LMN-3456',
                'owner_name' => 'Campus Shuttle',
                'category' => 'parent',
                'vehicle_type' => 'Bus',
                'vehicle_color' => 'Yellow',
                'status' => 'active',
                'tag_uid' => 'RFID-LMN-1004',
                'tag_label' => 'Shuttle Bus Tag',
                'tag_status' => 'active',
                'notes' => 'Sample transport vehicle for historical log checks.',
            ],
            [
                'plate_number' => 'XYZ-0001',
                'owner_name' => 'Legacy Contractor Vehicle',
                'category' => 'faculty_staff',
                'vehicle_type' => 'Truck',
                'vehicle_color' => 'Red',
                'status' => 'inactive',
                'tag_uid' => 'RFID-XYZ-1005',
                'tag_label' => 'Inactive Truck Tag',
                'tag_status' => 'inactive',
                'notes' => 'Inactive sample used to demonstrate attention states in RFID verification.',
            ],
            [
                'plate_number' => 'GST-9001',
                'owner_name' => 'Guest Vehicle Profile',
                'category' => 'guest',
                'vehicle_type' => 'Car',
                'vehicle_color' => 'Gray',
                'status' => 'active',
                'notes' => 'Guest profile sample for manual/CCTV observation flow. No RFID tag assigned.',
            ],
        ];

        foreach ($records as $record) {
            $vehicleRegistryService->register($record);
        }
    }
}
