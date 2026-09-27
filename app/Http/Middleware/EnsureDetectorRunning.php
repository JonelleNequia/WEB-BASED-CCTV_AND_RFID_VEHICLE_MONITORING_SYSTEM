<?php

namespace App\Http\Middleware;

use App\Services\DetectorRuntimeService;
use App\Services\DeviceServiceRuntime;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Phase 1: keep the Python detector running while anyone uses the system.
 *
 * Before, only the Station, Calibration, Monitoring and System Status pages
 * started the detector, so vehicle detection stopped when those were closed.
 * The check runs at most once every 20 seconds.
 */
class EnsureDetectorRunning
{
    protected const CHECK_EVERY_SECONDS = 20;

    public function __construct(
        protected DetectorRuntimeService $detectorRuntimeService,
        protected DeviceServiceRuntime $deviceServiceRuntime
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (app()->runningUnitTests()) {
            return $response;
        }

        try {
            if (Cache::add('detector-runtime-heartbeat', true, self::CHECK_EVERY_SECONDS)) {
                $this->detectorRuntimeService->ensureRunning();
            }
        } catch (Throwable) {
            // Never break a page because the detector check failed.
        }

        try {
            // Plug-and-detect: keep the device service (network watcher,
            // discovery, UHF reader link) running too.
            if (Cache::add('device-service-heartbeat', true, self::CHECK_EVERY_SECONDS)) {
                $this->deviceServiceRuntime->ensureRunning();
            }
        } catch (Throwable) {
            // Never break a page because the device service check failed.
        }

        return $response;
    }
}
