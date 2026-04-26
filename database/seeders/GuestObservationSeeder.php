<?php

namespace Database\Seeders;

use App\Models\Camera;
use App\Services\GuestObservationService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class GuestObservationSeeder extends Seeder
{
    /**
     * Seed sample guest observations for dashboard and log demos.
     */
    public function run(): void
    {
        /** @var GuestObservationService $guestObservationService */
        $guestObservationService = app(GuestObservationService::class);
        $entranceCameraId = Camera::query()->forRole('entrance')->value('id');
        $exitCameraId = Camera::query()->forRole('exit')->value('id');

        $records = [
            [
                'plate_text' => 'GUEST-1001',
                'vehicle_type' => 'Car',
                'vehicle_color' => 'Black',
                'location' => 'entrance',
                'observation_source' => 'manual',
                'observed_at' => Carbon::today()->setTime(9, 25, 0)->toIso8601String(),
                'camera_id' => $entranceCameraId,
                'notes' => 'Visitor for admissions office.',
            ],
            [
                'plate_text' => null,
                'vehicle_type' => 'Car',
                'vehicle_color' => 'White',
                'location' => 'parking',
                'observation_source' => 'cctv',
                'observed_at' => Carbon::today()->setTime(10, 40, 0)->toIso8601String(),
                'camera_id' => $exitCameraId,
                'notes' => 'Delivery vehicle observed in parking area.',
            ],
            [
                'plate_text' => 'TMP-3030',
                'vehicle_type' => 'Motorcycle',
                'vehicle_color' => 'Red',
                'location' => 'exit',
                'observation_source' => 'manual',
                'observed_at' => Carbon::today()->setTime(14, 5, 0)->toIso8601String(),
                'camera_id' => $exitCameraId,
                'notes' => 'Visitor checked out by guard after manual verification.',
            ],
        ];

        foreach ($records as $record) {
            $guestObservationService->create($record);
        }
    }
}
