<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\RealtimeLogController;
use App\Models\Gate;
use App\Models\RfidScanLog;
use App\Services\CalibrationService;
use App\Services\DetectorRuntimeService;
use App\Services\SettingsService;
use App\Support\DisplayTime;
use App\Support\StatusBadge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * UI Phase 2: Gate Monitor. Every gate side by side (live feed, latest scan,
 * recent logs); the full-screen kiosks open from here. Phase 1: gates from
 * the gates table instead of the fixed Entrance/Exit.
 */
class GateMonitorController extends Controller
{
    public function index(
        Request $request,
        CalibrationService $calibrationService,
        DetectorRuntimeService $detectorRuntimeService,
        SettingsService $settingsService
    ): View {
        $settingsService->ensureCameraRuntimeConfigExists();
        $runtime = $this->runtime($request, $detectorRuntimeService);
        $cameras = $calibrationService->cameraPayload();

        $gates = [];
        foreach (Gate::ordered() as $gate) {
            $location = $gate->code;
            $gates[$location] = [
                'label' => $gate->readerDisplayName(),
                'short_label' => $gate->name,
                'camera' => $cameras[$location] ?? [],
                'camera_status' => $runtime['cameras'][$location] ?? [],
                'stream_url' => $runtime['cameras'][$location]['stream_url'] ?? $detectorRuntimeService->defaultStreamUrl($location),
                'latest_scan' => $this->latestScan($location),
                'logs' => app(RealtimeLogController::class)->gateLogRows($location, 8),
                'kiosk_url' => route('gates.kiosk', $location),
            ];
        }

        return view('gates.index', [
            'gates' => $gates,
            'detectorRunning' => (bool) ($runtime['service_running'] ?? false),
        ]);
    }

    /**
     * Poll both gates at once for the Gate Monitor.
     */
    public function state(Request $request, DetectorRuntimeService $detectorRuntimeService): JsonResponse
    {
        $runtime = $this->runtime($request, $detectorRuntimeService);
        $gates = [];

        foreach (Gate::codes() as $location) {
            $camera = $runtime['cameras'][$location] ?? [];
            $gates[$location] = [
                'camera_running' => (bool) ($camera['camera_running'] ?? false),
                'camera_error' => ($camera['camera_running'] ?? false) ? null : ($camera['last_error'] ?? null),
                'stream_url' => $camera['stream_url'] ?? $detectorRuntimeService->defaultStreamUrl($location),
                'latest_scan' => $this->latestScan($location),
                'logs' => app(RealtimeLogController::class)->gateLogRows($location, 8),
            ];
        }

        return response()->json([
            'detector_running' => (bool) ($runtime['service_running'] ?? false),
            'gates' => $gates,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Both live feeds are on screen, so both stations count as viewed.
     *
     * @return array<string, mixed>
     */
    protected function runtime(Request $request, DetectorRuntimeService $detectorRuntimeService): array
    {
        foreach (Gate::codes() as $location) {
            $detectorRuntimeService->markStationViewerActive($location);
        }

        return $detectorRuntimeService->withViewerStreamUrls(
            $detectorRuntimeService->ensureRunning(),
            $request->getHost()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function latestScan(string $location): ?array
    {
        $scan = RfidScanLog::query()
            ->with(['vehicle', 'vehicleRfidTag'])
            ->where('scan_location', $location)
            ->latest('scan_time')
            ->latest('id')
            ->first();

        if (! $scan) {
            return null;
        }

        return [
            'id' => $scan->id,
            'title' => $scan->vehicle?->plate_number ?? $scan->tag_uid,
            'subtitle' => $scan->vehicle
                ? ($scan->vehicle->vehicle_owner_name ?: $scan->vehicle->vehicle_type)
                : 'Tag '.$scan->tag_uid,
            'result' => $scan->verificationLabel,
            'status' => $status = ($scan->is_anomaly ? 'anomaly' : $scan->verification_status),
            'tone' => StatusBadge::tone($status),
            'event_type' => $scan->resolved_event_type,
            'note' => $scan->anomaly_reason,
            'time' => DisplayTime::datetimeSeconds($scan->scan_time),
            'scan_time' => $scan->scan_time?->toIso8601String(),
        ];
    }
}
