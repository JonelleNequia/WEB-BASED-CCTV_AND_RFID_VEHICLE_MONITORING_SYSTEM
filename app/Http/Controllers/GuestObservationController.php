<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGuestObservationRequest;
use App\Models\Camera;
use App\Models\GuestVehicleObservation;
use App\Services\GuestObservationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GuestObservationController extends Controller
{
    /**
     * Show guest monitoring form and log history.
     */
    public function index(Request $request, GuestObservationService $guestObservationService): View
    {
        return view('guest-observations.index', [
            'filters' => $request->only(['plate_text', 'location', 'observation_source', 'date_from', 'date_to']),
            'observations' => $guestObservationService->paginated($request->all(), 10),
            'guestCountToday' => $guestObservationService->countToday(),
            'cameras' => Camera::query()->orderBy('camera_name')->get(),
            'latestUnregisteredCapture' => GuestVehicleObservation::query()
                ->with('camera')
                ->where('observation_source', 'cctv')
                ->where('vehicle_type', 'Unregistered')
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
        $guestObservationService->create($request->validated(), auth()->id());

        return back()->with('status', 'Guest observation saved.');
    }
}
