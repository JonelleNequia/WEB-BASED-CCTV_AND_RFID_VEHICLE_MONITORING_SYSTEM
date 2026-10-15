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
            'startupChecks' => $this->startupChecks($runtime, $detectorRuntimeService),
        ]);
    }

    /**
     * Startup checks (shown at the top of System status): the detector runs
     * the current code, and every gate's calibration can be used.
     *
     * @param  array<string, mixed>  $runtime
     * @return list<array{tone: string, title: string, detail: string}>
     */
    protected function startupChecks(array $runtime, DetectorRuntimeService $detectorRuntimeService): array
    {
        $checks = [];
        $code = $detectorRuntimeService->codeCheck($runtime);
        $checks[] = match ($code['state']) {
            'current' => ['tone' => 'success', 'title' => 'Detector code is current', 'detail' => 'Version '.$code['running'].($code['started_at'] ? ', started '.\App\Support\DisplayTime::datetimeSeconds($code['started_at']) : '').'.'],
            'old' => ['tone' => 'critical', 'title' => 'Old detector code is running', 'detail' => 'It runs '.($code['running'] ?? 'a version from before this check').'; the code on this PC is '.$code['on_disk'].'. It is being restarted; if this stays, restart the detector (or the PC).'],
            default => ['tone' => 'warning', 'title' => 'Detector not running', 'detail' => 'The code version is checked once it runs (code on this PC: '.($code['on_disk'] ?? 'not found').').'],
        };

        // Shared camera work: a LAN that keeps dropping takes every camera and reader with it.
        $lan = \App\Support\LanHealth::problem((array) data_get(app(\App\Services\DeviceServiceRuntime::class)->readStatus(), 'network', []));
        if ($lan) {
            $checks[] = ['tone' => 'critical', 'title' => 'The LAN connection is not steady', 'detail' => $lan['line'].' '.$lan['next_step']];
        }

        foreach (\App\Models\Gate::ordered() as $gate) {
            $status = $runtime['cameras'][$gate->code]['detection_status'] ?? null;
            if (in_array($status['code'] ?? null, ['zone_invalid', 'no_zone'], true)) {
                $checks[] = ['tone' => $status['code'] === 'zone_invalid' ? 'critical' : 'warning', 'title' => $gate->name.': '.$status['label'], 'detail' => trim($status['message'].' '.($status['next_step'] ?? ''))];
            }
            if ($gate->awayDirection() === null) {
                $checks[] = ['tone' => 'warning', 'title' => $gate->name.': IN / OUT not set', 'detail' => 'Vehicles are still detected and recorded, as "Direction unknown". Choose IN / OUT in Settings › Calibration.'];
            }
        }

        return $checks;
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
