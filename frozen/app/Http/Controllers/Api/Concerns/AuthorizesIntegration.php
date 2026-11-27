<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6 key check shared by the integration API controllers
 * (detector events, RFID readers, and the plug-and-detect device service).
 */
trait AuthorizesIntegration
{
    protected function authorizeIntegrationRequest(Request $request, SettingsService $settingsService): ?JsonResponse
    {
        // Phase 6: key from .env (DETECTOR_API_KEY), not the database.
        $configuredKey = $settingsService->detectorApiKey();
        $providedKey = trim((string) $request->header('X-Api-Key', ''));

        if ($configuredKey !== '' && hash_equals($configuredKey, $providedKey)) {
            return null;
        }

        if ($settingsService->allowsKeylessLocalIntegration() && $this->isLoopbackRequest($request)) {
            return null;
        }

        return response()->json([
            'message' => 'API key is missing or invalid.',
        ], 401);
    }

    protected function isLoopbackRequest(Request $request): bool
    {
        $ip = (string) ($request->ip() ?: $request->server('REMOTE_ADDR', ''));

        return $ip === '::1'
            || $ip === 'localhost'
            || str_starts_with($ip, '127.');
    }
}
