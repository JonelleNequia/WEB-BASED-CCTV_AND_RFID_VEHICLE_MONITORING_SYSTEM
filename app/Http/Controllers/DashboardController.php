<?php

namespace App\Http\Controllers;

use App\Models\GuestVehicleObservation;
use App\Models\Gate;
use App\Models\PlateProfile;
use App\Models\RfidScanLog;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\AlertSummaryService;
use App\Services\CalibrationService;
use App\Services\GuestObservationService;
use App\Services\MovementCountService;
use App\Services\RfidService;
use App\Services\VehicleOccupancyService;
use App\Services\VisitorRecordService;
use App\Support\DisplayTime;
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
                'alert_anomalies' => $data['alertCounts']['anomalies'],
                'alert_unknown_tags' => $data['alertCounts']['unknown_tags'],
                'registered_scans_today' => $data['rfidStats']['registered_scans_today'] ?? 0,
                'camera_connected' => $data['cameraSummary']['connected'],
                'camera_total' => $data['cameraSummary']['total'],
                // UI Phase 4
                'alerts_total' => $data['alertCounts']['total'],
                'review_count' => $data['reviewCount'],
            ],
            'attention' => $data['attentionItems'],
            'hourly' => $data['hourlyTraffic'],
            'traffic_summary' => $data['trafficSummary'],
            'movement_counts' => $data['movementCounts'],
            'inside_by_category' => $data['insideByCategory'],
            'recent_rfid_scans' => $data['recentRfidScans']->values(),
            'latest_events' => $data['latestEvents']->values(),
            'frequent_entry_vehicles' => $data['frequentEntryVehicles']
                ->values()
                ->map(fn (Vehicle $vehicle, int $index): array => [
                    'rank' => $index + 1,
                    'plate_number' => $vehicle->plate_number,
                    'owner_name' => $vehicle->vehicle_owner_name ?: 'N/A',
                    'category' => \App\Support\VehicleCategory::label($vehicle->category),
                    'total_entries_count' => (int) ($vehicle->ranking_total_entries_count ?? $vehicle->total_entries_count),
                    'entries_today_count_from_logs' => (int) ($vehicle->ranking_entries_today_count ?? $vehicle->entries_today_count_from_logs),
                ]),
            'frequent_unregistered_visitors' => $data['frequentUnregisteredVisitors']
                ->values()
                ->map(fn (PlateProfile $profile, int $index): array => [
                    'rank' => $index + 1,
                    'plate_number' => $profile->plate_number,
                    'entries_count' => (int) $profile->entries_count,
                    'visit_count' => (int) $profile->visit_count,
                    'entries_today_count' => (int) $profile->entries_today_count,
                    'last_seen' => DisplayTime::datetime($profile->last_seen_at),
                    'note' => $profile->note,
                    'profile_url' => route('visitors.profiles.show', $profile),
                    'register_url' => route('registry.index', ['tab' => 'vehicles', 'register_plate' => $profile->id]),
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
        // Calibration work: "connected" comes from the detector, as on System status.
        $detectorStatus = app(\App\Services\DetectorRuntimeService::class)->readStatus();
        $connectedCameras = $cameraStatuses->keys()
            ->filter(fn (string $role): bool => \App\Support\PipelineReport::connection($detectorStatus, $role)['state'] === 'connected')
            ->count();
        // Phase 7 (visitor model): one source for every IN / OUT number.
        $movementCounts = app(MovementCountService::class)->dashboardPeriods();
        $trafficSummary = $this->trafficSummary($movementCounts);
        $totalTraffic = $trafficSummary['today'];

        return [
            // Phase 1: same shared count as Vehicle Registry and RFID Desk.
            'vehiclesInside' => $rfidStats['vehicles_inside'],
            'entriesToday' => $rfidStats['entries_today'],
            'exitsToday' => $rfidStats['exits_today'],
            'totalVehiclesEnteredToday' => $totalTraffic['entries'],
            'totalVehiclesExitedToday' => $totalTraffic['exits'],
            'guestObservationsToday' => $guestObservationService->countToday(),
            // Alert card: camera no-pass alerts and lost/disabled tag reads today.
            'noPassAlertsToday' => GuestVehicleObservation::query()
                ->where('observation_source', 'cctv')
                ->where('status', '!=', GuestVehicleObservation::STATUS_RESOLVED)
                ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['observed_at', 'created_at']))
                ->count(),
            'passAlertsToday' => RfidScanLog::query()
                ->where('verification_status', 'inactive_tag')
                ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['scan_time', 'created_at']))
                ->count(),
            'trafficSummary' => $trafficSummary,
            // UI Phase 4: dashboard KPIs, "Needs attention" and hourly chart.
            'alertCounts' => app(AlertSummaryService::class)->counts(),
            'attentionItems' => app(AlertSummaryService::class)->items(8),
            'hourlyTraffic' => app(MovementCountService::class)->hourlyToday(),
            'movementCounts' => $movementCounts,
            // Registered vehicles only (unregistered visitors are never "inside").
            'insideByCategory' => app(VehicleOccupancyService::class)->insideByCategory(),
            // Gate direction work, Phase C: unregistered vehicles IN today without an OUT (an estimate only).
            'possiblyInsideCount' => app(\App\Services\VisitorPresenceService::class)->possiblyInside()['vehicles']->count(),
            // Gate direction work, Phase D: Visitors › Review cards waiting.
            'reviewCount' => app(\App\Services\VisitorReviewService::class)->totalCount(),
            'latestEvents' => $this->recentEventActivities(),
            'frequentEntryVehicles' => $this->frequentEntryVehicles(),
            // Phase 6 (visitor model): Visitor Ranking, separate from the registered one.
            'frequentUnregisteredVisitors' => app(VisitorRecordService::class)->unregisteredRanking(),
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
                'vehicleEvents as total_entries_count' => fn ($query) => $query->where('event_type', 'ENTRY')->where('event_status', VehicleEvent::STATUS_COMPLETED),
                'vehicleEvents as entries_today_count_from_logs' => fn ($query) => $query
                    ->where('event_type', 'ENTRY')
                    ->where('event_status', VehicleEvent::STATUS_COMPLETED)
                    ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['event_time', 'created_at'])),
                // Phase 6 (visitor model): entries the camera saw with no tag read
                // (visits before registration, moved by "Register this vehicle").
                'visitorRecords as camera_entries_count' => fn ($query) => $query->active()->withoutMovementEvent()->where('direction', 'IN'),
                'visitorRecords as camera_entries_today_count' => fn ($query) => $query->active()->withoutMovementEvent()->where('direction', 'IN')
                    ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['seen_at'])),
            ])
            ->orderBy('plate_number')
            ->get()
            ->map(function (Vehicle $vehicle) use ($today): Vehicle {
                $dailyCounter = $vehicle->daily_count_date?->toDateString() === $today
                    ? (int) $vehicle->entries_today_count
                    : 0;

                $vehicle->ranking_total_entries_count = max((int) $vehicle->total_entries_count, $dailyCounter) + (int) $vehicle->camera_entries_count;
                $vehicle->ranking_entries_today_count = max((int) $vehicle->entries_today_count_from_logs, $dailyCounter) + (int) $vehicle->camera_entries_today_count;

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

    /**
     * Phase 7 (visitor model): IN / OUT per period from MovementCountService
     * (same numbers as the counts panel), with the older summary keys.
     *
     * @param  array<string, array<string, mixed>>  $movementCounts
     * @return array<string, array{label: string, entries: int, exits: int, unknown: int, registered_scans: int, guest_observations: int}>
     */
    protected function trafficSummary(array $movementCounts): array
    {
        return collect($movementCounts)
            ->map(fn (array $counts, string $period): array => [
                'label' => $counts['label'],
                'entries' => $counts['in'],
                'exits' => $counts['out'],
                'unknown' => $counts['unknown'],
                'registered_scans' => $this->registeredScanCount($period),
                'guest_observations' => $this->guestObservationCount($period),
            ])
            ->all();
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
     * Camera records of unregistered visitors are listed with the scans.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function recentRfidActivities(RfidService $rfidService): Collection
    {
        $scanRows = $rfidService->recentScans(30)
            ->map(function ($scan): array {
                $time = $scan->scan_time;
                $plate = $scan->vehicle?->plate_number ?: ($scan->isUnknownTag() ? 'Unknown tag' : 'No plate');

                return [
                    'key' => 'rfid_scan:'.$scan->id,
                    'title' => $scan->tag_uid.' • '.$scan->resolvedEventTypeLabel,
                    'summary' => $plate.' • '.$scan->scanLocationLabel.' • State: '.$scan->resultingStateLabel,
                    'display_time' => DisplayTime::datetime($time, 'No time'),
                    'badge_label' => $scan->verificationLabel,
                    'badge_class' => $scan->verificationBadgeClass,
                    'sort_time' => $scan->created_at?->getTimestamp() ?? $time?->getTimestamp() ?? 0,
                ];
            });

        $guestRows = $this->unmirroredGuestObservationsQuery()
            ->with('camera')
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get()
            ->map(function (GuestVehicleObservation $observation): array {
                $time = $observation->observed_at;
                $plate = $this->guestDisplayPlate($observation);

                return [
                    'key' => 'guest_observation:'.$observation->id,
                    'title' => $plate,
                    'summary' => 'Unregistered Guest • '.Gate::labelFor($observation->location),
                    'display_time' => DisplayTime::datetime($time, 'No time'),
                    'badge_label' => 'Unregistered',
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
        return app(\App\Services\DashboardActivityService::class)->recent();
    }

    protected function unmirroredGuestObservationsQuery()
    {
        return app(\App\Services\DashboardActivityService::class)->legacyQuery();
    }

    protected function guestDisplayPlate(GuestVehicleObservation $observation): string
    {
        return $observation->plate_number
            ?: $observation->plate_text
            ?: 'No plate';
    }
}
