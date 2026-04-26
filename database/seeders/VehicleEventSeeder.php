<?php

namespace Database\Seeders;

use App\Models\Camera;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class VehicleEventSeeder extends Seeder
{
    /**
     * Seed sample entry and exit records so the prototype is immediately demo-ready.
     */
    public function run(): void
    {
        $entranceCamera = Camera::query()->where('camera_name', 'PHILCST Entrance Camera')->firstOrFail();
        $exitCamera = Camera::query()->where('camera_name', 'PHILCST Exit Camera')->firstOrFail();
        $vehicles = Vehicle::query()->pluck('id', 'plate_number');

        $matchedEntry = VehicleEvent::query()->updateOrCreate(
            [
                'event_type' => 'ENTRY',
                'plate_text' => 'ABC-1234',
                'event_time' => Carbon::today()->setTime(8, 0, 0),
            ],
            [
                'event_origin' => 'manual',
                'plate_confidence' => 98.50,
                'vehicle_id' => $vehicles['ABC-1234'] ?? null,
                'vehicle_type' => 'Car',
                'detected_vehicle_type' => 'Car',
                'vehicle_color' => 'White',
                'camera_id' => $entranceCamera->id,
                'roi_name' => 'Main Entrance Lane',
                'event_status' => 'completed',
                'match_status' => 'closed',
            ]
        );

        $reviewEntry = VehicleEvent::query()->updateOrCreate(
            [
                'event_type' => 'ENTRY',
                'plate_text' => 'DEF-5678',
                'event_time' => Carbon::today()->setTime(9, 10, 0),
            ],
            [
                'event_origin' => 'manual',
                'plate_confidence' => 95.00,
                'vehicle_id' => $vehicles['DEF-5678'] ?? null,
                'vehicle_type' => 'Motorcycle',
                'detected_vehicle_type' => 'Motorcycle',
                'vehicle_color' => 'Blue',
                'camera_id' => $entranceCamera->id,
                'roi_name' => 'Main Entrance Lane',
                'event_status' => 'completed',
                'match_status' => 'open',
            ]
        );

        $openEntry = VehicleEvent::query()->updateOrCreate(
            [
                'event_type' => 'ENTRY',
                'plate_text' => 'GHI-9012',
                'event_time' => Carbon::today()->setTime(11, 5, 0),
            ],
            [
                'event_origin' => 'manual',
                'plate_confidence' => 92.00,
                'vehicle_id' => $vehicles['GHI-9012'] ?? null,
                'vehicle_type' => 'Van',
                'detected_vehicle_type' => 'Van',
                'vehicle_color' => 'Silver',
                'camera_id' => $entranceCamera->id,
                'roi_name' => 'Main Entrance Lane',
                'event_status' => 'completed',
                'match_status' => 'open',
            ]
        );

        VehicleEvent::query()->updateOrCreate(
            [
                'event_type' => 'EXIT',
                'plate_text' => 'ABC-1234',
                'event_time' => Carbon::today()->setTime(10, 15, 0),
            ],
            [
                'event_origin' => 'manual',
                'plate_confidence' => 97.25,
                'vehicle_id' => $vehicles['ABC-1234'] ?? null,
                'vehicle_type' => 'Car',
                'detected_vehicle_type' => 'Car',
                'vehicle_color' => 'White',
                'camera_id' => $exitCamera->id,
                'roi_name' => 'Main Exit Lane',
                'matched_entry_id' => $matchedEntry->id,
                'match_score' => 90,
                'event_status' => 'completed',
                'match_status' => 'matched',
            ]
        );

        VehicleEvent::query()->updateOrCreate(
            [
                'event_type' => 'EXIT',
                'plate_text' => 'DEF-567G',
                'event_time' => Carbon::today()->setTime(12, 0, 0),
            ],
            [
                'event_origin' => 'manual',
                'plate_confidence' => 83.50,
                'vehicle_type' => 'Motorcycle',
                'detected_vehicle_type' => 'Motorcycle',
                'vehicle_color' => 'Blue',
                'vehicle_id' => $vehicles['DEF-5678'] ?? null,
                'camera_id' => $exitCamera->id,
                'roi_name' => 'Main Exit Lane',
                'matched_entry_id' => $reviewEntry->id,
                'match_score' => 69,
                'event_status' => 'completed',
                'match_status' => 'manual_review',
            ]
        );

        VehicleEvent::query()->updateOrCreate(
            [
                'event_type' => 'EXIT',
                'plate_text' => 'XYZ-0001',
                'event_time' => Carbon::today()->setTime(12, 45, 0),
            ],
            [
                'event_origin' => 'manual',
                'plate_confidence' => 75.00,
                'vehicle_id' => $vehicles['XYZ-0001'] ?? null,
                'vehicle_type' => 'Truck',
                'detected_vehicle_type' => 'Truck',
                'vehicle_color' => 'Red',
                'camera_id' => $exitCamera->id,
                'roi_name' => 'Main Exit Lane',
                'matched_entry_id' => null,
                'match_score' => 24,
                'event_status' => 'completed',
                'match_status' => 'unmatched',
            ]
        );

        VehicleEvent::query()->updateOrCreate(
            [
                'external_event_key' => 'seed-detected-entrance-001',
            ],
            [
                'event_type' => 'ENTRY',
                'event_status' => 'pending_details',
                'event_origin' => 'cctv_detected',
                'plate_text' => null,
                'plate_confidence' => null,
                'vehicle_id' => null,
                'vehicle_type' => 'Car',
                'detected_vehicle_type' => 'Car',
                'vehicle_color' => null,
                'camera_id' => $entranceCamera->id,
                'external_event_key' => 'seed-detected-entrance-001',
                'detection_metadata_json' => [
                    'track_id' => 7,
                    'confidence' => 0.91,
                    'detector_class' => 'car',
                    'line_side_before' => -1,
                    'line_side_after' => 1,
                ],
                'roi_name' => 'Entrance Trigger Line',
                'event_time' => Carbon::today()->setTime(13, 5, 0),
                'match_status' => 'pending_details',
            ]
        );

        VehicleEvent::query()->updateOrCreate(
            [
                'event_type' => 'ENTRY',
                'plate_text' => 'LMN-3456',
                'event_time' => Carbon::yesterday()->setTime(16, 30, 0),
            ],
            [
                'event_origin' => 'manual',
                'plate_confidence' => 90.00,
                'vehicle_id' => $vehicles['LMN-3456'] ?? null,
                'vehicle_type' => 'Bus',
                'detected_vehicle_type' => 'Bus',
                'vehicle_color' => 'Yellow',
                'camera_id' => $entranceCamera->id,
                'roi_name' => 'Main Entrance Lane',
                'event_status' => 'completed',
                'match_status' => 'open',
            ]
        );
    }
}
