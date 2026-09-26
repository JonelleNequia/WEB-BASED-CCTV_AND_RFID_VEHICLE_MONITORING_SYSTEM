<?php

namespace App\Services;

use App\Models\GuestVehicleObservation;
use App\Models\GuestVisit;
use App\Models\RfidScanLog;
use App\Support\PhilippineTime;
use Illuminate\Support\Facades\Cache;

/**
 * UI Phase 2: one count of things a guard/admin should look at.
 * Shown as the sidebar badge and on the Activity Logs "Alerts" tab.
 * Read-only; cached briefly so every page load does not re-count.
 */
class AlertSummaryService
{
    protected const CACHE_KEY = 'ui.alert-summary';

    protected const CACHE_SECONDS = 30;

    /**
     * @return array{anomalies: int, overstay: int, lost_pass: int, no_pass: int, total: int}
     */
    public function counts(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            $counts = [
                // Station anomalies today (direction mismatch, unknown tags...).
                'anomalies' => RfidScanLog::query()
                    ->where('is_anomaly', true)
                    ->whereNotIn('verification_status', ['guest_pass_lost', 'guest_pass_disabled'])
                    ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['scan_time', 'created_at']))
                    ->count(),
                'overstay' => GuestVisit::query()->where('status', GuestVisit::STATUS_OVERSTAY)->count(),
                'lost_pass' => RfidScanLog::query()
                    ->whereIn('verification_status', ['guest_pass_lost', 'guest_pass_disabled'])
                    ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['scan_time', 'created_at']))
                    ->count(),
                'no_pass' => GuestVehicleObservation::query()
                    ->where('observation_source', 'cctv')
                    ->where('status', '!=', GuestVehicleObservation::STATUS_RESOLVED)
                    ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['observed_at', 'created_at']))
                    ->count(),
            ];

            return $counts + ['total' => array_sum($counts)];
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
