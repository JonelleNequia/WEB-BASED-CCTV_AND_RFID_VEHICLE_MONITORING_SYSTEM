<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesIntegration;
use App\Http\Controllers\Controller;
use App\Services\DeviceRegistryService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plug-and-detect: scan results from the Python device service.
 */
class DeviceIntegrationController extends Controller
{
    use AuthorizesIntegration;

    public function store(Request $request, SettingsService $settingsService, DeviceRegistryService $registry): JsonResponse
    {
        if ($denied = $this->authorizeIntegrationRequest($request, $settingsService)) {
            return $denied;
        }

        $validated = $request->validate([
            'scan' => ['nullable', 'array'],
            'scan.complete' => ['nullable', 'boolean'],
            'devices' => ['present', 'array', 'max:2048'],
            'devices.*.ip' => ['required', 'ip'],
            'devices.*.mac' => ['nullable', 'string', 'regex:/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/'],
            'devices.*.kind' => ['nullable', 'in:camera,rfid_reader,router,unknown'],
        ]);

        return response()->json([
            'message' => 'Scan stored.',
            'summary' => $registry->ingestScan($request->all() + $validated),
        ]);
    }
}
