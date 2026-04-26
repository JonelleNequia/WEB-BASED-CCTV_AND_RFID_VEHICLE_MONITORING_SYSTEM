<?php

namespace App\Http\Controllers;

use App\Models\VehicleEvent;
use Illuminate\View\View;

class IncompleteRecordController extends Controller
{
    /**
     * Show records that still need completion details.
     */
    public function index(): View
    {
        return view('incomplete-records.index', [
            'events' => VehicleEvent::query()
                ->with(['camera', 'vehicle', 'rfidScanLog'])
                ->where('event_status', VehicleEvent::STATUS_PENDING_DETAILS)
                ->orderByDesc('event_time')
                ->paginate(10),
        ]);
    }
}
