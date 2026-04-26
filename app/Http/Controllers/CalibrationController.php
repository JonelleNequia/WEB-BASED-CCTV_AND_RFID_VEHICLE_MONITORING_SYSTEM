<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCalibrationRequest;
use App\Services\CalibrationService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CalibrationController extends Controller
{
    /**
     * Show the dual-camera live calibration page.
     */
    public function index(CalibrationService $calibrationService, SettingsService $settingsService): View
    {
        $settingsService->ensureCameraRuntimeConfigExists();

        return view('calibration.index', [
            'cameras' => $calibrationService->cameraPayload(),
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
     * Save the last known browser connection state from calibration or monitoring pages.
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
