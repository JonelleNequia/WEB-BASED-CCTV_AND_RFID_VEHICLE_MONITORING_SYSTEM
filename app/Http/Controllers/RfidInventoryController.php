<?php

namespace App\Http\Controllers;

use App\Models\RfidTag;
use App\Services\VehicleRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RfidInventoryController extends Controller
{
    /**
     * Show the offline RFID tag inventory enrollment page.
     */
    public function index(): View
    {
        return view('rfid-inventory.index', [
            'availableTags' => $this->availableTags(),
            'tagStats' => [
                'available' => RfidTag::query()->where('status', RfidTag::STATUS_AVAILABLE)->count(),
                'assigned' => RfidTag::query()->where('status', RfidTag::STATUS_ASSIGNED)->count(),
                'inactive' => RfidTag::query()->where('status', RfidTag::STATUS_INACTIVE)->count(),
            ],
        ]);
    }

    /**
     * Enroll one scanned UID into the offline available tag pool.
     */
    public function store(Request $request, VehicleRegistryService $vehicleRegistryService): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'uid' => ['required', 'string', 'max:100'],
        ]);

        $uid = $vehicleRegistryService->normalizeTagUid((string) $validated['uid']);

        $tag = RfidTag::query()
            ->where('uid', $uid)
            ->orWhere('tag_uid', $uid)
            ->first();

        $created = false;

        if (! $tag) {
            $tag = RfidTag::query()->create([
                'uid' => $uid,
                'status' => RfidTag::STATUS_AVAILABLE,
            ]);
            $created = true;
        }

        if ($tag->status !== RfidTag::STATUS_AVAILABLE) {
            $message = $tag->status === RfidTag::STATUS_ASSIGNED
                ? 'RFID tag is already assigned to a vehicle.'
                : 'RFID tag is inactive and cannot be re-enrolled from this page.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'tag' => $this->tagPayload($tag),
                ], 409);
            }

            return back()->withErrors(['uid' => $message]);
        }

        $message = $created
            ? 'RFID tag added to available inventory.'
            : 'RFID tag is already available in inventory.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'created' => $created,
                'tag' => $this->tagPayload($tag->fresh()),
                'available_tags' => $this->availableTags()->map(fn (RfidTag $availableTag): array => $this->tagPayload($availableTag))->values(),
            ], $created ? 201 : 200);
        }

        return back()->with('status', $message);
    }

    /**
     * @return \Illuminate\Support\Collection<int, RfidTag>
     */
    protected function availableTags()
    {
        return RfidTag::query()
            ->available()
            ->orderByDesc('created_at')
            ->orderBy('uid')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function tagPayload(RfidTag $tag): array
    {
        $tag->loadMissing('vehicle');

        return [
            'id' => $tag->id,
            'uid' => $tag->uid,
            'status' => $tag->status,
            'vehicle_plate' => $tag->vehicle?->plate_number,
            'vehicle_owner' => $tag->vehicle?->vehicle_owner_name,
            'created_at' => $tag->created_at?->format('M d, Y h:i A'),
        ];
    }
}
