<?php

namespace App\Services;

use App\Models\Gate;
use App\Models\GuestVehicleObservation;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Support\DisplayTime;
use App\Support\PhilippineTime;
use App\Support\VehicleCategory;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 7 (visitor model): the one place that counts vehicles going IN and
 * OUT, per period, category and gate.
 *
 * Movements come from:
 * - ENTRY/EXIT logs: registered vehicles (RFID with the camera's direction,
 *   or the vehicle state) and manual logs (a manual log of a plate that is
 *   not in the Registry is an Unregistered Visitor);
 * - Unregistered Visitor records (camera, no registered tag read). A record
 *   of a plate that was already in the Registry at that moment is a
 *   registered movement with the plate only (tag not read): counted for the
 *   vehicle's category, but it never changes "inside";
 * - older camera guest records from before the visitor records (Phase 8
 *   converts them); a guest record that has a visitor record is not counted
 *   twice.
 *
 * Duplicates and dismissed visitor records are not counted. A crossing whose
 * direction is unknown is counted as "unknown", not as IN or OUT.
 * "Inside now" stays registered vehicles only (VehicleOccupancyService).
 */
class MovementCountService
{
    public const PERIODS = [
        'today' => 'Today',
        'week' => 'This Week',
        'month' => 'This Month',
        'year' => 'This Year',
    ];

    public const SOURCE_RFID = 'rfid';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_CAMERA = 'camera';

    /** Registered vehicle, plate read by the camera, tag not read. */
    public const SOURCE_PLATE_ONLY = 'plate_only';

    public const SOURCE_LEGACY_CAMERA = 'legacy_camera';

    /**
     * Every movement in [from, until): time, direction (IN | OUT | UNKNOWN),
     * category, gate code and source.
     *
     * @return Collection<int, array{time: Carbon, direction: string, category: string, gate: ?string, source: string}>
     */
    public function movements(Carbon $from, Carbon $until): Collection
    {
        $registered = VehicleEvent::query()
            ->with(['vehicle:id,category', 'rfidScanLog:id,scan_location', 'camera:id,camera_role'])
            ->whereIn('event_type', ['ENTRY', 'EXIT'])
            ->where('event_status', '!=', VehicleEvent::STATUS_PENDING_DETAILS)
            ->whereNull('guest_visit_id')
            ->whereNotIn('event_origin', ['guest_cctv', 'guest_manual', 'guest_pass'])
            ->where('event_time', '>=', $from)
            ->where('event_time', '<', $until)
            ->get()
            ->map(fn (VehicleEvent $event): array => [
                'time' => $event->event_time,
                'direction' => $event->event_type === 'EXIT' ? 'OUT' : 'IN',
                'category' => $event->vehicle_id
                    ? VehicleCategory::normalize($event->vehicle_category ?: $event->vehicle?->category)
                    : VehicleCategory::UNREGISTERED_VISITOR,
                'gate' => $this->gate($event->rfidScanLog?->scan_location ?? $event->camera?->camera_role),
                'source' => $event->rfid_scan_log_id ? self::SOURCE_RFID : self::SOURCE_MANUAL,
            ]);

        $visitors = VisitorRecord::query()
            ->with('vehicle:id,category,created_at')
            ->active()
            ->where('seen_at', '>=', $from)
            ->where('seen_at', '<', $until)
            ->get()
            ->map(function (VisitorRecord $record): array {
                $plateOnly = $record->vehicle && $record->vehicle->created_at && $record->vehicle->created_at->lte($record->seen_at);

                return [
                    'time' => $record->seen_at,
                    'direction' => in_array($record->direction, ['IN', 'OUT'], true) ? $record->direction : 'UNKNOWN',
                    'category' => $plateOnly ? VehicleCategory::normalize($record->vehicle->category) : VehicleCategory::UNREGISTERED_VISITOR,
                    'gate' => $this->gate($record->gate),
                    'source' => $plateOnly ? self::SOURCE_PLATE_ONLY : self::SOURCE_CAMERA,
                ];
            });

        $legacy = GuestVehicleObservation::query()
            ->whereIn('observation_source', ['cctv', 'manual'])
            ->where(fn ($query) => $query->whereNull('external_event_key')
                ->orWhereNotIn('external_event_key', VisitorRecord::query()->select('external_event_key')))
            ->where('observed_at', '>=', $from)
            ->where('observed_at', '<', $until)
            ->get(['observed_at', 'location', 'detection_metadata_json'])
            ->map(fn (GuestVehicleObservation $observation): array => [
                'time' => $observation->observed_at,
                // Before Phase 2 the detector sent IN unless it saw an OUT.
                'direction' => VehicleEvent::eventTypeForDirection(data_get($observation->detection_metadata_json, 'direction')) === 'EXIT' ? 'OUT' : 'IN',
                'category' => VehicleCategory::UNREGISTERED_VISITOR,
                'gate' => $this->gate($observation->location),
                'source' => self::SOURCE_LEGACY_CAMERA,
            ]);

        return $registered->concat($visitors)->concat($legacy)->values();
    }

    /**
     * IN / OUT / unknown for one period, with the split per category and gate.
     *
     * @return array{label: string, in: int, out: int, unknown: int, categories: array<string, array{label: string, in: int, out: int}>, gates: array<string, array{label: string, in: int, out: int}>}
     */
    public function counts(string $period): array
    {
        $window = PhilippineTime::periodWindow(array_key_exists($period, self::PERIODS) ? $period : 'today');

        return $this->summarize($this->movements($window['local_start'], $window['local_end'])) + ['label' => self::PERIODS[$period] ?? 'Today'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allPeriods(): array
    {
        return collect(self::PERIODS)->mapWithKeys(fn (string $label, string $period): array => [$period => $this->counts($period)])->all();
    }

    /**
     * Today's IN / OUT per hour (Asia/Manila), for the dashboard chart.
     *
     * @return list<array{hour: int, label: string, entries: int, exits: int}>
     */
    public function hourlyToday(): array
    {
        $window = PhilippineTime::periodWindow('today');
        $hours = array_fill(0, 24, ['entries' => 0, 'exits' => 0]);

        foreach ($this->movements($window['local_start'], $window['local_end']) as $movement) {
            if ($movement['direction'] === 'UNKNOWN' || ! $movement['time']) {
                continue;
            }

            $hours[(int) DisplayTime::format($movement['time'], 'G')][$movement['direction'] === 'OUT' ? 'exits' : 'entries']++;
        }

        return collect($hours)
            ->map(fn (array $counts, int $hour): array => [
                'hour' => $hour,
                'label' => DisplayTime::format($window['local_start']->copy()->setTime($hour, 0), 'g A'),
            ] + $counts)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $movements
     * @return array<string, mixed>
     */
    protected function summarize(Collection $movements): array
    {
        $categories = collect(VehicleCategory::LABELS)->map(fn (string $label): array => ['label' => $label, 'in' => 0, 'out' => 0])->all();
        $gates = collect(Gate::query()->orderBy('sort_order')->pluck('name', 'code'))
            ->map(fn (string $label): array => ['label' => $label, 'in' => 0, 'out' => 0])
            ->all();
        $totals = ['in' => 0, 'out' => 0, 'unknown' => 0];

        foreach ($movements as $movement) {
            if ($movement['direction'] === 'UNKNOWN') {
                $totals['unknown']++;

                continue;
            }

            $key = $movement['direction'] === 'OUT' ? 'out' : 'in';
            $totals[$key]++;

            $category = $movement['category'] ?: VehicleCategory::UNREGISTERED_VISITOR;
            $categories[$category] ??= ['label' => VehicleCategory::label($category), 'in' => 0, 'out' => 0];
            $categories[$category][$key]++;

            if ($movement['gate']) {
                $gates[$movement['gate']] ??= ['label' => Gate::labelFor($movement['gate']), 'in' => 0, 'out' => 0];
                $gates[$movement['gate']][$key]++;
            }
        }

        return $totals + ['categories' => $categories, 'gates' => $gates];
    }

    protected function gate(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return Gate::LEGACY_CODES[strtolower((string) $value)] ?? strtolower((string) $value);
    }
}
