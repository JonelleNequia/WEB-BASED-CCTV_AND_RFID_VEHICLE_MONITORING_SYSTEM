<?php

namespace App\Http\Controllers;

use App\Services\DetectorRuntimeService;
use App\Services\SettingsService;
use Illuminate\View\View;

class SystemStatusController extends Controller
{
    /**
     * Show admin-only runtime and integration status.
     */
    public function index(
        DetectorRuntimeService $detectorRuntimeService,
        SettingsService $settingsService
    ): View {
        $runtime = $detectorRuntimeService->ensureRunning();

        // UI Phase 2: Settings › System Status tab.
        return view('settings.index', [
            'tab' => 'status',
            'runtime' => $runtime,
            'settings' => $settingsService->all(),
        ]);
    }
}
