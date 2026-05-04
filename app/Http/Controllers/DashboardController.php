<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\GuestVehicleObservation;
use App\Services\CalibrationService;
use App\Services\GuestObservationService;
use App\Services\RfidService;
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
        $rfidStats = $rfidService->stats();
        $cameraStatuses = collect($calibrationService->cameraPayload());
        $connectedCameras = $cameraStatuses->where('last_connection_status', 'connected')->count();

        return view('dashboard.index', [
            'vehiclesInside' => $rfidStats['vehicles_inside'],
            'entriesToday' => $rfidStats['entries_today'],
            'exitsToday' => $rfidStats['exits_today'],
            'guestObservationsToday' => $guestObservationService->countToday(),
            'latestEvents' => $this->recentEventActivities(),
            'frequentEntryVehicles' => Vehicle::query()
                ->where('category', '!=', 'guest')
                ->withCount([
                    'vehicleEvents as total_entries_count' => fn ($query) => $query->where('event_type', 'ENTRY'),
                    'vehicleEvents as entries_today_count_from_logs' => fn ($query) => $query
                        ->where('event_type', 'ENTRY')
                        ->whereDate('event_time', today()),
                ])
                ->whereHas('vehicleEvents', fn ($query) => $query->where('event_type', 'ENTRY'))
                ->orderByDesc('total_entries_count')
                ->orderBy('plate_number')
                ->limit(5)
                ->get(),
            'rfidStats' => $rfidStats,
            'recentRfidScans' => $this->recentRfidActivities($rfidService),
            'cameraSummary' => [
                'connected' => $connectedCameras,
                'total' => $cameraStatuses->count(),
                'needs_attention' => $cameraStatuses->count() - $connectedCameras,
                'items' => $cameraStatuses->values(),
            ],
        ]);
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
                    'sort_time' => $time?->getTimestamp() ?? $scan->created_at?->getTimestamp() ?? 0,
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
                    'title' => 'GUEST',
                    'summary' => 'Guest Observation #'.$observation->id.' • '.ucfirst((string) $observation->location).' Station • Pending Review',
                    'display_time' => $time?->format('M d, Y h:i A') ?: 'No time',
                    'badge_label' => 'GUEST',
                    'badge_class' => 'manual-review',
                    'sort_time' => $time?->getTimestamp() ?? $observation->created_at?->getTimestamp() ?? 0,
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
                    'sort_time' => $time?->getTimestamp() ?? $event->created_at?->getTimestamp() ?? 0,
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
                    'badge_label' => ucfirst(str_replace('_', ' ', (string) $observation->status)),
                    'badge_class' => $observation->status === 'reviewed' ? 'matched' : 'manual-review',
                    'sort_time' => $time?->getTimestamp() ?? $observation->created_at?->getTimestamp() ?? 0,
                ];
            });

        return $eventRows
            ->concat($guestRows)
            ->sortByDesc('sort_time')
            ->take(30)
            ->values();
    }
}
