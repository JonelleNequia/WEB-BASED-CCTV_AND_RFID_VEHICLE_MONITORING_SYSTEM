<?php

namespace App\Support;

use App\Models\VisitorRecord;

/** One presentation for Activity Logs, reports and monitoring feeds. */
final class VisitorRecordLog
{
    public static function fields(VisitorRecord $record): array
    {
        $time = $record->seen_at;
        $vehicle = $record->vehicle;
        // Option A (Phase 7): the plate of a registered vehicle, read without its tag.
        $plateOnly = $record->isRegisteredAtOccurrence();
        $category = $record->classificationCategory();
        $review = $category === 'review';

        return [
            'record_type' => 'visitor_record',
            'record_type_label' => $plateOnly ? 'Registered vehicle (plate only)' : ($review ? 'Review' : 'Unregistered Guest'),
            'id' => $record->id,
            'detail_url' => $record->plateProfile
                ? route('visitors.profiles.show', $record->plateProfile)
                : route('visitors.index', ['plate_status' => $record->plate_status]).'#visitor-record-'.$record->id,
            'export_url' => route('vehicle-events.export.csv', [
                'record_type' => 'visitor_record',
                'record_id' => $record->id,
            ]),
            'event_type' => match ($record->direction) {
                'IN' => 'ENTRY',
                'OUT' => 'EXIT',
                default => 'UNKNOWN',
            },
            'movement_label' => $record->direction === 'UNKNOWN' ? 'Direction unknown' : $record->direction,
            'set_direction_url' => $record->direction === 'UNKNOWN' && $record->status === VisitorRecord::STATUS_ACTIVE
                ? route('visitors.records.direction', $record) : null,
            'direction_source' => $record->direction_source,
            'direction_source_label' => self::directionLabel($record->direction_source, $record->direction_confidence),
            'plate_number' => $record->plateLabel(),
            'owner_name' => $vehicle?->vehicle_owner_name ?: 'N/A',
            'vehicle_type' => $record->display_vehicle_type,
            'vehicle_type_source' => $record->vehicle_type_source_label,
            'detected_vehicle_type' => data_get($record->ocr_details_json, \App\Services\VehicleTypeEvidenceService::KEY.'.raw'),
            'category' => $category,
            'category_label' => VehicleCategory::label($category),
            'source_label' => match (true) {
                $record->source === VisitorRecord::SOURCE_MANUAL => 'Manual · no registered tag',
                $plateOnly => 'Camera · plate only',
                $record->source === VisitorRecord::SOURCE_LEGACY => 'Camera · older guest record',
                default => 'Camera · no registered tag',
            },
            'station_label' => \App\Models\Gate::labelFor($record->gate),
            'state_label' => $plateOnly ? 'Not recorded for this occurrence' : 'Not tracked (guest)',
            'display_time' => DisplayTime::datetime($time, 'No time'),
            'summary_label' => ($plateOnly ? 'Registered vehicle' : ($review ? 'Review' : 'Unregistered Guest')).' #'.$record->id.' • '.(DisplayTime::datetime($time, 'No time')),
            'event_time_export' => $time?->toDateTimeString(),
            // UI Phase 3: what needs a look (yellow) or the plate result.
            ...match (true) {
                $review => ['status_label' => 'Review', 'status_badge_class' => 'manual-review'],
                $record->plate_status === VisitorRecord::PLATE_UNREADABLE => ['status_label' => 'Plate unreadable', 'status_badge_class' => 'manual-review'],
                $record->direction === 'UNKNOWN' => ['status_label' => 'Direction unknown', 'status_badge_class' => 'manual-review'],
                $plateOnly => ['status_label' => 'Plate identified', 'status_badge_class' => 'matched'],
                $record->plate_status === VisitorRecord::PLATE_PENDING => ['status_label' => 'Reading plate…', 'status_badge_class' => 'secondary'],
                $record->plate_status === VisitorRecord::PLATE_CORRECTED => ['status_label' => 'Plate corrected', 'status_badge_class' => 'matched'],
                default => ['status_label' => 'Plate read', 'status_badge_class' => 'matched'],
            },
            'match_label' => $plateOnly ? 'Registered vehicle (plate only)' : ($review ? 'Review' : 'Unregistered'),
            'rfid_tag_uid' => 'N/A',
            'image_url' => $record->snapshot_url,
            'image_status' => $record->snapshot_url ? 'Available'
                : (data_get($record->crossing?->detection_metadata_json, \App\Services\CrossingSnapshotService::KEY.'.status') === 'failed'
                    ? 'Unavailable (image upload failed)' : 'Unavailable'),
            // Unregistered visitors are normal traffic, not alerts.
            'is_alert' => false,
            'alert_reason' => $record->registration_reason,
            'sort_time' => (float) ($record->created_at?->format('U.u') ?? $time?->format('U.u') ?? 0),
            'log_type' => $plateOnly ? 'registered' : ($review ? 'review' : 'unregistered_visitor'),
            'log_type_label' => $plateOnly ? 'Registered' : ($review ? 'Review' : 'Unregistered Guest'),
            'is_guest' => ! $plateOnly && ! $review,
        ];
    }

    private static function directionLabel(?string $source, ?string $confidence): ?string
    {
        $label = DirectionSource::label($source);

        return $label && $source === DirectionSource::CAMERA && $confidence ? "{$label} ({$confidence})" : $label;
    }
}
