<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\RealtimeLogController;
use App\Models\Gate;
use App\Models\RfidScanLog;
use App\Services\CalibrationService;
use App\Services\DetectorRuntimeService;
use App\Services\RfidIngestService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class StationController extends Controller
{
    /**
     * Phase 1: the kiosk of one gate (code, or the old "entrance"/"exit").
     */
    public function show(
        string $location,
        CalibrationService $calibrationService,
        DetectorRuntimeService $detectorRuntimeService,
        SettingsService $settingsService
    ): View {
        $location = $this->validateLocation($location);
        $settingsService->ensureCameraRuntimeConfigExists();
        $detectorRuntimeService->markStationViewerActive($location);

        $camera = $calibrationService->cameraPayload()[$location];
        $detectorStatus = $detectorRuntimeService->withViewerStreamUrls(
            $detectorRuntimeService->ensureRunning(),
            request()->getHost()
        );
        $cameraStatus = $detectorStatus['cameras'][$location] ?? [];

        return view('stations.show', [
            'location' => $location,
            'stationLabel' => $this->stationLabel($location),
            'camera' => $camera,
            'detectorStatus' => $detectorStatus,
            'cameraStatus' => $cameraStatus,
            'streamUrl' => $cameraStatus['stream_url'] ?? $detectorRuntimeService->defaultStreamUrl($location),
            'logs' => $this->recentLogs(),
        ]);
    }

    /**
     * Poll one station window with only the data that belongs on that screen.
     */
    public function state(string $location, Request $request, DetectorRuntimeService $detectorRuntimeService): JsonResponse
    {
        $location = $this->validateLocation($location);
        $detectorRuntimeService->markStationViewerActive($location);
        $runtime = $detectorRuntimeService->withViewerStreamUrls(
            $detectorRuntimeService->ensureRunning(),
            $request->getHost()
        );

        return response()->json([
            'location' => $location,
            'runtime' => $runtime,
            'camera' => $runtime['cameras'][$location] ?? null,
            'stream_url' => $runtime['cameras'][$location]['stream_url'] ?? $detectorRuntimeService->defaultStreamUrl($location),
            'logs' => $this->recentLogs(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Record one RFID scan typed by the USB reader while a station window is focused.
     */
    public function rfidScan(
        string $location,
        Request $request,
        RfidIngestService $rfidIngestService
    ): JsonResponse
    {
        $location = $this->validateLocation($location);

        try {
            $validated = $request->validate([
                'tag_uid' => ['required', 'string', 'max:100'],
                'reader_name' => ['nullable', 'string', 'max:100'],
                'scan_time' => ['nullable', 'date'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);

            $readerName = $validated['reader_name'] ?? Gate::labelFor($location).' Kiosk Reader';

            // Phase 1: every gate records IN and OUT (the vehicle's state
            // decides, until the camera gives the direction). Same cooldown.
            $result = $rfidIngestService->ingest([
                ...$validated,
                'scan_location' => $location,
                'reader_name' => $readerName,
            ], 'station_reader');

            return response()->json([
                ...$this->stationScanPayload($result->scanLog, $result->isDuplicate()),
                ...$result->toArray(),
            ], $result->isDuplicate() ? 200 : 201);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Station RFID scan failed.', [
                'location' => $location,
                'message' => $exception->getMessage(),
                'payload' => $request->except(['_token']),
            ]);

            return response()->json([
                'message' => 'RFID scan could not be recorded. Check laravel.log for details.',
            ], 500);
        }
    }

    /**
     * Recent activity of every gate (newest first). UI Phase 4: same rows as
     * the Gate Monitor; the newest one at this gate is the big result.
     *
     * @return list<array<string, mixed>>
     */
    protected function recentLogs(int $limit = 14): array
    {
        return app(RealtimeLogController::class)->stationLogRows($limit);
    }

    /**
     * Build the station scan JSON contract shared by recorded and ignored scans.
     *
     * @return array<string, mixed>
     */
    protected function stationScanPayload(RfidScanLog $scanLog, bool $duplicateIgnored = false): array
    {
        $scanLog->loadMissing([
            'vehicle.rfidTag',
            'correlatedVehicleEvent',
            'guestVehicleObservation',
        ]);

        $vehicle = $scanLog->vehicle;
        $verified = $scanLog->verification_status === 'verified';

        return [
            'duplicate_ignored' => $duplicateIgnored,
            'vehicle' => $vehicle ? [
                'id' => $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'owner_name' => $vehicle->owner_name,
                'category' => $vehicle->category,
                'vehicle_type' => $vehicle->vehicle_type,
                'rfid_tag_uid' => $vehicle->rfidTag?->uid ?? $vehicle->rfid_tag_uid,
                'current_state' => $vehicle->current_state,
                'entries_today_count' => (int) $vehicle->entries_today_count,
                'exits_today_count' => (int) $vehicle->exits_today_count,
            ] : null,
            'action_taken' => $verified ? $scanLog->resolved_event_type : null,
            'new_state' => $verified ? $scanLog->resulting_state : null,
            'event' => $scanLog->correlatedVehicleEvent ? [
                'id' => $scanLog->correlatedVehicleEvent->id,
                'type' => $scanLog->correlatedVehicleEvent->event_type,
                'event_time' => $scanLog->correlatedVehicleEvent->event_time?->toIso8601String(),
                'entries_today_count' => (int) ($scanLog->correlatedVehicleEvent->daily_entries_count ?? 0),
                'exits_today_count' => (int) ($scanLog->correlatedVehicleEvent->daily_exits_count ?? 0),
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
            ],
        ];
    }

    /**
     * A gate code, or the old "entrance"/"exit" (Gate 1 / Gate 2).
     */
    protected function validateLocation(string $location): string
    {
        $code = Gate::resolveCode($location);
        abort_if($code === null, 404);

        return $code;
    }

    protected function stationLabel(string $location): string
    {
        return Gate::labelFor($location);
    }
}
