<?php

namespace Database\Seeders;

use App\Models\Camera;
use Illuminate\Database\Seeder;

class CameraSeeder extends Seeder
{
    /**
     * Seed the demo cameras used across the prototype.
     */
    public function run(): void
    {
        $cameras = [
            [
                'camera_name' => 'PHILCST Entrance Camera',
                'camera_role' => 'entrance',
                'source_type' => 'webcam',
                'source_value' => '0',
                'source_username' => null,
                'source_password' => null,
                'browser_device_id' => null,
                'browser_label' => null,
                'calibration_mask_json' => null,
                'calibration_line_json' => null,
                'last_connection_status' => 'unknown',
                'last_connection_message' => 'Waiting for browser camera access.',
                'last_connected_at' => null,
                'status' => 'active',
            ],
            [
                'camera_name' => 'PHILCST Exit Camera',
                'camera_role' => 'exit',
                'source_type' => 'webcam',
                'source_value' => '1',
                'source_username' => null,
                'source_password' => null,
                'browser_device_id' => null,
                'browser_label' => null,
                'calibration_mask_json' => null,
                'calibration_line_json' => null,
                'last_connection_status' => 'unknown',
                'last_connection_message' => 'Waiting for browser camera access.',
                'last_connected_at' => null,
                'status' => 'active',
            ],
        ];

        foreach ($cameras as $camera) {
            Camera::query()->updateOrCreate(
                ['camera_role' => $camera['camera_role']],
                $camera
            );
        }
    }
}
