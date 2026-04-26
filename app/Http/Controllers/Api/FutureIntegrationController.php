<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventReceiveLog;
use App\Models\VehicleEvent;
use App\Services\DetectorRuntimeService;
use App\Services\EventService;
use App\Services\RfidService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FutureIntegrationController extends Controller
{
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

        $validated = $request->validate([
            'external_event_key' => ['required', 'string', 'max:255'],
            'camera_role' => ['required_without:camera_id', 'nullable', 'in:entrance,exit'],
            'camera_id' => ['required_without:camera_role', 'nullable', 'integer', 'exists:cameras,id'],
            'detected_vehicle_type' => ['required', 'string', 'max:50'],
            'event_time' => ['required', 'date'],
            'vehicle_image_path' => ['required', 'string', 'max:255'],
            'roi_name' => ['nullable', 'string', 'max:100'],
            'detection_metadata' => ['nullable', 'array'],
        ]);

        $isDuplicate = VehicleEvent::query()
            ->where('external_event_key', $validated['external_event_key'])
            ->exists();

        $vehicleEvent = $eventService->createDetectedEvent([
            'camera_id' => $validated['camera_id'] ?? null,
            'camera_role' => $validated['camera_role'] ?? null,
            'detected_vehicle_type' => $validated['detected_vehicle_type'],
            'event_time' => $validated['event_time'],
            'vehicle_image_path' => $validated['vehicle_image_path'],
            'external_event_key' => $validated['external_event_key'],
            'roi_name' => $validated['roi_name'] ?? null,
            'detection_metadata_json' => $validated['detection_metadata'] ?? null,
        ]);

        EventReceiveLog::query()->create([
            'source_name' => $sourceName,
            'payload_json' => $request->all(),
            'status' => $isDuplicate ? 'duplicate' : 'ingested',
            'notes' => $isDuplicate
                ? 'Duplicate crossing payload ignored because external_event_key already exists.'
                : 'Detected vehicle crossing ingested and saved as an incomplete record event.',
        ]);

        return response()->json([
            'message' => $isDuplicate
                ? 'Duplicate crossing ignored. The original incomplete record event is still available.'
                : 'Detected vehicle crossing saved as an incomplete record event.',
            'duplicate' => $isDuplicate,
            'created' => ! $isDuplicate,
            'event_id' => $vehicleEvent->id,
            'event_status' => $vehicleEvent->event_status,
            'event_type' => $vehicleEvent->event_type,
        ], $isDuplicate ? 200 : 201);
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
            'scan_id' => $scanLog->id,
            'verification_status' => $scanLog->verification_status,
            'scan_location' => $scanLog->scan_location,
        ], 201);
    }
}
