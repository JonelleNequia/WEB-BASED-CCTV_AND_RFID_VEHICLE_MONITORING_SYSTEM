<?php

namespace App\Http\Controllers;

use App\Models\VehicleEvent;
use App\Services\EventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ManualReviewController extends Controller
{
    /**
     * Show exit events that need manual review.
     */
    public function index(): View
    {
        return view('manual-review.index', [
            'events' => VehicleEvent::query()
                ->with(['camera', 'matchedEntry.camera'])
                ->where('event_type', 'EXIT')
                ->where('match_status', 'manual_review')
                ->orderByDesc('event_time')
                ->paginate(10),
        ]);
    }

    /**
     * Confirm the suggested entry match and close the review item.
     */
    public function markMatched(VehicleEvent $vehicleEvent, EventService $eventService): RedirectResponse
    {
        $eventService->resolveManualReview($vehicleEvent, 'matched');

        return back()->with('status', 'Review item resolved as matched.');
    }

    /**
     * Reject the suggested match and keep the exit record unmatched.
     */
    public function markUnmatched(VehicleEvent $vehicleEvent, EventService $eventService): RedirectResponse
    {
        $eventService->resolveManualReview($vehicleEvent, 'unmatched');

        return back()->with('status', 'Review item resolved as unmatched.');
    }
}
