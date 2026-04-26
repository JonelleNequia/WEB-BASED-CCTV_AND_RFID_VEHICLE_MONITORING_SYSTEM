<?php

namespace App\Http\Controllers;

use App\Http\Requests\SimulateRfidScanRequest;
use App\Services\RfidService;
use App\Services\SettingsService;
use App\Services\VehicleRegistryService;
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
    ): RedirectResponse {
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
            'inactive_vehicle' => 'RFID scan recorded, but the vehicle record is inactive.',
            default => 'RFID scan recorded, but the tag is not recognized in the registry.',
        };

        return back()->with(
            'status',
            $statusMessage
        );
    }
}
