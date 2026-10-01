<?php

namespace App\Support;

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

    /** Recorded, but needs review (direction mismatch, unassigned tag, ...). */
    public const ANOMALY = 'anomaly';

    /** Lost or disabled tag: shown as an alert on the dashboard. */
    public const ALERT = 'alert';

    /** Guest-category vehicle: handled as a guest observation. */
    public const GUEST = 'guest';

    /** Phase 3: registered tag read, waiting for the camera's crossing. */
    public const PENDING = 'pending';

    /** Phase 3: a tag that is not in the registry (never a visitor record). */
    public const UNKNOWN_TAG = 'unknown_tag';

    public function __construct(
        public readonly RfidScanLog $scanLog,
        public readonly string $outcome,
        public readonly string $message,
    ) {
    }

    public function isDuplicate(): bool
    {
        return $this->outcome === self::DUPLICATE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'message' => $this->message,
            'duplicate_ignored' => $this->isDuplicate(),
            'anomaly' => (bool) $this->scanLog->is_anomaly,
            'anomaly_reason' => $this->scanLog->anomaly_reason,
        ];
    }
}
