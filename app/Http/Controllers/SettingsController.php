<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSettingsRequest;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /**
     * Show editable system settings.
     */
    public function index(SettingsService $settingsService): View
    {
        $settingsService->ensureCameraRuntimeConfigExists();

        return view('settings.index', [
            'settings' => $settingsService->all(),
            'cameraConfigs' => $settingsService->cameraConfigurations(),
        ]);
    }

    /**
     * Persist system settings from the admin form.
     */
    public function update(SaveSettingsRequest $request, SettingsService $settingsService): RedirectResponse
    {
        $settingsService->save($request->validated());

        return back()->with('status', 'System settings updated.');
    }
}
