<?php

namespace App\Http\Controllers;

use App\Models\GuestVehicleObservation;
use App\Models\VehicleEvent;
use App\Services\CalibrationService;
use App\Services\DetectorRuntimeService;
use App\Services\GuestObservationService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class MonitoringController extends Controller
{
    /**
     * Show the live browser-camera monitoring page.
     */
    public function index(
        CalibrationService $calibrationService,
        GuestObservationService $guestObservationService,
        SettingsService $settingsService,
        DetectorRuntimeService $detectorRuntimeService
    ): View
    {
        $settingsService->ensureCameraRuntimeConfigExists();

        $cameras = $calibrationService->cameraPayload();
        $connectedCount = collect($cameras)->where('last_connection_status', 'connected')->count();
        $detectorStatus = $detectorRuntimeService->ensureRunning();

        return view('monitoring.index', [
            'cameras' => $cameras,
            'cameraSummary' => [
                'connected' => $connectedCount,
                'total' => count($cameras),
            ],
            'recentGuestObservations' => $guestObservationService->recent(4),
            'detectorStatus' => $detectorStatus,
            'recentActivities' => $this->recentActivities(),
        ]);
    }

    /**
     * Poll-friendly state endpoint for the guard command center.
     */
    public function liveState(DetectorRuntimeService $detectorRuntimeService): JsonResponse
    {
        return response()->json([
            'runtime' => $detectorRuntimeService->readStatus(),
            'activities' => $this->recentActivities(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function recentActivities(int $limit = 12): array
    {
        $vehicleEvents = VehicleEvent::query()
            ->with(['vehicle', 'rfidScanLog'])
            ->latest('event_time')
            ->limit($limit)
            ->get()
            ->map(fn (VehicleEvent $event): array => [
                'id' => 'event-'.$event->id,
                'kind' => $event->event_type,
                'title' => $event->event_type.' - '.($event->plate_text ?: $event->vehicle?->plate_number ?: 'Unknown vehicle'),
                'subtitle' => trim(($event->event_origin_label ?? 'Vehicle Event').' | '.($event->resulting_state ?: 'Pending state')),
                'badge' => $event->event_type === 'ENTRY' ? 'matched' : 'closed',
                'occurred_at' => $event->event_time?->toIso8601String(),
                'display_time' => $event->event_time?->format('M d, Y h:i:s A'),
            ]);

        $guestObservations = GuestVehicleObservation::query()
            ->with('camera')
            ->latest('observed_at')
            ->limit($limit)
            ->get()
            ->map(fn (GuestVehicleObservation $observation): array => [
                'id' => 'guest-'.$observation->id,
                'kind' => 'GUEST',
                'title' => 'GUEST - '.($observation->plate_text ?: 'No plate'),
                'subtitle' => ucfirst($observation->location).' | '.trim(($observation->vehicle_color ?: '').' '.($observation->vehicle_type ?: 'Vehicle')),
                'badge' => 'manual-review',
                'occurred_at' => $observation->observed_at?->toIso8601String(),
                'display_time' => $observation->observed_at?->format('M d, Y h:i:s A'),
            ]);

        return $vehicleEvents
            ->concat($guestObservations)
            ->sortByDesc('occurred_at')
            ->take($limit)
            ->values()
            ->all();
    }
}
