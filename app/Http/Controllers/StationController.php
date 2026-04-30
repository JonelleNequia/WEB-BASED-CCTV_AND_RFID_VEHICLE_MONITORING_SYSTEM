<?php

namespace App\Http\Controllers;

use App\Models\VehicleEvent;
use App\Services\CalibrationService;
use App\Services\DetectorRuntimeService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class StationController extends Controller
{
    /**
     * Show one read-only station window for the local extended-display setup.
     */
    public function show(
        string $location,
        CalibrationService $calibrationService,
        DetectorRuntimeService $detectorRuntimeService,
        SettingsService $settingsService
    ): View {
        $location = $this->validateLocation($location);
        $settingsService->ensureCameraRuntimeConfigExists();

        $camera = $calibrationService->cameraPayload()[$location];
        $detectorStatus = $detectorRuntimeService->ensureRunning();
        $eventType = $this->eventTypeForLocation($location);
        $cameraStatus = $detectorStatus['cameras'][$location] ?? [];

        return view('stations.show', [
            'location' => $location,
            'stationLabel' => $this->stationLabel($location),
            'eventType' => $eventType,
            'camera' => $camera,
            'detectorStatus' => $detectorStatus,
            'cameraStatus' => $cameraStatus,
            'streamUrl' => $cameraStatus['stream_url'] ?? "http://127.0.0.1:8765/stream/{$location}",
            'logs' => $this->recentLogs($eventType),
        ]);
    }

    /**
     * Poll one station window with only the data that belongs on that screen.
     */
    public function state(string $location, DetectorRuntimeService $detectorRuntimeService): JsonResponse
    {
        $location = $this->validateLocation($location);
        $eventType = $this->eventTypeForLocation($location);
        $runtime = $detectorRuntimeService->readStatus();

        return response()->json([
            'location' => $location,
            'event_type' => $eventType,
            'runtime' => $runtime,
            'camera' => $runtime['cameras'][$location] ?? null,
            'stream_url' => $runtime['cameras'][$location]['stream_url'] ?? "http://127.0.0.1:8765/stream/{$location}",
            'logs' => $this->recentLogs($eventType),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function recentLogs(string $eventType, int $limit = 14): array
    {
        return VehicleEvent::query()
            ->with(['camera', 'vehicle', 'rfidScanLog'])
            ->where('event_type', $eventType)
            ->latest('event_time')
            ->limit($limit)
            ->get()
            ->map(function (VehicleEvent $event): array {
                $vehicle = $event->vehicle;
                $scanLog = $event->rfidScanLog;

                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'plate_number' => $event->plate_text ?: $vehicle?->plate_number ?: 'UNKNOWN',
                    'owner_name' => $vehicle?->vehicle_owner_name ?: $vehicle?->owner_name ?: 'N/A',
                    'vehicle_type' => $event->display_vehicle_type,
                    'camera_role' => $event->camera?->camera_role,
                    'scan_location' => $scanLog?->scan_location,
                    'verification_label' => $scanLog?->verificationLabel
                        ?? ($event->vehicle_id ? 'Registered' : 'Unregistered / Guest'),
                    'resulting_state' => $event->resulting_state ?: 'N/A',
                    'event_time' => $event->event_time?->toIso8601String(),
                    'display_time' => $event->event_time?->format('M d, Y h:i:s A'),
                    'status' => $event->display_status,
                ];
            })
            ->all();
    }

    protected function validateLocation(string $location): string
    {
        abort_unless(in_array($location, ['entrance', 'exit'], true), 404);

        return $location;
    }

    protected function eventTypeForLocation(string $location): string
    {
        return $location === 'exit' ? 'EXIT' : 'ENTRY';
    }

    protected function stationLabel(string $location): string
    {
        return $location === 'exit' ? 'Exit Station' : 'Entrance Station';
    }
}
