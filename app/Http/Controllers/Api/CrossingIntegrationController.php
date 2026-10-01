<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesIntegration;
use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Models\Gate;
use App\Models\VehicleCrossing;
use App\Rules\ValidGate;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Phase 2 (visitor model): the detector reports every vehicle that crossed a
 * gate's trigger line, with the direction it worked out from the camera.
 * Sending the same event key again (a retry) does not add a second row.
 */
class CrossingIntegrationController extends Controller
{
    use AuthorizesIntegration;

    public function store(Request $request, SettingsService $settingsService): JsonResponse
    {
        if ($denied = $this->authorizeIntegrationRequest($request, $settingsService)) {
            return $denied;
        }

        // Multipart sends the metadata as a JSON string.
        if (is_string($request->input('detection_metadata'))) {
            $request->merge(['detection_metadata' => json_decode($request->input('detection_metadata'), true) ?: []]);
        }
        $request->merge(['direction' => strtoupper(trim((string) $request->input('direction', '')))]);

        $validated = $request->validate([
            'external_event_key' => ['required', 'string', 'max:120'],
            'camera_role' => ['required', 'string', new ValidGate],
            'camera_id' => ['nullable', 'integer', 'exists:cameras,id'],
            'direction' => ['required', 'in:'.implode(',', VehicleCrossing::DIRECTIONS)],
            'direction_reason' => ['nullable', 'string', 'max:120'],
            'event_time' => ['required', 'date'],
            'track_id' => ['nullable', 'integer', 'min:0'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'detected_vehicle_type' => ['nullable', 'string', 'max:50'],
            'detection_metadata' => ['nullable', 'array'],
            'snapshot' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ]);

        $existing = VehicleCrossing::query()->where('external_event_key', $validated['external_event_key'])->first();

        if ($existing) {
            if (! $existing->snapshot_path && $request->hasFile('snapshot')) {
                $existing->update(['snapshot_path' => $request->file('snapshot')->store('crossing_snapshots', 'public')]);
            }

            return response()->json([
                'message' => 'Crossing already stored.',
                'duplicate' => true,
                'crossing' => $this->payload($existing),
            ]);
        }

        $gate = Gate::normalizeCode($validated['camera_role']);
        $crossing = VehicleCrossing::query()->create([
            'gate' => $gate,
            'camera_id' => $validated['camera_id'] ?? Camera::query()->forRole($gate)->value('id'),
            'direction' => $validated['direction'],
            'direction_reason' => $validated['direction_reason'] ?? null,
            'crossed_at' => Carbon::parse($validated['event_time']),
            'track_id' => $validated['track_id'] ?? null,
            'confidence' => $validated['confidence'] ?? null,
            'vehicle_type' => $validated['detected_vehicle_type'] ?? null,
            'snapshot_path' => $request->hasFile('snapshot') ? $request->file('snapshot')->store('crossing_snapshots', 'public') : null,
            'external_event_key' => $validated['external_event_key'],
            'detection_metadata_json' => $validated['detection_metadata'] ?? null,
        ]);

        return response()->json([
            'message' => 'Crossing stored.',
            'duplicate' => false,
            'crossing' => $this->payload($crossing),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(VehicleCrossing $crossing): array
    {
        return [
            'id' => $crossing->id,
            'gate' => $crossing->gate,
            'direction' => $crossing->direction,
            'crossed_at' => $crossing->crossed_at?->toIso8601String(),
            'snapshot_path' => $crossing->snapshot_path,
        ];
    }
}
