<?php

namespace App\Http\Controllers;

use App\Services\CalibrationService;
use App\Services\GuestObservationService;
use App\Services\SettingsService;
use Illuminate\View\View;

class MonitoringController extends Controller
{
    /**
     * Show the live browser-camera monitoring page.
     */
    public function index(
        CalibrationService $calibrationService,
        GuestObservationService $guestObservationService,
        SettingsService $settingsService
    ): View
    {
        $settingsService->ensureCameraRuntimeConfigExists();

        $cameras = $calibrationService->cameraPayload();
        $connectedCount = collect($cameras)->where('last_connection_status', 'connected')->count();

        return view('monitoring.index', [
            'cameras' => $cameras,
            'cameraSummary' => [
                'connected' => $connectedCount,
                'total' => count($cameras),
            ],
            'recentGuestObservations' => $guestObservationService->recent(4),
        ]);
    }
}
