<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\CalibrationService;
use App\Services\GuestObservationService;
use App\Services\RfidService;
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
            'latestEvents' => VehicleEvent::query()
                ->with(['camera', 'vehicle', 'rfidScanLog'])
                ->orderByDesc('event_time')
                ->limit(8)
                ->get(),
            'frequentEntryVehicles' => Vehicle::query()
                ->whereIn('category', Vehicle::RFID_RECURRING_CATEGORIES)
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
            'recentRfidScans' => $rfidService->recentScans(8),
            'cameraSummary' => [
                'connected' => $connectedCameras,
                'total' => $cameraStatuses->count(),
                'needs_attention' => $cameraStatuses->count() - $connectedCameras,
                'items' => $cameraStatuses->values(),
            ],
        ]);
    }
}
