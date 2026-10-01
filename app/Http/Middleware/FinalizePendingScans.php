<?php

namespace App\Http\Middleware;

use App\Services\RfidCameraFusionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Phase 3 (visitor model): registered tag reads wait up to ~20 s for the
 * camera's crossing. Every request first closes the ones whose time ran out
 * ("scan only", or the vehicle's state when the camera went offline), so the
 * kiosk, logs and dashboard never show a read waiting forever. One indexed
 * query when nothing is waiting; there is no scheduler on the gate PC.
 */
class FinalizePendingScans
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            app(RfidCameraFusionService::class)->finalizeExpired();
        } catch (Throwable $exception) {
            // Never block a page or an API call over this.
            Log::warning('Finalizing pending RFID scans failed.', ['message' => $exception->getMessage()]);
        }

        return $next($request);
    }
}
