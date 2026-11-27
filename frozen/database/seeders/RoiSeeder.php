<?php

namespace Database\Seeders;

use App\Models\Camera;
use App\Models\Roi;
use Illuminate\Database\Seeder;

class RoiSeeder extends Seeder
{
    /**
     * Seed simple ROI calibration records for the manual prototype.
     */
    public function run(): void
    {
        $entranceCamera = Camera::query()->where('camera_name', 'PHILCST Entrance Camera')->firstOrFail();
        $exitCamera = Camera::query()->where('camera_name', 'PHILCST Exit Camera')->firstOrFail();

        $rois = [
            [
                'camera_id' => $entranceCamera->id,
                'roi_name' => 'Main Entrance Lane',
                'x' => 40,
                'y' => 120,
                'width' => 880,
                'height' => 320,
                'direction_type' => 'ENTRY',
                'status' => 'active',
            ],
            [
                'camera_id' => $exitCamera->id,
                'roi_name' => 'Main Exit Lane',
                'x' => 55,
                'y' => 130,
                'width' => 900,
                'height' => 315,
                'direction_type' => 'EXIT',
                'status' => 'active',
            ],
            [
                'camera_id' => $exitCamera->id,
                'roi_name' => 'Service Gate Lane',
                'x' => 120,
                'y' => 150,
                'width' => 760,
                'height' => 290,
                'direction_type' => 'BOTH',
                'status' => 'inactive',
            ],
        ];

        foreach ($rois as $roi) {
            Roi::query()->updateOrCreate(
                ['camera_id' => $roi['camera_id'], 'roi_name' => $roi['roi_name']],
                $roi
            );
        }
    }
}
