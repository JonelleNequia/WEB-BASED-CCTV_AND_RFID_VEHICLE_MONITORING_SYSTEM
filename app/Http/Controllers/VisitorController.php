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
        $filters = $request->only(['q', 'gate', 'plate_status', 'status']);

        return view('visitors.index', [
            'tab' => $tab,
            'filters' => $filters,
            'records' => $tab === 'records' ? $this->records($filters)->paginate(20)->withQueryString() : null,
            'profiles' => $tab === 'plates'
                ? PlateProfile::query()->current()
                    ->when(filled($filters['q'] ?? null), fn (Builder $query) => $query->where('plate_key', 'like', '%'.PlateNumber::key($filters['q']).'%'))
                    ->orderByDesc('last_seen_at')
                    ->paginate(20)
                    ->withQueryString()
                : null,
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
            'profile' => $plateProfile->load(['aliases', 'noteAuthor']),
            'records' => VisitorRecord::query()
                ->where('plate_profile_id', $plateProfile->id)
                ->latest('seen_at')
                ->paginate(20),
            'mergeTargets' => PlateProfile::query()->current()->whereKeyNot($plateProfile->id)->orderBy('plate_number')->get(['id', 'plate_number', 'visit_count']),
        ]);
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
            ->with(['plateProfile', 'corrector'])
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
