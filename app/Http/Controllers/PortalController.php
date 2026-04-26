<?php

namespace App\Http\Controllers;

use App\Models\VehicleEvent;
use App\Services\CalibrationService;
use App\Services\RfidService;
use App\Services\SettingsService;
use App\Services\VehicleRegistryService;
use Illuminate\View\View;

class PortalController extends Controller
{
    /**
     * Show a lightweight entrance or exit portal view for the final multi-monitor setup.
     */
    public function show(
        string $location,
        CalibrationService $calibrationService,
        RfidService $rfidService,
        SettingsService $settingsService,
        VehicleRegistryService $vehicleRegistryService
    ): View {
        abort_unless(in_array($location, ['entrance', 'exit'], true), 404);

        $settingsService->ensureCameraRuntimeConfigExists();
        $camera = $calibrationService->cameraPayload()[$location];
        $recentEvents = VehicleEvent::query()
            ->with(['camera', 'vehicle', 'rfidScanLog'])
            ->whereHas('camera', fn ($query) => $query->where('camera_role', $location))
            ->orderByDesc('event_time')
            ->limit(6)
            ->get();
        $recentRfidScans = $rfidService->recentScans(6, $location);
        $latestRegisteredScan = $rfidService->recentRegisteredActivity(1, $location)->first();

        return view('portals.show', [
            'location' => $location,
            'portalLabel' => $location === 'entrance'
                ? ($settingsService->get('entrance_portal_label', 'PHILCST Entrance Portal') ?? 'PHILCST Entrance Portal')
                : ($settingsService->get('exit_portal_label', 'PHILCST Exit Portal') ?? 'PHILCST Exit Portal'),
            'camera' => $camera,
            'recentEvents' => $recentEvents,
            'recentRfidScans' => $recentRfidScans,
            'latestScan' => $latestRegisteredScan ?? $recentRfidScans->first(),
            'latestEvent' => $recentEvents->first(),
            'registeredTags' => $vehicleRegistryService->registeredTags(),
            'settings' => $settingsService->all(),
        ]);
    }
}
