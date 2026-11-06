<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Support\PhilippineTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Reconcile explicitly processed occurrences, never replay an installation's history. */
class MovementReconciliationService
{
    private const FIELDS = ['current_state', 'last_seen_at', 'daily_count_date', 'entries_today_count',
        'exits_today_count', 'first_entry_today_at', 'last_exit_today_at', 'last_entry_at', 'last_exit_at'];

    public function begin(Vehicle $vehicle): void
    {
        if (! DB::table('vehicle_occupancy_baselines')->where('vehicle_id', $vehicle->id)->exists()) {
            $vehicle = $vehicle->fresh();
            DB::table('vehicle_occupancy_baselines')->insert([
                'vehicle_id' => $vehicle->id,
                'snapshot_json' => json_encode(array_intersect_key($vehicle->getRawOriginal(), array_flip(self::FIELDS)), JSON_THROW_ON_ERROR),
            ]);
        }
    }

    /** Called within the shared identity transaction after OCR/review/direction changes. */
    public function syncPlate(VisitorRecord $record): ?VehicleEvent
    {
        return app(PlateProcessingLock::class)->run(function () use ($record): ?VehicleEvent {
            $record = $record->fresh(['crossing', 'vehicle']);
            app(VehicleTypeEvidenceService::class)->resolve($record);
            if ($record->isDirty()) $record->save();
            $event = app(CanonicalMovementEventService::class)->resolve($record);
            if ($event?->rfid_scan_log_id && $event->event_status === VehicleEvent::STATUS_PENDING_DETAILS
                && $record->registration_status === 'registered'
                && (int) $record->vehicle_id === (int) $record->crossing?->rfidScanLog?->vehicle_id) {
                app(RfidCameraFusionService::class)->applyMovement($record->crossing->rfidScanLog, $record->crossing);

                return $event->fresh();
            }
            if ($event?->rfid_scan_log_id || $record->crossing?->rfid_scan_log_id) {
                app(VehicleTypeEvidenceService::class)->syncEvent($record);
                return $event; // RFID identity is never replaced by plate correction.
            }
            $oldVehicleId = $event?->vehicle_id;
            $eligible = $record->status === VisitorRecord::STATUS_ACTIVE && $record->isRegisteredAtOccurrence() && $record->vehicle;
            if (! $eligible) {
                if ($event) {
                    $event->forceFill(['event_status' => VehicleEvent::STATUS_PENDING_DETAILS,
                        'plate_text' => $record->plate_number, 'plate_number' => \App\Support\PlateNumber::storageKey($record->plate_number),
                        'vehicle_id' => null, 'vehicle_category' => $record->classificationCategory()])->save();
                    if ($oldVehicleId) {
                        $this->reconcile((int) $oldVehicleId);
                    }
                }

                return $event;
            }
            $this->begin($record->vehicle);
            // The legacy Guest writer uses this classification marker in the
            // anomaly column. It is not a movement anomaly, and must not become
            // one when this same occurrence is promoted to a registered plate.
            // Preserve all genuine reasons and the separate RFID path above.
            $anomalyReason = $event?->anomaly_reason;
            if ($event?->event_origin === 'guest_cctv'
                && $event->match_status === VehicleEvent::MATCH_NO_PASS_ALERT
                && $anomalyReason === 'Unregistered guest') {
                $anomalyReason = null;
            }
            $event ??= new VehicleEvent(['visitor_record_id' => $record->id, 'external_event_key' => $record->external_event_key]);
            $event->forceFill([
                'occupancy_managed' => true, 'vehicle_id' => $record->vehicle_id,
                'event_type' => VehicleEvent::eventTypeForDirection($record->direction),
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'event_origin' => $record->reviewed_at !== null || $record->source === VisitorRecord::SOURCE_MANUAL ? 'plate_review' : 'plate_ocr',
                'plate_text' => $record->plate_number, 'plate_number' => \App\Support\PlateNumber::storageKey($record->plate_number),
                'plate_confidence' => $record->plate_confidence, 'vehicle_category' => $record->classificationCategory(),
                'vehicle_type' => $record->vehicle_type,
                'detected_vehicle_type' => data_get($record->ocr_details_json, VehicleTypeEvidenceService::KEY.'.raw'),
                'detection_metadata_json' => array_replace((array) $event->detection_metadata_json, [VehicleTypeEvidenceService::KEY => data_get($record->ocr_details_json, VehicleTypeEvidenceService::KEY)]),
                'camera_id' => $record->camera_id, 'event_time' => $record->seen_at,
                'camera_device_id' => $record->camera_device_id, 'camera_device_label' => $record->camera_device_label,
                'vehicle_image_path' => $event->vehicle_image_path ?: $record->snapshot_path, 'plate_image_path' => $event->plate_image_path ?: $record->plate_image_path,
                'direction' => $record->direction, 'direction_source' => $record->direction_source,
                'direction_confidence' => $record->direction_confidence,
                'anomaly_reason' => $anomalyReason,
                'match_status' => $record->direction === 'IN' ? 'open' : ($record->direction === 'OUT' ? 'closed' : 'manual_review'),
                'details_completed_at' => $event->details_completed_at ?: now(),
            ])->save();
            if ($oldVehicleId && (int) $oldVehicleId !== (int) $record->vehicle_id) {
                $this->reconcile((int) $oldVehicleId);
            }
            $this->reconcile((int) $record->vehicle_id);

            return $event->fresh();
        });
    }

    public function reconcile(int $vehicleId): Vehicle
    {
        return app(PlateProcessingLock::class)->run(function () use ($vehicleId): Vehicle {
            $vehicle = Vehicle::query()->whereKey($vehicleId)->lockForUpdate()->firstOrFail();
            $this->begin($vehicle);
            $base = json_decode(DB::table('vehicle_occupancy_baselines')->where('vehicle_id', $vehicleId)->value('snapshot_json'), true, flags: JSON_THROW_ON_ERROR);
            $events = VehicleEvent::query()->where('vehicle_id', $vehicleId)->where('occupancy_managed', true)
                ->where('event_status', VehicleEvent::STATUS_COMPLETED)->orderBy('event_time')->orderBy('id')->get();
            $state = $base['current_state'] ?: Vehicle::STATE_OUTSIDE;
            $anchor = ! empty($base['last_seen_at']) ? Carbon::parse($base['last_seen_at']) : null;
            $lastSeen = $anchor;
            $daily = [];
            $baseDate = ! empty($base['daily_count_date']) ? Carbon::parse($base['daily_count_date'])->toDateString() : null;
            if ($baseDate) {
                $daily[$baseDate] = ['in' => (int) $base['entries_today_count'], 'out' => (int) $base['exits_today_count'],
                    'first' => $base['first_entry_today_at'], 'last' => $base['last_exit_today_at']];
            }
            $lastEntry = $base['last_entry_at'];
            $lastExit = $base['last_exit_at'];
            foreach ($events as $event) {
                $at = $event->event_time;
                if (! $at) {
                    continue;
                }
                if ($lastSeen === null || $at->gt($lastSeen)) {
                    $lastSeen = $at;
                }
                if (! in_array($event->event_type, ['ENTRY', 'EXIT'], true)) {
                    continue; // An unknown direction is an observation, not a toggle.
                }
                $inside = $event->event_type === 'ENTRY';
                $after = $inside ? Vehicle::STATE_INSIDE : Vehicle::STATE_OUTSIDE;
                if ($anchor === null || $at->gte($anchor)) {
                    $state = $after;
                }
                $date = PhilippineTime::localDateString($at);
                $daily[$date] ??= ['in' => 0, 'out' => 0, 'first' => null, 'last' => null];
                $daily[$date][$inside ? 'in' : 'out']++;
                if ($inside) {
                    $daily[$date]['first'] = $this->earlier($daily[$date]['first'], $at);
                    $lastEntry = $this->later($lastEntry, $at);
                } else {
                    $daily[$date]['last'] = $this->later($daily[$date]['last'], $at);
                    $lastExit = $this->later($lastExit, $at);
                }
                $event->forceFill([
                    'resulting_state' => $after,
                    // Pre-baseline historical counter values cannot be reconstructed honestly.
                    'daily_entries_count' => $anchor && $at->lt($anchor) ? null : $daily[$date]['in'],
                    'daily_exits_count' => $anchor && $at->lt($anchor) ? null : $daily[$date]['out'],
                ]);
                if ($event->isDirty()) {
                    $event->save();
                }
                if ($event->rfid_scan_log_id) {
                    \App\Models\RfidScanLog::query()->whereKey($event->rfid_scan_log_id)->update(['resulting_state' => $after]);
                }
            }
            ksort($daily);
            $date = array_key_last($daily);
            $totals = $date !== null ? $daily[$date] : ['in' => 0, 'out' => 0, 'first' => null, 'last' => null];
            $vehicle->forceFill([
                'current_state' => $state, 'last_seen_at' => $lastSeen,
                'daily_count_date' => $date, 'entries_today_count' => $totals['in'], 'exits_today_count' => $totals['out'],
                'first_entry_today_at' => $totals['first'], 'last_exit_today_at' => $totals['last'],
                'last_entry_at' => $lastEntry, 'last_exit_at' => $lastExit,
            ])->save();
            $latest = $events->whereIn('event_type', ['ENTRY', 'EXIT'])->last();
            foreach ($events->where('event_type', 'ENTRY') as $entry) {
                $open = $state === Vehicle::STATE_INSIDE && $latest?->id === $entry->id
                    && ($anchor === null || $entry->event_time->gte($anchor));
                \App\Models\ActiveSession::query()->where('entry_event_id', $entry->id)->update(['status' => $open ? 'open' : 'closed']);
            }

            return $vehicle->fresh();
        });
    }

    private function earlier($left, $right): mixed
    {
        return $left === null || Carbon::parse($right)->lt(Carbon::parse($left)) ? $right : $left;
    }

    private function later($left, $right): mixed
    {
        return $left === null || Carbon::parse($right)->gt(Carbon::parse($left)) ? $right : $left;
    }
}
