<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\GuestVisit;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Services\GuestPassService;
use App\Support\DisplayTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 3: guest pass actions. The Guest Passes page and the Entrance
 * Station pop-up (Phase 4) call these.
 */
class GuestPassController extends Controller
{
    /**
     * Phase 4: Guest Passes page (replaces Guest Monitoring as the main guest flow).
     */
    public function index(Request $request, GuestPassService $guestPassService): View
    {
        $guestPassService->markOverstays();

        $filters = $request->only(['status', 'q', 'date']);
        $status = $filters['status'] ?? 'open';

        $visits = GuestVisit::query()
            ->with(['rfidTag', 'issuer'])
            ->when($status === 'open', fn ($query) => $query->open())
            ->when(in_array($status, [GuestVisit::STATUS_ACTIVE, GuestVisit::STATUS_COMPLETED, GuestVisit::STATUS_OVERSTAY, GuestVisit::STATUS_LOST_TAG], true),
                fn ($query) => $query->where('status', $status))
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                $term = '%'.trim((string) $filters['q']).'%';
                $query->where(function ($query) use ($term): void {
                    $query->where('plate', 'like', $term)
                        ->orWhere('driver_name', 'like', $term)
                        ->orWhereHas('rfidTag', fn ($tagQuery) => $tagQuery->where('display_number', 'like', $term));
                });
            })
            ->when(filled($filters['date'] ?? null), fn ($query) => $query->whereDate('entry_at', $filters['date']))
            ->orderByRaw("CASE status WHEN 'overstay' THEN 0 WHEN 'active' THEN 1 ELSE 2 END")
            ->latest('entry_at')
            ->paginate(15)
            ->withQueryString();

        $passes = RfidTag::query()->guestPasses()->get();

        // UI Phase 2: Guests page.
        return view('guests.index', [
            'visits' => $visits,
            'filters' => ['status' => $status] + $filters,
            'stats' => [
                'active_guests' => GuestVisit::query()->open()->count(),
                'overstay' => GuestVisit::query()->where('status', GuestVisit::STATUS_OVERSTAY)->count(),
                'passes_available' => $passes->where('status', RfidTag::STATUS_AVAILABLE)->count(),
                'passes_total' => $passes->count(),
                'passes_lost' => $passes->where('status', RfidTag::STATUS_LOST)->count(),
            ],
            'availablePasses' => $passes->where('status', RfidTag::STATUS_AVAILABLE)->sortBy('display_number')->values(),
            'cameras' => Camera::query()->orderBy('camera_name')->get(),
            'validityMinutes' => $guestPassService->validityMinutes(),
            'requiresId' => $guestPassService->requiresId(),
        ]);
    }

    /**
     * Phase 4: one visit with its snapshots and events.
     */
    public function show(GuestVisit $guestVisit): View
    {
        return view('guest-passes.show', [
            'visit' => $guestVisit->load(['rfidTag', 'issuer', 'vehicleEvents' => fn ($query) => $query->orderBy('event_time')]),
        ]);
    }

    /**
     * Phase 4: close a visit by hand (the guest left without passing the Exit reader).
     */
    public function close(Request $request, GuestVisit $guestVisit, GuestPassService $guestPassService): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $guestPassService->closeManually($guestVisit, $validated['reason']);

        return back()->with('status', $guestVisit->rfidTag->label.' visit closed manually. The pass is available again.');
    }

    /**
     * Phase 4: the guest did not return the card.
     */
    public function markLost(Request $request, GuestVisit $guestVisit, GuestPassService $guestPassService): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $guestPassService->markLost($guestVisit, $validated['reason'] ?? null);

        return back()->with('status', $guestVisit->rfidTag->label.' marked LOST. Scans of this card will raise an alert.');
    }

    /**
     * Phase 4: Exit Station "Card returned" confirmation.
     */
    public function cardReturned(Request $request, GuestVisit $guestVisit): JsonResponse
    {
        $note = 'Card and ID returned (confirmed by '.($request->user()?->name ?? 'guard').' at '.DisplayTime::datetime(now()).').';
        $guestVisit->forceFill(['notes' => trim(($guestVisit->notes ? $guestVisit->notes."\n" : '').$note)])->save();

        return response()->json(['message' => 'Card return confirmed.']);
    }

    /**
     * Issue an available guest pass and record the guest's ENTRY.
     */
    public function issue(Request $request, RfidTag $rfidTag, GuestPassService $guestPassService): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'plate' => ['nullable', 'string', 'max:50'],
            'driver_name' => ['nullable', 'string', 'max:150'],
            'vehicle_type' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:150'],
            'id_presented' => ['nullable', 'string', 'max:100'],
            'valid_until' => ['nullable', 'date', 'after:now'],
            // Phase 4: "Valid for" choice on the Issue Guest Pass pop-up.
            'valid_minutes' => ['nullable', 'integer', 'min:15', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'rfid_scan_log_id' => ['nullable', 'integer', 'exists:rfid_scan_logs,id'],
            // Phase 5: the no-pass alert the pop-up was prefilled from.
            'guest_observation_id' => ['nullable', 'integer', 'exists:guest_vehicle_observations,id'],
        ]);

        $scanLog = isset($validated['rfid_scan_log_id'])
            ? RfidScanLog::query()->find($validated['rfid_scan_log_id'])
            : null;

        if (! empty($validated['valid_minutes']) && empty($validated['valid_until'])) {
            $validated['valid_until'] = now()->addMinutes((int) $validated['valid_minutes'])->toIso8601String();
        }

        $visit = $guestPassService->issue($rfidTag, $validated, $request->user()?->id, $scanLog);
        $message = $visit->rfidTag->label.' issued'.($visit->plate ? ' to '.$visit->plate : '').'. ENTRY recorded.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'guest_visit' => [
                    'id' => $visit->id,
                    'pass' => $visit->rfidTag->display_number,
                    'plate' => $visit->plate,
                    'status' => $visit->status,
                    'entry_at' => $visit->entry_at?->toIso8601String(),
                    'valid_until' => $visit->valid_until?->toIso8601String(),
                ],
            ], 201);
        }

        return back()->with('status', $message);
    }
}
