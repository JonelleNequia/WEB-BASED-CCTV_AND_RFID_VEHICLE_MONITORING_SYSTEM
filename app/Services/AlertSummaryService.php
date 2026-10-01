<?php

namespace App\Services;

use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Support\DisplayTime;
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
     * @return array{anomalies: int, no_pass: int, total: int}
     */
    public function counts(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            $counts = [
                // Station anomalies today (direction mismatch, lost/disabled tags...).
                'anomalies' => RfidScanLog::query()
                    ->where('is_anomaly', true)
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
                $items->push([
                    'kind' => 'anomaly',
                    'tone' => 'critical',
                    'label' => 'Anomaly',
                    'title' => ($scan->vehicle?->plate_number ?? $scan->vehicleRfidTag?->label ?? $scan->tag_uid).' · '.\App\Models\Gate::labelFor($scan->scan_location),
                    'detail' => (string) ($scan->anomaly_reason ?: $scan->verificationLabel),
                    'time' => DisplayTime::time($scan->scan_time),
                    'sort' => $scan->scan_time?->getTimestamp() ?? 0,
                    'action_label' => $scan->correlated_vehicle_event_id ? 'Open log' : 'Review',
                    'action_url' => $scan->correlated_vehicle_event_id
                        ? route('vehicle-events.show', $scan->correlated_vehicle_event_id)
                        : route('logs.index', ['tab' => 'scans', 'history_q' => $scan->tag_uid]),
                ]);
            });

        GuestVehicleObservation::query()
            ->where('observation_source', 'cctv')
            ->where('status', '!=', GuestVehicleObservation::STATUS_RESOLVED)
            ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['observed_at', 'created_at']))
            ->latest('observed_at')
            ->limit($limit)
            ->get()
            ->each(fn (GuestVehicleObservation $observation) => $items->push([
                'kind' => 'no_pass',
                'tone' => 'critical',
                'label' => 'No pass',
                'title' => ($observation->plate_number ?: $observation->plate_text ?: 'Unknown plate').' · '.ucfirst((string) $observation->location),
                'detail' => 'Camera saw a vehicle with no RFID tag.',
                'time' => DisplayTime::time($observation->observed_at),
                'sort' => $observation->observed_at?->getTimestamp() ?? 0,
                'action_label' => 'Review',
                'action_url' => route('logs.index', ['tab' => 'alerts', 'plate_text' => $observation->plate_number ?: $observation->plate_text]),
            ]));

        return $items->sortByDesc('sort')->take($limit)->values()->all();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
