<?php

use App\Services\DetectorRuntimeService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;

// Phase 1: start (or confirm) the detector without opening a Station page,
// e.g. from Windows Task Scheduler at startup.
Artisan::command('detector:start', function (DetectorRuntimeService $detectorRuntimeService) {
    Cache::forget('detector-runtime-heartbeat');
    $status = $detectorRuntimeService->ensureRunning();

    $this->info((string) ($status['auto_start_message'] ?? 'Detector check finished.'));
    $this->line('Service running: '.(($status['service_running'] ?? false) ? 'yes' : 'no'));
})->purpose('Start the Python vehicle detector in the background');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
