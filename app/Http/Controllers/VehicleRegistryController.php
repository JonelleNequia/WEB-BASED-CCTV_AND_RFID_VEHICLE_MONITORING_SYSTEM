<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVehicleRegistrationRequest;
use App\Services\RfidService;
use App\Services\VehicleRegistryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class VehicleRegistryController extends Controller
{
    /**
     * Show the registered vehicles and RFID tags page.
     */
    public function index(
        VehicleRegistryService $vehicleRegistryService,
        RfidService $rfidService
    ): View {
        return view('vehicle-registry.index', [
            'vehicles' => $vehicleRegistryService->registeredVehicles(),
            'registeredTags' => $vehicleRegistryService->registeredTags(),
            'vehicleTypes' => $vehicleRegistryService->vehicleTypes(),
            'vehicleCategories' => $vehicleRegistryService->vehicleCategories(),
            'vehicleColors' => $vehicleRegistryService->vehicleColors(),
            'rfidStats' => $rfidService->stats(),
        ]);
    }

    /**
     * Store one registered vehicle and optional RFID tag.
     */
    public function store(
        StoreVehicleRegistrationRequest $request,
        VehicleRegistryService $vehicleRegistryService
    ): RedirectResponse {
        $vehicle = $vehicleRegistryService->register($request->validated());

        return back()->with('status', $vehicle->plate_number.' was saved to the local vehicle registry.');
    }
}
