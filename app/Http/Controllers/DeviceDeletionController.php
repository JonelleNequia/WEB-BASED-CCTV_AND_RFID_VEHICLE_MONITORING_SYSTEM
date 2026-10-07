<?php

namespace App\Http\Controllers;

use App\Models\NetworkDevice;
use App\Services\DeviceDeletionService;
use App\Services\DeviceRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Delete device work: "Delete device", "Hide this device" and "Show
 * hidden devices" (admin only, Settings routes).
 */
class DeviceDeletionController extends Controller
{
    public function summary(NetworkDevice $networkDevice, DeviceDeletionService $deletion): JsonResponse
    {
        return response()->json($deletion->summary($networkDevice));
    }

    public function destroy(Request $request, NetworkDevice $networkDevice, DeviceDeletionService $deletion): JsonResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm the deletion first.']);
        $result = $deletion->delete($networkDevice, $request->user());

        return response()->json(['ok' => true, ...$result]);
    }

    public function hide(NetworkDevice $networkDevice, DeviceDeletionService $deletion, DeviceRegistryService $registry): JsonResponse
    {
        $deletion->hide($networkDevice);

        return response()->json(['ok' => true, 'message' => 'Hidden. It is listed under "Show hidden devices".', 'devices' => $registry->panelPayload()]);
    }

    public function unhide(NetworkDevice $networkDevice, DeviceDeletionService $deletion, DeviceRegistryService $registry): JsonResponse
    {
        $deletion->unhide($networkDevice);

        return response()->json(['ok' => true, 'message' => 'Shown again in the device list.', 'devices' => $registry->panelPayload()]);
    }
}
