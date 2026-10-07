<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCalibrationRequest;
use App\Services\CalibrationService;
use App\Services\CameraStreams;
use App\Services\DetectorRuntimeService;
use App\Services\SettingsService;
use App\Support\CameraFiles;
use App\Support\DisplayTime;
use App\Support\PipelineReport;
use Illuminate\Support\Carbon;
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
        // B1: "Set up" / "Edit" on a gate card opens only that gate.
        $only = \App\Models\Gate::resolveCode((string) request('gate'));
        if ($only && isset($cameras[$only])) {
            $cameras = [$only => $cameras[$only]];
        }

        foreach (array_keys($cameras) as $role) {
            // Calibration work: the same live stream as the kiosk and Gate Monitor.
            $cameras[$role]['stream_url'] = $detectorRuntimeService->streamUrlForRole($role, $detectorStatus, request()->getHost());
            $cameras[$role] = [...$cameras[$role], ...$this->liveState($role, $detectorStatus)];
        }

        // UI Phase 2: Settings › Calibration tab.
        return view('settings.index', [
            'tab' => 'calibration',
            'cameras' => $cameras,
            'detectorStatus' => $detectorStatus,
            'debugOverlay' => $settingsService->get('detector_debug_overlay', '0') === '1',
            'recentCrossings' => $calibrationService->recentCrossings(),
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

    public function heartbeat(DetectorRuntimeService $detectorRuntimeService, CalibrationService $calibrationService): JsonResponse
    {
        $detectorRuntimeService->markCalibrationViewerActive();
        $detectorStatus = $detectorRuntimeService->withViewerStreamUrls(
            $detectorRuntimeService->ensureRunning(),
            request()->getHost()
        );

        $gates = [];
        foreach (\App\Models\Gate::codes() as $role) {
            $gates[$role] = $this->liveState($role, $detectorStatus);
        }

        return response()->json([
            'runtime' => $detectorStatus,
            // Calibration work: Connected / Reconnecting / Offline and the last picture, per gate.
            'gates' => $gates,
            'crossings' => $calibrationService->recentCrossings(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Save one camera's zone, trigger line and IN side.
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
     * Calibration work: the gate's connection (same detector status as
     * System status) and the detector's last saved picture, which calibration
     * shows while the camera is offline so the zone can still be drawn.
     *
     * @param  array<string, mixed>  $detectorStatus
     * @return array{has_camera: bool, connection: array<string, string>, snapshot_url: ?string, snapshot_at: ?string}
     */
    protected function liveState(string $role, array $detectorStatus): array
    {
        $hasCamera = app(CameraStreams::class)->forGate($role)['source'] !== CameraStreams::SOURCE_NONE;
        $frame = CameraFiles::framePath($role, 'latest');
        $mtime = $hasCamera && is_file($frame) && filesize($frame) > 0 ? filemtime($frame) : false;

        return [
            'has_camera' => $hasCamera,
            'connection' => PipelineReport::connection($detectorStatus, $role, $hasCamera),
            'snapshot_url' => $mtime ? route('camera.frame', [$role, 'latest'], false).'?t='.$mtime : null,
            'snapshot_at' => $mtime ? DisplayTime::datetimeSeconds(Carbon::createFromTimestamp($mtime)) : null,
        ];
    }
}
