<?php

namespace App\Services;

use App\Models\DirectionCorrection;
use App\Models\RfidScanLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Support\DirectionSource;
use App\Support\RfidIngestResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ROI work, Phase 2: "Set IN" / "Set OUT" on a record the camera could not
 * tell (Direction unknown), in one click.
 *
 * - Registered vehicle: the record becomes IN / OUT, the vehicle's state and
 *   today's counters change as if the camera had seen it (a direction that
 *   does not fit the state is flagged, like a camera one).
 * - Unregistered Visitor: the record becomes IN / OUT (counted from now on).
 *
 * The crossing gets the direction too, and every change is kept in
 * direction_corrections (who, when, what the camera had said).
 */
class DirectionCorrectionService
{
    public function __construct(
        protected RfidCameraFusionService $fusionService,
        protected EventService $eventService,
        protected VisitorRecordService $visitorRecordService
    ) {
    }

    public function setForEvent(VehicleEvent $event, string $direction, User $user): VehicleEvent
    {
        $direction = $this->direction($direction);

        return DB::transaction(function () use ($event, $direction, $user): VehicleEvent {
            $event = VehicleEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($event->event_type !== VehicleEvent::TYPE_UNKNOWN) {
                throw ValidationException::withMessages(['direction' => 'This record already has a direction.']);
            }

            $scan = $event->rfid_scan_log_id ? RfidScanLog::query()->find($event->rfid_scan_log_id) : null;
            $crossing = $scan?->vehicle_crossing_id ? VehicleCrossing::query()->find($scan->vehicle_crossing_id) : null;
            $eventType = $direction === 'IN' ? 'ENTRY' : 'EXIT';
            $anomalyReason = null;
            $transition = [];

            if ($event->vehicle_id) {
                $vehicle = Vehicle::query()->whereKey($event->vehicle_id)->lockForUpdate()->firstOrFail();
                $inside = strtoupper((string) $vehicle->current_state) === Vehicle::STATE_INSIDE;
                if ($eventType === 'ENTRY' && $inside) {
                    $anomalyReason = "{$vehicle->plate_number} set IN, but it was already inside (an OUT was missed).";
                } elseif ($eventType === 'EXIT' && ! $inside) {
                    $anomalyReason = "{$vehicle->plate_number} set OUT, but it was already outside (an IN was missed).";
                }
                $transition = $this->fusionService->moveVehicle($vehicle, $eventType, $event->event_time ?? now());
            }

            $this->remember($event->id, null, $crossing, $event->rfidScanLog?->scan_location ?? $crossing?->gate, $direction, $user);

            $event->forceFill([
                'event_type' => $eventType,
                'direction' => $direction,
                'direction_source' => DirectionSource::MANUAL,
                'match_status' => $eventType === 'ENTRY' ? 'open' : 'closed',
                'resulting_state' => $transition['resulting_state'] ?? $event->resulting_state,
                'daily_entries_count' => $transition['daily_entries_count'] ?? $event->daily_entries_count,
                'daily_exits_count' => $transition['daily_exits_count'] ?? $event->daily_exits_count,
                'anomaly_reason' => $anomalyReason,
            ])->save();
            $event = $this->eventService->applyRfidSessionState($event);

            $scan?->forceFill([
                'resolved_event_type' => $eventType,
                'scan_direction' => $eventType === 'EXIT' ? 'exit' : 'entry',
                'resulting_state' => $transition['resulting_state'] ?? $scan->resulting_state,
                'fusion_status' => RfidScanLog::FUSION_SET_BY_STAFF,
                'fusion_note' => "Set {$direction} by {$user->name}.",
                'is_anomaly' => $anomalyReason !== null,
                'anomaly_reason' => $anomalyReason,
                'outcome' => $anomalyReason ? RfidIngestResult::ANOMALY : RfidIngestResult::RECORDED,
            ])->save();
            $this->setCrossing($crossing, $direction, $user);

            return $event->fresh();
        });
    }

    public function setForVisitorRecord(VisitorRecord $record, string $direction, User $user): VisitorRecord
    {
        $direction = $this->direction($direction);

        return DB::transaction(function () use ($record, $direction, $user): VisitorRecord {
            $record = VisitorRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($record->direction !== 'UNKNOWN') {
                throw ValidationException::withMessages(['direction' => 'This record already has a direction.']);
            }

            $crossing = $record->vehicle_crossing_id ? VehicleCrossing::query()->find($record->vehicle_crossing_id) : null;
            $this->remember(null, $record->id, $crossing, $record->gate, $direction, $user);

            $record->forceFill(['direction' => $direction, 'direction_source' => DirectionSource::MANUAL])->save();
            $this->setCrossing($crossing, $direction, $user);
            // The hidden guest copy of the same pass (Dashboard, older lists) follows.
            VehicleEvent::query()
                ->where('external_event_key', $record->external_event_key)
                ->whereIn('event_origin', ['guest_cctv', 'guest_manual'])
                ->where('event_type', VehicleEvent::TYPE_UNKNOWN)
                ->update(['event_type' => $direction === 'IN' ? 'ENTRY' : 'EXIT', 'direction' => $direction, 'direction_source' => DirectionSource::MANUAL]);
            $this->visitorRecordService->refreshProfiles([$record->plate_profile_id]);

            return $record->fresh();
        });
    }

    protected function direction(string $direction): string
    {
        $direction = strtoupper(trim($direction));
        if (! in_array($direction, ['IN', 'OUT'], true)) {
            throw ValidationException::withMessages(['direction' => 'Choose IN or OUT.']);
        }

        return $direction;
    }

    protected function remember(?int $eventId, ?int $recordId, ?VehicleCrossing $crossing, ?string $gate, string $direction, User $user): void
    {
        DirectionCorrection::query()->create([
            'vehicle_event_id' => $eventId,
            'visitor_record_id' => $recordId,
            'vehicle_crossing_id' => $crossing?->id,
            'gate' => $gate,
            'detected_direction' => $crossing?->direction ?? 'UNKNOWN',
            'corrected_direction' => $direction,
            'detected_reason' => $crossing?->direction_reason ? mb_substr($crossing->direction_reason, 0, 160) : null,
            'corrected_by' => $user->id,
        ]);
    }

    protected function setCrossing(?VehicleCrossing $crossing, string $direction, User $user): void
    {
        $crossing?->forceFill([
            'direction' => $direction,
            'direction_reason' => mb_substr("Set {$direction} by {$user->name}", 0, 120),
        ])->save();
    }
}
