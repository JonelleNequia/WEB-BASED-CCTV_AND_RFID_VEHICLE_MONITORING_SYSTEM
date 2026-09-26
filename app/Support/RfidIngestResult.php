<?php

namespace App\Support;

use App\Models\GuestVisit;
use App\Models\RfidScanLog;

/**
 * Phase 3: what one RFID read did. Returned by RfidIngestService so every
 * caller (Station page, RFID Desk, hardware API, future UHF listener) reacts
 * the same way.
 */
final class RfidIngestResult
{
    /** A registered vehicle ENTRY or EXIT was recorded. */
    public const RECORDED = 'recorded';

    /** Same tag at the same station within the cooldown. Nothing new saved. */
    public const DUPLICATE = 'duplicate';

    /** An available guest pass was read at the Entrance: show the Issue form. */
    public const ISSUE_REQUIRED = 'issue_required';

    /** An issued guest pass was read at the Exit: visit closed, pass available. */
    public const GUEST_PASS_EXIT = 'guest_pass_exit';

    /** An issued guest pass was read again at the Entrance. */
    public const IGNORED = 'ignored';

    /** Recorded, but needs review (direction mismatch, pass not issued, ...). */
    public const ANOMALY = 'anomaly';

    /** Lost or disabled tag: shown as an alert on the dashboard. */
    public const ALERT = 'alert';

    /** Unknown tag or non-recurring vehicle: handled as a guest observation. */
    public const GUEST = 'guest';

    public function __construct(
        public readonly RfidScanLog $scanLog,
        public readonly string $outcome,
        public readonly string $message,
        public readonly ?GuestVisit $guestVisit = null,
    ) {
    }

    public function isDuplicate(): bool
    {
        return $this->outcome === self::DUPLICATE;
    }

    public function requiresIssue(): bool
    {
        return $this->outcome === self::ISSUE_REQUIRED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $tag = $this->scanLog->vehicleRfidTag;

        return [
            'outcome' => $this->outcome,
            'message' => $this->message,
            'duplicate_ignored' => $this->isDuplicate(),
            'requires_issue' => $this->requiresIssue(),
            'anomaly' => (bool) $this->scanLog->is_anomaly,
            'anomaly_reason' => $this->scanLog->anomaly_reason,
            'guest_pass' => $tag?->isGuestPass() ? [
                'id' => $tag->id,
                'display_number' => $tag->display_number,
                'label' => $tag->label,
                'status' => $tag->status,
            ] : null,
            'guest_visit' => $this->guestVisit ? [
                'id' => $this->guestVisit->id,
                'plate' => $this->guestVisit->plate,
                'driver_name' => $this->guestVisit->driver_name,
                'status' => $this->guestVisit->status,
                'id_presented' => $this->guestVisit->id_presented,
                'entry_at' => $this->guestVisit->entry_at?->toIso8601String(),
                'exit_at' => $this->guestVisit->exit_at?->toIso8601String(),
            ] : null,
        ];
    }
}
