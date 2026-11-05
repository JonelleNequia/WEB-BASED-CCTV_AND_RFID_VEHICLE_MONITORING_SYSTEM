<?php

namespace App\Services;

use App\Models\Gate;
use App\Models\RfidScanLog;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Support\DirectionSource;
use App\Support\RfidIngestResult;
use Carbon\Carbon;

/**
 * Phase 3 (visitor model): a registered tag read takes its direction from the
 * camera.
 *
 * - The UHF reader reads a tag while the vehicle approaches, so a read from
 *   up to `rfid_lookback_seconds` (10) before the crossing, or up to
 *   `rfid_lookahead_seconds` (2) after it, belongs to that crossing.
 * - Camera IN / OUT sets the vehicle's state. A direction that does not fit
 *   the state (IN while already inside) is still recorded, and flagged.
 * - A camera crossing without a known direction stays UNKNOWN for staff
 *   Review and never toggles occupancy.
 * - No camera at the gate, or camera offline: the vehicle's state decides
 *   too (OUTSIDE -> IN, INSIDE -> OUT).
 * - Every record keeps direction_source (camera / state / manual) and, for
 *   the camera, its confidence (high / medium).
 * - The camera was watching but no crossing came: "scan only", no movement.
 *
 * RFID only with a vehicle: reads are no longer saved while the camera is
 * watching (RfidTagMatcher keeps them; RfidIngestService::forCrossing() makes
 * the record), so nothing new waits "pending". finalizeExpired() still runs
 * on every request (FinalizePendingScans) and from `rfid:finalize-pending`
 * for reads saved before that.
 */
class RfidCameraFusionService
{
    public const DEFAULT_LOOKBACK_SECONDS = 10;

    public const DEFAULT_LOOKAHEAD_SECONDS = 2;

    /** After its window ends, the detector needs a moment to send the crossing. */
    public const CROSSING_DELIVERY_SECONDS = 6;

    public function __construct(
        protected SettingsService $settingsService,
        protected DetectorRuntimeService $detectorRuntimeService,
        protected EventService $eventService,
        protected VisitorRecordService $visitorRecordService
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
     * RFID only with a vehicle: how long this gate's camera has not been
     * watching (null = it is watching). INF when it never was, or the
     * detector does not say since when.
     */
    public function cameraOfflineSeconds(string $gate): ?float
    {
        if ($this->cameraProblem($gate) === null) {
            return null;
        }

        $since = $this->detectorRuntimeService->readStatus()['cameras'][$gate]['offline_since'] ?? null;

        try {
            return $since ? max(0.0, (float) Carbon::parse($since)->diffInMilliseconds(now(), false) / 1000) : INF;
        } catch (\Throwable) {
            return INF;
        }
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
            return $this->applyMovement($scan, null, 'RFID only ('.$problem.'): IN/OUT from the vehicle\'s state.');
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
        return app(PlateProcessingLock::class)->run(function () use ($crossing): ?RfidScanLog {
            $crossing = VehicleCrossing::query()->lockForUpdate()->find($crossing->id);

            if (! $crossing || $crossing->rfid_scan_log_id) {
                return null;
            }

            $scan = $this->scanForCrossing($crossing);

            if (! $scan) {
                return null;
            }

            if ($this->applyMovement($scan, $crossing) === RfidIngestResult::IGNORED) {
                return null;
            }

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
            app(PlateProcessingLock::class)->run(function () use ($id): void {
                $scan = RfidScanLog::query()->lockForUpdate()->find($id);

                if (! $scan || $scan->fusion_status !== RfidScanLog::FUSION_PENDING) {
                    return;
                }

                $problem = $this->cameraProblem($scan->scan_location);

                if ($problem !== null) {
                    if ($this->applyMovement($scan, null, 'Camera gave no direction ('.$problem.'); vehicle state used.') === RfidIngestResult::IGNORED) {
                        // Retain an already stored legacy read, but stop retrying
                        // it after loss/replacement/inactivation. No new record.
                        $scan->forceFill(['fusion_status' => RfidScanLog::FUSION_SCAN_ONLY,
                            'outcome' => RfidIngestResult::IGNORED,
                            'fusion_note' => 'Stored read is no longer eligible; ignored without a movement.'])->save();
                    }

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
     * is one (a direction that does not fit the state still wins, updates
     * the state and is flagged); otherwise the vehicle's state (the camera
     * could not tell, or there was no crossing). Returns RECORDED or ANOMALY.
     */
    public function applyMovement(RfidScanLog $scan, ?VehicleCrossing $crossing, ?string $toggleNote = null): string
    {
        return app(PlateProcessingLock::class)->run(function () use ($scan, $crossing, $toggleNote): string {
            $scan = $scan->fresh();
            $tag = \App\Models\RfidTag::findUid($scan->tag_uid);
            if (\App\Models\RfidTag::isUnavailableUid($scan->tag_uid)
                || ! $tag || $tag->status !== \App\Models\RfidTag::STATUS_ASSIGNED || $tag->vehicle?->status !== 'active'
                || ($tag->vehicle?->rfid_tag_id && (int) $tag->vehicle->rfid_tag_id !== (int) $tag->id)
                || (int) $tag->vehicle_id !== (int) $scan->vehicle_id) {
                return RfidIngestResult::IGNORED;
            }
            if ($scan->correlatedVehicleEvent?->event_status === VehicleEvent::STATUS_COMPLETED
                && $scan->resolved_event_type !== null) {
                return (string) ($scan->outcome ?: RfidIngestResult::RECORDED);
            }
            $vehicle = Vehicle::query()->whereKey($scan->vehicle_id)->lockForUpdate()->firstOrFail();
            $crossing = $crossing?->fresh();
            $record = $crossing ? \App\Models\VisitorRecord::query()->where('vehicle_crossing_id', $crossing->id)->first() : null;
            $existing = $record ? app(CanonicalMovementEventService::class)->resolve($record) : null;
            $projector = app(MovementReconciliationService::class);
            $projector->begin($vehicle);
            if ($existing?->vehicle_id && (int) $existing->vehicle_id !== (int) $vehicle->id && (int) $record->vehicle_id !== (int) $vehicle->id) {
                // Two identities for one occurrence: keep the evidence and use
                // existing Review, never move either vehicle on a guess.
                $oldVehicleId = $existing->vehicle_id;
                $existing->forceFill(['event_status' => VehicleEvent::STATUS_PENDING_DETAILS, 'rfid_scan_log_id' => $scan->id])->save();
                $record->forceFill(['status' => \App\Models\VisitorRecord::STATUS_ACTIVE, 'registration_status' => 'review',
                    'registration_reason' => 'Plate evidence conflicts with the linked RFID identity. Check the plate; RFID identity is retained.'])->save();
                $scan->forceFill(['vehicle_crossing_id' => $crossing->id, 'correlated_vehicle_event_id' => $existing->id,
                    'fusion_status' => RfidScanLog::FUSION_SCAN_ONLY, 'is_anomaly' => true,
                    'anomaly_reason' => 'RFID and plate identify different vehicles; check Review.', 'outcome' => RfidIngestResult::ANOMALY])->save();
                $crossing->forceFill(['rfid_scan_log_id' => $scan->id])->save();
                $projector->reconcile((int) $oldVehicleId);

                return RfidIngestResult::ANOMALY;
            }
            $time = $crossing?->crossed_at ?? $scan->scan_time;
            $inside = strtoupper((string) $vehicle->current_state) === Vehicle::STATE_INSIDE;
            $direction = $crossing ? (in_array($crossing->direction, ['IN', 'OUT'], true) ? $crossing->direction : 'UNKNOWN')
                : ($vehicle->last_seen_at && $time->lt($vehicle->last_seen_at) ? 'UNKNOWN' : ($inside ? 'OUT' : 'IN'));
            $eventType = VehicleEvent::eventTypeForDirection($direction);
            $source = $crossing ? ($direction === 'UNKNOWN' ? null : DirectionSource::CAMERA) : DirectionSource::STATE;
            $alreadyApplied = $existing && $existing->occupancy_managed
                && $existing->event_status === VehicleEvent::STATUS_COMPLETED && (int) $existing->vehicle_id === (int) $vehicle->id;
            $anomaly = ! $alreadyApplied && (($direction === 'IN' && $inside) || ($direction === 'OUT' && ! $inside))
                ? "Camera saw {$vehicle->plate_number} go {$direction}, but it was already ".($inside ? 'inside (an OUT was missed).' : 'outside (an IN was missed).') : null;
            $transition = ['detected_vehicle_type' => $crossing?->vehicle_type, 'event_type' => $eventType, 'event_time' => $time, 'resulting_state' => $vehicle->current_state,
                'anomaly_reason' => $anomaly, 'direction_source' => $source,
                'direction_confidence' => $source === DirectionSource::CAMERA ? DirectionSource::confidence(data_get($crossing?->detection_metadata_json, 'direction_confidence')) : null];
            $scan->forceFill(['resolved_event_type' => $eventType, 'scan_direction' => $direction === 'OUT' ? 'exit' : 'entry',
                'vehicle_crossing_id' => $crossing?->id, 'fusion_status' => $crossing ? RfidScanLog::FUSION_CAMERA : RfidScanLog::FUSION_TOGGLE,
                'fusion_note' => $crossing ? 'Camera direction '.$direction.'.' : ($toggleNote ?? 'RFID-only direction from vehicle state.'),
                'is_anomaly' => $anomaly !== null, 'anomaly_reason' => $anomaly,
                'outcome' => $anomaly ? RfidIngestResult::ANOMALY : RfidIngestResult::RECORDED])->save();
            if ($existing) {
                $oldVehicleId = $existing->vehicle_id;
                $existing->forceFill(['vehicle_id' => $vehicle->id, 'vehicle_category' => $vehicle->category,
                    'plate_text' => $record->plate_number, 'plate_number' => $record->plate_number,
                    'event_origin' => $record->reviewed_at !== null ? 'plate_review' : ($existing->event_origin === 'guest_cctv' ? 'plate_ocr' : $existing->event_origin),
                    'event_status' => VehicleEvent::STATUS_COMPLETED, 'rfid_scan_log_id' => $scan->id,
                    'event_type' => $eventType, 'direction' => $direction, 'direction_source' => $source,
                    'anomaly_reason' => $anomaly, 'occupancy_managed' => true])->save();
                $event = $this->eventService->applyRfidSessionState($existing);
                app(VehicleTypeEvidenceService::class)->syncEvent($record);
                if ($oldVehicleId && (int) $oldVehicleId !== (int) $vehicle->id) {
                    $projector->reconcile((int) $oldVehicleId);
                }
            } else {
                $event = $this->eventService->createFromRfidScan($scan, $transition);
                $event->forceFill(['occupancy_managed' => true, 'visitor_record_id' => $record?->id,
                    'vehicle_image_path' => $event->vehicle_image_path ?: $record?->snapshot_path,
                    'plate_image_path' => $event->plate_image_path ?: $record?->plate_image_path])->save();
            }
            $scan->forceFill(['correlated_vehicle_event_id' => $event->id])->save();
            $crossing?->forceFill(['rfid_scan_log_id' => $scan->id])->save();
            if ($crossing) {
                $this->visitorRecordService->dismissForCrossing($crossing, 'Registered tag read for '.$vehicle->plate_number.'.');
            }
            $projector->reconcile((int) $vehicle->id);
            if ($crossing) {
                app(CrossingSnapshotService::class)->synchronize($crossing->fresh());
            }

            return (string) $scan->outcome;
        });
    }
}
