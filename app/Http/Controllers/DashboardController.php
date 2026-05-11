<?php

namespace App\Http\Controllers;

use App\Models\ActiveSession;
use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\CalibrationService;
use App\Services\GuestObservationService;
use App\Services\RfidService;
use App\Support\PhilippineTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Show the dashboard summary for the prototype.
     */
    public function index(
        RfidService $rfidService,
        CalibrationService $calibrationService,
        GuestObservationService $guestObservationService
    ): View
    {
        return view('dashboard.index', $this->dashboardData($rfidService, $calibrationService, $guestObservationService));
    }

    /**
     * Pollable dashboard state for offline real-time updates.
     */
    public function liveState(
        RfidService $rfidService,
        CalibrationService $calibrationService,
        GuestObservationService $guestObservationService
    ): JsonResponse
    {
        $data = $this->dashboardData($rfidService, $calibrationService, $guestObservationService);

        return response()->json([
            'metrics' => [
                'vehicles_inside' => $data['vehiclesInside'],
                'registered_entries_today' => $data['entriesToday'],
                'registered_exits_today' => $data['exitsToday'],
                'total_vehicles_entered_today' => $data['totalVehiclesEnteredToday'],
                'total_vehicles_exited_today' => $data['totalVehiclesExitedToday'],
                'guest_observations_today' => $data['guestObservationsToday'],
                'registered_scans_today' => $data['rfidStats']['registered_scans_today'] ?? 0,
                'camera_connected' => $data['cameraSummary']['connected'],
                'camera_total' => $data['cameraSummary']['total'],
            ],
            'traffic_summary' => $data['trafficSummary'],
            'recent_rfid_scans' => $data['recentRfidScans']->values(),
            'latest_events' => $data['latestEvents']->values(),
            'frequent_entry_vehicles' => $data['frequentEntryVehicles']
                ->values()
                ->map(fn (Vehicle $vehicle, int $index): array => [
                    'rank' => $index + 1,
                    'plate_number' => $vehicle->plate_number,
                    'owner_name' => $vehicle->vehicle_owner_name ?: 'N/A',
                    'category' => ucfirst(str_replace('_', ' ', (string) $vehicle->category)),
                    'total_entries_count' => (int) ($vehicle->ranking_total_entries_count ?? $vehicle->total_entries_count),
                    'entries_today_count_from_logs' => (int) ($vehicle->ranking_entries_today_count ?? $vehicle->entries_today_count_from_logs),
                ]),
            'camera_summary' => $data['cameraSummary'],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function dashboardData(
        RfidService $rfidService,
        CalibrationService $calibrationService,
        GuestObservationService $guestObservationService
    ): array
    {
        $rfidStats = $rfidService->stats();
        $cameraStatuses = collect($calibrationService->cameraPayload());
        $connectedCameras = $cameraStatuses->where('last_connection_status', 'connected')->count();
        $trafficSummary = $this->trafficSummary();
        $totalTraffic = $trafficSummary['today'];

        return [
            'vehiclesInside' => $rfidStats['vehicles_inside'] + $this->guestVehiclesInsideCount(),
            'entriesToday' => $rfidStats['entries_today'],
            'exitsToday' => $rfidStats['exits_today'],
            'totalVehiclesEnteredToday' => $totalTraffic['entries'],
            'totalVehiclesExitedToday' => $totalTraffic['exits'],
            'guestObservationsToday' => $guestObservationService->countToday(),
            'trafficSummary' => $trafficSummary,
            'latestEvents' => $this->recentEventActivities(),
            'frequentEntryVehicles' => $this->frequentEntryVehicles(),
            'rfidStats' => $rfidStats,
            'recentRfidScans' => $this->recentRfidActivities($rfidService),
            'cameraSummary' => [
                'connected' => $connectedCameras,
                'total' => $cameraStatuses->count(),
                'needs_attention' => $cameraStatuses->count() - $connectedCameras,
                'items' => $cameraStatuses->values(),
            ],
        ];
    }

    protected function frequentEntryVehicles(): Collection
    {
        $today = PhilippineTime::todayDateString();

        return Vehicle::query()
            ->where('category', '!=', 'guest')
            ->withCount([
                'vehicleEvents as total_entries_count' => fn ($query) => $query->where('event_type', 'ENTRY'),
                'vehicleEvents as entries_today_count_from_logs' => fn ($query) => $query
                    ->where('event_type', 'ENTRY')
                    ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['event_time', 'created_at'])),
            ])
            ->orderBy('plate_number')
            ->get()
            ->map(function (Vehicle $vehicle) use ($today): Vehicle {
                $dailyCounter = $vehicle->daily_count_date?->toDateString() === $today
                    ? (int) $vehicle->entries_today_count
                    : 0;

                $vehicle->ranking_total_entries_count = max((int) $vehicle->total_entries_count, $dailyCounter);
                $vehicle->ranking_entries_today_count = max((int) $vehicle->entries_today_count_from_logs, $dailyCounter);

                return $vehicle;
            })
            ->filter(fn (Vehicle $vehicle): bool => (int) $vehicle->ranking_total_entries_count > 0)
            ->sort(function (Vehicle $left, Vehicle $right): int {
                $entryComparison = (int) $right->ranking_total_entries_count <=> (int) $left->ranking_total_entries_count;

                return $entryComparison !== 0
                    ? $entryComparison
                    : strcmp((string) $left->plate_number, (string) $right->plate_number);
            })
            ->take(5)
            ->values();
    }

    protected function guestVehiclesInsideCount(): int
    {
        return ActiveSession::query()
            ->where('status', 'open')
            ->whereHas('entryEvent', function ($query): void {
                $query->where(function ($guestQuery): void {
                    $guestQuery->where('vehicle_category', 'guest')
                        ->orWhereIn('event_origin', ['guest_cctv', 'guest_manual']);
                });
            })
            ->count();
    }

    /**
     * @return array{entries: int, exits: int}
     */
    protected function totalTrafficToday(): array
    {
        return $this->totalTrafficForPeriod('today');
    }

    /**
     * @return array<string, array{label: string, entries: int, exits: int, registered_scans: int, guest_observations: int}>
     */
    protected function trafficSummary(): array
    {
        return collect($this->periodOptions())
            ->mapWithKeys(function (string $label, string $period): array {
                $traffic = $this->totalTrafficForPeriod($period);

                return [
                    $period => [
                        'label' => $label,
                        'entries' => $traffic['entries'],
                        'exits' => $traffic['exits'],
                        'registered_scans' => $this->registeredScanCount($period),
                        'guest_observations' => $this->guestObservationCount($period),
                    ],
                ];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function periodOptions(): array
    {
        return [
            'today' => 'Today',
            'week' => 'This Week',
            'month' => 'This Month',
            'year' => 'This Year',
        ];
    }

    /**
     * @return array{entries: int, exits: int}
     */
    protected function totalTrafficForPeriod(string $period): array
    {
        $registeredEntries = VehicleEvent::query()
            ->where('event_type', 'ENTRY')
            ->where('event_status', '!=', VehicleEvent::STATUS_PENDING_DETAILS)
            ->whereNotIn('event_origin', ['guest_cctv', 'guest_manual'])
            ->where(fn ($query) => PhilippineTime::constrainPeriodAny($query, ['event_time', 'created_at'], $period))
            ->count();

        $registeredExits = VehicleEvent::query()
            ->where('event_type', 'EXIT')
            ->where('event_status', '!=', VehicleEvent::STATUS_PENDING_DETAILS)
            ->whereNotIn('event_origin', ['guest_cctv', 'guest_manual'])
            ->where(fn ($query) => PhilippineTime::constrainPeriodAny($query, ['event_time', 'created_at'], $period))
            ->count();

        return [
            'entries' => (int) $registeredEntries
                + $this->guestTrafficCount('entrance', $period)
                + $this->unlinkedGuestRfidTrafficCount('entrance', $period),
            'exits' => (int) $registeredExits
                + $this->guestTrafficCount('exit', $period)
                + $this->unlinkedGuestRfidTrafficCount('exit', $period),
        ];
    }

    protected function guestTrafficCount(string $location, string $period = 'today'): int
    {
        return GuestVehicleObservation::query()
            ->where('location', $location)
            ->where(fn ($query) => PhilippineTime::constrainPeriodAny($query, ['observed_at', 'created_at'], $period))
            ->count();
    }

    protected function unlinkedGuestRfidTrafficCount(string $location, string $period = 'today'): int
    {
        return RfidScanLog::query()
            ->where('verification_status', 'guest')
            ->where('scan_location', $location)
            ->whereNull('guest_vehicle_observation_id')
            ->where(fn ($query) => PhilippineTime::constrainPeriodAny($query, ['scan_time', 'created_at'], $period))
            ->count();
    }

    protected function registeredScanCount(string $period): int
    {
        return RfidScanLog::query()
            ->where(fn ($query) => PhilippineTime::constrainPeriodAny($query, ['scan_time', 'created_at'], $period))
            ->where('verification_status', 'verified')
            ->where(function ($query): void {
                $query->whereNull('vehicle_category')
                    ->orWhere('vehicle_category', '!=', 'guest');
            })
            ->count();
    }

    protected function guestObservationCount(string $period): int
    {
        return GuestVehicleObservation::query()
            ->where(fn ($query) => PhilippineTime::constrainPeriodAny($query, ['observed_at', 'created_at'], $period))
            ->count();
    }

    /**
     * Camera guest timeouts are operational RFID-adjacent activity, so include
     * them in the dashboard stream with the same GUEST label as scan rows.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function recentRfidActivities(RfidService $rfidService): Collection
    {
        $scanRows = $rfidService->recentScans(30)
            ->map(function ($scan): array {
                $time = $scan->scan_time;
                $plate = $scan->vehicle?->plate_number ?: 'GUEST';

                return [
                    'title' => $scan->verification_status === 'guest'
                        ? 'GUEST'
                        : $scan->tag_uid.' • '.$scan->resolvedEventTypeLabel,
                    'summary' => $plate.' • '.$scan->scanLocationLabel.' • State: '.$scan->resultingStateLabel,
                    'display_time' => $time?->format('M d, Y h:i A') ?: 'No time',
                    'badge_label' => $scan->verification_status === 'guest' ? 'GUEST' : $scan->verificationLabel,
                    'badge_class' => $scan->verificationBadgeClass,
                    'sort_time' => $scan->created_at?->getTimestamp() ?? $time?->getTimestamp() ?? 0,
                ];
            });

        $guestRows = GuestVehicleObservation::query()
            ->with('camera')
            ->where(function ($query): void {
                $query->whereNull('external_event_key')
                    ->orWhereNotExists(function ($subquery): void {
                        $subquery->selectRaw('1')
                            ->from('vehicle_events')
                            ->whereColumn('vehicle_events.external_event_key', 'guest_vehicle_observations.external_event_key')
                            ->whereIn('vehicle_events.event_origin', ['guest_cctv', 'guest_manual']);
                    });
            })
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get()
            ->map(function (GuestVehicleObservation $observation): array {
                $time = $observation->observed_at;

                return [
                    'title' => 'GUEST',
                    'summary' => 'Guest Observation #'.$observation->id.' • '.ucfirst((string) $observation->location).' Station',
                    'display_time' => $time?->format('M d, Y h:i A') ?: 'No time',
                    'badge_label' => 'GUEST',
                    'badge_class' => 'secondary',
                    'sort_time' => $observation->created_at?->getTimestamp() ?? $time?->getTimestamp() ?? 0,
                ];
            });

        return $scanRows
            ->concat($guestRows)
            ->sortByDesc('sort_time')
            ->take(30)
            ->values();
    }

    /**
     * The dashboard event stream mirrors the unified Event Logs page: RFID
     * vehicle events plus camera/manual guest observations, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function recentEventActivities(): Collection
    {
        $eventRows = VehicleEvent::query()
            ->with(['camera', 'vehicle', 'rfidScanLog'])
            ->where('event_status', '!=', VehicleEvent::STATUS_PENDING_DETAILS)
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get()
            ->map(function (VehicleEvent $event): array {
                $time = $event->event_time;
                $plate = $event->plate_text ?: $event->vehicle?->plate_number ?: 'GUEST';

                return [
                    'title' => $event->event_type.' • '.$plate,
                    'summary' => $event->event_origin_label.' • '.$event->display_vehicle_type,
                    'display_time' => $time?->format('M d, Y h:i A') ?: 'No time',
                    'badge_label' => str($event->display_status)->replace('_', ' ')->title()->value(),
                    'badge_class' => $event->status_badge_class,
                    'sort_time' => $event->created_at?->getTimestamp() ?? $time?->getTimestamp() ?? 0,
                ];
            });

        $guestRows = GuestVehicleObservation::query()
            ->with('camera')
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get()
            ->map(function (GuestVehicleObservation $observation): array {
                $time = $observation->observed_at;

                return [
                    'title' => 'GUEST • GUEST',
                    'summary' => 'Guest Observation #'.$observation->id.' • '.ucfirst((string) $observation->location).' Station',
                    'display_time' => $time?->format('M d, Y h:i A') ?: 'No time',
                    'badge_label' => 'GUEST',
                    'badge_class' => 'secondary',
                    'sort_time' => $observation->created_at?->getTimestamp() ?? $time?->getTimestamp() ?? 0,
                ];
            });

        return $eventRows
            ->concat($guestRows)
            ->sortByDesc('sort_time')
            ->take(30)
            ->values();
    }
}
