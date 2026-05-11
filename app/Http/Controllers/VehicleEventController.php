<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteVehicleEventRequest;
use App\Http\Requests\StoreVehicleEventRequest;
use App\Models\Camera;
use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\Roi;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\EventService;
use App\Services\VehicleRegistryService;
use App\Support\PhilippineTime;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class VehicleEventController extends Controller
{
    /**
     * Display a searchable and paginated event log.
     */
    public function index(Request $request): View
    {
        $filteredLogs = $this->filteredUnifiedLogs($request);
        $logs = $this->paginatedUnifiedLogCollection($request, $filteredLogs, 10);

        return view('vehicle-events.index', [
            'logs' => $logs,
            'events' => $logs,
            'eventLogSummary' => $this->eventLogSummary($filteredLogs),
            'selectedPeriodLabel' => $this->selectedPeriodLabel($request),
            'filters' => $request->only([
                'plate_text',
                'event_type',
                'match_status',
                'date_from',
                'date_to',
                'period',
                'category',
                'vehicle_owner_name',
            ]),
            'periodOptions' => $this->periodOptions(),
            'categoryOptions' => $this->categoryOptions(),
        ]);
    }

    /**
     * Export filtered vehicle events as CSV.
     */
    public function exportCsv(Request $request)
    {
        $logs = $this->paginatedUnifiedLogs($request, 10);
        $filename = 'vehicle-events-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($logs): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Record Type',
                'ID',
                'Type',
                'Plate',
                'Owner',
                'Vehicle Type',
                'Color',
                'Category',
                'Source',
                'Station / Camera',
                'State',
                'Event Time',
                'Status',
                'Match Status',
                'RFID Tag',
            ]);

            $logs->getCollection()->each(function (array $log) use ($handle): void {
                fputcsv($handle, [
                    $log['record_type_label'],
                    $log['id'],
                    $log['event_type'],
                    $log['plate_number'],
                    $log['owner_name'],
                    $log['vehicle_type'],
                    $log['vehicle_color'],
                    $log['category_label'],
                    $log['source_label'],
                    $log['station_label'],
                    $log['state_label'],
                    $log['event_time_export'],
                    $log['status_label'],
                    $log['match_label'],
                    $log['rfid_tag_uid'],
                ]);
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
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

    /**
     * @return array<string, string>
     */
    protected function periodOptions(): array
    {
        return [
            'today' => 'Today',
            'week' => 'This Week',
            'month' => 'This Month',
            'year' => 'This Year',
        ];
    }

    protected function selectedPeriod(Request $request): ?string
    {
        $period = $request->string('period')->lower()->value();

        return array_key_exists($period, $this->periodOptions()) ? $period : null;
    }

    protected function selectedPeriodLabel(Request $request): string
    {
        $period = $this->selectedPeriod($request);

        if ($period !== null) {
            return $this->periodOptions()[$period];
        }

        if ($request->filled('date_from') || $request->filled('date_to')) {
            return 'Custom Range';
        }

        return 'All Records';
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    protected function dateWindowForRequest(Request $request): array
    {
        $period = $this->selectedPeriod($request);

        if ($period !== null) {
            $window = PhilippineTime::periodWindow($period);

            return [$window['local_start'], $window['local_end']];
        }

        $dateFrom = $this->parseDate($request->string('date_from')->value());
        $dateTo = $this->parseDate($request->string('date_to')->value());

        return [
            $dateFrom?->copy()->startOfDay(),
            $dateTo?->copy()->addDay()->startOfDay(),
        ];
    }

    protected function filteredEventsQuery(Request $request, ?Carbon $dateFrom = null, ?Carbon $dateUntil = null)
    {
        return VehicleEvent::query()
            ->with(['camera', 'matchedEntry', 'vehicle', 'rfidScanLog.vehicleRfidTag'])
            ->where('event_status', '!=', VehicleEvent::STATUS_PENDING_DETAILS)
            ->when($request->filled('plate_text'), function ($query) use ($request): void {
                $plate = '%'.$request->string('plate_text')->trim().'%';

                $query->where(function ($query) use ($plate): void {
                    $query->where('plate_text', 'like', $plate)
                        ->orWhere('plate_number', 'like', $plate)
                        ->orWhereHas('vehicle', fn ($vehicleQuery) => $vehicleQuery->where('plate_number', 'like', $plate));
                });
            })
            ->when($request->filled('event_type'), function ($query) use ($request): void {
                $query->where('event_type', $request->string('event_type')->upper()->value());
            })
            ->when($request->filled('match_status'), function ($query) use ($request): void {
                $selectedStatus = $request->string('match_status')->value();

                $query->where('match_status', $selectedStatus);
            })
            ->when($request->filled('category'), function ($query) use ($request): void {
                $category = $request->string('category')->value();

                $query->where(function ($query) use ($category): void {
                    $query->where('vehicle_category', $category)
                        ->orWhereHas('vehicle', fn ($vehicleQuery) => $vehicleQuery->where('category', $category));
                });
            })
            ->when($request->filled('vehicle_owner_name'), function ($query) use ($request): void {
                $owner = '%'.$request->string('vehicle_owner_name')->trim().'%';

                $query->whereHas('vehicle', function ($vehicleQuery) use ($owner): void {
                    $vehicleQuery->where('vehicle_owner_name', 'like', $owner)
                        ->orWhere('owner_name', 'like', $owner);
                });
            })
            ->when($dateFrom !== null, function ($query) use ($dateFrom): void {
                $query->where('event_time', '>=', $dateFrom->copy()->startOfDay());
            })
            ->when($dateUntil !== null, function ($query) use ($dateUntil): void {
                $query->where('event_time', '<', $dateUntil);
            });
    }

    /**
     * Build the visible report page. CSV export intentionally uses this same
     * paginator so exports only contain the currently visible records.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function paginatedUnifiedLogs(Request $request, int $perPage): LengthAwarePaginator
    {
        $logs = $this->filteredUnifiedLogs($request);

        return $this->paginatedUnifiedLogCollection($request, $logs, $perPage);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $logs
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function paginatedUnifiedLogCollection(Request $request, Collection $logs, int $perPage): LengthAwarePaginator
    {
        $page = max(1, (int) $request->query('page', 1));
        $items = $logs->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $logs->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function filteredUnifiedLogs(Request $request): Collection
    {
        [$dateFrom, $dateUntil] = $this->dateWindowForRequest($request);

        $eventLogs = $this->filteredEventsQuery($request, $dateFrom, $dateUntil)
            ->get()
            ->map(fn (VehicleEvent $event): array => $this->vehicleEventLogPayload($event));

        $guestLogs = $this->filteredGuestLogs($request, $dateFrom, $dateUntil)
            ->get()
            ->map(fn (GuestVehicleObservation $observation): array => $this->guestLogPayload($observation));
        $rfidOnlyLogs = $this->filteredRfidOnlyLogs($request, $dateFrom, $dateUntil)
            ->get()
            ->map(fn (RfidScanLog $scanLog): array => $this->rfidOnlyLogPayload($scanLog));

        return $eventLogs
            ->concat($guestLogs)
            ->concat($rfidOnlyLogs)
            ->sortByDesc('sort_time')
            ->values();
    }

    protected function filteredGuestLogs(Request $request, ?Carbon $dateFrom = null, ?Carbon $dateUntil = null)
    {
        $showingGuestOnly = $request->filled('event_type')
            && $request->string('event_type')->upper()->value() === 'GUEST';

        return GuestVehicleObservation::query()
            ->with('camera')
            ->when(! $showingGuestOnly, function ($query): void {
                $query->where(function ($query): void {
                    $query->whereNull('external_event_key')
                        ->orWhereNotExists(function ($subquery): void {
                            $subquery->selectRaw('1')
                                ->from('vehicle_events')
                                ->whereColumn('vehicle_events.external_event_key', 'guest_vehicle_observations.external_event_key')
                                ->whereIn('vehicle_events.event_origin', ['guest_cctv', 'guest_manual']);
                        });
                });
            })
            ->when($request->filled('plate_text'), function ($query) use ($request): void {
                $plate = '%'.$request->string('plate_text')->trim().'%';

                $query->where(function ($query) use ($plate): void {
                    $query->where('plate_number', 'like', $plate)
                        ->orWhere('plate_text', 'like', $plate);
                });
            })
            ->when($request->filled('event_type'), function ($query) use ($request): void {
                if ($request->string('event_type')->upper()->value() !== 'GUEST') {
                    $query->whereRaw('1 = 0');
                }
            })
            ->when($request->filled('match_status'), function ($query) use ($request): void {
                $status = $request->string('match_status')->value();

                if (in_array($status, ['pending_review', 'reviewed', 'verified'], true)) {
                    $query->where('status', $status);

                    return;
                }

                $query->whereRaw('1 = 0');
            })
            ->when($request->filled('category') || $request->filled('vehicle_owner_name'), function ($query): void {
                $query->whereRaw('1 = 0');
            })
            ->when($dateFrom !== null, function ($query) use ($dateFrom): void {
                $query->where('observed_at', '>=', $dateFrom->copy()->startOfDay());
            })
            ->when($dateUntil !== null, function ($query) use ($dateUntil): void {
                $query->where('observed_at', '<', $dateUntil);
            });
    }

    protected function filteredRfidOnlyLogs(Request $request, ?Carbon $dateFrom = null, ?Carbon $dateUntil = null)
    {
        return RfidScanLog::query()
            ->with(['vehicle', 'vehicleRfidTag'])
            ->whereNull('correlated_vehicle_event_id')
            ->whereNull('guest_vehicle_observation_id')
            ->when($request->filled('plate_text'), function ($query) use ($request): void {
                $term = '%'.$request->string('plate_text')->trim().'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('tag_uid', 'like', $term)
                        ->orWhereHas('vehicle', fn ($vehicleQuery) => $vehicleQuery->where('plate_number', 'like', $term));
                });
            })
            ->when($request->filled('event_type'), function ($query) use ($request): void {
                if ($request->string('event_type')->upper()->value() !== 'RFID') {
                    $query->whereRaw('1 = 0');
                }
            })
            ->when($request->filled('match_status'), function ($query): void {
                $query->whereRaw('1 = 0');
            })
            ->when($request->filled('category'), function ($query) use ($request): void {
                $category = $request->string('category')->value();

                $query->where(function ($query) use ($category): void {
                    $query->where('vehicle_category', $category)
                        ->orWhereHas('vehicle', fn ($vehicleQuery) => $vehicleQuery->where('category', $category));
                });
            })
            ->when($request->filled('vehicle_owner_name'), function ($query) use ($request): void {
                $owner = '%'.$request->string('vehicle_owner_name')->trim().'%';

                $query->whereHas('vehicle', function ($vehicleQuery) use ($owner): void {
                    $vehicleQuery->where('vehicle_owner_name', 'like', $owner)
                        ->orWhere('owner_name', 'like', $owner);
                });
            })
            ->when($dateFrom !== null, function ($query) use ($dateFrom): void {
                $query->where('scan_time', '>=', $dateFrom->copy()->startOfDay());
            })
            ->when($dateUntil !== null, function ($query) use ($dateUntil): void {
                $query->where('scan_time', '<', $dateUntil);
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $logs
     * @return array{total: int, entries: int, exits: int, guests: int, rfid: int}
     */
    protected function eventLogSummary(Collection $logs): array
    {
        return [
            'total' => $logs->count(),
            'entries' => $logs->where('event_type', 'ENTRY')->count(),
            'exits' => $logs->where('event_type', 'EXIT')->count(),
            'guests' => $logs->where('event_type', 'GUEST')->count(),
            'rfid' => $logs->where('event_type', 'RFID')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function vehicleEventLogPayload(VehicleEvent $event): array
    {
        $vehicle = $event->vehicle;
        $time = $event->event_time;

        return [
            'record_type' => 'vehicle_event',
            'record_type_label' => 'Vehicle Event',
            'id' => $event->id,
            'detail_url' => route('vehicle-events.show', $event),
            'event_type' => $event->event_type,
            'plate_number' => $event->plate_text ?: $vehicle?->plate_number ?: 'GUEST',
            'owner_name' => $vehicle?->vehicle_owner_name ?: $vehicle?->owner_name ?: 'N/A',
            'vehicle_type' => $event->display_vehicle_type,
            'vehicle_color' => $event->vehicle_color ?: 'N/A',
            'category_label' => $this->displayCategory($event->vehicle_category ?: $vehicle?->category),
            'source_label' => $event->event_origin_label,
            'station_label' => $event->camera?->camera_name ?: ($event->roi_name ?: 'No camera linked'),
            'state_label' => $event->resulting_state_label,
            'display_time' => $time?->format('M d, Y • h:i A') ?: 'No time',
            'summary_label' => 'Vehicle Event #'.$event->id.' • '.($time?->format('M d, Y • h:i A') ?: 'No time'),
            'event_time_export' => $time?->toDateTimeString(),
            'status_label' => str($event->display_status)->replace('_', ' ')->title()->value(),
            'status_badge_class' => $event->status_badge_class,
            'match_label' => $event->match_display,
            'rfid_tag_uid' => $event->rfidScanLog?->tag_uid ?: 'N/A',
            'image_url' => $event->has_visual_evidence ? $event->vehicle_image_url : null,
            'sort_time' => $this->sortTimestamp($event->created_at, $time),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function guestLogPayload(GuestVehicleObservation $observation): array
    {
        $time = $observation->observed_at;
        return [
            'record_type' => 'guest_observation',
            'record_type_label' => 'Guest Observation',
            'id' => $observation->id,
            'detail_url' => route('guest-observations.index', ['plate_text' => $observation->plate_number ?: $observation->plate_text]),
            'event_type' => 'GUEST',
            'plate_number' => $observation->plate_number ?: $observation->plate_text ?: 'GUEST',
            'owner_name' => 'N/A',
            'vehicle_type' => $observation->vehicle_type ?: 'Vehicle',
            'vehicle_color' => $observation->vehicle_color ?: 'N/A',
            'category_label' => 'Guest',
            'source_label' => $observation->observation_source === 'cctv' ? 'Guest CCTV' : 'Guest Manual',
            'station_label' => ucfirst($observation->location).' Station',
            'state_label' => 'Guest',
            'display_time' => $time?->format('M d, Y • h:i A') ?: 'No time',
            'summary_label' => 'Guest Observation #'.$observation->id.' • '.($time?->format('M d, Y • h:i A') ?: 'No time'),
            'event_time_export' => $time?->toDateTimeString(),
            'status_label' => 'Guest',
            'status_badge_class' => 'secondary',
            'match_label' => 'Guest',
            'rfid_tag_uid' => 'N/A',
            'image_url' => $observation->snapshot_path ? $observation->snapshot_url : null,
            'sort_time' => $this->sortTimestamp($observation->created_at, $time),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rfidOnlyLogPayload(RfidScanLog $scanLog): array
    {
        $vehicle = $scanLog->vehicle;
        $time = $scanLog->scan_time;

        return [
            'record_type' => 'rfid_scan',
            'record_type_label' => 'RFID Scan',
            'id' => $scanLog->id,
            'detail_url' => route('rfid-scans.index', ['history_q' => $scanLog->tag_uid]),
            'event_type' => 'RFID',
            'plate_number' => $vehicle?->plate_number ?: 'GUEST',
            'owner_name' => $vehicle?->vehicle_owner_name ?: $vehicle?->owner_name ?: 'N/A',
            'vehicle_type' => $vehicle?->vehicle_type ?: 'N/A',
            'vehicle_color' => 'N/A',
            'category_label' => $this->displayCategory($scanLog->vehicle_category ?: $vehicle?->category),
            'source_label' => 'RFID Desk',
            'station_label' => ucfirst($scanLog->scan_location).' Station',
            'state_label' => $scanLog->resultingStateLabel,
            'display_time' => $time?->format('M d, Y • h:i A') ?: 'No time',
            'summary_label' => 'RFID Scan #'.$scanLog->id.' • '.($time?->format('M d, Y • h:i A') ?: 'No time'),
            'event_time_export' => $time?->toDateTimeString(),
            'status_label' => $scanLog->verificationLabel,
            'status_badge_class' => $scanLog->verificationBadgeClass,
            'match_label' => 'No vehicle event',
            'rfid_tag_uid' => $scanLog->tag_uid,
            'image_url' => null,
            'sort_time' => $this->sortTimestamp($scanLog->created_at, $time),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function categoryOptions(): array
    {
        return VehicleEvent::query()
            ->whereNotNull('vehicle_category')
            ->distinct()
            ->orderBy('vehicle_category')
            ->pluck('vehicle_category')
            ->merge(Vehicle::RFID_RECURRING_CATEGORIES)
            ->merge(Vehicle::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'))
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $category): array => [$category => $this->displayCategory($category)])
            ->all();
    }

    protected function displayCategory(?string $category): string
    {
        if (blank($category)) {
            return 'N/A';
        }

        return str((string) $category)->replace('_', ' ')->title()->value();
    }

    protected function sortTimestamp($createdAt, $eventAt): float
    {
        return (float) ($createdAt?->format('U.u') ?? $eventAt?->format('U.u') ?? 0);
    }

}
