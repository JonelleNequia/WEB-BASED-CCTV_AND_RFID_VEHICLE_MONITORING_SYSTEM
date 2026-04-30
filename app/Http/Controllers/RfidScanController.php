<?php

namespace App\Http\Controllers;

use App\Http\Requests\SimulateRfidScanRequest;
use App\Models\RfidScanLog;
use App\Services\RfidService;
use App\Services\SettingsService;
use App\Services\VehicleRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RfidScanController extends Controller
{
    /**
     * Show recent RFID scans and the simulation form.
     */
    public function index(
        RfidService $rfidService,
        SettingsService $settingsService,
        VehicleRegistryService $vehicleRegistryService
    ): View {
        return view('rfid-scans.index', [
            'scanLogs' => $rfidService->recentScans(12),
            'rfidStats' => $rfidService->stats(),
            'registeredTags' => $vehicleRegistryService->registeredTags(),
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
        $scanLog = $rfidService->simulate($request->validated());

        $statusMessage = match ($scanLog->verification_status) {
            'verified' => 'RFID scan recorded. '
                .$scanLog->resolvedEventTypeLabel
                .' applied for '
                .($scanLog->vehicle?->plate_number ?? 'registered vehicle')
                .' and current state is '
                .$scanLog->resultingStateLabel.'.',
            'non_recurring_category' => 'RFID scan recorded, but this vehicle category is configured for guest/manual monitoring.',
            'inactive_tag' => 'RFID scan recorded, but the assigned tag is inactive.',
            'unassigned_tag' => 'RFID scan recorded, but this tag is still available in inventory and is not assigned to a vehicle.',
            'inactive_vehicle' => 'RFID scan recorded, but the vehicle record is inactive.',
            default => 'RFID scan recorded, but the tag is not recognized in the registry.',
        };

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $statusMessage,
                ...$this->rfidScanResponsePayload($scanLog),
            ], 201);
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
