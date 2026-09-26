<?php

use App\Services\DetectorRuntimeService;
use App\Services\GuestPassService;
use Illuminate\Support\Facades\Schedule;
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

// Phase 3: move guest visits past valid_until to "overstay".
Artisan::command('guests:mark-overstay', function (GuestPassService $guestPassService) {
    $this->info('Visits marked overstay: '.$guestPassService->markOverstays());
})->purpose('Mark guest pass visits that passed their valid-until time as overstay');

Schedule::command('guests:mark-overstay')->everyMinute();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
