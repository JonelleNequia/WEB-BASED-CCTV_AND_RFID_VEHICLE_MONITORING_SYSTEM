<?php

namespace App\Services;

use App\Models\Gate;
use App\Models\RfidScanLog;
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
 *
 * A4: counting too, per gate: crossings, direction unknown, duplicates
 * (caught automatically or dismissed by a guard) and missed vehicles (a
 * registered tag read with the camera online but no crossing seen), plus
 * the list of mistakes with their snapshots.
 */
class DetectionAccuracyService
{
    /**
     * @return array<string, mixed>
     */
    public function report(CarbonInterface $since, ?string $gate = null, ?CarbonInterface $until = null): array
    {
        $until ??= now();
        $matrix = array_fill_keys(VehicleType::TYPES, array_fill_keys(VehicleType::TYPES, 0));
        $unchecked = 0;

        VehicleCrossing::query()
            ->with('rfidScanLog.vehicle')
            ->whereNotNull('rfid_scan_log_id')
            ->whereBetween('crossed_at', [$since, $until])
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
            ->whereBetween('seen_at', [$since, $until])
            ->when($gate, fn ($query) => $query->where('gate', $gate))
            ->count();
        // The latest correction per record (a guard may change it twice).
        $corrections = VehicleTypeCorrection::query()
            ->whereNotNull('vehicle_crossing_id')
            ->whereBetween('created_at', [$since, $until])
            ->when($gate, fn ($query) => $query->where('gate', $gate))
            ->orderBy('id')
            ->get()
            ->keyBy('visitor_record_id');
        $wrong = $corrections->filter(fn (VehicleTypeCorrection $item): bool => VehicleType::category($item->detected_type) !== $item->corrected_type);
        $pair = fn (string $from, string $to): int => $wrong->filter(fn (VehicleTypeCorrection $item): bool => VehicleType::category($item->detected_type) === $from && $item->corrected_type === $to)->count();

        return [
            'since' => $since,
            'until' => $until,
            'gate' => $gate,
            'counting' => $this->counting($since, $until, $gate),
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

    /**
     * A4: per gate: crossings, direction unknown, duplicates, missed.
     *
     * @return array<string, array<string, int|float|null>>
     */
    public function counting(CarbonInterface $since, CarbonInterface $until, ?string $gate = null): array
    {
        $gates = $gate ? [$gate] : Gate::codes();
        $rows = [];

        foreach ($gates as $code) {
            $crossings = VehicleCrossing::query()->where('gate', $code)->whereBetween('crossed_at', [$since, $until]);
            $records = VisitorRecord::query()->where('gate', $code)->where('source', VisitorRecord::SOURCE_CAMERA)->whereBetween('seen_at', [$since, $until]);
            // Registered tag reads: the camera saw the crossing, or it was online and saw nothing.
            $reads = RfidScanLog::query()->where('scan_location', $code)->whereBetween('scan_time', [$since, $until]);
            $seen = (clone $reads)->where('fusion_status', RfidScanLog::FUSION_CAMERA)->count();
            $missed = (clone $reads)->where('fusion_status', RfidScanLog::FUSION_SCAN_ONLY)->count();
            $total = (clone $crossings)->count();
            $duplicates = (clone $records)->where('status', VisitorRecord::STATUS_DUPLICATE)->count();
            $dismissed = (clone $records)->where('status', VisitorRecord::STATUS_DISMISSED)->count();

            $rows[$code] = [
                'label' => Gate::labelFor($code),
                'crossings' => $total,
                'direction_unknown' => (clone $crossings)->where('direction', VehicleCrossing::DIRECTION_UNKNOWN)->count(),
                'duplicates' => $duplicates,
                'dismissed' => $dismissed,
                'false_rate' => $total ? round(100 * ($duplicates + $dismissed) / $total, 1) : null,
                'tag_reads_seen' => $seen,
                'missed' => $missed,
                'missed_rate' => ($seen + $missed) ? round(100 * $missed / ($seen + $missed), 1) : null,
            ];
        }

        return $rows;
    }

    /**
     * A4: the mistakes, newest first, with their snapshots (to see why).
     *
     * @return list<array{time: string, gate: string, kind: string, detail: string, snapshot: ?string}>
     */
    public function mistakes(CarbonInterface $since, ?string $gate = null, ?CarbonInterface $until = null, int $limit = 20): array
    {
        $until ??= now();
        $items = collect();
        $add = fn ($time, string $gateCode, string $kind, string $detail, ?string $snapshot) => $items->push([
            'sort' => $time?->getTimestamp() ?? 0, 'time' => $time?->format('Y-m-d H:i:s') ?? '—',
            'gate' => Gate::labelFor($gateCode), 'kind' => $kind, 'detail' => $detail, 'snapshot' => $snapshot ? url($snapshot) : null,
        ]);

        VehicleCrossing::query()->with('rfidScanLog.vehicle')->whereNotNull('rfid_scan_log_id')
            ->whereBetween('crossed_at', [$since, $until])->when($gate, fn ($query) => $query->where('gate', $gate))
            ->get()
            ->each(function (VehicleCrossing $crossing) use ($add): void {
                $vehicle = $crossing->rfidScanLog?->vehicle;
                $actual = VehicleType::category($vehicle?->vehicle_type);
                $detected = VehicleType::category($crossing->vehicle_type);
                if ($actual && $detected && $actual !== $detected) {
                    $add($crossing->crossed_at, $crossing->gate, 'wrong type', "{$vehicle->plate_number}: camera {$detected}, Registry {$actual}", $crossing->snapshot_url);
                }
            });

        VehicleTypeCorrection::query()->with('visitorRecord.crossing')
            ->whereBetween('created_at', [$since, $until])->when($gate, fn ($query) => $query->where('gate', $gate))
            ->get()
            ->filter(fn (VehicleTypeCorrection $item): bool => VehicleType::category($item->detected_type) !== $item->corrected_type)
            ->each(fn (VehicleTypeCorrection $item) => $add($item->visitorRecord?->seen_at ?? $item->created_at, $item->gate, 'wrong type',
                'record #'.$item->visitor_record_id.': camera '.($item->detected_type ?: '—').', guard '.$item->corrected_type, $item->visitorRecord?->crossing?->snapshot_url));

        VisitorRecord::query()->with('crossing')->where('source', VisitorRecord::SOURCE_CAMERA)
            ->whereIn('status', [VisitorRecord::STATUS_DUPLICATE, VisitorRecord::STATUS_DISMISSED])
            ->whereBetween('seen_at', [$since, $until])->when($gate, fn ($query) => $query->where('gate', $gate))
            ->get()
            ->each(fn (VisitorRecord $record) => $add($record->seen_at, $record->gate, $record->status === VisitorRecord::STATUS_DUPLICATE ? 'counted twice' : 'not a vehicle / dismissed',
                'record #'.$record->id.': '.($record->status_note ?: '—'), $record->crossing?->snapshot_url));

        RfidScanLog::query()->with('vehicle')->where('fusion_status', RfidScanLog::FUSION_SCAN_ONLY)
            ->whereBetween('scan_time', [$since, $until])->when($gate, fn ($query) => $query->where('scan_location', $gate))
            ->get()
            ->each(fn (RfidScanLog $scan) => $add($scan->scan_time, (string) $scan->scan_location, 'missed by the camera',
                ($scan->vehicle?->plate_number ?? $scan->tag_uid).': tag read, no crossing seen', null));

        return $items->sortByDesc('sort')->take($limit)->map(fn (array $item): array => collect($item)->except('sort')->all())->values()->all();
    }
}
