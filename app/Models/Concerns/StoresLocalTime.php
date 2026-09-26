<?php

namespace App\Models\Concerns;

/**
 * Phase 1: store every timestamp in the app timezone (Asia/Manila).
 *
 * Eloquent normally saves a Carbon value using that value's own timezone.
 * The Python detector sends times like "2026-09-26T08:58:00+08:00" and other
 * callers may send UTC ("...Z"), so the same column ended up with mixed
 * timezones. Converting here keeps every stored value in Philippine time.
 */
trait StoresLocalTime
{
    public function fromDateTime($value)
    {
        if (empty($value)) {
            return $value;
        }

        return $this->asDateTime($value)
            ->setTimezone(config('app.timezone'))
            ->format($this->getDateFormat());
    }
}
