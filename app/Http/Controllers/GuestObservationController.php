<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGuestObservationRequest;
use App\Models\ActiveSession;
use App\Models\Camera;
use App\Models\EventReceiveLog;
use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\VehicleEvent;
use App\Services\GuestObservationService;
use App\Services\SettingsService;
use App\Support\PlateNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class GuestObservationController extends Controller
{
    protected const DETECTOR_TRACK_DUPLICATE_WINDOW_SECONDS = 30;

    protected const DETECTOR_PLATE_DUPLICATE_WINDOW_SECONDS = 180;

    protected const DETECTOR_SHORT_DUPLICATE_WINDOW_SECONDS = 30;

    protected const DETECTOR_SHARED_SCENE_DUPLICATE_WINDOW_SECONDS = 45;

    protected const DETECTOR_SHARED_SOURCE_MIN_IOU = 0.30;

    /**
     * Show guest monitoring form and log history.
     */
    public function index(Request $request, GuestObservationService $guestObservationService): View
    {
        return view('guest-observations.index', [
            'filters' => $request->only(['plate_text', 'location', 'date_from', 'date_to']),
            'observations' => $guestObservationService->paginated($request->all(), 10),
            'guestCountToday' => $guestObservationService->countToday(),
            'cameras' => Camera::query()->orderBy('camera_name')->get(),
            'latestUnregisteredCapture' => GuestVehicleObservation::query()
                ->with('camera')
                ->where('observation_source', 'cctv')
                ->latest('observed_at')
                ->first(),
        ]);
    }

    /**
     * Store one guest monitoring record.
     */
    public function store(
        Request $request,
        GuestObservationService $guestObservationService,
        SettingsService $settingsService
    ): RedirectResponse|JsonResponse {
        if ($this->isDetectorGuestObservationRequest($request)) {
            return $this->storeDetectorGuestObservation($request, $settingsService);
        }

        try {
            $this->prepareManualGuestObservationRequest($request);

            $data = $request->validate($this->manualGuestObservationRules());

            if ($request->hasFile('snapshot')) {
                $data['snapshot_image'] = $request->file('snapshot');
            }

            $guestObservationService->create($data, auth()->id());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Manual guest observation save failed.', [
                'message' => $exception->getMessage(),
                'payload' => $request->except(['_token', 'snapshot', 'snapshot_image', 'image']),
            ]);

            return back()
                ->withInput()
                ->withErrors(['guest_observation' => 'Guest observation could not be saved. Please try again.']);
        }

        return back()->with('status', 'Guest observation saved.');
    }

    /**
     * Verify or correct one detector-created guest observation.
     */
    public function update(
        UpdateGuestObservationRequest $request,
        GuestVehicleObservation $guestVehicleObservation
    ): RedirectResponse {
        $validated = $request->validated();
        $plateNumber = $this->normalizePlate($validated['plate_number'] ?? null);

        $updates = [
            'plate_number' => $plateNumber,
            'plate_text' => $plateNumber,
            'vehicle_type' => $validated['vehicle_type'] ?? null,
            'vehicle_color' => $this->normalizeVehicleColor($validated['vehicle_color'] ?? null),
            'location' => $validated['location'],
            'observed_at' => $validated['observed_at'],
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
        ];

        DB::transaction(function () use ($guestVehicleObservation, $updates): void {
            $guestVehicleObservation->update($updates);

            if (filled($guestVehicleObservation->external_event_key)) {
                $this->syncDetectorGuestVehicleEvent($guestVehicleObservation, []);
            }
        });

        return back()->with('status', 'Guest observation updated.');
    }

    /**
     * Mark one pending guest observation as verified after admin correction.
     */
    public function verify(Request $request, GuestVehicleObservation $guestVehicleObservation): RedirectResponse
    {
        $validated = $request->validate([
            'plate_number' => ['nullable', 'string', 'max:50'],
            'vehicle_type' => ['nullable', 'string', 'max:50'],
            'vehicle_color' => ['nullable', 'string', 'max:50'],
            'location' => ['required', 'in:entrance,exit'],
            'observed_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $plateNumber = $this->normalizePlate($validated['plate_number'] ?? null);

        $updates = [
            'plate_number' => $plateNumber,
            'plate_text' => $plateNumber,
            'vehicle_type' => $validated['vehicle_type'] ?? null,
            'vehicle_color' => $this->normalizeVehicleColor($validated['vehicle_color'] ?? null),
            'location' => $validated['location'],
            'observed_at' => Carbon::parse($validated['observed_at']),
            'status' => 'verified',
            'notes' => $validated['notes'] ?? null,
        ];

        DB::transaction(function () use ($guestVehicleObservation, $updates): void {
            $guestVehicleObservation->update($updates);

            if (filled($guestVehicleObservation->external_event_key)) {
                $this->syncDetectorGuestVehicleEvent($guestVehicleObservation, []);
            }
        });

        return back()->with('status', 'Guest observation marked as verified.');
    }

    protected function normalizePlate(?string $plate): ?string
    {
        return PlateNumber::normalize($plate);
    }

    protected function normalizeVehicleColor(?string $color): ?string
    {
        if (blank($color)) {
            return null;
        }

        return Str::title(trim((string) $color));
    }

    protected function isDetectorGuestObservationRequest(Request $request): bool
    {
        return $request->is('api/*')
            || $request->expectsJson()
            || $request->filled('external_event_key')
            || $request->filled('camera_role');
    }

    protected function storeDetectorGuestObservation(
        Request $request,
        SettingsService $settingsService
    ): JsonResponse {
        $sourceName = $request->header('X-Source-Name', 'philcst-detector');
        $snapshotPath = null;

        try {
            $authorized = $this->authorizeIntegrationRequest($request, $settingsService);

            if ($authorized !== null) {
                EventReceiveLog::query()->create([
                    'source_name' => $sourceName,
                    'payload_json' => $this->safeGuestObservationLogPayload($request),
                    'status' => 'unauthorized',
                    'notes' => 'API key missing or invalid for detector guest observation.',
                ]);

                return $authorized;
            }

            $this->prepareDetectorGuestObservationRequest($request);

            $validated = $request->validate([
                'external_event_key' => ['required', 'string', 'max:120'],
                'camera_role' => ['required', 'string', 'in:entrance,exit'],
                'camera_id' => ['nullable', 'integer', 'exists:cameras,id'],
                'detected_vehicle_type' => ['nullable', 'string', 'max:50'],
                'event_time' => ['required', 'date'],
                'vehicle_image_path' => ['nullable', 'string', 'max:255'],
                'snapshot' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
                'snapshot_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
                'plate_number' => ['nullable', 'string', 'max:50'],
                'plate_text' => ['nullable', 'string', 'max:50'],
                'vehicle_color' => ['nullable', 'string', 'max:50'],
                'detected_vehicle_color' => ['nullable', 'string', 'max:50'],
                'detection_metadata' => ['nullable', 'array'],
            ]);

            $snapshotFile = $request->hasFile('snapshot')
                ? $request->file('snapshot')
                : ($request->hasFile('snapshot_image')
                    ? $request->file('snapshot_image')
                    : ($request->hasFile('image') ? $request->file('image') : null));

            $cameraId = $validated['camera_id']
                ?? Camera::query()->forRole($validated['camera_role'])->value('id');
            $cameraId = $cameraId !== null ? (int) $cameraId : null;

            $existing = $this->findExistingDetectorGuestObservation($validated['external_event_key']);

            if ($existing) {
                $snapshotPath = $this->storeDetectorGuestSnapshotForObservation(
                    $snapshotFile,
                    $validated,
                    $existing
                );

                DB::transaction(function () use ($existing, $validated, $snapshotPath): void {
                    $this->updateDetectorGuestObservationDetails($existing, $validated, $snapshotPath);
                    $this->syncDetectorGuestVehicleEvent($existing, $validated);
                });

                EventReceiveLog::query()->create([
                    'source_name' => $sourceName,
                    'payload_json' => $this->safeGuestObservationLogPayload($request, $snapshotPath),
                    'status' => 'guest_observation_duplicate_updated',
                    'notes' => "Duplicate guest observation merged for ID: {$existing->id}",
                ]);

                return response()->json([
                    'message' => 'Duplicate guest observation merged.',
                    'duplicate' => true,
                    'guest_observation_id' => $existing->id,
                    'snapshot_path' => $existing->snapshot_path,
                    'overlay' => $this->guestOverlayPayload($existing),
                ]);
            }

            $recentVerifiedScan = $this->findRecentVerifiedRfidScanForGuestPayload(
                $validated['camera_role'],
                Carbon::parse($validated['event_time'])
            );

            if ($recentVerifiedScan) {
                EventReceiveLog::query()->create([
                    'source_name' => $sourceName,
                    'payload_json' => $this->safeGuestObservationLogPayload($request),
                    'status' => 'guest_observation_suppressed_registered',
                    'notes' => "Guest observation suppressed because RFID scan {$recentVerifiedScan->id} already verified a registered vehicle.",
                ]);

                return response()->json([
                    'message' => 'Guest observation suppressed because a verified RFID scan matched this detector window.',
                    'duplicate' => true,
                    'suppressed' => true,
                    'rfid_scan_id' => $recentVerifiedScan->id,
                    'overlay' => $this->registeredOverlayPayload($recentVerifiedScan),
                ]);
            }

            $recentDuplicate = $this->findRecentGuestObservationForDetectorPayload(
                $validated['camera_role'],
                Carbon::parse($validated['event_time']),
                $this->normalizePlate($validated['plate_number'] ?? $validated['plate_text'] ?? null),
                $validated['detection_metadata'] ?? [],
                $cameraId
            );

            if ($recentDuplicate) {
                $snapshotPath = $this->storeDetectorGuestSnapshotForObservation(
                    $snapshotFile,
                    $validated,
                    $recentDuplicate
                );

                DB::transaction(function () use ($recentDuplicate, $validated, $snapshotPath): void {
                    $this->updateDetectorGuestObservationDetails($recentDuplicate, $validated, $snapshotPath);
                    $this->syncDetectorGuestVehicleEvent($recentDuplicate, $validated);
                });

                EventReceiveLog::query()->create([
                    'source_name' => $sourceName,
                    'payload_json' => $this->safeGuestObservationLogPayload($request, $snapshotPath),
                    'status' => 'guest_observation_recent_duplicate_merged',
                    'notes' => "Recent duplicate guest observation merged into ID: {$recentDuplicate->id}",
                ]);

                return response()->json([
                    'message' => 'Recent duplicate guest observation merged.',
                    'duplicate' => true,
                    'guest_observation_id' => $recentDuplicate->id,
                    'snapshot_path' => $recentDuplicate->snapshot_path,
                    'overlay' => $this->guestOverlayPayload($recentDuplicate),
                ]);
            }

            if (! $snapshotFile && blank($validated['vehicle_image_path'] ?? null)) {
                throw ValidationException::withMessages([
                    'snapshot' => 'A JPEG snapshot upload or existing vehicle image path is required.',
                ]);
            }

            $snapshotPath = $this->storeDetectorGuestSnapshot($snapshotFile, $validated);

            if (! $snapshotPath) {
                throw ValidationException::withMessages([
                    'snapshot' => 'The guest snapshot could not be stored.',
                ]);
            }

            $observation = DB::transaction(function () use ($validated, $cameraId, $snapshotPath): GuestVehicleObservation {
                $plateNumber = $this->normalizePlate($validated['plate_number'] ?? $validated['plate_text'] ?? null);
                $vehicleColor = $this->normalizeVehicleColor($validated['vehicle_color'] ?? $validated['detected_vehicle_color'] ?? null);

                $observation = GuestVehicleObservation::query()->create([
                    'plate_text' => $plateNumber,
                    'plate_number' => $plateNumber,
                    'vehicle_type' => $validated['detected_vehicle_type'] ?? 'Vehicle',
                    'vehicle_color' => $vehicleColor,
                    'location' => $validated['camera_role'],
                    'observation_source' => 'cctv',
                    'status' => 'pending_review',
                    'observed_at' => Carbon::parse($validated['event_time']),
                    'camera_id' => $cameraId,
                    'external_event_key' => $validated['external_event_key'],
                    'detection_metadata_json' => $validated['detection_metadata'] ?? null,
                    'snapshot_path' => $snapshotPath,
                    'notes' => 'No successful RFID scan was recorded within the detector confirmation window.',
                    'created_by' => null,
                ]);

                $this->syncDetectorGuestVehicleEvent($observation, $validated);

                return $observation->fresh();
            });

            EventReceiveLog::query()->create([
                'source_name' => $sourceName,
                'payload_json' => $this->safeGuestObservationLogPayload($request, $snapshotPath),
                'status' => 'guest_observation_created',
                'notes' => "Guest observation created with ID: {$observation->id}",
            ]);

            return response()->json([
                'message' => 'Guest observation saved.',
                'duplicate' => false,
                'guest_observation_id' => $observation->id,
                'status' => $observation->status,
                'snapshot_path' => $observation->snapshot_path,
                'overlay' => $this->guestOverlayPayload($observation),
            ], 201);
        } catch (ValidationException $exception) {
            Log::warning('Detector guest observation validation failed.', [
                'errors' => $exception->errors(),
                'payload' => $request->except(['snapshot', 'snapshot_image', 'image']),
            ]);

            EventReceiveLog::query()->create([
                'source_name' => $sourceName,
                'payload_json' => $this->safeGuestObservationLogPayload($request, $snapshotPath),
                'status' => 'validation_failed',
                'notes' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Guest observation payload is invalid.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            Log::error('Detector guest observation save failed.', [
                'message' => $exception->getMessage(),
                'payload' => $request->except(['snapshot', 'snapshot_image', 'image']),
            ]);

            try {
                EventReceiveLog::query()->create([
                    'source_name' => $sourceName,
                    'payload_json' => $this->safeGuestObservationLogPayload($request, $snapshotPath),
                    'status' => 'failed',
                    'notes' => $exception->getMessage(),
                ]);
            } catch (Throwable $logException) {
                Log::error('Detector guest observation failure could not be logged.', [
                    'message' => $logException->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Guest observation could not be saved. Check storage permissions and laravel.log.',
            ], 500);
        }
    }

    /**
     * Mirror one detector guest observation into vehicle_events for station,
     * dashboard, and report screens that read the primary event stream.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function syncDetectorGuestVehicleEvent(
        GuestVehicleObservation $observation,
        array $validated
    ): VehicleEvent {
        $observation->refresh();

        $externalEventKey = $observation->external_event_key ?: ($validated['external_event_key'] ?? null);

        if (blank($externalEventKey)) {
            throw ValidationException::withMessages([
                'external_event_key' => 'A detector guest observation needs an external event key before syncing to event logs.',
            ]);
        }

        $eventType = $observation->location === 'exit' ? 'EXIT' : 'ENTRY';
        $plateNumber = $this->normalizePlate(
            $observation->plate_number
                ?: $observation->plate_text
                ?: ($validated['plate_number'] ?? $validated['plate_text'] ?? null)
        );
        $vehicleColor = $this->normalizeVehicleColor(
            $observation->vehicle_color
                ?: ($validated['vehicle_color'] ?? $validated['detected_vehicle_color'] ?? null)
        );
        $vehicleType = $observation->vehicle_type
            ?: ($validated['detected_vehicle_type'] ?? 'Vehicle');

        $existingEvent = VehicleEvent::query()
            ->where('external_event_key', (string) $externalEventKey)
            ->first();

        $event = VehicleEvent::query()->updateOrCreate(
            ['external_event_key' => (string) $externalEventKey],
            [
                'event_type' => $eventType,
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'event_origin' => 'guest_cctv',
                'direction' => $eventType === 'EXIT' ? 'OUT' : 'IN',
                'plate_text' => $plateNumber,
                'plate_number' => $plateNumber !== null ? substr($plateNumber, 0, 20) : null,
                'plate_confidence' => null,
                'vehicle_id' => null,
                'rfid_scan_log_id' => null,
                'vehicle_type' => $vehicleType,
                'detected_vehicle_type' => $vehicleType,
                'vehicle_color' => $vehicleColor,
                'vehicle_category' => 'guest',
                'camera_id' => $observation->camera_id,
                'detection_metadata_json' => $observation->detection_metadata_json,
                'details_completed_at' => now(),
                'roi_name' => $observation->location === 'exit'
                    ? 'Exit Guest Detector'
                    : 'Entrance Guest Detector',
                'event_time' => $observation->observed_at,
                'vehicle_image_path' => $observation->snapshot_path,
                'plate_image_path' => null,
                'matched_entry_id' => null,
                'match_score' => null,
                'match_status' => $eventType === 'ENTRY' ? 'open' : 'unmatched',
                'resulting_state' => $eventType === 'ENTRY' ? 'INSIDE' : 'OUTSIDE',
                'daily_entries_count' => null,
                'daily_exits_count' => null,
            ]
        );

        if ($existingEvent && $eventType === 'EXIT' && $existingEvent->matched_entry_id) {
            $event->forceFill([
                'matched_entry_id' => $existingEvent->matched_entry_id,
            ])->save();
        }

        $this->applyGuestVehicleSessionState($event);

        return $event->fresh(['camera', 'matchedEntry.camera', 'activeSession']);
    }

    /**
     * Keep guest vehicles persistent: ENTRY opens a session, EXIT closes it.
     */
    protected function applyGuestVehicleSessionState(VehicleEvent $event): void
    {
        if (! $this->isGuestVehicleEvent($event)) {
            return;
        }

        if ($event->event_type === 'ENTRY') {
            $session = ActiveSession::query()->firstOrCreate(
                ['entry_event_id' => $event->id],
                [
                    'plate_text' => $event->plate_text,
                    'plate_number' => $event->plate_number ?: ($event->plate_text !== null ? substr($event->plate_text, 0, 20) : null),
                    'vehicle_type' => $event->vehicle_type,
                    'vehicle_color' => $event->vehicle_color,
                    'entry_time' => $event->event_time,
                    'status' => 'open',
                ]
            );

            $this->syncGuestSessionDetails($session, $event);

            $event->forceFill([
                'match_status' => $session->status === 'closed' ? 'closed' : 'open',
                'resulting_state' => 'INSIDE',
            ])->save();

            return;
        }

        if ($event->event_type !== 'EXIT') {
            return;
        }

        ActiveSession::query()
            ->where('entry_event_id', $event->id)
            ->delete();

        $session = $event->matched_entry_id
            ? ActiveSession::query()->where('entry_event_id', $event->matched_entry_id)->first()
            : $this->findOpenGuestSessionForExit($event);

        if (! $session) {
            $event->forceFill([
                'matched_entry_id' => null,
                'match_score' => null,
                'match_status' => 'unmatched',
                'resulting_state' => 'OUTSIDE',
            ])->save();

            return;
        }

        $session->forceFill([
            'status' => 'closed',
            'time_out' => $event->event_time,
        ])->save();

        $event->forceFill([
            'matched_entry_id' => $session->entry_event_id,
            'match_score' => null,
            'match_status' => 'closed',
            'resulting_state' => 'OUTSIDE',
        ])->save();

        $session->entryEvent?->forceFill([
            'match_status' => 'closed',
            'resulting_state' => 'INSIDE',
        ])->save();
    }

    protected function findOpenGuestSessionForExit(VehicleEvent $event): ?ActiveSession
    {
        $plate = $this->normalizePlate($event->plate_text ?: $event->plate_number);

        $query = ActiveSession::query()
            ->with('entryEvent')
            ->where('status', 'open')
            ->where('entry_event_id', '!=', $event->id)
            ->where('entry_time', '<=', $event->event_time)
            ->whereHas('entryEvent', function ($entryQuery): void {
                $entryQuery->where(function ($guestQuery): void {
                    $guestQuery->where('vehicle_category', 'guest')
                        ->orWhereIn('event_origin', ['guest_cctv', 'guest_manual']);
                });
            });

        if ($plate !== null) {
            $fingerprint = $this->plateFingerprint($plate);

            return $query
                ->orderByDesc('entry_time')
                ->limit(50)
                ->get()
                ->first(function (ActiveSession $session) use ($fingerprint): bool {
                    return $fingerprint !== ''
                        && in_array($fingerprint, [
                            $this->plateFingerprint($session->plate_text),
                            $this->plateFingerprint($session->plate_number),
                        ], true);
                });
        }

        if ($plate === null) {
            if (filled($event->vehicle_type)) {
                $query->where('vehicle_type', $event->vehicle_type);
            }

            if (filled($event->vehicle_color)) {
                $query->where('vehicle_color', $event->vehicle_color);
            }
        }

        return $query->orderByDesc('entry_time')->first();
    }

    protected function isGuestVehicleEvent(VehicleEvent $event): bool
    {
        return $event->vehicle_category === 'guest'
            || in_array($event->event_origin, ['guest_cctv', 'guest_manual'], true);
    }

    protected function syncGuestSessionDetails(ActiveSession $session, VehicleEvent $event): void
    {
        $updates = [];

        if (filled($event->plate_text)) {
            $updates['plate_text'] = $event->plate_text;
            $updates['plate_number'] = $event->plate_number ?: substr($event->plate_text, 0, 20);
        }

        if (filled($event->vehicle_type)) {
            $updates['vehicle_type'] = $event->vehicle_type;
        }

        if (filled($event->vehicle_color)) {
            $updates['vehicle_color'] = $event->vehicle_color;
        }

        if ($updates !== []) {
            $session->forceFill($updates)->save();
        }
    }

    /**
     * Merge late OCR/color results into the existing record created at timeout.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function updateDetectorGuestObservationDetails(
        GuestVehicleObservation $observation,
        array $validated,
        ?string $snapshotPath = null
    ): void {
        if ($observation->status === 'verified') {
            return;
        }

        $updates = [];

        if ($snapshotPath !== null) {
            $updates['snapshot_path'] = $snapshotPath;
        }

        $sameExternalEvent = filled($validated['external_event_key'] ?? null)
            && filled($observation->external_event_key)
            && (string) $validated['external_event_key'] === (string) $observation->external_event_key;
        $analysisComplete = ($validated['detection_metadata']['analysis_status'] ?? null) === 'complete';
        $plateNumber = $this->normalizePlate($validated['plate_number'] ?? $validated['plate_text'] ?? null);

        if ($plateNumber !== null && (blank($observation->plate_number) || ($sameExternalEvent && $analysisComplete))) {
            $updates['plate_number'] = $plateNumber;
            $updates['plate_text'] = $plateNumber;
        }

        $vehicleColor = $this->normalizeVehicleColor($validated['vehicle_color'] ?? $validated['detected_vehicle_color'] ?? null);

        if ($vehicleColor !== null && (blank($observation->vehicle_color) || ($sameExternalEvent && $analysisComplete))) {
            $updates['vehicle_color'] = $vehicleColor;
        }

        if ($this->shouldMergeDetectorMetadata($observation, $validated)) {
            $updates['detection_metadata_json'] = $this->mergedDetectorGuestMetadata($observation, $validated);
        }

        if ($updates !== []) {
            $observation->forceFill($updates)->save();
            $observation->refresh();
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function storeDetectorGuestSnapshotForObservation(
        mixed $snapshotFile,
        array $validated,
        GuestVehicleObservation $observation
    ): ?string {
        if (! $snapshotFile && ! $this->shouldReplaceGuestSnapshot($observation)) {
            return null;
        }

        return $this->storeDetectorGuestSnapshot($snapshotFile, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function storeDetectorGuestSnapshot(mixed $snapshotFile, array $validated): ?string
    {
        if ($snapshotFile) {
            if (method_exists($snapshotFile, 'isValid') && ! $snapshotFile->isValid()) {
                throw ValidationException::withMessages([
                    'snapshot' => 'The uploaded guest snapshot is not valid.',
                ]);
            }

            Storage::disk('public')->makeDirectory('guest_snapshots');

            return $snapshotFile->store('guest_snapshots', 'public');
        }

        return filled($validated['vehicle_image_path'] ?? null)
            ? (string) $validated['vehicle_image_path']
            : null;
    }

    protected function shouldReplaceGuestSnapshot(GuestVehicleObservation $observation): bool
    {
        return blank($observation->snapshot_path)
            || ! Storage::disk('public')->exists($observation->snapshot_path);
    }

    protected function findExistingDetectorGuestObservation(string $externalEventKey): ?GuestVehicleObservation
    {
        return GuestVehicleObservation::query()
            ->where('external_event_key', $externalEventKey)
            ->orWhereJsonContains('detection_metadata_json->merged_event_keys', $externalEventKey)
            ->first();
    }

    protected function shouldMergeDetectorMetadata(GuestVehicleObservation $observation, array $validated): bool
    {
        return (! empty($validated['detection_metadata']) && is_array($validated['detection_metadata']))
            || $this->isMergedDetectorExternalKey($observation, $validated);
    }

    protected function mergedDetectorGuestMetadata(GuestVehicleObservation $observation, array $validated): array
    {
        $current = is_array($observation->detection_metadata_json)
            ? $observation->detection_metadata_json
            : [];
        $incoming = (! empty($validated['detection_metadata']) && is_array($validated['detection_metadata']))
            ? $validated['detection_metadata']
            : [];
        $isMergedKey = $this->isMergedDetectorExternalKey($observation, $validated);

        if ($isMergedKey) {
            foreach (['track_id', 'confidence', 'direction', 'bbox_xyxy', 'xyxy'] as $field) {
                unset($incoming[$field]);
            }
        }

        $merged = array_replace($current, $incoming);

        if ($isMergedKey) {
            $mergedKeys = $merged['merged_event_keys'] ?? [];
            $mergedKeys = is_array($mergedKeys) ? $mergedKeys : [];
            $mergedKeys[] = (string) $validated['external_event_key'];
            $merged['merged_event_keys'] = array_values(array_unique(array_filter($mergedKeys)));
        }

        return $merged;
    }

    protected function isMergedDetectorExternalKey(GuestVehicleObservation $observation, array $validated): bool
    {
        return filled($validated['external_event_key'] ?? null)
            && filled($observation->external_event_key)
            && (string) $observation->external_event_key !== (string) $validated['external_event_key'];
    }

    protected function findRecentVerifiedRfidScanForGuestPayload(string $cameraRole, Carbon $eventTime): ?RfidScanLog
    {
        $eventTime = $eventTime->copy()->setTimezone(config('app.timezone', 'UTC'));
        $from = $eventTime->copy()->subSeconds(3);
        $to = $eventTime->copy()->addSeconds(8);

        $query = RfidScanLog::query()
            ->with('vehicle.rfidTag')
            ->where('verification_status', 'verified')
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('scan_time', [$from, $to])
                    ->orWhereBetween('created_at', [$from, $to]);
            });

        $sameStationScan = (clone $query)
            ->where('scan_location', $cameraRole)
            ->latest('scan_time')
            ->latest('created_at')
            ->first();

        if ($sameStationScan) {
            return $sameStationScan;
        }

        return $query
            ->latest('scan_time')
            ->latest('created_at')
            ->first();
    }

    protected function findRecentGuestObservationForDetectorPayload(
        string $cameraRole,
        Carbon $eventTime,
        ?string $plateNumber,
        array $metadata = [],
        ?int $cameraId = null
    ): ?GuestVehicleObservation {
        $eventTime = $eventTime->copy()->setTimezone(config('app.timezone', 'UTC'));
        $trackId = $metadata['track_id'] ?? null;

        if (is_scalar($trackId) && $trackId !== '') {
            $trackDuplicate = $this->latestTrackDetectorDuplicate(
                $this->recentDetectorGuestObservationQuery(
                    $eventTime,
                    self::DETECTOR_TRACK_DUPLICATE_WINDOW_SECONDS
                ),
                $cameraRole,
                $cameraId,
                $trackId,
                $metadata
            );

            if ($trackDuplicate) {
                return $trackDuplicate;
            }
        }

        if ($plateNumber !== null) {
            $plateQuery = $this->recentDetectorGuestObservationQuery(
                $eventTime,
                self::DETECTOR_PLATE_DUPLICATE_WINDOW_SECONDS
            );

            $sameStationPlateDuplicate = $this->latestPlateDetectorDuplicate(
                $plateQuery,
                $plateNumber,
                $cameraRole
            );

            if ($sameStationPlateDuplicate) {
                return $sameStationPlateDuplicate;
            }

            $sharedSourcePlateDuplicate = $this->latestSharedSourceDetectorDuplicate(
                $plateQuery,
                $cameraId,
                $plateNumber,
                $metadata
            );

            if ($sharedSourcePlateDuplicate) {
                return $sharedSourcePlateDuplicate;
            }

            $nearPlateDuplicate = $this->latestPlateDetectorDuplicate(
                $this->recentDetectorGuestObservationQuery($eventTime, self::DETECTOR_SHORT_DUPLICATE_WINDOW_SECONDS),
                $plateNumber
            );

            if ($nearPlateDuplicate) {
                return $nearPlateDuplicate;
            }

            $unidentifiedDuplicate = $this->recentUnidentifiedDetectorDuplicate(
                $eventTime,
                $cameraRole,
                $cameraId,
                $metadata
            );

            if ($unidentifiedDuplicate) {
                return $unidentifiedDuplicate;
            }

            return null;
        }

        $sameStationUnidentifiedDuplicate = $this->recentDetectorGuestObservationQuery(
            $eventTime,
            self::DETECTOR_SHORT_DUPLICATE_WINDOW_SECONDS
        )
            ->whereNull('plate_number')
            ->where('location', $cameraRole)
            ->latest('created_at')
            ->first();

        if ($sameStationUnidentifiedDuplicate) {
            return $sameStationUnidentifiedDuplicate;
        }

        $sharedSceneDuplicate = $this->latestSharedSourceDetectorDuplicate(
            $this->recentDetectorGuestObservationQuery(
                $eventTime,
                self::DETECTOR_SHARED_SCENE_DUPLICATE_WINDOW_SECONDS
            ),
            $cameraId,
            null,
            $metadata
        );

        if ($sharedSceneDuplicate) {
            return $sharedSceneDuplicate;
        }

        return null;
    }

    protected function recentDetectorGuestObservationQuery(Carbon $eventTime, int $windowSeconds): Builder
    {
        $from = $eventTime->copy()->subSeconds($windowSeconds);
        $to = $eventTime->copy()->addSeconds($windowSeconds);
        $recentlyReceivedFrom = now()->subSeconds($windowSeconds);

        return GuestVehicleObservation::query()
            ->where('observation_source', 'cctv')
            ->where('status', 'pending_review')
            ->where(function ($query) use ($from, $to, $recentlyReceivedFrom): void {
                $query->whereBetween('observed_at', [$from, $to])
                    ->orWhereBetween('created_at', [$from, $to])
                    ->orWhere('created_at', '>=', $recentlyReceivedFrom);
            });
    }

    protected function recentUnidentifiedDetectorDuplicate(
        Carbon $eventTime,
        string $cameraRole,
        ?int $cameraId,
        array $metadata = []
    ): ?GuestVehicleObservation {
        $query = $this->recentDetectorGuestObservationQuery($eventTime, self::DETECTOR_SHORT_DUPLICATE_WINDOW_SECONDS)
            ->whereNull('plate_number');

        $sameStationDuplicate = (clone $query)
            ->where('location', $cameraRole)
            ->latest('created_at')
            ->first();

        if ($sameStationDuplicate) {
            return $sameStationDuplicate;
        }

        return $this->latestSharedSourceDetectorDuplicate($query, $cameraId, null, $metadata);
    }

    protected function latestPlateDetectorDuplicate(
        Builder $query,
        string $plateNumber,
        ?string $cameraRole = null
    ): ?GuestVehicleObservation {
        $plateFingerprint = $this->plateFingerprint($plateNumber);

        if ($plateFingerprint === '') {
            return null;
        }

        $candidateQuery = (clone $query)
            ->where(function ($query): void {
                $query->whereNotNull('plate_number')
                    ->orWhereNotNull('plate_text');
            });

        if ($cameraRole !== null) {
            $candidateQuery->where('location', $cameraRole);
        }

        $candidates = $candidateQuery
            ->latest('created_at')
            ->limit(25)
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->plateFingerprint($candidate->plate_number ?: $candidate->plate_text) === $plateFingerprint) {
                return $candidate;
            }
        }

        return null;
    }

    protected function latestTrackDetectorDuplicate(
        Builder $query,
        string $cameraRole,
        ?int $cameraId,
        mixed $trackId,
        array $metadata = []
    ): ?GuestVehicleObservation {
        $requestBox = $this->metadataBoundingBox($metadata);
        $candidates = (clone $query)
            ->where('detection_metadata_json->track_id', $trackId)
            ->latest('created_at')
            ->limit(25)
            ->get();

        foreach ($candidates as $candidate) {
            if ($candidate->location === $cameraRole) {
                return $candidate;
            }

            if (! $this->camerasShareCaptureSource($cameraId, (int) $candidate->camera_id)) {
                continue;
            }

            $candidateBox = $this->metadataBoundingBox($candidate->detection_metadata_json ?? []);

            if ($requestBox === null || $candidateBox === null) {
                return $candidate;
            }

            if ($this->boundingBoxIou($requestBox, $candidateBox) >= self::DETECTOR_SHARED_SOURCE_MIN_IOU) {
                return $candidate;
            }
        }

        return null;
    }

    protected function latestSharedSourceDetectorDuplicate(
        Builder $query,
        ?int $cameraId,
        ?string $plateNumber = null,
        array $metadata = []
    ): ?GuestVehicleObservation {
        if ($cameraId === null) {
            return null;
        }

        $plateFingerprint = $plateNumber !== null
            ? $this->plateFingerprint($plateNumber)
            : null;
        $requestBox = $this->metadataBoundingBox($metadata);

        $candidates = (clone $query)
            ->whereNotNull('camera_id')
            ->latest('created_at')
            ->limit(25)
            ->get();

        foreach ($candidates as $candidate) {
            if ($plateFingerprint !== null
                && $this->plateFingerprint($candidate->plate_number ?: $candidate->plate_text) !== $plateFingerprint) {
                continue;
            }

            if ($this->camerasShareCaptureSource($cameraId, (int) $candidate->camera_id)) {
                if ($plateFingerprint !== null) {
                    return $candidate;
                }

                $candidateBox = $this->metadataBoundingBox($candidate->detection_metadata_json ?? []);

                if ($requestBox === null || $candidateBox === null) {
                    continue;
                }

                if ($this->boundingBoxIou($requestBox, $candidateBox) >= self::DETECTOR_SHARED_SOURCE_MIN_IOU) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<int, float>|null
     */
    protected function metadataBoundingBox(?array $metadata): ?array
    {
        $box = $metadata['bbox_xyxy'] ?? $metadata['xyxy'] ?? null;

        if (! is_array($box) || count($box) < 4) {
            return null;
        }

        $values = array_values($box);

        foreach (array_slice($values, 0, 4) as $value) {
            if (! is_numeric($value)) {
                return null;
            }
        }

        $x1 = (float) $values[0];
        $y1 = (float) $values[1];
        $x2 = (float) $values[2];
        $y2 = (float) $values[3];

        if ($x2 <= $x1 || $y2 <= $y1) {
            return null;
        }

        return [$x1, $y1, $x2, $y2];
    }

    /**
     * @param  array<int, float>  $left
     * @param  array<int, float>  $right
     */
    protected function boundingBoxIou(array $left, array $right): float
    {
        $intersectionX1 = max($left[0], $right[0]);
        $intersectionY1 = max($left[1], $right[1]);
        $intersectionX2 = min($left[2], $right[2]);
        $intersectionY2 = min($left[3], $right[3]);
        $intersectionWidth = max(0.0, $intersectionX2 - $intersectionX1);
        $intersectionHeight = max(0.0, $intersectionY2 - $intersectionY1);
        $intersectionArea = $intersectionWidth * $intersectionHeight;

        if ($intersectionArea <= 0.0) {
            return 0.0;
        }

        $leftArea = max(0.0, $left[2] - $left[0]) * max(0.0, $left[3] - $left[1]);
        $rightArea = max(0.0, $right[2] - $right[0]) * max(0.0, $right[3] - $right[1]);
        $unionArea = $leftArea + $rightArea - $intersectionArea;

        return $unionArea > 0.0 ? $intersectionArea / $unionArea : 0.0;
    }

    protected function camerasShareCaptureSource(?int $leftCameraId, ?int $rightCameraId): bool
    {
        if ($leftCameraId === null || $rightCameraId === null) {
            return false;
        }

        if ($leftCameraId === $rightCameraId) {
            return true;
        }

        $cameras = Camera::query()
            ->whereIn('id', [$leftCameraId, $rightCameraId])
            ->get()
            ->keyBy('id');

        $leftCamera = $cameras->get($leftCameraId);
        $rightCamera = $cameras->get($rightCameraId);

        if (! $leftCamera || ! $rightCamera) {
            return false;
        }

        $leftType = Str::lower(trim((string) $leftCamera->source_type));
        $rightType = Str::lower(trim((string) $rightCamera->source_type));
        $leftSource = trim((string) $leftCamera->source_value);
        $rightSource = trim((string) $rightCamera->source_value);

        return $leftType !== ''
            && $leftType === $rightType
            && $leftSource !== ''
            && $leftSource === $rightSource;
    }

    protected function plateFingerprint(?string $plateNumber): string
    {
        if (blank($plateNumber)) {
            return '';
        }

        return preg_replace('/[^A-Z0-9]/', '', Str::upper((string) $plateNumber)) ?? '';
    }

    protected function prepareManualGuestObservationRequest(Request $request): void
    {
        if (! $request->hasFile('snapshot_image') && $request->hasFile('snapshot')) {
            $request->files->set('snapshot_image', $request->file('snapshot'));
        }

        if (! $request->filled('plate_number') && $request->filled('plate_text')) {
            $request->merge(['plate_number' => $request->input('plate_text')]);
        }
    }

    protected function prepareDetectorGuestObservationRequest(Request $request): void
    {
        if (! $request->hasFile('snapshot_image') && $request->hasFile('snapshot')) {
            $request->files->set('snapshot_image', $request->file('snapshot'));
        }

        if (! $request->hasFile('snapshot_image') && $request->hasFile('image')) {
            $request->files->set('snapshot_image', $request->file('image'));
        }

        if ($request->input('camera_id') === '') {
            $request->request->remove('camera_id');
        }

        if (! $request->filled('plate_number') && $request->filled('plate_text')) {
            $request->merge(['plate_number' => $request->input('plate_text')]);
        }

        $metadata = $request->input('detection_metadata');

        if (is_string($metadata) && filled($metadata)) {
            $decoded = json_decode($metadata, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $request->merge(['detection_metadata' => $decoded]);
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function manualGuestObservationRules(): array
    {
        return [
            'plate_number' => ['nullable', 'string', 'max:50'],
            'plate_text' => ['nullable', 'string', 'max:50'],
            'vehicle_type' => ['required', 'string', 'max:50'],
            'vehicle_color' => ['nullable', 'string', 'max:50'],
            'location' => ['required', 'in:entrance,exit'],
            'observation_source' => ['nullable', 'in:manual,cctv'],
            'observed_at' => ['required', 'date'],
            'camera_id' => ['nullable', 'integer', 'exists:cameras,id'],
            'snapshot_image' => ['nullable', 'image', 'max:5120'],
            'snapshot' => ['nullable', 'image', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function safeGuestObservationLogPayload(Request $request, ?string $snapshotPath = null): array
    {
        $payload = $request->except(['snapshot', 'snapshot_image', 'image']);
        $file = $request->file('snapshot')
            ?: $request->file('snapshot_image')
            ?: $request->file('image');

        if ($file) {
            $payload['snapshot_image'] = [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'stored_path' => $snapshotPath,
            ];
        }

        return $payload;
    }

    protected function authorizeIntegrationRequest(Request $request, SettingsService $settingsService): ?JsonResponse
    {
        $configuredKey = trim((string) $settingsService->get('python_api_key', ''));
        $providedKey = trim((string) $request->header('X-Api-Key', ''));

        if ($configuredKey !== '' && hash_equals($configuredKey, $providedKey)) {
            return null;
        }

        if ($configuredKey === ''
            && $settingsService->get('deployment_mode', 'offline_local') === 'offline_local'
            && $this->isLoopbackRequest($request)) {
            return null;
        }

        return response()->json([
            'message' => 'API key is missing or invalid.',
        ], 401);
    }

    protected function isLoopbackRequest(Request $request): bool
    {
        $ip = (string) ($request->ip() ?: $request->server('REMOTE_ADDR', ''));

        return $ip === '::1'
            || $ip === 'localhost'
            || str_starts_with($ip, '127.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function registeredOverlayPayload(RfidScanLog $scanLog): array
    {
        $vehicle = $scanLog->vehicle;

        return [
            'verification' => 'registered',
            'label' => 'REGISTERED - '.$vehicle?->plate_number,
            'color' => 'green',
            'rfid_scan_id' => $scanLog->id,
            'action_taken' => $scanLog->resolved_event_type,
            'new_state' => $scanLog->resulting_state,
            'vehicle' => $vehicle ? [
                'id' => $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'owner_name' => $vehicle->owner_name,
                'category' => $vehicle->category,
                'vehicle_type' => $vehicle->vehicle_type,
                'rfid_tag_uid' => $vehicle->rfidTag?->uid ?? $vehicle->rfid_tag_uid,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function guestOverlayPayload(GuestVehicleObservation $observation): array
    {
        return [
            'verification' => 'guest',
            'label' => 'GUEST',
            'color' => 'red',
            'guest_observation_id' => $observation->id,
            'status' => $observation->status,
            'vehicle' => null,
        ];
    }
}
