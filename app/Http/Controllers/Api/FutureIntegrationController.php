<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventReceiveLog;
use App\Models\VehicleEvent;
use App\Models\ActiveSession;
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
     * 
     * Expected payload:
     * - camera_id (required)
     * - direction (required): 'IN' or 'OUT'
     * - plate_number (nullable)
     * - image_path (required)
     */
    public function receive(
        Request $request,
        SettingsService $settingsService
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
                'event_type' => 'entry',
                'direction' => $direction,
                'plate_number' => $plateNumber,
                'plate_text' => $plateNumber,
                'camera_id' => $cameraId,
                'vehicle_image_path' => $imagePath,
                'event_time' => now(),
                'event_status' => $plateNumber ? 'pending_details' : 'requires_manual_review',
                'event_origin' => 'cctv_detected',
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
                ->where('plate_number', $plateNumber)
                ->whereNull('time_out')
                ->where('status', 'active')
                ->oldest()
                ->first();
        }

        if ($activeSession) {
            // Update the active session with time_out
            $activeSession->update([
                'time_out' => now(),
                'status' => 'completed',
            ]);

            // Also create a VehicleEvent for the exit
            VehicleEvent::query()->create([
                'event_type' => 'exit',
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
            'event_type' => 'exit',
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
