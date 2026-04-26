<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteVehicleEventRequest;
use App\Http\Requests\StoreVehicleEventRequest;
use App\Models\Camera;
use App\Models\Roi;
use App\Models\VehicleEvent;
use App\Services\EventService;
use App\Services\VehicleRegistryService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VehicleEventController extends Controller
{
    /**
     * Display a searchable and paginated event log.
     */
    public function index(Request $request): View
    {
        $dateFrom = $this->parseDate($request->string('date_from')->value());
        $dateTo = $this->parseDate($request->string('date_to')->value());

        $events = VehicleEvent::query()
            ->with(['camera', 'matchedEntry', 'vehicle', 'rfidScanLog.vehicleRfidTag'])
            ->when($request->filled('plate_text'), function ($query) use ($request): void {
                $query->where('plate_text', 'like', '%'.$request->string('plate_text')->trim().'%');
            })
            ->when($request->filled('event_type'), function ($query) use ($request): void {
                $query->where('event_type', $request->string('event_type')->upper()->value());
            })
            ->when($request->filled('match_status'), function ($query) use ($request): void {
                $selectedStatus = $request->string('match_status')->value();

                if ($selectedStatus === VehicleEvent::STATUS_PENDING_DETAILS) {
                    $query->where('event_status', VehicleEvent::STATUS_PENDING_DETAILS);

                    return;
                }

                $query->where('match_status', $selectedStatus);
            })
            ->when($dateFrom !== null, function ($query) use ($dateFrom): void {
                $query->where('event_time', '>=', $dateFrom->startOfDay());
            })
            ->when($dateTo !== null, function ($query) use ($dateTo): void {
                $query->where('event_time', '<=', $dateTo->endOfDay());
            })
            ->orderByDesc('event_time')
            ->paginate(10)
            ->withQueryString();

        return view('vehicle-events.index', [
            'events' => $events,
            'filters' => $request->only(['plate_text', 'event_type', 'match_status', 'date_from', 'date_to']),
        ]);
    }

    /**
     * Show the manual event creation form.
     */
    public function create(Request $request, VehicleRegistryService $vehicleRegistryService): View
    {
        $eventType = strtoupper($request->string('event_type', 'ENTRY')->value());

        if (! in_array($eventType, ['ENTRY', 'EXIT'], true)) {
            $eventType = 'ENTRY';
        }

        return view('vehicle-events.create', [
            'eventType' => $eventType,
            'cameras' => Camera::query()->orderBy('camera_name')->get(),
            'rois' => Roi::query()->with('camera')->orderBy('roi_name')->get(),
            'vehicleTypes' => $vehicleRegistryService->vehicleTypes(),
            'vehicleColors' => $vehicleRegistryService->vehicleColors(),
        ]);
    }

    /**
     * Store a manual event and trigger the entry or exit workflow.
     */
    public function store(StoreVehicleEventRequest $request, EventService $eventService): RedirectResponse
    {
        $vehicleEvent = $eventService->create($request->validated());

        $message = $vehicleEvent->event_type === 'ENTRY'
            ? 'ENTRY event saved and active session opened.'
            : 'EXIT event saved and automatic matching completed.';

        return redirect()
            ->route('vehicle-events.show', $vehicleEvent)
            ->with('status', $message);
    }

    /**
     * Show the details of one event record.
     */
    public function show(VehicleEvent $vehicleEvent, VehicleRegistryService $vehicleRegistryService): View
    {
        return view('vehicle-events.show', [
            'vehicleEvent' => $vehicleEvent->load([
                'camera',
                'vehicle.rfidTags',
                'rfidScanLog.vehicleRfidTag',
                'matchedEntry.camera',
                'activeSession',
            ]),
            'vehicleTypes' => $vehicleRegistryService->vehicleTypes(),
            'vehicleColors' => $vehicleRegistryService->vehicleColors(),
        ]);
    }

    /**
     * Complete an auto-detected event with the manual details required by the workflow.
     */
    public function complete(
        CompleteVehicleEventRequest $request,
        VehicleEvent $vehicleEvent,
        EventService $eventService
    ): RedirectResponse {
        $completedEvent = $eventService->completePendingEvent($vehicleEvent, $request->validated());

        $message = $completedEvent->event_type === 'ENTRY'
            ? 'Incomplete ENTRY record completed and active session opened.'
            : 'Incomplete EXIT record completed and automatic matching finished.';

        return redirect()
            ->route('vehicle-events.show', $completedEvent)
            ->with('status', $message);
    }

    /**
     * Parse one date filter safely without throwing.
     */
    protected function parseDate(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

}
