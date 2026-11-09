<?php

namespace App\Http\Middleware;

use App\Services\LifecycleBarrier;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;

class LifecycleWriterBarrier
{
    public function handle(Request $request, Closure $next)
    {
        $barrier = app(LifecycleBarrier::class);
        if (! $barrier->enabled() || $request->is('up')) return $next($request);
        // Authenticate before a maintenance response, without invoking legacy audit writers.
        $integration = $request->is('api/v1/integration/*', 'api/latest-scan', 'api/check-latest-scan', 'api/guest-observation');
        if ($integration) {
            $key = app(SettingsService::class)->detectorApiKey();
            if ($key === '' || ! hash_equals($key, trim((string) $request->header('X-Api-Key', '')))) {
                return response()->json(['message' => 'API key is missing or invalid.'], 401);
            }
        }
        try { $barrier->acquire($request->is('api/v1/integration/deliveries')); }
        catch (\Throwable) {
            return $integration || $request->expectsJson()
                ? response()->json(['message' => 'Maintenance active; retain the original delivery.', 'maintenance' => true], 503, ['Retry-After' => '30'])
                : response('Maintenance is in progress. Please try again later.', 503)->header('Retry-After', '30');
        }
        try { return $next($request); }
        finally { $barrier->release(); }
    }
}
