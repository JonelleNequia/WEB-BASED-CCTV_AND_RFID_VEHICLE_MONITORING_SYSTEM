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
                'camera_role' => 'gate-1',
                // Camera source work: no camera until one is added in Settings › Gates.
                'source_type' => 'none',
                'source_value' => '',
                'source_username' => null,
                'source_password' => null,
                'calibration_mask_json' => null,
                'calibration_line_json' => null,
                'last_connection_status' => 'unknown',
                'last_connection_message' => null,
                'last_connected_at' => null,
                'status' => 'active',
            ],
            [
                'camera_name' => 'PHILCST Exit Camera',
                'camera_role' => 'gate-2',
                // Camera source work: no camera until one is added in Settings › Gates.
                'source_type' => 'none',
                'source_value' => '',
                'source_username' => null,
                'source_password' => null,
                'calibration_mask_json' => null,
                'calibration_line_json' => null,
                'last_connection_status' => 'unknown',
                'last_connection_message' => null,
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
