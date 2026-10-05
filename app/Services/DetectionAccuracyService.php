<?php

namespace App\Services;

use App\Models\VehicleCrossing;
use App\Models\VehicleTypeCorrection;
use App\Models\VisitorRecord;
use App\Support\VehicleType;
use Carbon\CarbonInterface;

/**
 * A2 (detection): how often the camera gets the vehicle type right, from
 * the live gates (no test video needed):
 * - registered vehicles: the camera's type vs the type in the Registry
 *   (the crossing got the vehicle's RFID tag read, so we know who it was);
 * - unregistered visitors: the types guards corrected (Visitors › Correct type).
 */
class DetectionAccuracyService
{
    /**
     * @return array<string, mixed>
     */
    public function report(CarbonInterface $since, ?string $gate = null): array
    {
        $matrix = array_fill_keys(VehicleType::TYPES, array_fill_keys(VehicleType::TYPES, 0));
        $unchecked = 0;

        VehicleCrossing::query()
            ->with('rfidScanLog.vehicle')
            ->whereNotNull('rfid_scan_log_id')
            ->where('crossed_at', '>=', $since)
            ->when($gate, fn ($query) => $query->where('gate', $gate))
            ->get()
            ->each(function (VehicleCrossing $crossing) use (&$matrix, &$unchecked): void {
                $actual = VehicleType::category($crossing->rfidScanLog?->vehicle?->vehicle_type);
                $detected = VehicleType::category($crossing->vehicle_type);
                if ($actual === null || $detected === null) {
                    $unchecked++;

                    return;
                }
                $matrix[$actual][$detected]++;
            });

        $registered = array_sum(array_map('array_sum', $matrix));
        $registeredCorrect = array_sum(array_map(fn (string $type): int => $matrix[$type][$type], VehicleType::TYPES));

        $visitorRecords = VisitorRecord::query()
            ->where('source', VisitorRecord::SOURCE_CAMERA)
            ->where('seen_at', '>=', $since)
            ->when($gate, fn ($query) => $query->where('gate', $gate))
            ->count();
        // The latest correction per record (a guard may change it twice).
        $corrections = VehicleTypeCorrection::query()
            ->whereNotNull('vehicle_crossing_id')
            ->where('created_at', '>=', $since)
            ->when($gate, fn ($query) => $query->where('gate', $gate))
            ->orderBy('id')
            ->get()
            ->keyBy('visitor_record_id');
        $wrong = $corrections->filter(fn (VehicleTypeCorrection $item): bool => VehicleType::category($item->detected_type) !== $item->corrected_type);
        $pair = fn (string $from, string $to): int => $wrong->filter(fn (VehicleTypeCorrection $item): bool => VehicleType::category($item->detected_type) === $from && $item->corrected_type === $to)->count();

        return [
            'since' => $since,
            'gate' => $gate,
            'registered' => [
                'checked' => $registered,
                'correct' => $registeredCorrect,
                'accuracy' => $registered ? round(100 * $registeredCorrect / $registered, 1) : null,
                'matrix' => $matrix,
                'unchecked' => $unchecked,
            ],
            'visitors' => [
                'records' => $visitorRecords,
                'corrected' => $wrong->count(),
                'wrong_rate' => $visitorRecords ? round(100 * $wrong->count() / $visitorRecords, 1) : null,
            ],
            'car_as_truck' => $matrix[VehicleType::CAR][VehicleType::TRUCK_BUS] + $pair(VehicleType::TRUCK_BUS, VehicleType::CAR),
            'truck_as_car' => $matrix[VehicleType::TRUCK_BUS][VehicleType::CAR] + $pair(VehicleType::CAR, VehicleType::TRUCK_BUS),
        ];
    }
}
