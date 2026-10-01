<?php

namespace App\Http\Controllers;

use App\Models\RfidTag;
use App\Http\Requests\StoreVehicleRegistrationRequest;
use App\Models\Vehicle;
use App\Services\RfidService;
use App\Services\VehicleRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class VehicleRegistryController extends Controller
{
    /**
     * Show the registered vehicles and RFID tags page.
     */
    public function index(
        Request $request,
        VehicleRegistryService $vehicleRegistryService,
        RfidService $rfidService
    ): View {
        $filters = $request->only(['q', 'category', 'state', 'status']);

        // UI Phase 2: Registry › Vehicles tab (UI Phase 3: search + filters).
        return view('registry.index', [
            'tab' => 'vehicles',
            'filters' => $filters,
            'vehicles' => $vehicleRegistryService->registeredVehicles($filters),
            'availableTags' => $vehicleRegistryService->availableTags(),
            'vehicleTypes' => $vehicleRegistryService->vehicleTypes(),
            'vehicleCategories' => $vehicleRegistryService->vehicleCategories(),
            'rfidStats' => $rfidService->stats(),
        ]);
    }

    /**
     * Show the standalone RFID tag inventory workspace.
     */
    public function rfidInventory(
        Request $request,
        VehicleRegistryService $vehicleRegistryService,
        RfidService $rfidService
    ): View {
        // UI Phase 3: status filter. (Every tag is a vehicle tag since Phase 0.)
        $tagStatus = in_array($request->query('status'), RfidTag::STATUSES, true) ? $request->query('status') : null;
        $allTags = $vehicleRegistryService->rfidTagInventory();

        // UI Phase 2: Registry › RFID Tags tab.
        return view('registry.index', [
            'tab' => 'tags',
            'rfidTagInventory' => $allTags
                ->when($tagStatus, fn ($tags) => $tags->where('status', $tagStatus))
                ->values(),
            'tagStatusFilter' => $tagStatus,
            'nextTagNumber' => $vehicleRegistryService->nextTagNumber(),
            'rfidStats' => $rfidService->stats(),
            'tagStats' => [
                'total' => $allTags->count(),
                'available' => $allTags->where('status', RfidTag::STATUS_AVAILABLE)->count(),
                'assigned' => $allTags->where('status', RfidTag::STATUS_ASSIGNED)->count(),
                'lost' => $allTags->where('status', RfidTag::STATUS_LOST)->count(),
                'disabled' => $allTags->where('status', RfidTag::STATUS_DISABLED)->count(),
            ],
        ]);
    }

    /**
     * Store one RFID tag in the inventory pool before vehicle assignment.
     */
    public function storeRfidTag(
        Request $request,
        VehicleRegistryService $vehicleRegistryService
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            // UI Phase 3: bulk scanning sends auto_number instead of a number.
            'tag_number' => [
                'required_unless:auto_number,1',
                'nullable',
                'integer',
                'min:1',
                'max:999999',
                Rule::unique('vehicle_rfid_tags', 'tag_number'),
            ],
            'uid' => ['required', 'string', 'max:100'],
            'auto_number' => ['sometimes', 'boolean'],
        ]);

        try {
            $tag = $vehicleRegistryService->registerRfidTag($validated);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('RFID tag registration failed.', [
                'message' => $exception->getMessage(),
                'payload' => $request->except(['_token']),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'RFID tag could not be registered. Please check the UID and try again.',
                ], 500);
            }

            return back()
                ->withInput()
                ->withErrors(['uid' => 'RFID tag could not be registered. Please check the UID and try again.']);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'RFID #'.$tag->tag_number.' ('.$tag->uid.') was added to the RFID inventory.',
                'rfid_tag_id' => $tag->id,
                'tag_number' => $tag->tag_number,
                'uid' => $tag->uid,
                'label' => $tag->label,
            ], 201);
        }

        return redirect()
            ->route('registry.index', ['tab' => 'tags'])
            ->with('status', 'RFID #'.$tag->tag_number.' ('.$tag->uid.') was added to the RFID inventory.');
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
        } catch (ValidationException $exception) {
            throw $exception;
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
     * UI Phase 3: data for the vehicle side panel, Edit and Replace Tag drawers.
     */
    public function show(Vehicle $vehicle, VehicleRegistryService $vehicleRegistryService): JsonResponse
    {
        return response()->json($vehicleRegistryService->vehicleDetails($vehicle));
    }

    /**
     * UI Phase 3: check a scanned UID before assigning it (Add Vehicle / Replace Tag).
     */
    public function lookupTag(Request $request, VehicleRegistryService $vehicleRegistryService): JsonResponse
    {
        $validated = $request->validate([
            'uid' => ['required', 'string', 'max:100'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
        ]);

        $vehicle = isset($validated['vehicle_id']) ? Vehicle::query()->find($validated['vehicle_id']) : null;

        return response()->json($vehicleRegistryService->lookupTagForVehicle($validated['uid'], $vehicle));
    }

    /**
     * UI Phase 3: Replace Tag (damaged or lost sticker).
     */
    public function replaceTag(Request $request, Vehicle $vehicle, VehicleRegistryService $vehicleRegistryService): RedirectResponse
    {
        $validated = $request->validate([
            'rfid_tag_id' => ['nullable', 'required_without:rfid_uid', 'integer', 'exists:vehicle_rfid_tags,id'],
            'rfid_uid' => ['nullable', 'string', 'max:100'],
            'old_tag_status' => ['required', Rule::in([RfidTag::STATUS_LOST, RfidTag::STATUS_DISABLED])],
        ]);

        $oldTag = $vehicle->rfidTag;
        $vehicle = $vehicleRegistryService->replaceTag($vehicle, $validated);

        return back()->with('status', $vehicle->plate_number.' now uses '.$vehicle->rfidTag->label
            .($oldTag ? '. '.$oldTag->label.' was marked '.strtoupper($validated['old_tag_status']).'.' : '.'));
    }

    /**
     * UI Phase 3: Deactivate / Activate a vehicle.
     */
    public function updateStatus(Request $request, Vehicle $vehicle, VehicleRegistryService $vehicleRegistryService): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $vehicle = $vehicleRegistryService->setVehicleStatus($vehicle, $validated['status']);

        return back()->with('status', $vehicle->plate_number.($vehicle->status === 'active'
            ? ' is active again.'
            : ' was deactivated. Scans of its tag will be flagged.'));
    }

    /**
     * UI Phase 3: mark a tag lost or disabled, or enable it again.
     */
    public function updateTagStatus(Request $request, RfidTag $rfidTag, VehicleRegistryService $vehicleRegistryService): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([RfidTag::STATUS_LOST, RfidTag::STATUS_DISABLED, RfidTag::STATUS_AVAILABLE])],
        ]);

        $tag = $vehicleRegistryService->setTagStatus($rfidTag, $validated['status']);

        return back()->with('status', $tag->label.' is now '.strtoupper($tag->status).'.');
    }

    /**
     * Show the edit form for one registered vehicle.
     */
    public function edit(Vehicle $vehicle, VehicleRegistryService $vehicleRegistryService): View
    {
        return view('vehicle-registry.edit', [
            'vehicle' => $vehicle->load(['rfidTag', 'rfidTags']),
            'assignableTags' => $vehicleRegistryService->assignableTagsFor($vehicle),
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
        } catch (ValidationException $exception) {
            throw $exception;
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
            ->route('registry.index', ['tab' => 'vehicles'])
            ->with('status', $updatedVehicle->plate_number.' was updated.');
    }
}
