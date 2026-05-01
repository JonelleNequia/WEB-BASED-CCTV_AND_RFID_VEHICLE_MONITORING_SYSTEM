<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActiveSession;
use App\Models\Camera;
use App\Models\EventReceiveLog;
use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\VehicleEvent;
use Carbon\Carbon;
use App\Services\DetectorRuntimeService;
use App\Services\EventService;
use App\Services\RfidService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FutureIntegrationController extends Controller
{
    protected const RFID_OVERLAY_LOOKAHEAD_SECONDS = 5;

    protected const RFID_OVERLAY_POLL_MICROSECONDS = 200000;

    /**
     * Expose the current detector integration status and auto-start behavior.
     */
    public function status(
        Request $request,
        SettingsService $settingsService,
        DetectorRuntimeService $detectorRuntimeService
    ): JsonResponse
    {
        $configuredKey = trim((string) $settingsService->get('python_api_key', ''));
        $providedKey = trim((string) $request->header('X-Api-Key', ''));

        if ($configuredKey === '' || ! hash_equals($configuredKey, $providedKey)) {
            return response()->json([
                'message' => 'API key is missing or invalid.',
            ], 401);
        }

        $settings = $settingsService->all();
        $runtime = $detectorRuntimeService->readStatus();

        return response()->json([
            'project' => 'Web-Based CCTV and RFID Parking Vehicle Monitoring System for PHILCST',
            'integration_ready' => true,
            'ingestion_mode' => $settings['operating_mode'],
            'message' => $runtime['service_message'],
            'runtime' => $runtime,
        ]);
    }

    /**
     * Accept one detected crossing event from the Python vehicle detector.
     * 
     * Expected payload:
     * - camera_id (required)
     * - direction (required): 'IN' or 'OUT'
     * - plate_number (nullable)
     * - image_path (required)
     */
    public function receive(
        Request $request,
        SettingsService $settingsService,
        EventService $eventService
    ): JsonResponse
    {
        $configuredKey = trim((string) $settingsService->get('python_api_key', ''));
        $providedKey = trim((string) $request->header('X-Api-Key', ''));
        $sourceName = $request->header('X-Source-Name', 'philcst-detector');

        if ($configuredKey === '' || ! hash_equals($configuredKey, $providedKey)) {
            EventReceiveLog::query()->create([
                'source_name' => $sourceName,
                'payload_json' => $request->all(),
                'status' => 'unauthorized',
                'notes' => 'API key missing or does not match the configured detector key.',
            ]);

            return response()->json([
                'message' => 'API key is missing or invalid. Configure the detector API key in Settings before testing this endpoint.',
            ], 401);
        }

        if ($request->filled('external_event_key') || $request->filled('camera_role')) {
            $validated = $request->validate([
                'external_event_key' => ['required', 'string', 'max:120'],
                'camera_role' => ['required_without:camera_id', 'string', 'in:entrance,exit'],
                'camera_id' => ['nullable', 'integer', 'exists:cameras,id'],
                'detected_vehicle_type' => ['required', 'string', 'max:50'],
                'event_time' => ['required', 'date'],
                'vehicle_image_path' => ['nullable', 'required_if:unregistered_capture,true', 'string', 'max:255'],
                'roi_name' => ['nullable', 'string', 'max:100'],
                'detection_metadata' => ['nullable', 'array'],
                'unregistered_capture' => ['sometimes', 'boolean'],
            ]);

            $existing = VehicleEvent::query()
                ->where('external_event_key', $validated['external_event_key'])
                ->first();
            $isUnregisteredCapture = $request->boolean('unregistered_capture');
            $hasVehicleImage = filled($validated['vehicle_image_path'] ?? null);
            $eventTime = Carbon::parse($validated['event_time']);
            $rfidScan = $isUnregisteredCapture
                ? null
                : $this->resolveRecentVerifiedRfidScan($validated['camera_role'], $eventTime);

            if ($rfidScan && ! $isUnregisteredCapture) {
                EventReceiveLog::query()->create([
                    'source_name' => $sourceName,
                    'payload_json' => $request->all(),
                    'status' => 'rfid_matched',
                    'notes' => "Detected crossing matched RFID scan ID: {$rfidScan->id}. No CCTV capture was stored.",
                ]);

                return response()->json([
                    'message' => 'RFID verification matched. No CCTV capture is required.',
                    'duplicate' => false,
                    'requires_capture' => false,
                    'event_id' => $rfidScan->correlated_vehicle_event_id,
                    'event_status' => VehicleEvent::STATUS_COMPLETED,
                    'event_type' => $rfidScan->resolved_event_type,
                    'overlay' => $this->overlayPayload(null, $rfidScan),
                ]);
            }

            if (! $hasVehicleImage) {
                EventReceiveLog::query()->create([
                    'source_name' => $sourceName,
                    'payload_json' => $request->all(),
                    'status' => 'capture_requested',
                    'notes' => 'No RFID match found for the detected crossing. Requesting one evidence capture.',
                ]);

                return response()->json([
                    'message' => 'No RFID match found. Capture one vehicle snapshot and record as unregistered.',
                    'duplicate' => false,
                    'requires_capture' => true,
                    'event_id' => null,
                    'event_status' => null,
                    'event_type' => null,
                    'overlay' => $this->overlayPayload(null, null),
                ], 202);
            }

            $vehicleEvent = $eventService->createDetectedEvent([
                ...$validated,
                'detection_metadata_json' => $validated['detection_metadata'] ?? null,
            ]);

            if ($rfidScan) {
                $vehicle = $rfidScan->vehicle;
                $vehicleEvent->forceFill([
                    'event_status' => VehicleEvent::STATUS_COMPLETED,
                    'vehicle_id' => $vehicle?->id,
                    'rfid_scan_log_id' => $rfidScan->id,
                    'plate_text' => $vehicle?->plate_number,
                    'vehicle_type' => $vehicle?->vehicle_type ?: $vehicleEvent->vehicle_type,
                    'vehicle_category' => $vehicle?->category,
                    'resulting_state' => $rfidScan->resulting_state,
                    'match_status' => 'rfid_verified',
                    'details_completed_at' => now(),
                ])->save();
            }

            EventReceiveLog::query()->create([
                'source_name' => $sourceName,
                'payload_json' => $request->all(),
                'status' => $existing ? 'duplicate' : 'ingested',
                'notes' => $existing
                    ? "Duplicate crossing ignored for event ID: {$vehicleEvent->id}"
                    : "Detected vehicle event created with ID: {$vehicleEvent->id}",
            ]);

            return response()->json([
                'message' => $existing
                    ? 'Duplicate crossing ignored. The original incomplete record event is still available.'
                    : 'Unregistered vehicle capture saved for review.',
                'duplicate' => $existing !== null,
                'requires_capture' => false,
                'event_id' => $vehicleEvent->id,
                'event_status' => $vehicleEvent->event_status,
                'event_type' => $vehicleEvent->event_type,
                'overlay' => $this->overlayPayload($vehicleEvent, $rfidScan),
            ], $existing ? 200 : 201);
        }

        $validated = $request->validate([
            'camera_id' => ['required', 'integer', 'exists:cameras,id'],
            'direction' => ['required', 'string', 'in:IN,OUT'],
            'plate_number' => ['nullable', 'string', 'max:20'],
            'image_path' => ['required', 'string', 'max:255'],
        ]);

        $cameraId = $validated['camera_id'];
        $direction = $validated['direction'];
        $plateNumber = $validated['plate_number'] ?? null;
        $imagePath = $validated['image_path'];

        if ($direction === 'IN') {
            // Create new VehicleEvent for IN direction
            $vehicleEvent = VehicleEvent::query()->create([
                'event_type' => 'ENTRY',
                'direction' => $direction,
                'plate_number' => $plateNumber,
                'plate_text' => $plateNumber,
                'camera_id' => $cameraId,
                'vehicle_image_path' => $imagePath,
                'event_time' => now(),
                'event_status' => VehicleEvent::STATUS_PENDING_DETAILS,
                'event_origin' => 'cctv_detected',
                'match_status' => VehicleEvent::STATUS_PENDING_DETAILS,
            ]);

            EventReceiveLog::query()->create([
                'source_name' => $sourceName,
                'payload_json' => $request->all(),
                'status' => 'ingested',
                'notes' => "Vehicle entry event created with ID: {$vehicleEvent->id}",
            ]);

            return response()->json([
                'message' => 'Vehicle entry event created.',
                'event_id' => $vehicleEvent->id,
                'event_status' => $vehicleEvent->event_status,
            ], 201);
        }

        // Direction is 'OUT'
        $activeSession = null;

        if ($plateNumber) {
            // Find active session matching plate_number
            $activeSession = ActiveSession::query()
                ->where(function ($query) use ($plateNumber): void {
                    $query->where('plate_number', $plateNumber)
                        ->orWhere('plate_text', $plateNumber);
                })
                ->whereNull('time_out')
                ->where('status', 'open')
                ->oldest()
                ->first();
        }

        if ($activeSession) {
            // Update the active session with time_out
            $activeSession->update([
                'time_out' => now(),
                'status' => 'closed',
            ]);

            // Also create a VehicleEvent for the exit
            VehicleEvent::query()->create([
                'event_type' => 'EXIT',
                'direction' => $direction,
                'plate_number' => $plateNumber,
                'plate_text' => $plateNumber,
                'camera_id' => $cameraId,
                'vehicle_image_path' => $imagePath,
                'event_time' => now(),
                'event_status' => 'completed',
                'event_origin' => 'cctv_detected',
                'matched_entry_id' => $activeSession->entry_event_id,
            ]);

            EventReceiveLog::query()->create([
                'source_name' => $sourceName,
                'payload_json' => $request->all(),
                'status' => 'ingested',
                'notes' => "Vehicle exit matched with active session ID: {$activeSession->id}",
            ]);

            return response()->json([
                'message' => 'Vehicle exit recorded and matched with active session.',
                'session_id' => $activeSession->id,
                'event_status' => 'completed',
            ], 200);
        }

        // No active session found or plate_number is null - requires manual review
        $vehicleEvent = VehicleEvent::query()->create([
                'event_type' => 'EXIT',
            'direction' => $direction,
            'plate_number' => $plateNumber,
            'plate_text' => $plateNumber,
            'camera_id' => $cameraId,
            'vehicle_image_path' => $imagePath,
            'event_time' => now(),
            'event_status' => 'requires_manual_review',
            'event_origin' => 'cctv_detected',
        ]);

        EventReceiveLog::query()->create([
            'source_name' => $sourceName,
            'payload_json' => $request->all(),
            'status' => 'ingested',
            'notes' => "Vehicle exit saved as requires_manual_review. No matching active session found.",
        ]);

        return response()->json([
            'message' => 'Vehicle exit recorded but requires manual review. No matching active session found.',
            'event_id' => $vehicleEvent->id,
            'event_status' => $vehicleEvent->event_status,
        ], 201);
    }

    /**
     * Let the detector poll for a verified RFID scan during its local 5-second detection window.
     */
    public function rfidMatch(Request $request, SettingsService $settingsService): JsonResponse
    {
        $authorized = $this->authorizeIntegrationRequest($request, $settingsService);

        if ($authorized !== null) {
            return $authorized;
        }

        $validated = $request->validate([
            'camera_role' => ['required', 'string', 'in:entrance,exit'],
            'event_time' => ['required', 'date'],
            'window_seconds' => ['nullable', 'numeric', 'min:1', 'max:10'],
        ]);

        $eventTime = Carbon::parse($validated['event_time']);
        $windowSeconds = (int) ($validated['window_seconds'] ?? 5);
        $rfidScan = $this->findRecentVerifiedRfidScanWithinWindow(
            $validated['camera_role'],
            $eventTime,
            $windowSeconds
        );

        return response()->json([
            'matched' => $rfidScan !== null,
            'overlay' => $this->overlayPayload(null, $rfidScan),
            'scan' => $rfidScan ? [
                'id' => $rfidScan->id,
                'scan_location' => $rfidScan->scan_location,
                'scan_time' => $rfidScan->scan_time?->toIso8601String(),
                'event_type' => $rfidScan->resolved_event_type,
                'resulting_state' => $rfidScan->resulting_state,
            ] : null,
        ]);
    }

    /**
     * Store one detector-created guest observation only after the RFID window times out.
     */
    public function receiveGuestObservation(Request $request, SettingsService $settingsService): JsonResponse
    {
        $authorized = $this->authorizeIntegrationRequest($request, $settingsService);
        $sourceName = $request->header('X-Source-Name', 'philcst-detector');

        if ($authorized !== null) {
            EventReceiveLog::query()->create([
                'source_name' => $sourceName,
                'payload_json' => $request->all(),
                'status' => 'unauthorized',
                'notes' => 'API key missing or invalid for detector guest observation.',
            ]);

            return $authorized;
        }

        $validated = $request->validate([
            'external_event_key' => ['required', 'string', 'max:120'],
            'camera_role' => ['required', 'string', 'in:entrance,exit'],
            'camera_id' => ['nullable', 'integer', 'exists:cameras,id'],
            'detected_vehicle_type' => ['nullable', 'string', 'max:50'],
            'event_time' => ['required', 'date'],
            'vehicle_image_path' => ['required', 'string', 'max:255'],
            'plate_number' => ['nullable', 'string', 'max:50'],
            'detection_metadata' => ['nullable', 'array'],
        ]);

        $existing = GuestVehicleObservation::query()
            ->where('external_event_key', $validated['external_event_key'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Duplicate guest observation ignored.',
                'duplicate' => true,
                'guest_observation_id' => $existing->id,
                'overlay' => $this->guestOverlayPayload($existing),
            ]);
        }

        $cameraId = $validated['camera_id']
            ?? Camera::query()->forRole($validated['camera_role'])->value('id');

        $observation = GuestVehicleObservation::query()->create([
            'plate_text' => $validated['plate_number'] ?? null,
            'vehicle_type' => $validated['detected_vehicle_type'] ?? 'Vehicle',
            'vehicle_color' => null,
            'location' => $validated['camera_role'],
            'observation_source' => 'cctv',
            'status' => 'pending_review',
            'observed_at' => Carbon::parse($validated['event_time']),
            'camera_id' => $cameraId,
            'external_event_key' => $validated['external_event_key'],
            'detection_metadata_json' => $validated['detection_metadata'] ?? null,
            'snapshot_path' => $validated['vehicle_image_path'],
            'notes' => 'No successful RFID scan was recorded within the 5-second detector window.',
            'created_by' => null,
        ]);

        EventReceiveLog::query()->create([
            'source_name' => $sourceName,
            'payload_json' => $request->all(),
            'status' => 'guest_observation_created',
            'notes' => "Guest observation created with ID: {$observation->id}",
        ]);

        return response()->json([
            'message' => 'Unregistered guest observation saved for review.',
            'duplicate' => false,
            'guest_observation_id' => $observation->id,
            'status' => $observation->status,
            'overlay' => $this->guestOverlayPayload($observation),
        ], 201);
    }

    /**
     * Accept one RFID scan from a future hardware adapter while development stays offline-first.
     */
    public function receiveRfidScan(
        Request $request,
        SettingsService $settingsService,
        RfidService $rfidService
    ): JsonResponse {
        $configuredKey = trim((string) $settingsService->get('python_api_key', ''));
        $providedKey = trim((string) $request->header('X-Api-Key', ''));
        $sourceName = $request->header('X-Source-Name', 'philcst-rfid-adapter');

        if ($configuredKey === '' || ! hash_equals($configuredKey, $providedKey)) {
            EventReceiveLog::query()->create([
                'source_name' => $sourceName,
                'payload_json' => $request->all(),
                'status' => 'unauthorized',
                'notes' => 'API key missing or does not match the configured RFID adapter key.',
            ]);

            return response()->json([
                'message' => 'API key is missing or invalid. Configure the shared integration key in Settings first.',
            ], 401);
        }

        $validated = $request->validate([
            'tag_uid' => ['required', 'string', 'max:100'],
            'scan_location' => ['required', 'in:entrance,exit'],
            'scan_direction' => ['nullable', 'in:entry,exit'],
            'reader_name' => ['nullable', 'string', 'max:100'],
            'scan_time' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'payload_json' => ['nullable', 'array'],
        ]);

        $scanLog = $rfidService->ingest($validated, 'hardware_placeholder');

        EventReceiveLog::query()->create([
            'source_name' => $sourceName,
            'payload_json' => $request->all(),
            'status' => 'ingested',
            'notes' => 'RFID scan received for future offline hardware integration.',
        ]);

        return response()->json([
            'message' => 'RFID scan ingested and saved to the local log.',
            ...$this->rfidScanResponsePayload($scanLog),
        ], 201);
    }

    /**
     * Build the JSON contract expected by the front-end monitor after an RFID scan.
     *
     * @return array<string, mixed>
     */
    protected function rfidScanResponsePayload(RfidScanLog $scanLog): array
    {
        $scanLog->loadMissing([
            'vehicle.rfidTag',
            'correlatedVehicleEvent',
            'guestVehicleObservation',
        ]);

        $verified = $scanLog->verification_status === 'verified';
        $vehicle = $scanLog->vehicle;

        return [
            'vehicle' => $vehicle ? [
                'id' => $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'owner_name' => $vehicle->owner_name,
                'category' => $vehicle->category,
                'vehicle_type' => $vehicle->vehicle_type,
                'rfid_tag_uid' => $vehicle->rfidTag?->uid ?? $vehicle->rfid_tag_uid,
                'current_state' => $vehicle->current_state,
            ] : null,
            'action_taken' => $verified ? $scanLog->resolved_event_type : null,
            'new_state' => $verified ? $scanLog->resulting_state : null,
            'event' => $scanLog->correlatedVehicleEvent ? [
                'id' => $scanLog->correlatedVehicleEvent->id,
                'type' => $scanLog->correlatedVehicleEvent->event_type,
                'event_time' => $scanLog->correlatedVehicleEvent->event_time?->toIso8601String(),
            ] : null,
            'scan' => [
                'id' => $scanLog->id,
                'tag_uid' => $scanLog->tag_uid,
                'verification_status' => $scanLog->verification_status,
                'verification_label' => $scanLog->verificationLabel,
                'scan_location' => $scanLog->scan_location,
                'event_type' => $scanLog->resolved_event_type,
                'resulting_state' => $scanLog->resulting_state,
                'vehicle_plate' => $vehicle?->plate_number,
                'vehicle_event_id' => $scanLog->correlated_vehicle_event_id,
                'guest_observation_id' => $scanLog->guest_vehicle_observation_id,
                'guest_snapshot_url' => $scanLog->guestVehicleObservation?->snapshot_url,
            ],
        ];
    }

    protected function resolveRecentVerifiedRfidScan(string $cameraRole, Carbon $eventTime): ?RfidScanLog
    {
        $deadline = microtime(true) + (app()->runningUnitTests() ? 0 : self::RFID_OVERLAY_LOOKAHEAD_SECONDS);

        do {
            $scanLog = $this->findRecentVerifiedRfidScan($cameraRole, $eventTime);

            if ($scanLog) {
                return $scanLog;
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep(self::RFID_OVERLAY_POLL_MICROSECONDS);
        } while (true);
    }

    protected function findRecentVerifiedRfidScan(string $cameraRole, Carbon $eventTime): ?RfidScanLog
    {
        $from = $eventTime->copy()->subSeconds(12);
        $to = $eventTime->copy()->addSeconds(12);

        return RfidScanLog::query()
            ->with('vehicle.rfidTag')
            ->where('verification_status', 'verified')
            ->where('scan_location', $cameraRole)
            ->whereBetween('scan_time', [$from, $to])
            ->latest('scan_time')
            ->first();
    }

    protected function findRecentVerifiedRfidScanWithinWindow(
        string $cameraRole,
        Carbon $eventTime,
        int $windowSeconds = 5
    ): ?RfidScanLog {
        $windowEnd = $eventTime->copy()->addSeconds($windowSeconds);
        $to = now()->lessThan($windowEnd) ? now() : $windowEnd;

        return RfidScanLog::query()
            ->with('vehicle.rfidTag')
            ->where('verification_status', 'verified')
            ->where('scan_location', $cameraRole)
            ->whereBetween('scan_time', [$eventTime->copy()->subSecond(), $to])
            ->latest('scan_time')
            ->first();
    }

    protected function authorizeIntegrationRequest(Request $request, SettingsService $settingsService): ?JsonResponse
    {
        $configuredKey = trim((string) $settingsService->get('python_api_key', ''));
        $providedKey = trim((string) $request->header('X-Api-Key', ''));

        if ($configuredKey !== '' && hash_equals($configuredKey, $providedKey)) {
            return null;
        }

        return response()->json([
            'message' => 'API key is missing or invalid.',
        ], 401);
    }

    /**
     * @return array<string, mixed>
     */
    protected function overlayPayload(?VehicleEvent $vehicleEvent, ?RfidScanLog $rfidScan): array
    {
        if (! $rfidScan || ! $rfidScan->vehicle) {
            return [
                'verification' => 'guest',
                'label' => 'UNREGISTERED / GUEST',
                'color' => 'red',
                'event_id' => $vehicleEvent?->id,
                'rfid_scan_id' => null,
                'vehicle' => null,
            ];
        }

        $vehicle = $rfidScan->vehicle;

        return [
            'verification' => 'registered',
            'label' => 'REGISTERED - Plate: '.$vehicle->plate_number,
            'color' => 'green',
            'event_id' => $vehicleEvent?->id ?? $rfidScan->correlated_vehicle_event_id,
            'rfid_scan_id' => $rfidScan->id,
            'action_taken' => $rfidScan->resolved_event_type,
            'new_state' => $rfidScan->resulting_state,
            'vehicle' => [
                'id' => $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'owner_name' => $vehicle->owner_name,
                'category' => $vehicle->category,
                'vehicle_type' => $vehicle->vehicle_type,
                'rfid_tag_uid' => $vehicle->rfidTag?->uid ?? $vehicle->rfid_tag_uid,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function guestOverlayPayload(GuestVehicleObservation $observation): array
    {
        return [
            'verification' => 'guest',
            'label' => 'UNREGISTERED / GUEST',
            'color' => 'red',
            'guest_observation_id' => $observation->id,
            'status' => $observation->status,
            'vehicle' => null,
        ];
    }
}
