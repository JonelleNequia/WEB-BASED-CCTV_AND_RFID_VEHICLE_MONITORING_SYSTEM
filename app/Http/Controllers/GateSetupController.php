<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\Gate;
use App\Services\DetectorRuntimeService;
use App\Services\DeviceRegistryService;
use App\Services\GateSetupService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * B1 (Settings): the "⋯" actions on a gate card (Settings › Gates):
 * rename, change login, test and remove the camera; rename and remove the
 * RFID reader. Adding a device is the "+ Add" flow.
 */
class GateSetupController extends Controller
{
    public function renameCamera(Request $request, string $gate): RedirectResponse
    {
        $camera = $this->camera($gate);
        $camera->forceFill(['camera_name' => $request->validate(['camera_name' => ['required', 'string', 'max:100']])['camera_name']])->save();
        $this->export();

        return back()->with('status', 'Camera renamed to '.$camera->camera_name.'.');
    }

    public function cameraLogin(Request $request, string $gate, GateSetupService $setup): RedirectResponse
    {
        $validated = $request->validate([
            'source_username' => ['required', 'string', 'max:255'],
            'source_password' => ['nullable', 'string', 'max:255'],
        ]);
        $camera = $this->camera($gate);
        $camera->source_username = $validated['source_username'];
        if (filled($validated['source_password'] ?? null)) {
            $camera->source_password = $validated['source_password'];
        }
        $camera->save();
        $this->export();

        // The detector retries a changed login at once (A1); say right away whether it works.
        $test = $setup->testCamera($camera->camera_role);

        return back()->with($test['ok'] ? 'status' : 'error', 'Camera login saved. '.$test['message']);
    }

    public function testCamera(string $gate, GateSetupService $setup): JsonResponse
    {
        $result = $setup->testCamera($this->code($gate));

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function removeCamera(string $gate, GateSetupService $setup): RedirectResponse
    {
        $code = $this->code($gate);
        $setup->removeCamera($code);
        $this->export();

        return back()->with('status', Gate::labelFor($code).' has no camera now. Its detection zone is kept for the next camera.');
    }

    public function renameReader(Request $request, string $gate): RedirectResponse
    {
        $model = Gate::query()->where('code', $this->code($gate))->firstOrFail();
        $model->forceFill(['reader_name' => $request->validate(['reader_name' => ['required', 'string', 'max:100']])['reader_name']])->save();
        $this->export();

        return back()->with('status', 'Reader renamed to '.$model->reader_name.'.');
    }

    public function removeReader(string $gate, GateSetupService $setup): RedirectResponse
    {
        $code = $this->code($gate);
        $setup->removeReader($code);
        $this->export();

        return back()->with('status', Gate::labelFor($code).' has no RFID reader now.');
    }

    protected function code(string $gate): string
    {
        return Gate::resolveCode($gate) ?? abort(404);
    }

    protected function camera(string $gate): Camera
    {
        return Camera::query()->forRole($this->code($gate))->firstOrFail();
    }

    /** New names, logins and removals go to the detector and the device service. */
    protected function export(): void
    {
        app(SettingsService::class)->exportCameraRuntimeConfig();
        app(DeviceRegistryService::class)->exportRuntimeConfig();
        app(DetectorRuntimeService::class)->ensureRunning(force: true);
    }
}
