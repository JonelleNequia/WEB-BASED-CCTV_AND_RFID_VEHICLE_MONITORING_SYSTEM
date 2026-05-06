<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGuestObservationRequest;
use App\Models\Camera;
use App\Models\EventReceiveLog;
use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Services\GuestObservationService;
use App\Services\SettingsService;
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

        $guestVehicleObservation->update([
            'plate_number' => $plateNumber,
            'plate_text' => $plateNumber,
            'vehicle_type' => $validated['vehicle_type'] ?? null,
            'vehicle_color' => $this->normalizeVehicleColor($validated['vehicle_color'] ?? null),
            'location' => $validated['location'],
            'observed_at' => $validated['observed_at'],
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
        ]);

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

        $guestVehicleObservation->update([
            'plate_number' => $plateNumber,
            'plate_text' => $plateNumber,
            'vehicle_type' => $validated['vehicle_type'] ?? null,
            'vehicle_color' => $this->normalizeVehicleColor($validated['vehicle_color'] ?? null),
            'location' => $validated['location'],
            'observed_at' => Carbon::parse($validated['observed_at']),
            'status' => 'verified',
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('status', 'Guest observation marked as verified.');
    }

    protected function normalizePlate(?string $plate): ?string
    {
        if (blank($plate)) {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', $plate) ?? $plate;

        return Str::upper(trim($normalized));
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

            $snapshotFile = $request->file('snapshot')
                ?: $request->file('snapshot_image')
                ?: $request->file('image');

            $existing = GuestVehicleObservation::query()
                ->where('external_event_key', $validated['external_event_key'])
                ->first();

            if ($existing) {
                $this->updateDetectorGuestObservationDetails($existing, $validated);

                EventReceiveLog::query()->create([
                    'source_name' => $sourceName,
                    'payload_json' => $this->safeGuestObservationLogPayload($request),
                    'status' => 'guest_observation_duplicate_updated',
                    'notes' => "Duplicate guest observation merged for ID: {$existing->id}",
                ]);

                return response()->json([
                    'message' => 'Duplicate guest observation merged.',
                    'duplicate' => true,
                    'guest_observation_id' => $existing->id,
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
                $validated['detection_metadata'] ?? []
            );

            if ($recentDuplicate) {
                $this->updateDetectorGuestObservationDetails($recentDuplicate, $validated);

                EventReceiveLog::query()->create([
                    'source_name' => $sourceName,
                    'payload_json' => $this->safeGuestObservationLogPayload($request),
                    'status' => 'guest_observation_recent_duplicate_merged',
                    'notes' => "Recent duplicate guest observation merged into ID: {$recentDuplicate->id}",
                ]);

                return response()->json([
                    'message' => 'Recent duplicate guest observation merged.',
                    'duplicate' => true,
                    'guest_observation_id' => $recentDuplicate->id,
                    'overlay' => $this->guestOverlayPayload($recentDuplicate),
                ]);
            }

            if (! $snapshotFile && blank($validated['vehicle_image_path'] ?? null)) {
                throw ValidationException::withMessages([
                    'snapshot' => 'A JPEG snapshot upload or existing vehicle image path is required.',
                ]);
            }

            if ($snapshotFile) {
                Storage::disk('public')->makeDirectory('guest_snapshots');
                $snapshotPath = $snapshotFile->store('guest_snapshots', 'public');
            } else {
                $snapshotPath = $validated['vehicle_image_path'] ?? null;
            }

            if (! $snapshotPath) {
                throw ValidationException::withMessages([
                    'snapshot' => 'The guest snapshot could not be stored.',
                ]);
            }

            $cameraId = $validated['camera_id']
                ?? Camera::query()->forRole($validated['camera_role'])->value('id');

            $observation = DB::transaction(function () use ($validated, $cameraId, $snapshotPath): GuestVehicleObservation {
                $plateNumber = $this->normalizePlate($validated['plate_number'] ?? $validated['plate_text'] ?? null);
                $vehicleColor = $this->normalizeVehicleColor($validated['vehicle_color'] ?? $validated['detected_vehicle_color'] ?? null);

                return GuestVehicleObservation::query()->create([
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
            });

            EventReceiveLog::query()->create([
                'source_name' => $sourceName,
                'payload_json' => $this->safeGuestObservationLogPayload($request, $snapshotPath),
                'status' => 'guest_observation_created',
                'notes' => "Guest observation created with ID: {$observation->id}",
            ]);

            return response()->json([
                'message' => 'Guest observation saved for review.',
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
     * Merge late OCR/color results into the existing record created at timeout.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function updateDetectorGuestObservationDetails(
        GuestVehicleObservation $observation,
        array $validated
    ): void {
        if ($observation->status === 'verified') {
            return;
        }

        $updates = [];
        $plateNumber = $this->normalizePlate($validated['plate_number'] ?? $validated['plate_text'] ?? null);

        if ($plateNumber !== null && blank($observation->plate_number)) {
            $updates['plate_number'] = $plateNumber;
            $updates['plate_text'] = $plateNumber;
        }

        $vehicleColor = $this->normalizeVehicleColor($validated['vehicle_color'] ?? $validated['detected_vehicle_color'] ?? null);

        if ($vehicleColor !== null && blank($observation->vehicle_color)) {
            $updates['vehicle_color'] = $vehicleColor;
        }

        if (! empty($validated['detection_metadata']) && is_array($validated['detection_metadata'])) {
            $updates['detection_metadata_json'] = array_replace(
                $observation->detection_metadata_json ?? [],
                $validated['detection_metadata'],
            );
        }

        if ($updates !== []) {
            $observation->forceFill($updates)->save();
            $observation->refresh();
        }
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
        array $metadata = []
    ): ?GuestVehicleObservation {
        $eventTime = $eventTime->copy()->setTimezone(config('app.timezone', 'UTC'));
        $from = $eventTime->copy()->subSeconds(6);
        $to = $eventTime->copy()->addSeconds(6);

        $query = GuestVehicleObservation::query()
            ->where('observation_source', 'cctv')
            ->where('status', 'pending_review')
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('observed_at', [$from, $to])
                    ->orWhereBetween('created_at', [$from, $to]);
            });

        $trackId = $metadata['track_id'] ?? null;

        if (is_scalar($trackId) && $trackId !== '') {
            $trackDuplicate = (clone $query)
                ->where('detection_metadata_json->track_id', $trackId)
                ->latest('created_at')
                ->first();

            if ($trackDuplicate) {
                return $trackDuplicate;
            }
        }

        $plateDuplicate = (clone $query)
            ->where(function ($query) use ($plateNumber): void {
                if ($plateNumber !== null) {
                    $query->whereNull('plate_number')
                        ->orWhere('plate_number', $plateNumber);

                    return;
                }

                $query->whereNull('plate_number');
            })
            ->latest('created_at')
            ->first();

        if ($plateDuplicate) {
            return $plateDuplicate;
        }

        return (clone $query)
            ->where('location', '!=', $cameraRole)
            ->latest('created_at')
            ->first();
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
