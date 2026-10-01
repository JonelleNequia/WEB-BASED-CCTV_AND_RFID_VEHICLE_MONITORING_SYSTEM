<?php

namespace App\Http\Controllers;

use App\Http\Requests\SimulateRfidScanRequest;
use App\Models\RfidScanLog;
use App\Support\PhilippineTime;
use App\Services\RfidService;
use App\Services\SettingsService;
use App\Services\VehicleRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class RfidScanController extends Controller
{
    /**
     * UI Phase 2: Activity Logs › RFID Scans tab (the old RFID Desk history).
     */
    public function history(Request $request, RfidService $rfidService): View
    {
        $filters = $request->only(['history_q', 'scan_location', 'verification_status']);

        return view('logs.index', [
            'tab' => 'scans',
            'scanLogs' => $rfidService->scanHistory($filters, 12),
            'filters' => $filters,
        ]);
    }

    /**
     * UI Phase 2: Settings › Test Scan tab (the old RFID Desk simulation).
     */
    public function testScan(
        RfidService $rfidService,
        SettingsService $settingsService,
        VehicleRegistryService $vehicleRegistryService
    ): View {
        return view('settings.index', [
            'tab' => 'test-scan',
            'latestScan' => $rfidService->recentScans(1)->first(),
            'rfidStats' => $rfidService->stats(),
            'registeredTags' => $vehicleRegistryService->registeredTags(),
            // Phase 4: anomalies for "Needs Attention".
            'attentionItems' => RfidScanLog::query()
                ->with('vehicleRfidTag')
                ->where('is_anomaly', true)
                ->where(fn ($query) => PhilippineTime::constrainTodayAny($query, ['scan_time', 'created_at']))
                ->latest('scan_time')
                ->limit(5)
                ->get(),
            'settings' => $settingsService->all(),
        ]);
    }

    /**
     * Simulate one RFID scan for local development without hardware.
     */
    public function store(
        SimulateRfidScanRequest $request,
        RfidService $rfidService
    ): RedirectResponse|JsonResponse {
        try {
            // Phase 3: simulate() returns the shared ingest result.
            $result = $rfidService->simulate($request->validated());
            $scanLog = $result->scanLog;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Simulated RFID scan failed.', [
                'message' => $exception->getMessage(),
                'payload' => $request->except(['_token']),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'RFID scan could not be recorded. Check laravel.log for details.',
                ], 500);
            }

            return back()
                ->withInput()
                ->withErrors(['rfid_scan' => 'RFID scan could not be recorded. Check laravel.log for details.']);
        }

        $statusMessage = $result->message;

        if ($request->expectsJson()) {
            return response()->json([
                ...$this->rfidScanResponsePayload($scanLog),
                ...$result->toArray(),
            ], $result->isDuplicate() ? 200 : 201);
        }

        return back()->with(
            'status',
            $statusMessage
        );
    }

    /**
     * Build the JSON contract used by the monitor after one state-based RFID scan.
     *
     * @return array<string, mixed>
     */
    protected function rfidScanResponsePayload(RfidScanLog $scanLog): array
    {
        $scanLog->loadMissing([
            'vehicle.rfidTag',
            'correlatedVehicleEvent',
            'guestVehicleObservation',
        ]);

        $verified = $scanLog->verification_status === 'verified';
        $vehicle = $scanLog->vehicle;

        return [
            'vehicle' => $vehicle ? [
                'id' => $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'owner_name' => $vehicle->owner_name,
                'category' => $vehicle->category,
                'vehicle_type' => $vehicle->vehicle_type,
                'rfid_tag_uid' => $vehicle->rfidTag?->uid ?? $vehicle->rfid_tag_uid,
                'current_state' => $vehicle->current_state,
            ] : null,
            'action_taken' => $verified ? $scanLog->resolved_event_type : null,
            'new_state' => $verified ? $scanLog->resulting_state : null,
            'event' => $scanLog->correlatedVehicleEvent ? [
                'id' => $scanLog->correlatedVehicleEvent->id,
                'type' => $scanLog->correlatedVehicleEvent->event_type,
                'event_time' => $scanLog->correlatedVehicleEvent->event_time?->toIso8601String(),
            ] : null,
            'scan' => [
                'id' => $scanLog->id,
                'tag_uid' => $scanLog->tag_uid,
                'verification_status' => $scanLog->verification_status,
                'verification_label' => $scanLog->verificationLabel,
                'scan_location' => $scanLog->scan_location,
                'event_type' => $scanLog->resolved_event_type,
                'resulting_state' => $scanLog->resulting_state,
                'vehicle_plate' => $vehicle?->plate_number,
                'vehicle_event_id' => $scanLog->correlated_vehicle_event_id,
                'guest_observation_id' => $scanLog->guest_vehicle_observation_id,
                'guest_snapshot_url' => $scanLog->guestVehicleObservation?->snapshot_url,
            ],
        ];
    }
}
