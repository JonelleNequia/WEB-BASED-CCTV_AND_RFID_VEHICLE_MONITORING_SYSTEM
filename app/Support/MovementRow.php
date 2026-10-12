<?php

namespace App\Support;

use App\Models\VehicleEvent;

/**
 * UI Phase 4 (layout): the short form of one gate log row used by the kiosk
 * and the Gate Monitor: plate, category, IN / OUT and time, plus its color.
 */
final class MovementRow
{
    public static function direction(mixed $value): ?string
    {
        return match (strtoupper(trim((string) $value))) {
            'ENTRY', 'IN' => 'IN',
            'EXIT', 'OUT' => 'OUT',
            default => null,
        };
    }

    /**
     * A camera event keeps the direction the detector reported (ROI work:
     * an unknown direction is stored as UNKNOWN, shown as unknown).
     */
    public static function eventDirection(VehicleEvent $event): ?string
    {
        $reported = data_get($event->detection_metadata_json, 'direction');

        return self::direction($reported ?? $event->event_type);
    }

    public static function eventCategory(VehicleEvent $event): string
    {
        if (in_array($event->event_origin, ['guest_cctv', 'guest_manual'], true)) {
            return VehicleCategory::label(VehicleCategory::UNREGISTERED_VISITOR);
        }

        $category = $event->vehicle_category ?: $event->vehicle?->category;

        return filled($category)
            ? VehicleCategory::label($category)
            : ($event->vehicle_id ? 'Registered' : VehicleCategory::label(VehicleCategory::UNREGISTERED_VISITOR));
    }

    /**
     * Color (UI Phase 3) and the short badge text: IN / OUT, or what the
     * row is when there is no direction (unknown tag, waiting for the camera).
     *
     * @param  array<string, mixed>  $log
     * @return array<string, mixed>
     */
    public static function finish(array $log): array
    {
        unset($log['sort_time']);
        $log['tone'] = StatusBadge::movementTone($log);
        $log['direction'] ??= null;
        $log['gate'] = ($log['camera_role'] ?? null) ?: ($log['scan_location'] ?? null);
        $log['direction_label'] = $log['direction'] ?? match (strtoupper((string) ($log['event_type'] ?? ''))) {
            'UNKNOWN TAG' => 'TAG?',
            'TAG ALERT' => 'ALERT',
            'WAITING' => 'WAIT',
            'SCAN ONLY' => 'TAG',
            'UNKNOWN' => 'IN/OUT?',
            default => '—',
        };
        // ROI work, Phase 3: the kiosk / Gate Monitor result once a pass is Recorded.
        [$log['result_title'], $log['result_detail']] = self::result($log);

        return $log;
    }

    /**
     * "Registered: ABC 1234" with the owner, or "Unregistered Visitor" with
     * the plate read so far; other rows (tag reads) keep plate and category.
     *
     * @param  array<string, mixed>  $log
     * @return array{0: string, 1: string}
     */
    public static function result(array $log): array
    {
        $plate = (string) ($log['plate_number'] ?? '');
        $hasPlate = $plate !== '' && $plate !== 'No plate';
        $owner = ($log['owner_name'] ?? 'N/A') !== 'N/A' ? (string) $log['owner_name'] : null;
        $unregistered = ($log['category_label'] ?? null) === VehicleCategory::label(VehicleCategory::UNREGISTERED_VISITOR)
            || strtoupper((string) ($log['event_type'] ?? '')) === 'UNREGISTERED';

        return match (true) {
            (bool) ($log['registered'] ?? false) => ['Registered: '.($hasPlate ? $plate : 'no plate'), implode(' · ', array_filter([$owner, $log['category_label'] ?? null]))],
            $unregistered => ['Unregistered Visitor', $hasPlate ? 'Plate '.$plate : 'Plate not read yet'],
            default => [$hasPlate ? $plate : '—', (string) ($log['category_label'] ?? '')],
        };
    }

    /**
     * Look of the kiosk / Gate Monitor big result for the latest row:
     * registered green, unregistered gray, needs a look yellow, problem red.
     *
     * @param  array<string, mixed>|null  $log
     */
    public static function resultLook(?array $log): string
    {
        return match ($log['tone'] ?? null) {
            null => 'idle',
            'critical' => 'alert',
            'warning' => 'unknown',
            'info' => 'verified',
            default => 'unregistered',
        };
    }
}
