<?php

namespace App\Http\Controllers;

use App\Services\DetectorRuntimeService;
use App\Services\SettingsService;
use App\Support\PipelineReport;
use Illuminate\Http\Response;
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
            'report' => PipelineReport::build($runtime, $settingsService->performanceSettings($settingsService->all())),
        ]);
    }

    /**
     * Live-latency work: the metrics panel alone, polled by the status page.
     */
    public function metrics(DetectorRuntimeService $detectorRuntimeService, SettingsService $settingsService): Response
    {
        return response()->view('settings.partials.pipeline-metrics', [
            'report' => PipelineReport::build($detectorRuntimeService->readStatus(), $settingsService->performanceSettings($settingsService->all())),
        ])->header('Cache-Control', 'no-store, max-age=0');
    }
}
