<?php

namespace App\Services;

use App\Models\RfidScanLog;
use App\Support\DisplayTime;
use App\Support\PhilippineTime;
use Illuminate\Support\Facades\Cache;

/**
 * UI Phase 2: one count of things a guard/admin should look at today:
 * anomalies (direction does not fit, lost or disabled tag...) and unknown
 * tags. Unregistered visitors are normal traffic, not alerts.
 * Shown as the sidebar badge, the Dashboard and the Activity Logs "Alerts"
 * tab. Read-only; cached briefly so every page load does not re-count.
 */
class AlertSummaryService
{
    protected const CACHE_KEY = 'ui.alert-summary';

    protected const CACHE_SECONDS = 30;

    /**
     * @return array{anomalies: int, unknown_tags: int, total: int}
     */
    public function counts(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            $today = fn () => RfidScanLog::query()
                ->where('is_anomaly', true)
                ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['scan_time', 'created_at']));
            $counts = [
                // Direction does not fit, lost / disabled tag, inactive vehicle...
                'anomalies' => $today()->where('verification_status', '!=', 'unknown_tag')->count(),
                'unknown_tags' => $today()->where('verification_status', 'unknown_tag')->count(),
            ];

            return $counts + ['total' => array_sum($counts)];
        });
    }

    /**
     * UI Phase 4: Dashboard "Needs attention" list, newest first, each with an action.
     *
     * @return list<array{kind: string, tone: string, label: string, title: string, detail: string, time: string, sort: int, action_label: string, action_url: string}>
     */
    public function items(int $limit = 8): array
    {
        $items = collect();

        RfidScanLog::query()
            ->with(['vehicle', 'vehicleRfidTag'])
            ->where('is_anomaly', true)
            ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['scan_time', 'created_at']))
            ->latest('scan_time')
            ->limit($limit)
            ->get()
            ->each(function (RfidScanLog $scan) use ($items): void {
                $unknown = $scan->verification_status === 'unknown_tag';
                $items->push([
                    'kind' => $unknown ? 'unknown_tag' : 'anomaly',
                    'tone' => $unknown ? 'warning' : 'critical',
                    'label' => $unknown ? 'Unknown tag' : 'Anomaly',
                    'title' => ($scan->vehicle?->plate_number ?? $scan->vehicleRfidTag?->label ?? $scan->tag_uid).' · '.\App\Models\Gate::labelFor($scan->scan_location),
                    'detail' => (string) ($scan->anomaly_reason ?: $scan->verificationLabel),
                    'time' => DisplayTime::time($scan->scan_time),
                    'sort' => $scan->scan_time?->getTimestamp() ?? 0,
                    'action_label' => match (true) {
                        $unknown => 'Register this tag',
                        (bool) $scan->correlated_vehicle_event_id => 'Open log',
                        default => 'Review',
                    },
                    'action_url' => match (true) {
                        $unknown => route('registry.index', ['tab' => 'vehicles', 'register_tag' => $scan->tag_uid]),
                        (bool) $scan->correlated_vehicle_event_id => route('vehicle-events.show', $scan->correlated_vehicle_event_id),
                        default => route('logs.index', ['tab' => 'scans', 'history_q' => $scan->tag_uid]),
                    },
                ]);
            });

        return $items->sortByDesc('sort')->take($limit)->values()->all();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
