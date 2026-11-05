<?php

namespace App\Services;

use App\Exceptions\OccurrenceConflict;
use App\Models\Gate;
use App\Models\RfidScanLog;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use Illuminate\Support\Facades\DB;

/** Resolve occurrence evidence, not plate similarity. Call under PlateProcessingLock. */
class CanonicalMovementEventService
{
    public function resolve(VisitorRecord $record): ?VehicleEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Occurrence resolution requires the shared identity transaction.');
        }
        $crossing = $record->crossing;
        if ($crossing && $crossing->external_event_key !== $record->external_event_key) {
            throw new OccurrenceConflict('Record and crossing have different external keys.');
        }
        $scan = $crossing?->rfidScanLog;
        $events = VehicleEvent::query()->where(function ($query) use ($record, $scan): void {
            $query->whereRaw('1 = 0');
            if ($record->exists) {
                $query->orWhere('visitor_record_id', $record->id);
            }
            if (filled($record->external_event_key)) {
                $query->orWhere('external_event_key', $record->external_event_key);
            }
            if ($scan) {
                $query->orWhere('rfid_scan_log_id', $scan->id);
                if ($scan->correlated_vehicle_event_id) {
                    $query->orWhere('id', $scan->correlated_vehicle_event_id);
                }
            }
        })->lockForUpdate()->get();
        if ($events->count() > 1) {
            throw new OccurrenceConflict('Occurrence relationships resolve to multiple existing events.');
        }
        $event = $events->first();
        if (! $event) {
            return null;
        }
        if (($event->visitor_record_id && (int) $event->visitor_record_id !== (int) $record->id)
            || (filled($event->external_event_key) && $event->external_event_key !== $record->external_event_key)) {
            throw new OccurrenceConflict('Existing event key or record relationship belongs to another occurrence.');
        }
        // A canonical record link is authoritative. Unlinked historical rows
        // need independent crossing evidence before a missing link is restored.
        if (! $event->visitor_record_id) {
            if ($event->rfid_scan_log_id) {
                $eventScan = RfidScanLog::query()->find($event->rfid_scan_log_id);
                $sameCrossing = $crossing && $eventScan
                    && ((int) $crossing->rfid_scan_log_id === (int) $eventScan->id
                        || (int) $eventScan->vehicle_crossing_id === (int) $crossing->id);
                if (! $sameCrossing || Gate::resolveCode($eventScan->scan_location, historical: true) !== Gate::resolveCode($record->gate, historical: true)
                    || ($eventScan->correlated_vehicle_event_id && (int) $eventScan->correlated_vehicle_event_id !== (int) $event->id)
                    || (filled($eventScan->detector_event_key) && $eventScan->detector_event_key !== $record->external_event_key)
                    || ($crossing->rfid_scan_log_id && (int) $crossing->rfid_scan_log_id !== (int) $eventScan->id)) {
                    throw new OccurrenceConflict('Existing RFID event is not authoritatively linked to this crossing.');
                }
                if (! $crossing->rfid_scan_log_id) {
                    $crossing->forceFill(['rfid_scan_log_id' => $eventScan->id])->save();
                    $record->unsetRelation('crossing');
                }
            } else {
                $sameCamera = $event->camera_id && $record->camera_id && (int) $event->camera_id === (int) $record->camera_id;
                $sameTime = $event->event_time && $record->seen_at && $event->event_time->equalTo($record->seen_at);
                $sameGate = Gate::resolveCode($event->camera?->camera_role, historical: true) === Gate::resolveCode($record->gate, historical: true);
                $eventDirection = VehicleEvent::directionForEventType($event->event_type);
                $sameDirection = $eventDirection === $record->direction;
                $plateOrigin = in_array($event->event_origin, ['guest_cctv', 'plate_ocr', 'plate_review'], true);
                if (! $sameCamera || ! $sameTime || ! $sameGate || ! $sameDirection || ! $plateOrigin
                    || ($event->vehicle_id && ! $event->occupancy_managed)) {
                    throw new OccurrenceConflict('Key-only event has incompatible camera, gate, time, direction, origin or unmanaged registered movement.');
                }
            }
            if ($record->exists) {
                $event->forceFill(['visitor_record_id' => $record->id])->save();
            }
        }

        return $event;
    }
}
