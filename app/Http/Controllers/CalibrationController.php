<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCalibrationRequest;
use App\Services\CalibrationService;
use App\Services\DetectorRuntimeService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CalibrationController extends Controller
{
    /**
     * Show the admin-only camera calibration page for ROI and trigger lines.
     */
    public function index(
        CalibrationService $calibrationService,
        DetectorRuntimeService $detectorRuntimeService,
        SettingsService $settingsService
    ): View {
        $settingsService->ensureCameraRuntimeConfigExists();
        $detectorRuntimeService->markCalibrationViewerActive();
        $detectorStatus = $detectorRuntimeService->withViewerStreamUrls(
            $detectorRuntimeService->ensureRunning(),
            request()->getHost()
        );
        $cameras = $calibrationService->cameraPayload();

        foreach (array_keys($cameras) as $role) {
            $cameras[$role]['stream_url'] = $detectorRuntimeService->streamUrlForRole($role, $detectorStatus, request()->getHost());
            $cameras[$role]['detector_status'] = $detectorStatus['cameras'][$role] ?? [];
        }

        // UI Phase 2: Settings › Calibration tab.
        return view('settings.index', [
            'tab' => 'calibration',
            'cameras' => $cameras,
            'detectorStatus' => $detectorStatus,
            'debugOverlay' => $settingsService->get('detector_debug_overlay', '0') === '1',
        ]);
    }

    /**
     * Detector debug view on/off: the live view then shows every raw YOLO
     * detection, the zone and line as the detector uses them, track IDs and
     * counters. The detector picks it up within a second.
     */
    public function debugOverlay(Request $request, SettingsService $settingsService): JsonResponse|RedirectResponse
    {
        $enabled = $request->boolean('enabled');
        $settingsService->save(['detector_debug_overlay' => $enabled ? '1' : '0']);

        return $request->expectsJson()
            ? response()->json(['enabled' => $enabled])
            : back()->with('status', $enabled ? 'Detector debug view is on.' : 'Detector debug view is off.');
    }

    public function heartbeat(DetectorRuntimeService $detectorRuntimeService): JsonResponse
    {
        $detectorRuntimeService->markCalibrationViewerActive();
        $detectorStatus = $detectorRuntimeService->withViewerStreamUrls(
            $detectorRuntimeService->ensureRunning(),
            request()->getHost()
        );

        return response()->json([
            'runtime' => $detectorStatus,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Save one camera's live calibration overlay and selected browser source.
     */
    public function update(
        SaveCalibrationRequest $request,
        CalibrationService $calibrationService,
        SettingsService $settingsService
    ): RedirectResponse|JsonResponse {
        $camera = $calibrationService->save($request->validated());
        $settingsService->exportCameraRuntimeConfig();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $camera->camera_name.' calibration saved.',
                'camera' => $calibrationService->cameraPayload()[$camera->camera_role],
            ]);
        }

        return back()->with('status', $camera->camera_name.' calibration saved.');
    }

    /**
     * Save the last known browser connection state from monitoring pages.
     */
    public function syncState(
        Request $request,
        CalibrationService $calibrationService,
        SettingsService $settingsService
    ): JsonResponse {
        $validated = $request->validate([
            'camera_id' => ['required', 'integer', 'exists:cameras,id'],
            'browser_device_id' => ['nullable', 'string', 'max:255'],
            'browser_label' => ['nullable', 'string', 'max:255'],
            'last_connection_status' => ['required', 'in:connected,not_connected,denied,unavailable,error,unknown'],
            'last_connection_message' => ['nullable', 'string', 'max:1000'],
        ]);

        $camera = $calibrationService->syncBrowserState($validated);
        $settingsService->exportCameraRuntimeConfig();

        return response()->json([
            'message' => $camera->camera_name.' browser state updated.',
            'camera' => $calibrationService->cameraPayload()[$camera->camera_role],
        ]);
    }
}
