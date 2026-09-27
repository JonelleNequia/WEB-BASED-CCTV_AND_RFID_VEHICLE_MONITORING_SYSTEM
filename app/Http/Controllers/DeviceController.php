<?php

namespace App\Http\Controllers;

use App\Models\DeviceAssignment;
use App\Models\NetworkDevice;
use App\Services\DeviceRegistryService;
use App\Services\DeviceServiceRuntime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Plug-and-detect: Settings › Stations & Readers › Devices.
 */
class DeviceController extends Controller
{
    public function index(DeviceRegistryService $registry): JsonResponse
    {
        return response()->json($registry->panelPayload())->header('Cache-Control', 'no-store, max-age=0');
    }

    public function scan(DeviceRegistryService $registry, DeviceServiceRuntime $runtime): JsonResponse
    {
        $registry->requestScan();
        $started = $runtime->ensureRunning();

        return response()->json([
            'message' => $started
                ? 'Starting the device service, then scanning. This takes about 15 seconds.'
                : 'Scanning the network. New devices appear here in a few seconds.',
        ]);
    }

    public function assign(Request $request, NetworkDevice $networkDevice, DeviceRegistryService $registry): JsonResponse
    {
        $validated = $request->validate([
            'station' => ['required', Rule::in(DeviceAssignment::STATIONS)],
            'role' => ['required', Rule::in([DeviceAssignment::ROLE_CAMERA, DeviceAssignment::ROLE_READER])],
            'stream' => ['nullable', 'in:main,sub'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'transport' => ['nullable', 'in:tcp,udp'],
        ]);

        $result = $registry->assign($networkDevice, $validated['station'], $validated['role'], $validated);

        return response()->json($result + ['devices' => $registry->panelPayload()], $result['ok'] ? 200 : 422);
    }

    public function unassign(Request $request, DeviceRegistryService $registry): JsonResponse
    {
        $validated = $request->validate([
            'station' => ['required', Rule::in(DeviceAssignment::STATIONS)],
            'role' => ['required', Rule::in([DeviceAssignment::ROLE_CAMERA, DeviceAssignment::ROLE_READER])],
        ]);

        $registry->unassign($validated['station'], $validated['role']);

        return response()->json([
            'ok' => true,
            'message' => ucfirst($validated['station']).' '.$validated['role'].' unassigned.',
            'devices' => $registry->panelPayload(),
        ]);
    }

    public function acknowledge(DeviceRegistryService $registry): JsonResponse
    {
        $registry->acknowledge();

        return response()->json(['ok' => true]);
    }
}
