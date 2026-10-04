<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * UI Phase 1: one status → badge look for the whole system.
 *
 * UI Phase 3 color rules: green = OK / registered, blue = IN / OUT movement,
 * gray = unregistered visitor (normal traffic), yellow = needs a look
 * (unknown tag, plate unreadable, direction unknown), red = a real problem
 * only (anomaly, lost tag, offline device).
 *
 * Tones differ in lightness and shape, not only color:
 * - critical: dark solid fill, white text, "!" mark (needs action)
 * - warning:  light amber fill, dark text, half-filled mark
 * - success:  light green fill, dark text, filled dot
 * - brand:    light purple fill, dark text, filled dot
 * - info:     light blue fill, dark text, filled dot
 * - neutral:  white with outline, grey text, hollow dot
 */
final class StatusBadge
{
    public const TONES = [
        'critical' => [
            'lost', 'anomaly', 'alert', 'denied', 'pass_alert', 'inactive_tag', 'inactive_vehicle',
            'unauthorized', 'failed', 'error', 'offline',
        ],
        'warning' => [
            'pending_details', 'pending_review', 'manual_review', 'requires_manual_review',
            'review', 'needs_review', 'stale', 'standby', 'unmatched',
            'unknown_tag', 'unassigned_tag', 'guest', 'non_recurring_category',
            'unreadable', 'plate_unreadable', 'direction_unknown', 'scan_only',
        ],
        'success' => [
            'inside', 'active', 'assigned', 'verified', 'registered', 'matched', 'completed',
            'resolved', 'no_pass_resolved', 'online', 'connected', 'ready', 'recorded', 'read', 'corrected',
        ],
        'brand' => [
            'issued',
        ],
        'info' => [
            'entry', 'open', 'in', 'exit', 'out', 'closed',
        ],
        'neutral' => [
            'outside', 'available', 'inactive', 'disabled', 'archived', 'pending', 'waiting',
            'duplicate', 'ignored', 'dismissed', 'unknown', 'none', 'no_tag', 'reviewed',
            'unregistered', 'unregistered_visitor', 'no_pass', 'no_pass_alert',
        ],
    ];

    /** Labels that read better than the raw status key. */
    public const LABELS = [
        'no_pass_alert' => 'Unregistered',
        'no_pass_resolved' => 'Resolved',
        'pending_details' => 'Pending details',
        'pending_review' => 'Needs review',
        'manual_review' => 'Manual review',
        'requires_manual_review' => 'Manual review',
        'issue_required' => 'Issue required',
        'no_tag' => 'No tag',
        'open' => 'Entry',
        'closed' => 'Exit',
    ];

    /**
     * UI Phase 3: tone of one kiosk / live-feed row (IN / OUT blue,
     * unregistered gray, unknown tag and scan only yellow, anomaly red).
     *
     * @param  array<string, mixed>  $log
     */
    public static function movementTone(array $log): string
    {
        $type = strtoupper((string) ($log['event_type'] ?? ''));

        return match (true) {
            ! empty($log['anomaly']) => 'critical',
            in_array($type, ['UNKNOWN TAG', 'SCAN ONLY'], true) => 'warning',
            $type === 'UNREGISTERED', ! empty($log['no_pass_alert']), ($log['verification_label'] ?? '') === 'Unregistered Visitor' => 'neutral',
            in_array($type, ['ENTRY', 'EXIT', 'IN', 'OUT'], true) => 'info',
            default => 'neutral',
        };
    }

    public static function key(?string $status): string
    {
        return Str::of((string) $status)->trim()->lower()->replace([' ', '-'], '_')->value();
    }

    public static function tone(?string $status): string
    {
        $key = self::key($status);

        foreach (self::TONES as $tone => $statuses) {
            if (in_array($key, $statuses, true)) {
                return $tone;
            }
        }

        return 'neutral';
    }

    public static function label(?string $status): string
    {
        $key = self::key($status);

        if ($key === '') {
            return '—';
        }

        return self::LABELS[$key] ?? Str::of($key)->replace('_', ' ')->ucfirst()->value();
    }
}
