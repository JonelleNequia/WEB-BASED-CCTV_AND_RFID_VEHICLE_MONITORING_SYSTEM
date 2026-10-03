<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteVehicleEventRequest;
use App\Http\Requests\StoreVehicleEventRequest;
use App\Models\Camera;
use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\Roi;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\EventService;
use App\Services\VehicleRegistryService;
use App\Support\DisplayTime;
use App\Support\PhilippineTime;
use App\Support\VehicleCategory;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class VehicleEventController extends Controller
{
    /**
     * Display a searchable and paginated event log.
     */
    public function index(Request $request): View|JsonResponse
    {
        $filteredLogs = $this->filteredUnifiedLogs($request);
        $logs = $this->paginatedUnifiedLogCollection($request, $filteredLogs, 15);

        // UI Phase 4: the Activity Logs table refreshes itself with the same filters.
        if ($request->wantsJson()) {
            return response()->json([
                'logs' => $logs->getCollection()->values(),
                'total' => $logs->total(),
                'summary' => $this->eventLogSummary($filteredLogs),
            ]);
        }

        // UI Phase 2: Activity Logs › All Events tab.
        return view('logs.index', [
            'tab' => 'events',
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
                'log_type',
                'gate',
            ]),
            'logTypeOptions' => self::LOG_TYPES,
            'logFilterChips' => self::LOG_FILTER_CHIPS,
            'periodOptions' => $this->periodOptions(),
            'categoryOptions' => $this->categoryOptions(),
            'gateOptions' => \App\Models\Gate::options(),
            'printReports' => $this->printReportPayload($request),
        ]);
    }

    /**
     * Export filtered vehicle events as CSV.
     */
    public function exportCsv(Request $request)
    {
        $singleLog = $this->singleUnifiedLog($request);
        // UI Phase 4: the toolbar CSV (all=1) exports every filtered row, not only the visible page.
        $rows = match (true) {
            $singleLog !== null => collect([$singleLog]),
            $request->boolean('all') => $this->filteredUnifiedLogs($request),
            default => $this->paginatedUnifiedLogs($request, 10)->getCollection(),
        };
        $filename = $singleLog !== null
            ? str($singleLog['record_type'].'-'.$singleLog['id'].'-'.now()->format('Ymd-His'))->slug().'.csv'
            : 'vehicle-events-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Type',
                'Log Type',
                'Source',
                'Plate',
                'Owner',
                'Vehicle',
                'Color',
                'Category',
                'Gate',
                'State',
                'Time',
                'Status',
                'RFID Tag',
            ]);

            $rows->each(function (array $log) use ($handle): void {
                fputcsv($handle, [
                    $log['event_type'],
                    $log['log_type_label'] ?? '',
                    $log['source_label'] ?? '',
                    $log['plate_number'],
                    $log['owner_name'],
                    $log['vehicle_type'],
                    $log['vehicle_color'],
                    $log['category_label'] ?? '',
                    $log['station_label'],
                    $log['state_label'],
                    $log['event_time_export'],
                    $log['status_label'],
                    $log['rfid_tag_uid'],
                ]);
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Resolve one unified log row for compact record-level exports.
     *
     * @return array<string, mixed>|null
     */
    protected function singleUnifiedLog(Request $request): ?array
    {
        if (! $request->filled('record_type') || ! $request->filled('record_id')) {
            return null;
        }

        $recordType = $request->string('record_type')->value();
        $recordId = (int) $request->integer('record_id');

        return match ($recordType) {
            'vehicle_event' => ($event = VehicleEvent::query()
                ->with(['camera', 'matchedEntry', 'vehicle', 'rfidScanLog.vehicleRfidTag', 'guestVisit.rfidTag'])
                ->find($recordId))
                    ? $this->vehicleEventLogPayload($event)
                    : abort(404),
            'guest_observation' => ($observation = GuestVehicleObservation::query()
                ->with('camera')
                ->find($recordId))
                    ? $this->guestLogPayload($observation)
                    : abort(404),
            'visitor_record' => ($record = VisitorRecord::query()
                ->with(['vehicle', 'plateProfile'])
                ->find($recordId))
                    ? $this->visitorRecordLogPayload($record, $this->guestAlertStatuses(collect([$record])))
                    : abort(404),
            'rfid_scan' => ($scanLog = RfidScanLog::query()
                ->with(['vehicle', 'vehicleRfidTag'])
                ->find($recordId))
                    ? $this->rfidOnlyLogPayload($scanLog)
                    : abort(404),
            default => abort(404),
        };
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
     * Build compact report datasets for browser print.
     *
     * @return array<string, array{label:string, rows:\Illuminate\Support\Collection<int, array<string, string>>}>
     */
    protected function printReportPayload(Request $request): array
    {
        $reports = [
            // UI Phase 4: "Current list" prints exactly what the filters show.
            'current' => ['label' => 'Current List', 'period' => false],
            'all' => ['label' => 'All Records', 'period' => null],
            'today' => ['label' => 'Today', 'period' => 'today'],
            'week' => ['label' => 'This Week', 'period' => 'week'],
            'year' => ['label' => 'This Year', 'period' => 'year'],
        ];

        return collect($reports)
            ->map(function (array $report) use ($request): array {
                $reportRequest = $report['period'] === false
                    ? Request::create($request->url(), 'GET', $request->except('page'))
                    : $this->reportRequest($request, $report['period']);

                return [
                    'label' => $report['label'],
                    // Phase 7: the printed report says which filters made it.
                    'filters_label' => $this->filtersLabel($reportRequest),
                    'rows' => $this->filteredUnifiedLogs($reportRequest)
                        ->map(fn (array $log): array => $this->compactPrintRow($log))
                        ->values(),
                ];
            })
            ->all();
    }

    protected function reportRequest(Request $request, ?string $period): Request
    {
        $query = $request->except(['page', 'period', 'date_from', 'date_to']);

        if ($period !== null) {
            $query['period'] = $period;
        }

        return Request::create($request->url(), 'GET', $query);
    }

    /**
     * @param  array<string, mixed>  $log
     * @return array<string, string>
     */
    protected function compactPrintRow(array $log): array
    {
        return [
            'timestamp' => (string) ($log['event_time_export'] ?: $log['display_time'] ?: 'N/A'),
            // Phase 4: guest pass rows show "Guest Pass #G-03" in printed reports.
            'log_type' => (string) ($log['log_type_label'] ?? ''),
            'source' => (string) ($log['source_label'] ?? ''),
            'plate_number' => (string) ($log['plate_number'] ?: 'GUEST'),
            'owner_name' => (string) ($log['owner_name'] ?: 'N/A'),
            'movement' => (string) ($log['movement_label'] ?? $log['event_type'] ?? ''),
            'category' => (string) ($log['category_label'] ?? ''),
            'gate' => (string) ($log['station_label'] ?? ''),
            'state' => (string) ($log['state_label'] ?: 'N/A'),
            'status' => (string) ($log['status_label'] ?: 'N/A'),
            'rfid_tag' => (string) ($log['rfid_tag_uid'] ?: 'N/A'),
        ];
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

                // Phase 4: a category also matches its older stored values (Student -> Registered Visitor).
                $values = \App\Support\VehicleCategory::storedValues($category);
                $query->where(function ($query) use ($values): void {
                    $query->whereIn('vehicle_category', $values)
                        ->orWhereHas('vehicle', fn ($vehicleQuery) => $vehicleQuery->whereIn('category', $values));
                });
            })
            ->when($request->filled('vehicle_owner_name'), function ($query) use ($request): void {
                $owner = '%'.$request->string('vehicle_owner_name')->trim().'%';

                $query->whereHas('vehicle', function ($vehicleQuery) use ($owner): void {
                    $vehicleQuery->where('vehicle_owner_name', 'like', $owner)
                        ->orWhere('owner_name', 'like', $owner);
                });
            })
            // Phase 7: gate = the reader's gate, or the camera's.
            ->when($this->gateFilter($request) !== null, function ($query) use ($request): void {
                $gates = $this->gateFilterValues($request);
                $query->where(fn ($inner) => $inner
                    ->whereHas('rfidScanLog', fn ($scan) => $scan->whereIn('scan_location', $gates))
                    ->orWhereHas('camera', fn ($camera) => $camera->whereIn('camera_role', $gates)));
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
            ->with('rfidScanLog:id,scan_location,tag_uid')
            ->get()
            ->map(fn (VehicleEvent $event): array => $this->vehicleEventLogPayload($event));

        $guestLogs = $this->filteredGuestLogs($request, $dateFrom, $dateUntil)
            ->get()
            ->map(fn (GuestVehicleObservation $observation): array => $this->guestLogPayload($observation));
        $rfidOnlyLogs = $this->filteredRfidOnlyLogs($request, $dateFrom, $dateUntil)
            ->get()
            ->map(fn (RfidScanLog $scanLog): array => $this->rfidOnlyLogPayload($scanLog));
        $visitorLogs = $this->filteredVisitorLogs($request, $dateFrom, $dateUntil);

        return $eventLogs
            ->concat($guestLogs)
            ->concat($rfidOnlyLogs)
            ->concat($visitorLogs)
            // Phase 4: Log Type filter (Registered, Guest Pass, Manual, No-pass Alert).
            ->when(
                array_key_exists((string) $request->query('log_type'), self::LOG_TYPES),
                fn (Collection $logs) => $logs->where('log_type', (string) $request->query('log_type'))
            )
            // UI Phase 4: "Alerts" chip = no-pass alerts, anomalies, lost/disabled pass scans.
            ->when(
                $request->query('log_type') === 'alerts',
                fn (Collection $logs) => $logs->where('is_alert', true)
            )
            ->sortByDesc('sort_time')
            ->values();
    }

    /**
     * Phase 4: Log Type filter options.
     */
    public const LOG_TYPES = [
        'registered' => 'Registered',
        'unregistered_visitor' => 'Unregistered Visitor',
        'guest_pass' => 'Guest Pass (old records)',
        'manual' => 'Manual',
        'no_pass_alert' => 'No-pass Alert',
    ];

    /** UI Phase 4: filter chips on Activity Logs › All Events. (Guest Pass removed in Phase 0; old rows keep their label.) */
    public const LOG_FILTER_CHIPS = [
        '' => 'All',
        'registered' => 'Registered',
        'unregistered_visitor' => 'Unregistered Visitors',
        'manual' => 'Manual',
        'alerts' => 'Alerts',
    ];

    /** Phase 7: Movement filter (ENTRY = IN, EXIT = OUT). */
    public const MOVEMENT_OPTIONS = [
        'ENTRY' => 'IN',
        'EXIT' => 'OUT',
        'UNKNOWN' => 'Direction unknown',
        'GUEST' => 'Guest (older camera records)',
        'RFID' => 'RFID scan only',
    ];

    /**
     * @return array{log_type: string, log_type_label: string, is_guest: bool}
     */
    protected function logTypeFields(string $logType, bool $isGuest): array
    {
        return [
            'log_type' => $logType,
            'log_type_label' => self::LOG_TYPES[$logType] ?? ucfirst($logType),
            'is_guest' => $isGuest,
        ];
    }

    protected function vehicleEventLogType(VehicleEvent $event): string
    {
        return match (true) {
            $event->event_origin === 'guest_pass' || $event->guest_visit_id !== null => 'guest_pass',
            $event->event_origin === 'guest_cctv' => 'no_pass_alert',
            in_array($event->event_origin, ['manual', 'guest_manual'], true) => 'manual',
            $event->vehicle_id !== null => 'registered',
            default => 'manual',
        };
    }

    protected function filteredGuestLogs(Request $request, ?Carbon $dateFrom = null, ?Carbon $dateUntil = null)
    {
        $showingGuestOnly = $request->filled('event_type')
            && $request->string('event_type')->upper()->value() === 'GUEST';

        return GuestVehicleObservation::query()
            ->with('camera')
            // Phase 7: a no-pass alert that has an Unregistered Visitor record
            // is shown once, as the visitor record.
            ->where(fn ($query) => $query->whereNull('external_event_key')
                ->orWhereNotIn('external_event_key', VisitorRecord::query()->select('external_event_key')))
            ->when($this->gateFilter($request) !== null, fn ($query) => $query->whereIn('location', $this->gateFilterValues($request)))
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
            // Older camera guest records are Unregistered Visitors (Phase 4).
            ->when(($request->filled('category') && $request->string('category')->value() !== VehicleCategory::UNREGISTERED_VISITOR)
                || $request->filled('vehicle_owner_name'), function ($query): void {
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
            ->when($this->gateFilter($request) !== null, fn ($query) => $query->whereIn('scan_location', $this->gateFilterValues($request)))
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

                // Phase 4: a category also matches its older stored values (Student -> Registered Visitor).
                $values = \App\Support\VehicleCategory::storedValues($category);
                $query->where(function ($query) use ($values): void {
                    $query->whereIn('vehicle_category', $values)
                        ->orWhereHas('vehicle', fn ($vehicleQuery) => $vehicleQuery->whereIn('category', $values));
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
            // Phase 4: count every guest row (guest pass, CCTV guest, manual guest),
            // not only rows whose event type is literally "GUEST".
            'guests' => $logs->where('is_guest', true)->count(),
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
            'export_url' => route('vehicle-events.export.csv', [
                'record_type' => 'vehicle_event',
                'record_id' => $event->id,
            ]),
            'event_type' => $event->event_type,
            'plate_number' => $event->plate_text ?: $vehicle?->plate_number ?: 'GUEST',
            'owner_name' => $vehicle?->vehicle_owner_name ?: $vehicle?->owner_name ?: 'N/A',
            'vehicle_type' => $event->display_vehicle_type,
            'vehicle_color' => $event->vehicle_color ?: 'N/A',
            'category_label' => $this->displayCategory($event->vehicle_category ?: $vehicle?->category),
            // Phase 4: "Guest Pass #G-03" instead of "Guest CCTV" for guest pass events.
            'source_label' => $event->source_display_label,
            // Phase 7: the gate (reader or camera); the camera name only when there is no gate.
            'station_label' => ($gate = $event->rfidScanLog?->scan_location ?? $event->camera?->camera_role)
                ? \App\Models\Gate::labelFor($gate)
                : ($event->camera?->camera_name ?: ($event->roi_name ?: 'No gate')),
            'movement_label' => match ($event->event_type) {
                'ENTRY' => 'IN',
                'EXIT' => 'OUT',
                default => $event->event_type,
            },
            'state_label' => $event->resulting_state_label,
            'display_time' => DisplayTime::datetime($time, 'No time'),
            'summary_label' => 'Vehicle Event #'.$event->id.' • '.(DisplayTime::datetime($time, 'No time')),
            'event_time_export' => $time?->toDateTimeString(),
            'status_label' => $event->display_status_label,
            'status_badge_class' => $event->status_badge_class,
            'match_label' => $event->match_display,
            'rfid_tag_uid' => $event->rfidScanLog?->tag_uid ?: 'N/A',
            'image_url' => $event->has_visual_evidence ? $event->vehicle_image_url : null,
            'is_alert' => filled($event->anomaly_reason) && $event->match_status !== VehicleEvent::MATCH_NO_PASS_RESOLVED,
            'alert_reason' => $event->anomaly_reason,
            'sort_time' => $this->sortTimestamp($event->created_at, $time),
            ...$this->logTypeFields(
                $this->vehicleEventLogType($event),
                $event->guest_visit_id !== null || strtolower((string) $event->vehicle_category) === 'guest'
                    || in_array($event->event_origin, ['guest_pass', 'guest_cctv', 'guest_manual'], true)
            ),
            ...VehicleEvent::guestPassLogFields($event),
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
            'detail_url' => route('logs.index', ['tab' => 'alerts', 'plate_text' => $observation->plate_number ?: $observation->plate_text]),
            'export_url' => route('vehicle-events.export.csv', [
                'record_type' => 'guest_observation',
                'record_id' => $observation->id,
            ]),
            'event_type' => 'GUEST',
            'plate_number' => $observation->plate_number ?: $observation->plate_text ?: 'GUEST',
            'owner_name' => 'N/A',
            'vehicle_type' => $observation->vehicle_type ?: 'Vehicle',
            'vehicle_color' => $observation->vehicle_color ?: 'N/A',
            'category_label' => \App\Support\VehicleCategory::LABELS[\App\Support\VehicleCategory::UNREGISTERED_VISITOR],
            'source_label' => $observation->observation_source === 'cctv' ? 'Guest CCTV' : 'Guest Manual',
            'station_label' => \App\Models\Gate::labelFor($observation->location),
            'state_label' => 'Guest',
            'display_time' => DisplayTime::datetime($time, 'No time'),
            'summary_label' => 'Guest Observation #'.$observation->id.' • '.(DisplayTime::datetime($time, 'No time')),
            'event_time_export' => $time?->toDateTimeString(),
            'status_label' => 'Guest',
            'status_badge_class' => 'secondary',
            'match_label' => 'Guest',
            'rfid_tag_uid' => 'N/A',
            'image_url' => $observation->snapshot_path ? $observation->snapshot_url : null,
            'is_alert' => $observation->observation_source === 'cctv' && $observation->status !== GuestVehicleObservation::STATUS_RESOLVED,
            'alert_reason' => $observation->observation_source === 'cctv' ? 'Vehicle with no pass' : null,
            'sort_time' => $this->sortTimestamp($observation->created_at, $time),
            ...$this->logTypeFields($observation->observation_source === 'cctv' ? 'no_pass_alert' : 'manual', true),
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
            'detail_url' => route('logs.index', ['tab' => 'scans', 'history_q' => $scanLog->tag_uid]),
            'export_url' => route('vehicle-events.export.csv', [
                'record_type' => 'rfid_scan',
                'record_id' => $scanLog->id,
            ]),
            'event_type' => 'RFID',
            'plate_number' => $vehicle?->plate_number ?: 'GUEST',
            'owner_name' => $vehicle?->vehicle_owner_name ?: $vehicle?->owner_name ?: 'N/A',
            'vehicle_type' => $vehicle?->vehicle_type ?: 'N/A',
            'vehicle_color' => 'N/A',
            'category_label' => $this->displayCategory($scanLog->vehicle_category ?: $vehicle?->category),
            'source_label' => 'RFID Scan',
            'station_label' => \App\Models\Gate::labelFor($scanLog->scan_location),
            'state_label' => $scanLog->resultingStateLabel,
            'display_time' => DisplayTime::datetime($time, 'No time'),
            'summary_label' => 'RFID Scan #'.$scanLog->id.' • '.(DisplayTime::datetime($time, 'No time')),
            'event_time_export' => $time?->toDateTimeString(),
            'status_label' => $scanLog->verificationLabel,
            'status_badge_class' => $scanLog->verificationBadgeClass,
            'match_label' => 'No vehicle event',
            'rfid_tag_uid' => $scanLog->tag_uid,
            'image_url' => null,
            'is_alert' => (bool) $scanLog->is_anomaly || in_array($scanLog->verification_status, ['guest', 'guest_pass_lost', 'guest_pass_disabled', 'inactive_tag'], true),
            'alert_reason' => $scanLog->anomaly_reason,
            'sort_time' => $this->sortTimestamp($scanLog->created_at, $time),
            ...$this->logTypeFields(
                match (true) {
                    $scanLog->vehicle_category === 'guest_pass' => 'guest_pass',
                    $scanLog->verification_status === 'guest' => 'no_pass_alert',
                    default => 'registered',
                },
                in_array($scanLog->vehicle_category, ['guest_pass', 'guest'], true) || $scanLog->verification_status === 'guest'
            ),
        ];
    }

    /**
     * Phase 7 (visitor model): Unregistered Visitor records (camera, no
     * registered tag read) as log rows, with the same filters.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function filteredVisitorLogs(Request $request, ?Carbon $dateFrom = null, ?Carbon $dateUntil = null): Collection
    {
        $eventType = $request->filled('event_type') ? $request->string('event_type')->upper()->value() : null;

        if (($eventType !== null && ! in_array($eventType, ['ENTRY', 'EXIT', 'UNKNOWN'], true)) || $request->filled('match_status')) {
            return collect();
        }

        $records = VisitorRecord::query()
            ->with(['vehicle', 'plateProfile'])
            ->active()
            ->when($eventType !== null, fn ($query) => $query->where('direction', match ($eventType) {
                'ENTRY' => 'IN',
                'EXIT' => 'OUT',
                default => 'UNKNOWN',
            }))
            ->when($request->filled('plate_text'), function ($query) use ($request): void {
                $key = '%'.\App\Support\PlateNumber::key($request->string('plate_text')->value()).'%';
                $query->where(fn ($inner) => $inner->where('plate_key', 'like', $key)
                    ->orWhereRaw("replace(replace(coalesce(ocr_plate_number, ''), ' ', ''), '-', '') like ?", [$key]));
            })
            ->when($this->gateFilter($request) !== null, fn ($query) => $query->whereIn('gate', $this->gateFilterValues($request)))
            ->when($dateFrom !== null, fn ($query) => $query->where('seen_at', '>=', $dateFrom->copy()->startOfDay()))
            ->when($dateUntil !== null, fn ($query) => $query->where('seen_at', '<', $dateUntil))
            ->get();

        $alerts = $this->guestAlertStatuses($records);

        return $records
            ->map(fn (VisitorRecord $record): array => $this->visitorRecordLogPayload($record, $alerts))
            ->when($request->filled('category'), fn (Collection $logs) => $logs->where('category', VehicleCategory::normalize($request->string('category')->value())))
            ->when($request->filled('vehicle_owner_name'), function (Collection $logs) use ($request): Collection {
                $owner = mb_strtolower($request->string('vehicle_owner_name')->trim()->value());

                return $logs->filter(fn (array $log): bool => $owner !== '' && str_contains(mb_strtolower((string) $log['owner_name']), $owner));
            })
            ->values();
    }

    /**
     * Open no-pass alerts (older guest records) by event key, so a visitor
     * record keeps the alert of its crossing.
     *
     * @param  Collection<int, VisitorRecord>  $records
     * @return array<string, bool>
     */
    protected function guestAlertStatuses(Collection $records): array
    {
        return GuestVehicleObservation::query()
            ->whereIn('external_event_key', $records->pluck('external_event_key')->filter()->all())
            ->where('observation_source', 'cctv')
            ->get(['external_event_key', 'status'])
            ->mapWithKeys(fn (GuestVehicleObservation $observation): array => [
                $observation->external_event_key => $observation->status !== GuestVehicleObservation::STATUS_RESOLVED,
            ])
            ->all();
    }

    /**
     * @param  array<string, bool>  $openAlerts
     * @return array<string, mixed>
     */
    protected function visitorRecordLogPayload(VisitorRecord $record, array $openAlerts = []): array
    {
        $time = $record->seen_at;
        $vehicle = $record->vehicle;
        // Option A (Phase 7): the plate of a registered vehicle, read without its tag.
        $plateOnly = $vehicle && $vehicle->created_at && $vehicle->created_at->lte($time);
        $category = $plateOnly ? VehicleCategory::normalize($vehicle->category) : VehicleCategory::UNREGISTERED_VISITOR;

        return [
            'record_type' => 'visitor_record',
            'record_type_label' => 'Unregistered Visitor',
            'id' => $record->id,
            'detail_url' => $record->plateProfile
                ? route('visitors.profiles.show', $record->plateProfile)
                : route('visitors.index', ['plate_status' => $record->plate_status]).'#visitor-record-'.$record->id,
            'export_url' => route('vehicle-events.export.csv', [
                'record_type' => 'visitor_record',
                'record_id' => $record->id,
            ]),
            'event_type' => match ($record->direction) {
                'IN' => 'ENTRY',
                'OUT' => 'EXIT',
                default => 'UNKNOWN',
            },
            'movement_label' => $record->direction === 'UNKNOWN' ? 'Direction unknown' : $record->direction,
            'plate_number' => $record->plateLabel(),
            'owner_name' => $vehicle?->vehicle_owner_name ?: 'N/A',
            'vehicle_type' => $record->vehicle_type ?: 'Vehicle',
            'vehicle_color' => $record->vehicle_color ?: 'N/A',
            'category' => $category,
            'category_label' => VehicleCategory::label($category),
            'source_label' => $plateOnly ? 'Camera · plate only, no tag read' : 'Camera · no registered tag',
            'station_label' => \App\Models\Gate::labelFor($record->gate),
            'state_label' => $plateOnly ? 'Not changed (no tag read)' : 'Not tracked (visitor)',
            'display_time' => DisplayTime::datetime($time, 'No time'),
            'summary_label' => 'Unregistered Visitor #'.$record->id.' • '.(DisplayTime::datetime($time, 'No time')),
            'event_time_export' => $time?->toDateTimeString(),
            'status_label' => $record->plateLabel(),
            'status_badge_class' => 'secondary',
            'match_label' => $plateOnly ? 'Registered vehicle (plate only)' : 'Unregistered',
            'rfid_tag_uid' => 'N/A',
            'image_url' => $record->snapshot_url,
            'is_alert' => (bool) ($openAlerts[$record->external_event_key] ?? false),
            'alert_reason' => ($openAlerts[$record->external_event_key] ?? false) ? 'Vehicle with no pass' : null,
            'sort_time' => $this->sortTimestamp($record->created_at, $time),
            ...$this->logTypeFields($plateOnly ? 'registered' : 'unregistered_visitor', ! $plateOnly),
        ];
    }

    protected function gateFilter(Request $request): ?string
    {
        return $request->filled('gate') ? \App\Models\Gate::resolveCode($request->string('gate')->value()) ?? '__none__' : null;
    }

    /**
     * The gate code and the old station name it replaced (older records).
     *
     * @return list<string>
     */
    protected function gateFilterValues(Request $request): array
    {
        $gate = (string) $this->gateFilter($request);

        return array_values(array_unique([$gate, ...array_keys(\App\Models\Gate::LEGACY_CODES, $gate, true)]));
    }

    /**
     * Phase 7: "Gate 1 · IN · Faculty & Staff · Today" for printed reports.
     */
    protected function filtersLabel(Request $request): string
    {
        $parts = array_filter([
            $request->filled('gate') ? \App\Models\Gate::labelFor($request->string('gate')->value()) : null,
            $request->filled('event_type') ? (self::MOVEMENT_OPTIONS[$request->string('event_type')->upper()->value()] ?? null) : null,
            $request->filled('category') ? VehicleCategory::label($request->string('category')->value()) : null,
            $request->filled('log_type') ? (self::LOG_FILTER_CHIPS[$request->string('log_type')->value()] ?? null) : null,
            $request->filled('plate_text') ? 'Plate "'.$request->string('plate_text')->trim().'"' : null,
            $request->filled('vehicle_owner_name') ? 'Owner "'.$request->string('vehicle_owner_name')->trim().'"' : null,
            $this->selectedPeriodLabel($request) !== 'All Records' ? $this->selectedPeriodLabel($request) : null,
            $request->filled('date_from') || $request->filled('date_to')
                ? trim(($request->string('date_from')->value() ?: '…').' to '.($request->string('date_to')->value() ?: '…'))
                : null,
        ]);

        return $parts === [] ? 'No filters' : implode(' · ', $parts);
    }

    /**
     * @return array<string, string>
     */
    protected function categoryOptions(): array
    {
        // Phase 4 (visitor model): the three categories.
        return \App\Support\VehicleCategory::LABELS;
    }

    protected function displayCategory(?string $category): string
    {
        // Phase 4 (visitor model): new names, also for older stored values.
        return \App\Support\VehicleCategory::label($category);
    }

    protected function sortTimestamp($createdAt, $eventAt): float
    {
        return (float) ($createdAt?->format('U.u') ?? $eventAt?->format('U.u') ?? 0);
    }

}
