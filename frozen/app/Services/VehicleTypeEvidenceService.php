<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\VehicleTypeCorrection;
use App\Models\VisitorRecord;
use App\Support\VehicleType;

/** Occurrence snapshots in existing JSON fields; raw detector evidence stays separate. */
class VehicleTypeEvidenceService
{
    public const KEY = '_application_vehicle_type';

    public static function detectorMetadata(mixed $raw, ?array $metadata = null): array
    {
        return array_replace($metadata ?? [], [self::KEY => ['source' => 'detector', 'raw' => $raw]]);
    }

    public static function manualMetadata(?array $metadata, ?string $before, ?string $after): array
    {
        $metadata ??= [];
        if ($before !== $after) {
            $evidence = (array) ($metadata[self::KEY] ?? []);
            $evidence['source'] = 'staff';
            $evidence['corrections'][] = ['before' => $before, 'after' => $after, 'by' => auth()->id(), 'at' => now()->toIso8601String()];
            $metadata[self::KEY] = $evidence;
        }

        return $metadata;
    }

    public function mergeOcrDetails(VisitorRecord $record, ?array $incoming): array
    {
        $incoming ??= [];
        unset($incoming[self::KEY], $incoming[PlateEvidenceService::KEY]);
        if ($plate = data_get($record->ocr_details_json, PlateEvidenceService::KEY)) {
            $incoming[PlateEvidenceService::KEY] = $plate;
        }
        if ($existing = data_get($record->ocr_details_json, self::KEY)) {
            $incoming[self::KEY] = $existing;
        }

        return $incoming;
    }

    public function resolve(VisitorRecord $record, mixed $raw = null, bool $provided = false): void
    {
        $previous = (array) data_get($record->ocr_details_json, self::KEY, []);
        $raw = $provided ? $raw : (array_key_exists('raw', $previous) ? $previous['raw']
            : ($record->source === VisitorRecord::SOURCE_MANUAL ? null : ($record->crossing?->vehicle_type ?? $record->vehicle_type)));
        $evidence = array_replace($previous, ['raw' => $raw, 'detected' => VehicleType::detector($raw)]);
        $rfidEvent = $record->crossing?->rfidScanLog?->correlatedVehicleEvent;
        $identifiedVehicleId = $record->isRegisteredAtOccurrence() ? $record->vehicle_id :
            ($rfidEvent?->event_status === \App\Models\VehicleEvent::STATUS_COMPLETED ? $rfidEvent->vehicle_id : null);
        $correction = $record->exists ? VehicleTypeCorrection::query()->where('visitor_record_id', $record->id)->latest('id')->first() : null;
        if ($correction) {
            $type = $correction->corrected_type;
            $evidence['source'] = 'staff';
        } elseif ($record->source === VisitorRecord::SOURCE_MANUAL && filled($record->vehicle_type)
            && in_array($previous['source'] ?? null, [null, 'manual'], true)) {
            $type = $record->vehicle_type;
            $evidence['source'] = 'manual';
        } elseif ($identifiedVehicleId) {
            $event = $record->movementEvent ?? $rfidEvent;
            if (($previous['source'] ?? null) === 'registry' && (int) ($previous['vehicle_id'] ?? 0) === (int) $identifiedVehicleId) {
                $type = $previous['registry'];
            } elseif ($event && (int) $event->vehicle_id === (int) $identifiedVehicleId && filled($event->vehicle_type)
                && data_get($event->detection_metadata_json, self::KEY.'.source') !== 'detector') {
                $type = $event->vehicle_type;
            } else {
                $type = Vehicle::query()->find($identifiedVehicleId)?->vehicle_type;
            }
            $evidence = array_replace($evidence, ['source' => 'registry', 'registry' => $type, 'vehicle_id' => $identifiedVehicleId]);
            if (blank($type)) {
                $type = $evidence['detected'];
                $evidence['source'] = 'detector';
            }
        } elseif ($record->source === VisitorRecord::SOURCE_MANUAL) {
            $type = VehicleType::validated($record->vehicle_type, $record->vehicle_type, required: false);
            $evidence['source'] = 'manual';
        } else {
            $type = $evidence['detected'];
            $evidence['source'] = 'detector';
        }
        $record->vehicle_type = $type;
        $record->ocr_details_json = array_replace((array) $record->ocr_details_json, [self::KEY => $evidence]);
    }

    public function hasOcrDetails(VisitorRecord $record): bool
    {
        return count(\Illuminate\Support\Arr::except((array) $record->ocr_details_json, [self::KEY, PlateEvidenceService::KEY])) > 0;
    }

    /** Type-only update: no occupancy projection, new event or history transfer. */
    public function syncEvent(VisitorRecord $record): void
    {
        $event = $record->movementEvent ?? $record->crossing?->rfidScanLog?->correlatedVehicleEvent;
        if (! $event || ($event->rfid_scan_log_id && $record->registration_status !== 'registered' && ! $record->typeCorrections()->exists())) {
            return;
        }
        if (! $event->occupancy_managed && ! $record->typeCorrections()->exists()) {
            return; // Legacy evidence is not relabelled by background processing.
        }
        $evidence = (array) data_get($record->ocr_details_json, self::KEY, []);
        $event->forceFill([
            'vehicle_type' => $record->vehicle_type,
            'detected_vehicle_type' => $evidence['raw'] ?? $event->detected_vehicle_type,
            'detection_metadata_json' => array_replace((array) $event->detection_metadata_json, [self::KEY => $evidence]),
        ])->save();
    }

    public static function sourceLabel(?string $source): string
    {
        return match ($source) {
            'registry' => 'Registry record', 'staff' => 'Staff confirmed', 'manual' => 'Manual entry',
            'detector' => 'Detector', default => 'Historical record',
        };
    }
}
