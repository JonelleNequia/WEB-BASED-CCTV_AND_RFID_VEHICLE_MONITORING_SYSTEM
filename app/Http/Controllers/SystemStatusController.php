<?php

namespace App\Http\Controllers;

use App\Models\EventReceiveLog;
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

        return view('system-status.index', [
            'runtime' => $runtime,
            'settings' => $settingsService->all(),
            'recentIntegrationLogs' => EventReceiveLog::query()
                ->latest('id')
                ->limit(12)
                ->get(),
        ]);
    }
}
