<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGuestObservationRequest;
use App\Http\Requests\UpdateGuestObservationRequest;
use App\Models\Camera;
use App\Models\GuestVehicleObservation;
use App\Services\GuestObservationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GuestObservationController extends Controller
{
    /**
     * Show guest monitoring form and log history.
     */
    public function index(Request $request, GuestObservationService $guestObservationService): View
    {
        return view('guest-observations.index', [
            'filters' => $request->only(['plate_text', 'location', 'date_from', 'date_to']),
            'observations' => $guestObservationService->paginated($request->all(), 10),
            'guestCountToday' => $guestObservationService->countToday(),
            'cameras' => Camera::query()->orderBy('camera_name')->get(),
            'latestUnregisteredCapture' => GuestVehicleObservation::query()
                ->with('camera')
                ->where('observation_source', 'cctv')
                ->latest('observed_at')
                ->first(),
        ]);
    }

    /**
     * Store one guest monitoring record.
     */
    public function store(
        StoreGuestObservationRequest $request,
        GuestObservationService $guestObservationService
    ): RedirectResponse {
        $data = $request->validated();

        if ($request->hasFile('snapshot')) {
            $data['snapshot_image'] = $request->file('snapshot');
        }

        $guestObservationService->create($data, auth()->id());

        return back()->with('status', 'Guest observation saved.');
    }

    /**
     * Verify or correct one detector-created guest observation.
     */
    public function update(
        UpdateGuestObservationRequest $request,
        GuestVehicleObservation $guestVehicleObservation
    ): RedirectResponse {
        $validated = $request->validated();
        $plateNumber = $this->normalizePlate($validated['plate_number'] ?? null);

        $guestVehicleObservation->update([
            'plate_number' => $plateNumber,
            'plate_text' => $plateNumber,
            'vehicle_type' => $validated['vehicle_type'] ?? null,
            'vehicle_color' => $validated['vehicle_color'] ?? null,
            'location' => $validated['location'],
            'observed_at' => $validated['observed_at'],
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('status', 'Guest observation updated.');
    }

    protected function normalizePlate(?string $plate): ?string
    {
        if (blank($plate)) {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', $plate) ?? $plate;

        return Str::upper(trim($normalized));
    }
}
