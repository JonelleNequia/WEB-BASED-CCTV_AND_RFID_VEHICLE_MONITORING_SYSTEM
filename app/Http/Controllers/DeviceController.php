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

    public function find(DeviceRegistryService $registry, DeviceServiceRuntime $runtime): JsonResponse
    {
        $registry->requestFind(90);
        $runtime->ensureRunning();

        return response()->json(['message' => 'Recording the devices already on the network. Wait for the prompt, then plug in the reader.']);
    }

    public function identify(DeviceRegistryService $registry, DeviceServiceRuntime $runtime): JsonResponse
    {
        $seconds = (int) data_get(\App\Support\DeviceFiles::profiles(), 'uhf_reader.identify_seconds', 45);
        $registry->requestIdentify($seconds);
        $runtime->ensureRunning();

        return response()->json([
            'message' => "Listening for {$seconds} seconds. Hold a UHF tag close to the reader now.",
            'seconds' => $seconds,
        ]);
    }

    public function assign(Request $request, NetworkDevice $networkDevice, DeviceRegistryService $registry): JsonResponse
    {
        $validated = $request->validate([
            'station' => ['required', Rule::in(DeviceAssignment::STATIONS)],
            'role' => ['required', Rule::in([DeviceAssignment::ROLE_CAMERA, DeviceAssignment::ROLE_READER])],
            'stream' => ['nullable', 'in:main,sub'],
            'snapshots' => ['nullable', 'boolean'],
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

    /**
     * Live-latency work: camera encoder settings, current vs recommended.
     */
    public function encoderPreview(string $station, \App\Services\CameraEncoderService $encoders): JsonResponse
    {
        abort_unless(in_array($station, DeviceAssignment::STATIONS, true), 404);
        $result = $encoders->preview($station);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function encoderOptimize(string $station, \App\Services\CameraEncoderService $encoders): JsonResponse
    {
        abort_unless(in_array($station, DeviceAssignment::STATIONS, true), 404);
        $result = $encoders->optimize($station);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Sidebar: UHF reader connection and last tag (polled).
     */
    public function uhfStatus(DeviceServiceRuntime $runtime): JsonResponse
    {
        return response()->json(['readers' => $runtime->uhfReaders()])->header('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * Registry: tags read by the UHF readers since `after` (Unix seconds).
     */
    public function uhfReads(Request $request, DeviceServiceRuntime $runtime): JsonResponse
    {
        $after = (float) $request->validate(['after' => ['required', 'numeric']])['after'];

        return response()->json($runtime->recentUhfReads($after))->header('Cache-Control', 'no-store, max-age=0');
    }

    public function acknowledge(DeviceRegistryService $registry): JsonResponse
    {
        $registry->acknowledge();

        return response()->json(['ok' => true]);
    }
}
