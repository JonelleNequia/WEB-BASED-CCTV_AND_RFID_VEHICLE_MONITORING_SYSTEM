<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesIntegration;
use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Models\Gate;
use App\Models\VehicleCrossing;
use App\Rules\ValidGate;
use App\Services\RfidIngestService;
use App\Services\SettingsService;
use App\Services\VisitorRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Phase 2 (visitor model): the detector reports every vehicle pass through a
 * gate's zone, with the direction it worked out from the camera (away from
 * it or toward it, and the gate's setting). Sending the same event key again does not add a
 * second row: it updates the record (a sharper picture, plate or type found
 * before the vehicle left the zone).
 */
class CrossingIntegrationController extends Controller
{
    use AuthorizesIntegration;

    public function store(
        Request $request,
        SettingsService $settingsService,
        RfidIngestService $rfidIngestService,
        VisitorRecordService $visitorRecordService
    ): JsonResponse {
        if ($denied = $this->authorizeIntegrationRequest($request, $settingsService)) {
            return $denied;
        }

        // Multipart sends the metadata as a JSON string.
        if (is_string($request->input('detection_metadata'))) {
            $metadata = json_decode($request->input('detection_metadata'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($metadata)) {
                $request->merge(['detection_metadata' => $metadata]);
            }
        }
        if (is_string($request->input('direction'))) {
            $request->merge(['direction' => strtoupper(trim($request->input('direction')))]);
        }

        $validated = $request->validate([
            'external_event_key' => ['required', 'string', 'max:120'],
            'camera_role' => ['required', 'string', new ValidGate],
            'camera_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('cameras', 'id')->whereIn('camera_role', Gate::query()->retained()->pluck('code')->all()), new \App\Rules\CameraAtGate('camera_role')],
            'direction' => ['required', 'in:'.implode(',', VehicleCrossing::DIRECTIONS)],
            'direction_reason' => ['nullable', 'string', 'max:120'],
            'event_time' => ['required', 'date'],
            'track_id' => ['nullable', 'integer', 'min:0'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'detected_vehicle_type' => ['nullable', 'string', 'max:50'],
            'detection_metadata' => ['nullable', 'array'],
            'snapshot' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:10240', 'dimensions:max_width=8192,max_height=8192'],
            'snapshot_path' => ['prohibited'],
            // Kept for old clients, but never used as an image association.
            'vehicle_image_path' => ['nullable', 'string', 'max:255', 'not_regex:~(?:\A[/\\\\]|\.\.|:)~'],
        ]);
        \Illuminate\Support\Facades\Validator::make($validated, [
            'detection_metadata.picture.selected_at' => ['nullable', 'date'],
            'detection_metadata.picture.score' => ['nullable', 'numeric', 'min:0'],
        ])->validate();

        unset($validated['detection_metadata'][\App\Services\CrossingSnapshotService::KEY]);
        $ownership = app(\App\Services\OccurrenceOwnership::class);
        $media = app(\App\Services\CrossingSnapshotService::class);
        try {
            if ($record = \App\Models\VisitorRecord::where('external_event_key', $validated['external_event_key'])->first()) {
                $ownership->record($record, $validated);
            }
            if ($previous = VehicleCrossing::where('external_event_key', $validated['external_event_key'])->first()) {
                $ownership->crossing($previous, $validated);
            }
        } catch (\App\Exceptions\OccurrenceConflict $exception) {
            return response()->json(['message' => 'Occurrence context conflicts with existing evidence.'], 409);
        }
        $upload = $media->prepare($request->file('snapshot'));
        $used = false;
        $committed = false;
        try {
            $response = app(\App\Services\PlateProcessingLock::class)->run(function () use ($validated, $rfidIngestService, $visitorRecordService, $ownership, $media, $upload, &$used): JsonResponse {
                $used = false;
                $record = \App\Models\VisitorRecord::where('external_event_key', $validated['external_event_key'])->first();
                if ($record) $ownership->record($record, $validated);
                $existing = VehicleCrossing::query()->where('external_event_key', $validated['external_event_key'])->first();

                if ($existing) {
                    $ownership->crossing($existing, $validated);
                    // ROI work: the detector updates the SAME record until the vehicle
                    // left the zone (clearer picture, type, how it moved). The
                    // direction, time and RFID link stay as they were recorded.
                    $updates = array_filter([
                        'vehicle_type' => $validated['detected_vehicle_type'] ?? null,
                        'confidence' => $validated['confidence'] ?? null,
                        'detection_metadata_json' => isset($validated['detection_metadata'])
                            ? array_replace((array) $existing->detection_metadata_json, $validated['detection_metadata'])
                            : null,
                    ], fn ($value) => $value !== null);
                    $existing->update($updates);
                    $used = $media->attach($existing, $upload, (array) data_get($validated, 'detection_metadata.picture', []));
                    if (array_key_exists('detected_vehicle_type', $validated)) {
                        foreach (\App\Models\VisitorRecord::query()->where('vehicle_crossing_id', $existing->id)->get() as $record) {
                            app(\App\Services\VehicleTypeEvidenceService::class)->resolve($record, $validated['detected_vehicle_type'], true);
                            $record->save();
                            app(\App\Services\VehicleTypeEvidenceService::class)->syncEvent($record);
                        }
                    }

                    return response()->json([
                        'message' => 'Crossing updated.',
                        'duplicate' => true,
                        'image_update' => $used ? 'stored' : ($upload['status'] === 'failed' ? 'failed' : 'unchanged'),
                        'crossing' => $this->payload($existing->fresh()),
                    ]);
                }

                $gate = Gate::normalizeCode($validated['camera_role']);
                if (($record && $record->vehicle_crossing_id)
                    || (! $record && \App\Models\VehicleEvent::where('external_event_key', $validated['external_event_key'])->exists())) {
                    throw new \App\Exceptions\OccurrenceConflict('The incoming key has unlinked or incompatible existing occurrence evidence.');
                }
                $crossing = VehicleCrossing::query()->create([
                    'gate' => $gate,
                    'camera_id' => $validated['camera_id'] ?? Camera::query()->forRole($gate)->value('id'),
                    'direction' => $validated['direction'],
                    'direction_reason' => $validated['direction_reason'] ?? null,
                    'crossed_at' => Carbon::parse($validated['event_time']),
                    'track_id' => $validated['track_id'] ?? null,
                    'confidence' => $validated['confidence'] ?? null,
                    'vehicle_type' => $validated['detected_vehicle_type'] ?? null,
                    'snapshot_path' => null,
                    'external_event_key' => $validated['external_event_key'],
                    'detection_metadata_json' => $validated['detection_metadata'] ?? null,
                ]);
                if ($record) $record->forceFill(['vehicle_crossing_id' => $crossing->id])->save();

                // RFID only with a vehicle: this vehicle's tag from the read buffer
                // makes its one record (registered IN / OUT with this direction).
                $tagged = $rfidIngestService->forCrossing($crossing);
                $scan = $tagged['scan'];
                // Phase 5: no registered tag -> an Unregistered Visitor record (an
                // unknown tag is kept on it: "Register this tag").
                $visitor = $scan ? null : $visitorRecordService->createFromCrossing($crossing->fresh(), $tagged['unknown_tag']);
                $used = $media->attach($crossing->fresh(), $upload, (array) data_get($validated, 'detection_metadata.picture', []));

                return response()->json([
                    'message' => 'Crossing stored.',
                    'duplicate' => false,
                    'image_update' => $used ? 'stored' : ($upload['status'] === 'failed' ? 'failed' : 'unavailable'),
                    'crossing' => $this->payload($crossing->fresh()),
                    'rfid_scan' => $scan ? [
                        'id' => $scan->id,
                        'plate_number' => $scan->vehicle?->plate_number,
                        'event_type' => $scan->resolved_event_type,
                        'resulting_state' => $scan->resulting_state,
                        'anomaly_reason' => $scan->anomaly_reason,
                    ] : null,
                    'visitor_record_id' => $visitor?->id,
                    'unknown_tag' => $tagged['unknown_tag'],
                ], 201);
            });
            $committed = true;

            return $response;
        } catch (\App\Exceptions\OccurrenceConflict $exception) {
            return response()->json(['message' => 'Occurrence context conflicts with existing evidence.'], 409);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Crossing could not be saved. Retry this occurrence.'], 500);
        } finally {
            if (! $committed || ! $used) $media->discard($upload);
        }
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
            'snapshot_url' => $crossing->snapshot_url,
            'snapshot_status' => \App\Support\OccurrenceMedia::exists($crossing->snapshot_path) ? 'available'
                : data_get($crossing->detection_metadata_json, \App\Services\CrossingSnapshotService::KEY.'.status', 'unavailable'),
            'rfid_scan_log_id' => $crossing->rfid_scan_log_id,
        ];
    }
}
