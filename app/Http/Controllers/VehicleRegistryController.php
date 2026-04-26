<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVehicleRegistrationRequest;
use App\Models\Vehicle;
use App\Services\RfidService;
use App\Services\VehicleRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

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
            'rfidStats' => $rfidService->stats(),
        ]);
    }

    /**
     * Store one registered vehicle and optional RFID tag.
     */
    public function store(
        StoreVehicleRegistrationRequest $request,
        VehicleRegistryService $vehicleRegistryService
    ): RedirectResponse|JsonResponse {
        try {
            $vehicle = $vehicleRegistryService->register($request->validated());
        } catch (Throwable $exception) {
            Log::error('Vehicle registration failed.', [
                'message' => $exception->getMessage(),
                'payload' => $request->except(['_token']),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Vehicle could not be saved. Please check the vehicle details and try again.',
                ], 500);
            }

            return back()
                ->withInput()
                ->withErrors(['vehicle' => 'Vehicle could not be saved. Please check the vehicle details and try again.']);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $vehicle->plate_number.' was saved to the local vehicle registry.',
                'vehicle_id' => $vehicle->id,
            ], 201);
        }

        return back()->with('status', $vehicle->plate_number.' was saved to the local vehicle registry.');
    }

    /**
     * Show the edit form for one registered vehicle.
     */
    public function edit(Vehicle $vehicle, VehicleRegistryService $vehicleRegistryService): View
    {
        return view('vehicle-registry.edit', [
            'vehicle' => $vehicle->load('rfidTags'),
            'vehicleTypes' => $vehicleRegistryService->vehicleTypes(),
            'vehicleCategories' => $vehicleRegistryService->vehicleCategories(),
        ]);
    }

    /**
     * Update one registered vehicle and optional RFID tag.
     */
    public function update(
        StoreVehicleRegistrationRequest $request,
        Vehicle $vehicle,
        VehicleRegistryService $vehicleRegistryService
    ): RedirectResponse|JsonResponse {
        try {
            $updatedVehicle = $vehicleRegistryService->update($vehicle, $request->validated());
        } catch (Throwable $exception) {
            Log::error('Vehicle update failed.', [
                'vehicle_id' => $vehicle->id,
                'message' => $exception->getMessage(),
                'payload' => $request->except(['_token', '_method']),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Vehicle could not be updated. Please check the vehicle details and try again.',
                ], 500);
            }

            return back()
                ->withInput()
                ->withErrors(['vehicle' => 'Vehicle could not be updated. Please check the vehicle details and try again.']);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $updatedVehicle->plate_number.' was updated.',
                'vehicle_id' => $updatedVehicle->id,
            ]);
        }

        return redirect()
            ->route('vehicle-registry.index')
            ->with('status', $updatedVehicle->plate_number.' was updated.');
    }
}
