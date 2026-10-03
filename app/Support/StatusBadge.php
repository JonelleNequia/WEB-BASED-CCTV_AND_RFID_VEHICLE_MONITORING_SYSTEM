<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * UI Phase 1: one status → badge look for the whole system.
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
            'lost', 'anomaly', 'overstay', 'alert', 'denied', 'no_pass', 'no_pass_alert',
            'unmatched', 'pass_alert', 'guest_pass_lost', 'guest_pass_disabled', 'guest_pass_not_issued',
            'unauthorized', 'failed', 'error', 'offline',
        ],
        'warning' => [
            'pending', 'pending_details', 'pending_review', 'manual_review', 'requires_manual_review',
            'review', 'needs_review', 'issue_required', 'guest_pass_available', 'stale', 'standby',
        ],
        'success' => [
            'inside', 'active', 'assigned', 'verified', 'registered', 'matched', 'completed',
            'resolved', 'no_pass_resolved', 'online', 'connected', 'ready', 'recorded',
        ],
        'brand' => [
            'issued', 'guest_pass', 'guest_pass_entry', 'guest_pass_exit', 'guest', 'guest_visit',
        ],
        'info' => [
            'entry', 'open', 'in',
        ],
        'neutral' => [
            'outside', 'available', 'inactive', 'disabled', 'exit', 'out', 'closed', 'archived',
            'duplicate', 'ignored', 'guest_pass_duplicate', 'unknown', 'none', 'no_tag', 'reviewed',
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
