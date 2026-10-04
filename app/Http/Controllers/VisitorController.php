<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesTab;
use App\Models\Gate;
use App\Models\PlateProfile;
use App\Models\VisitorRecord;
use App\Services\VisitorRecordService;
use App\Support\PlateNumber;
use Illuminate\Database\Eloquent\Builder;
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
        'records' => 'Unregistered Visitors',
        'plates' => 'Plate Profiles',
    ];

    public function index(Request $request): View
    {
        $tab = $this->resolveTab($request, self::TABS);
        $filters = $request->only(['q', 'gate', 'plate_status', 'status', 'sort']);

        return view('visitors.index', [
            'tab' => $tab,
            'filters' => $filters,
            'records' => $tab === 'records' ? $this->records($filters)->paginate(20)->withQueryString() : null,
            'profiles' => $tab === 'plates'
                ? PlateProfile::query()->current()
                    ->with('vehicle')
                    ->withCount(['records as entries_count' => fn ($query) => $query->active()->where('direction', 'IN')])
                    ->when(filled($filters['q'] ?? null), fn (Builder $query) => $query->where('plate_key', 'like', '%'.PlateNumber::key($filters['q']).'%'))
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
        ]);
    }

    public function showProfile(PlateProfile $plateProfile): View|RedirectResponse
    {
        if ($plateProfile->merged_into_id) {
            return redirect()->route('visitors.profiles.show', $plateProfile->merged_into_id)
                ->with('status', $plateProfile->plate_number.' was merged into this plate.');
        }

        return view('visitors.profile', [
            'profile' => $plateProfile->load(['aliases', 'noteAuthor', 'vehicle']),
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
            'plate_number' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9 -]+$/'],
            'vehicle_type' => ['nullable', 'string', 'max:50'],
            'vehicle_color' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:200'],
            'snapshot' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ], ['plate_number.regex' => 'Use letters, digits, spaces or a dash.']);

        $record = $service->createManual($validated, $request->user(), $request->file('snapshot'));

        return redirect()->route('visitors.index')
            ->with('status', 'Unregistered visitor '.($record->plate_number ?: '(no plate)').' recorded '.$record->direction.' at '.Gate::labelFor($record->gate).'.');
    }

    public function correctPlate(Request $request, VisitorRecord $visitorRecord, VisitorRecordService $service): RedirectResponse
    {
        $validated = $request->validate([
            'plate_number' => ['nullable', 'required_unless:unreadable,1', 'string', 'max:30', 'regex:/^[A-Za-z0-9 -]+$/'],
            'unreadable' => ['nullable', 'in:0,1'],
        ], ['plate_number.regex' => 'Use letters, digits, spaces or a dash.']);

        $plate = ($validated['unreadable'] ?? '0') === '1' ? null : $validated['plate_number'];
        $record = $service->correctPlate($visitorRecord, $plate, $request->user());

        $message = $plate === null
            ? "Record #{$record->id} marked as plate unreadable."
            : "Record #{$record->id} plate set to {$record->plate_number}.";

        if ($plate !== null && ! PlateNumber::isPhilippineFormat($plate)) {
            $message .= ' Note: this is not a usual PH plate layout (ABC 1234, ABC 123, AB 12345).';
        }

        return back()->with('status', $message);
    }

    public function dismiss(Request $request, VisitorRecord $visitorRecord, VisitorRecordService $service): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:150']]);
        $service->dismiss($visitorRecord, $validated['reason'].' ('.$request->user()->name.')');

        return back()->with('status', "Record #{$visitorRecord->id} dismissed.");
    }

    public function updateNote(Request $request, PlateProfile $plateProfile, VisitorRecordService $service): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $service->updateNote($plateProfile, $validated['note'] ?? null, $request->user());

        return back()->with('status', 'Visitor note saved.');
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
                $key = '%'.PlateNumber::key($filters['q']).'%';
                $query->where(fn (Builder $inner) => $inner->where('plate_key', 'like', $key)
                    ->orWhereRaw("replace(replace(coalesce(ocr_plate_number, ''), ' ', ''), '-', '') like ?", [$key]));
            })
            ->latest('seen_at')
            ->latest('id');
    }
}
