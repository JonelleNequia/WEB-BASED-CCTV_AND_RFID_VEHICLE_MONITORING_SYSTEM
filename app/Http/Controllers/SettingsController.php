<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTab;
use App\Http\Requests\SaveSettingsRequest;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    use ResolvesTab;

    /** UI Phase 2: Settings tabs; each form tab has its own Save button. */
    public const TABS = [
        'stations' => 'Stations & Readers',
        'cameras' => 'Cameras',
        'calibration' => 'Calibration',
        'guest-pass' => 'Guest Pass Rules',
        'status' => 'System Status',
        'test-scan' => 'Test Scan',
    ];

    /**
     * Show one Settings tab.
     */
    public function index(Request $request, SettingsService $settingsService): View
    {
        $tab = $this->resolveTab($request, self::TABS);

        return match ($tab) {
            'calibration' => app()->call([app(CalibrationController::class), 'index']),
            'status' => app()->call([app(SystemStatusController::class), 'index']),
            'test-scan' => app()->call([app(RfidScanController::class), 'testScan']),
            default => $this->formTab($tab, $settingsService),
        };
    }

    protected function formTab(string $tab, SettingsService $settingsService): View
    {
        $settingsService->ensureCameraRuntimeConfigExists();

        return view('settings.index', [
            'tab' => $tab,
            'settings' => $settingsService->all(),
            'cameraConfigs' => $settingsService->cameraConfigurations(),
            'detectorKeySet' => $settingsService->detectorApiKey() !== '',
        ]);
    }

    /**
     * Persist system settings from the admin form.
     */
    public function update(SaveSettingsRequest $request, SettingsService $settingsService): RedirectResponse
    {
        $settingsService->save($request->validated());
        $section = self::TABS[$request->input('section')] ?? 'System settings';

        return back()->with('status', $section.' saved.');
    }
}
