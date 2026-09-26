<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * UI Phase 1: one date/time format for the whole system (Asia/Manila).
 *
 * Datetime: "Sep 26, 2026 · 12:45 PM"   Date: "Sep 26, 2026"   Time: "12:45 PM"
 * public/js/ui.js (ui.formatDateTime) produces the same strings for live data.
 */
final class DisplayTime
{
    public const DATETIME = 'M j, Y · g:i A';

    public const DATETIME_SECONDS = 'M j, Y · g:i:s A';

    public const DATE = 'M j, Y';

    public const TIME = 'g:i A';

    public static function datetime(mixed $value, string $fallback = '—'): string
    {
        return self::format($value, self::DATETIME, $fallback);
    }

    public static function datetimeSeconds(mixed $value, string $fallback = '—'): string
    {
        return self::format($value, self::DATETIME_SECONDS, $fallback);
    }

    public static function date(mixed $value, string $fallback = '—'): string
    {
        return self::format($value, self::DATE, $fallback);
    }

    public static function time(mixed $value, string $fallback = '—'): string
    {
        return self::format($value, self::TIME, $fallback);
    }

    public static function format(mixed $value, string $pattern, string $fallback = '—'): string
    {
        $time = self::parse($value);

        return $time ? $time->format($pattern) : $fallback;
    }

    public static function parse(mixed $value): ?CarbonInterface
    {
        if (blank($value)) {
            return null;
        }

        try {
            $time = $value instanceof CarbonInterface ? $value->copy() : Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }

        return $time->setTimezone(config('app.timezone', 'Asia/Manila'));
    }
}
