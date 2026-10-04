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
     * A camera event keeps the direction the detector reported; an unknown
     * direction is stored as ENTRY (Phase 1) but is shown as unknown.
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
            default => '—',
        };

        return $log;
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
