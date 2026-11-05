<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTab;
use App\Models\Gate;
use App\Models\PlateProfile;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\DirectionCorrectionService;
use App\Services\VisitorRecordService;
use App\Support\PlateNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 5 (visitor model): Unregistered Visitor records and plate profiles.
 * Guards correct plates, dismiss false alarms and keep visitor notes; an
 * admin merges a misread plate into the right one.
 */
class VisitorController extends Controller
{
    use ResolvesTab;

    public const TABS = [
        'records' => 'Unregistered Guests',
        'plates' => 'Plate Profiles',
        // ROI work, Phase 2: passes the camera could not tell IN from OUT.
        'review' => 'Review',
    ];

    public function index(Request $request, \App\Services\VisitorReviewService $reviewService): View
    {
        $tab = $this->resolveTab($request, self::TABS);
        $filters = $request->only(['q', 'gate', 'plate_status', 'status', 'sort']);

        return view('visitors.index', [
            'tab' => $tab,
            'filters' => $filters,
            'records' => $tab === 'records' ? $this->records($filters)->paginate(20)->withQueryString() : null,
            // Gate direction work, Phase C: unregistered vehicles IN today without an OUT yet (an estimate).
            'possiblyInside' => $tab === 'records' ? app(\App\Services\VisitorPresenceService::class)->possiblyInside() : null,
            'profiles' => $tab === 'plates'
                ? PlateProfile::query()->current()
                    ->with('vehicle')
                    ->withCount(['records as entries_count' => fn ($query) => $query->active()->where('direction', 'IN')])
                    ->when(filled($filters['q'] ?? null), fn (Builder $query) => PlateNumber::search($query, ['plate_number', 'plate_key'], $filters['q']))
                    // UI Phase 4: most entries first by default; "recent" = last seen first;
                    // "entries" = Phase 6 Visitor Ranking (not registered).
                    ->when(($filters['sort'] ?? '') === 'entries', fn (Builder $query) => $query->whereNull('vehicle_id'))
                    ->when(($filters['sort'] ?? '') === 'recent',
                        fn (Builder $query) => $query->orderByDesc('last_seen_at'),
                        fn (Builder $query) => $query->orderByDesc('entries_count')->orderByDesc('visit_count')->orderByDesc('last_seen_at'))
                    ->paginate(20)
                    ->withQueryString()
                : null,
            // UI Phase 4: "Merge" from the list (admins).
            'mergeTargets' => $tab === 'plates' && $request->user()?->isAdmin()
                ? PlateProfile::query()->current()->orderBy('plate_number')->get(['id', 'plate_number', 'visit_count'])
                : collect(),
            'gates' => Gate::options(),
            'historicalGates' => Gate::filterOptions(),
            // ROI work: registered records the camera could not tell (saved before Phase A's state fallback).
            'review' => $tab === 'review' ? $this->directionUnknown() : null,
            // Gate direction work, Phase D: the Review cards (plate to check, unknown direction).
            'reviewQueue' => $tab === 'review' ? $reviewService->queue() : null,
            'reviewService' => $reviewService,
            'plateOptions' => $tab === 'review'
                ? PlateProfile::query()->current()->orderByDesc('visit_count')->limit(500)->pluck('plate_number')
                : collect(),
            'reviewLog' => $tab === 'review' ? $reviewService->recent() : collect(),
            'reviewCount' => $reviewService->count()
                + VehicleEvent::query()->where('event_type', VehicleEvent::TYPE_UNKNOWN)->whereNotNull('vehicle_id')
                    ->where('event_status', VehicleEvent::STATUS_COMPLETED)->count(),
        ]);
    }

    /**
     * ROI work, Phase 2: registered records whose direction the camera
     * could not tell (saved before Gate direction work Phase A used the
     * vehicle's state), newest first, to set IN or OUT in one click.
     * Unregistered ones are Review cards (VisitorReviewService).
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function directionUnknown(): \Illuminate\Support\Collection
    {
        // Gate direction work, Phase D: unregistered records are Review cards now.
        $registered = VehicleEvent::query()->where('event_type', VehicleEvent::TYPE_UNKNOWN)->whereNotNull('vehicle_id')
            ->where('event_status', VehicleEvent::STATUS_COMPLETED)
            ->with(['vehicle', 'rfidScanLog.vehicleCrossing', 'visitorRecord.crossing'])->latest('event_time')->limit(200)->get()
            ->map(fn (VehicleEvent $event): array => [
                'key' => 'event-'.$event->id,
                'time' => $event->event_time,
                'gate' => $event->rfidScanLog?->scan_location ?? $event->visitorRecord?->gate,
                'plate' => $event->plate_text ?: $event->vehicle?->plate_number ?: 'No plate',
                'who' => 'Registered · '.($event->vehicle?->vehicle_owner_name ?: $event->vehicle?->owner_name ?: 'owner not set'),
                'snapshot_url' => $event->rfidScanLog?->vehicleCrossing?->snapshot_url ?? $event->visitorRecord?->snapshot_url,
                'plate_image_url' => $event->visitorRecord?->plate_image_url,
                'reason' => $event->rfidScanLog?->vehicleCrossing?->direction_reason ?? $event->visitorRecord?->crossing?->direction_reason,
                'url' => route('vehicle-events.direction', $event),
            ]);

        return $registered->sortByDesc(fn (array $item) => $item['time']?->getTimestamp() ?? 0)->values();
    }

    /**
     * ROI work, Phase 2: Set IN / Set OUT on an Unregistered Visitor record the camera could not tell.
     */
    public function setDirection(Request $request, VisitorRecord $visitorRecord, DirectionCorrectionService $service): RedirectResponse
    {
        $validated = $request->validate(['direction' => ['required', 'in:IN,OUT']]);
        $record = $service->setForVisitorRecord($visitorRecord, $validated['direction'], $request->user());
        app(\App\Services\VisitorReviewService::class)->log($record, 'set_direction', 'UNKNOWN', $record->direction, $request->user());

        return back()->with('status', "Record #{$record->id} set to {$record->direction}.");
    }

    public function showProfile(PlateProfile $plateProfile): View|RedirectResponse
    {
        if ($plateProfile->merged_into_id) {
            return redirect()->route('visitors.profiles.show', $plateProfile->merged_into_id)
                ->with('status', $plateProfile->plate_number.' was merged into this plate.');
        }

        return view('visitors.profile', [
            'profile' => $plateProfile->load(['aliases', 'noteAuthor', 'vehicle']),
            // Gate direction work, Phase C: IN -> OUT trips, across gates.
            'trips' => app(\App\Services\VisitorPresenceService::class)->trips($plateProfile),
            'records' => VisitorRecord::query()
                ->where('plate_profile_id', $plateProfile->id)
                ->latest('seen_at')
                ->paginate(20),
            'mergeTargets' => PlateProfile::query()->current()->whereKeyNot($plateProfile->id)->orderBy('plate_number')->get(['id', 'plate_number', 'visit_count']),
        ]);
    }

    /**
     * Phase 8 (visitor model): a guard records a vehicle with no registered
     * tag by hand (replaces "Add Guest Observation").
     */
    public function store(Request $request, VisitorRecordService $service): RedirectResponse
    {
        $validated = $request->validate([
            'gate' => ['required', 'string', new \App\Rules\ValidGate],
            'direction' => ['required', 'in:IN,OUT'],
            'seen_at' => ['required', 'date', 'before_or_equal:now'],
            'plate_number' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9 -]+$/', new \App\Rules\PlateIdentifier(20)],
            'vehicle_type' => ['nullable', 'string', 'max:50', new \App\Rules\ValidVehicleType],
            'note' => ['nullable', 'string', 'max:200'],
            'snapshot' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ], ['plate_number.regex' => 'Use letters, digits, spaces or a dash.']);

        $record = $service->createManual($validated, $request->user(), $request->file('snapshot'));

        return redirect()->route('visitors.index')
            ->with('status', 'Unregistered guest '.($record->plate_number ?: '(no plate)').' recorded '.$record->direction.' at '.Gate::labelFor($record->gate).'.');
    }

    public function correctPlate(Request $request, VisitorRecord $visitorRecord, VisitorRecordService $service): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'plate_number' => ['nullable', 'required_unless:unreadable,1', 'string', 'max:30', 'regex:/^[A-Za-z0-9 -]+$/', new \App\Rules\PlateIdentifier(20, $visitorRecord->plate_number)],
            'unreadable' => ['nullable', 'in:0,1'],
        ], ['plate_number.regex' => 'Use letters, digits, spaces or a dash.']);

        $plate = ($validated['unreadable'] ?? '0') === '1' ? null : $validated['plate_number'];
        return $this->attemptCorrection($request, function () use ($service, $visitorRecord, $plate, $request): RedirectResponse {
            $record = $service->correctPlate($visitorRecord, $plate, $request->user());

            $message = $plate === null
                ? "Record #{$record->id} marked as plate unreadable."
                : "Record #{$record->id} plate set to {$record->plate_number}.";

            if ($plate !== null && ! PlateNumber::isPhilippineFormat($plate)) {
                $message .= ' Note: this is not a usual PH plate layout (ABC 1234, ABC 123, AB 12345).';
            }

            return back()->with('status', $message);
        });
    }

    /**
     * Gate direction work, Phase D: one Visitors › Review action on a card
     * (JSON for the keyboard-driven page; a redirect without JavaScript).
     * The plain form's buttons say "pick_match:12", "set_direction:IN",
     * "dismiss:duplicate".
     */
    public function review(Request $request, VisitorRecord $visitorRecord, \App\Services\VisitorReviewService $service): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'max:40'],
            'plate' => ['nullable', 'string', 'max:30', new \App\Rules\PlateIdentifier(20, $visitorRecord->plate_number)],
            'profile_id' => ['nullable', 'integer'],
            'direction' => ['nullable', 'in:IN,OUT'],
            'name' => ['nullable', 'string', \Illuminate\Validation\Rule::when(
                ! is_string($request->input('name')) || \App\Rules\PersonalName::normalize($request->input('name')) !== \App\Rules\PersonalName::normalize($visitorRecord->plateProfile?->owner_name ?? ''),
                'max:120'
            )],
            'reason' => ['nullable', 'string', 'max:40'],
        ]);
        [$action, $argument] = array_pad(explode(':', $validated['action'], 2), 2, null);
        if ($argument !== null) {
            $validated[match ($action) {
                'pick_match' => 'profile_id',
                'set_direction' => 'direction',
                default => 'reason',
            }] = $argument;
        }

        return $this->attemptCorrection($request, function () use ($service, $visitorRecord, $action, $validated, $request): JsonResponse|RedirectResponse {
            $result = $service->act($visitorRecord, $action, $validated, $request->user());

            return $request->expectsJson()
                ? response()->json(['ok' => true] + $result)
                : back()->with('status', $result['message']);
        });
    }

    private function attemptCorrection(Request $request, \Closure $work): JsonResponse|RedirectResponse
    {
        try {
            return $work();
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $conflict = $exception instanceof \App\Exceptions\OccurrenceConflict;
            $message = $conflict ? \App\Exceptions\OccurrenceConflict::PUBLIC_MESSAGE
                : 'Could not save this correction. Your input is kept. Try again; if it continues, ask an admin to check the system logs.';
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'error_code' => $conflict ? 'occurrence_conflict' : 'correction_failed',
                    'message' => $message], $conflict ? 409 : 500);
            }

            return back()->withErrors(['plate' => $message])->withInput($request->only(['plate', 'plate_number', 'unreadable', 'action'])
                + ['_review_record_id' => $request->route('visitorRecord')?->id]);
        }
    }

    /**
     * A2 (detection): Car / Motorcycle / Truck/Bus, set by a guard (logged).
     */
    public function correctType(Request $request, VisitorRecord $visitorRecord, VisitorRecordService $service): RedirectResponse
    {
        $validated = $request->validate(['vehicle_type' => ['required', 'string', 'max:50', new \App\Rules\ValidVehicleType]]);
        $record = $service->correctType($visitorRecord, $validated['vehicle_type'], $request->user());

        return back()->with('status', "Record #{$record->id} is now a {$record->vehicle_type}.");
    }

    public function dismiss(Request $request, VisitorRecord $visitorRecord, VisitorRecordService $service): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:150']]);
        $before = $visitorRecord->status;
        $service->dismiss($visitorRecord, $validated['reason'].' ('.$request->user()->name.')');
        app(\App\Services\VisitorReviewService::class)->log($visitorRecord, 'dismiss', $before, $validated['reason'], $request->user());

        return back()->with('status', "Record #{$visitorRecord->id} dismissed.");
    }

    public function updateNote(Request $request, PlateProfile $plateProfile, VisitorRecordService $service): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $service->updateNote($plateProfile, $validated['note'] ?? null, $request->user());

        return back()->with('status', 'Guest note saved.');
    }

    public function merge(Request $request, PlateProfile $plateProfile, VisitorRecordService $service): RedirectResponse
    {
        $validated = $request->validate(['target_id' => ['required', 'integer', 'exists:plate_profiles,id']]);
        $target = $service->merge($plateProfile, PlateProfile::query()->findOrFail($validated['target_id']), $request->user());

        return redirect()->route('visitors.profiles.show', $target)
            ->with('status', "{$plateProfile->plate_number} merged into {$target->plate_number}.");
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function records(array $filters): Builder
    {
        $status = $filters['status'] ?? VisitorRecord::STATUS_ACTIVE;

        return VisitorRecord::query()
            ->with(['plateProfile', 'corrector', 'vehicle'])
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when(filled($filters['gate'] ?? null), fn (Builder $query) => $query->where('gate', $filters['gate']))
            ->when(filled($filters['plate_status'] ?? null), fn (Builder $query) => $query->where('plate_status', $filters['plate_status']))
            ->when(filled($filters['q'] ?? null), function (Builder $query) use ($filters): void {
                PlateNumber::search($query, ['plate_number', 'ocr_plate_number'], $filters['q']);
            })
            ->latest('seen_at')
            ->latest('id');
    }
}
