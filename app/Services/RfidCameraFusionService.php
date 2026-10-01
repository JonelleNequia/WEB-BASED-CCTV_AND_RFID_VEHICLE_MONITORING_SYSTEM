<?php

namespace App\Services;

use App\Models\Gate;
use App\Models\RfidScanLog;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
use App\Support\PhilippineTime;
use App\Support\RfidIngestResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 (visitor model): a registered tag read takes its direction from the
 * camera.
 *
 * - The UHF reader reads a tag while the vehicle approaches, so a read from
 *   up to `rfid_lookback_seconds` (10) before the crossing, or up to
 *   `rfid_lookahead_seconds` (4) after it, belongs to that crossing.
 * - Camera IN / OUT sets the vehicle's state. A direction that does not fit
 *   the state (IN while already inside) is still recorded, and flagged.
 * - Camera direction unknown, no camera at the gate, or camera offline: the
 *   vehicle's state decides (OUTSIDE -> IN, INSIDE -> OUT).
 * - The camera was watching but no crossing came: "scan only", no movement.
 *
 * A read waits ("pending") until its crossing arrives or the time runs out;
 * finalizeExpired() runs on every request (FinalizePendingScans) and from
 * `rfid:finalize-pending`.
 */
class RfidCameraFusionService
{
    public const DEFAULT_LOOKBACK_SECONDS = 10;

    public const DEFAULT_LOOKAHEAD_SECONDS = 4;

    /** After its window ends, the detector needs a moment to send the crossing. */
    public const CROSSING_DELIVERY_SECONDS = 6;

    public function __construct(
        protected SettingsService $settingsService,
        protected DetectorRuntimeService $detectorRuntimeService,
        protected EventService $eventService
    ) {
    }

    public function lookbackSeconds(): int
    {
        return max(1, min(DetectorRfidMatchService::MAX_LOOKBACK_SECONDS, $this->settingsService->getInt('rfid_lookback_seconds', self::DEFAULT_LOOKBACK_SECONDS)));
    }

    public function lookaheadSeconds(): int
    {
        return max(1, min(10, $this->settingsService->getInt('rfid_lookahead_seconds', self::DEFAULT_LOOKAHEAD_SECONDS)));
    }

    /** How long a read waits for its crossing. */
    public function pendingTimeoutSeconds(): int
    {
        return $this->lookbackSeconds() + $this->lookaheadSeconds() + self::CROSSING_DELIVERY_SECONDS;
    }

    /**
     * Is a calibrated camera at this gate running right now? null = yes,
     * otherwise why not.
     */
    public function cameraProblem(string $gate): ?string
    {
        $status = $this->detectorRuntimeService->readStatus();
        $camera = $status['cameras'][$gate] ?? null;

        if (! ($status['service_running'] ?? false)) {
            return 'detector not running';
        }

        if (! is_array($camera)) {
            return 'no camera at this gate';
        }

        if (! ($camera['camera_running'] ?? false)) {
            return 'camera offline';
        }

        if (array_key_exists('calibration_ready', $camera) && ! $camera['calibration_ready']) {
            return 'camera not calibrated';
        }

        return null;
    }

    /**
     * A registered read just arrived: take the direction from a crossing that
     * is already here, wait for one, or use the vehicle's state when the gate
     * has no working camera. Returns the RfidIngestResult outcome.
     */
    public function registerScan(RfidScanLog $scan): string
    {
        $problem = $this->cameraProblem($scan->scan_location);

        if ($problem !== null) {
            return $this->applyMovement($scan, null, 'No camera direction ('.$problem.'); vehicle state used.');
        }

        if ($crossing = $this->crossingForScan($scan)) {
            return $this->applyMovement($scan, $crossing);
        }

        $scan->forceFill([
            'fusion_status' => RfidScanLog::FUSION_PENDING,
            'fusion_note' => 'Waiting for the camera at '.Gate::labelFor($scan->scan_location).'.',
            'outcome' => RfidIngestResult::PENDING,
        ])->save();

        return RfidIngestResult::PENDING;
    }

    /**
     * A crossing just arrived from the detector: give its direction to the
     * registered read that belongs to it (if any).
     */
    public function attachCrossing(VehicleCrossing $crossing): ?RfidScanLog
    {
        return DB::transaction(function () use ($crossing): ?RfidScanLog {
            $crossing = VehicleCrossing::query()->lockForUpdate()->find($crossing->id);

            if (! $crossing || $crossing->rfid_scan_log_id) {
                return null;
            }

            $scan = $this->scanForCrossing($crossing);

            if (! $scan) {
                return null;
            }

            $this->applyMovement($scan, $crossing);

            return $scan->fresh();
        });
    }

    /**
     * Reads whose crossing never came: "scan only" while the camera is
     * watching, the vehicle's state when the camera went away meanwhile.
     */
    public function finalizeExpired(): int
    {
        $expired = RfidScanLog::query()
            ->where('fusion_status', RfidScanLog::FUSION_PENDING)
            ->where('scan_time', '<', now()->subSeconds($this->pendingTimeoutSeconds()))
            ->orderBy('scan_time')
            ->limit(50)
            ->pluck('id');

        foreach ($expired as $id) {
            DB::transaction(function () use ($id): void {
                $scan = RfidScanLog::query()->lockForUpdate()->find($id);

                if (! $scan || $scan->fusion_status !== RfidScanLog::FUSION_PENDING) {
                    return;
                }

                $problem = $this->cameraProblem($scan->scan_location);

                if ($problem !== null) {
                    $this->applyMovement($scan, null, 'Camera gave no direction ('.$problem.'); vehicle state used.');

                    return;
                }

                $scan->forceFill([
                    'fusion_status' => RfidScanLog::FUSION_SCAN_ONLY,
                    'fusion_note' => 'Tag read, but the camera saw no vehicle cross the line. No IN/OUT recorded.',
                    'outcome' => RfidScanLog::FUSION_SCAN_ONLY,
                ])->save();
            });
        }

        return $expired->count();
    }

    /**
     * Unlinked crossing at the same gate from `lookahead` before the read to
     * `lookback` after it; the one the detector matched to this read first.
     */
    protected function crossingForScan(RfidScanLog $scan): ?VehicleCrossing
    {
        $time = $scan->scan_time;

        return VehicleCrossing::query()
            ->atGate($scan->scan_location)
            ->whereNull('rfid_scan_log_id')
            ->whereBetween('crossed_at', [$time->copy()->subSeconds($this->lookaheadSeconds()), $time->copy()->addSeconds($this->lookbackSeconds())])
            ->get()
            ->sortBy(fn (VehicleCrossing $crossing): array => [
                $scan->detector_event_key && $crossing->external_event_key === $scan->detector_event_key ? 0 : 1,
                abs($crossing->crossed_at->getTimestamp() - $time->getTimestamp()),
            ])
            ->first();
    }

    /**
     * The read the detector matched to this crossing (same event key), else
     * the closest waiting registered read at the gate inside the window.
     */
    protected function scanForCrossing(VehicleCrossing $crossing): ?RfidScanLog
    {
        $waiting = RfidScanLog::query()
            ->with('vehicle')
            ->where('scan_location', $crossing->gate)
            ->where('verification_status', 'verified')
            ->whereNull('vehicle_crossing_id')
            ->whereIn('fusion_status', [RfidScanLog::FUSION_PENDING, RfidScanLog::FUSION_SCAN_ONLY]);

        $claimed = (clone $waiting)->where('detector_event_key', $crossing->external_event_key)->first();

        if ($claimed) {
            return $claimed;
        }

        $time = $crossing->crossed_at;

        return $waiting
            // A read the detector matched to another crossing waits for that one.
            ->whereNull('detector_event_key')
            ->whereBetween('scan_time', [$time->copy()->subSeconds($this->lookbackSeconds()), $time->copy()->addSeconds($this->lookaheadSeconds())])
            ->get()
            ->sortBy(fn (RfidScanLog $scan): int => abs($scan->scan_time->getTimestamp() - $time->getTimestamp()))
            ->first();
    }

    /**
     * Record the movement of a registered read: camera direction when there
     * is one, else the vehicle's state. Returns RECORDED or ANOMALY.
     */
    protected function applyMovement(RfidScanLog $scan, ?VehicleCrossing $crossing, ?string $toggleNote = null): string
    {
        return DB::transaction(function () use ($scan, $crossing, $toggleNote): string {
            $vehicle = Vehicle::query()->whereKey($scan->vehicle_id)->lockForUpdate()->firstOrFail();
            $eventTime = $crossing?->crossed_at ?? $scan->scan_time;
            $currentState = strtoupper((string) $vehicle->current_state) === Vehicle::STATE_INSIDE ? Vehicle::STATE_INSIDE : Vehicle::STATE_OUTSIDE;
            $cameraDirection = in_array($crossing?->direction, [VehicleCrossing::DIRECTION_IN, VehicleCrossing::DIRECTION_OUT], true)
                ? $crossing->direction
                : null;
            $anomalyReason = null;

            if ($cameraDirection !== null) {
                $eventType = $cameraDirection === VehicleCrossing::DIRECTION_OUT ? 'EXIT' : 'ENTRY';
                $fusionStatus = RfidScanLog::FUSION_CAMERA;
                $note = 'Camera saw '.$cameraDirection.' at '.Gate::labelFor($crossing->gate).'.';

                if ($eventType === 'ENTRY' && $currentState === Vehicle::STATE_INSIDE) {
                    $anomalyReason = "Camera saw {$vehicle->plate_number} go IN, but it was already inside (an OUT was missed).";
                } elseif ($eventType === 'EXIT' && $currentState === Vehicle::STATE_OUTSIDE) {
                    $anomalyReason = "Camera saw {$vehicle->plate_number} go OUT, but it was already outside (an IN was missed).";
                }
            } else {
                $eventType = $currentState === Vehicle::STATE_INSIDE ? 'EXIT' : 'ENTRY';
                $fusionStatus = RfidScanLog::FUSION_TOGGLE;
                $note = $crossing
                    ? 'Camera direction unknown ('.($crossing->direction_reason ?: 'unclear').'); vehicle state used.'
                    : ($toggleNote ?? 'Vehicle state used.');
            }

            $transition = $this->moveVehicle($vehicle, $eventType, $eventTime) + [
                'anomaly_reason' => $anomalyReason,
                'event_time' => $eventTime,
            ];
            $outcome = $anomalyReason ? RfidIngestResult::ANOMALY : RfidIngestResult::RECORDED;

            $scan->forceFill([
                'resolved_event_type' => $eventType,
                'scan_direction' => $eventType === 'EXIT' ? 'exit' : 'entry',
                'resulting_state' => $transition['resulting_state'],
                'vehicle_crossing_id' => $crossing?->id,
                'fusion_status' => $fusionStatus,
                'fusion_note' => $note,
                'is_anomaly' => $anomalyReason !== null,
                'anomaly_reason' => $anomalyReason,
                'outcome' => $outcome,
            ])->save();

            $crossing?->forceFill(['rfid_scan_log_id' => $scan->id])->save();

            $event = $this->eventService->createFromRfidScan($scan, $transition);
            $scan->forceFill(['correlated_vehicle_event_id' => $event?->id])->save();

            return $outcome;
        });
    }

    /**
     * Update the vehicle's state and today's counters.
     *
     * @return array{event_type: string, resulting_state: string, daily_entries_count: int, daily_exits_count: int}
     */
    protected function moveVehicle(Vehicle $vehicle, string $eventType, Carbon $eventTime): array
    {
        $date = PhilippineTime::localDateString($eventTime);

        if ($vehicle->daily_count_date?->toDateString() !== $date) {
            $vehicle->fill([
                'daily_count_date' => $date,
                'entries_today_count' => 0,
                'exits_today_count' => 0,
                'first_entry_today_at' => null,
                'last_exit_today_at' => null,
            ]);
        }

        $updates = [
            'current_state' => $eventType === 'ENTRY' ? Vehicle::STATE_INSIDE : Vehicle::STATE_OUTSIDE,
            'last_seen_at' => $eventTime,
            'daily_count_date' => $date,
        ];

        if ($eventType === 'ENTRY') {
            $updates['entries_today_count'] = ((int) $vehicle->entries_today_count) + 1;
            $updates['last_entry_at'] = $eventTime;
            $updates['first_entry_today_at'] = $vehicle->first_entry_today_at ?: $eventTime;
        } else {
            $updates['exits_today_count'] = ((int) $vehicle->exits_today_count) + 1;
            $updates['last_exit_at'] = $eventTime;
            $updates['last_exit_today_at'] = $eventTime;
        }

        $vehicle->forceFill($updates)->save();

        return [
            'event_type' => $eventType,
            'resulting_state' => $vehicle->current_state,
            'daily_entries_count' => (int) $vehicle->entries_today_count,
            'daily_exits_count' => (int) $vehicle->exits_today_count,
        ];
    }
}
