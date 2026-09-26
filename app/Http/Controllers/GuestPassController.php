<?php

namespace App\Http\Controllers;

use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Services\GuestPassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phase 3: guest pass actions. The Guest Passes page and the Entrance
 * Station pop-up (Phase 4) call these.
 */
class GuestPassController extends Controller
{
    /**
     * Issue an available guest pass and record the guest's ENTRY.
     */
    public function issue(Request $request, RfidTag $rfidTag, GuestPassService $guestPassService): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'plate' => ['nullable', 'string', 'max:50'],
            'driver_name' => ['nullable', 'string', 'max:150'],
            'vehicle_type' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:150'],
            'id_presented' => ['nullable', 'string', 'max:100'],
            'valid_until' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'rfid_scan_log_id' => ['nullable', 'integer', 'exists:rfid_scan_logs,id'],
        ]);

        $scanLog = isset($validated['rfid_scan_log_id'])
            ? RfidScanLog::query()->find($validated['rfid_scan_log_id'])
            : null;

        $visit = $guestPassService->issue($rfidTag, $validated, $request->user()?->id, $scanLog);
        $message = $visit->rfidTag->label.' issued'.($visit->plate ? ' to '.$visit->plate : '').'. ENTRY recorded.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'guest_visit' => [
                    'id' => $visit->id,
                    'pass' => $visit->rfidTag->display_number,
                    'plate' => $visit->plate,
                    'status' => $visit->status,
                    'entry_at' => $visit->entry_at?->toIso8601String(),
                    'valid_until' => $visit->valid_until?->toIso8601String(),
                ],
            ], 201);
        }

        return back()->with('status', $message);
    }
}
