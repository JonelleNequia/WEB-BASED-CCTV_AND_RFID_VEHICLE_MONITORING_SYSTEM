<?php

namespace App\Http\Controllers;

use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\VehicleEvent;
use App\Services\CalibrationService;
use App\Services\DetectorRuntimeService;
use App\Services\GuestPassService;
use App\Services\RfidIngestService;
use App\Support\RfidIngestResult;
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
     * Show one read-only station window for the local extended-display setup.
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
        $eventType = $this->eventTypeForLocation($location);
        $cameraStatus = $detectorStatus['cameras'][$location] ?? [];

        return view('stations.show', [
            'location' => $location,
            'stationLabel' => $this->stationLabel($location),
            'eventType' => $eventType,
            'camera' => $camera,
            'detectorStatus' => $detectorStatus,
            'cameraStatus' => $cameraStatus,
            'streamUrl' => $cameraStatus['stream_url'] ?? "http://127.0.0.1:8765/stream/{$location}",
            'logs' => $this->recentLogs(),
        ]);
    }

    /**
     * Poll one station window with only the data that belongs on that screen.
     */
    public function state(string $location, Request $request, DetectorRuntimeService $detectorRuntimeService): JsonResponse
    {
        $location = $this->validateLocation($location);
        $eventType = $this->eventTypeForLocation($location);
        $detectorRuntimeService->markStationViewerActive($location);
        $runtime = $detectorRuntimeService->withViewerStreamUrls(
            $detectorRuntimeService->ensureRunning(),
            $request->getHost()
        );

        return response()->json([
            'location' => $location,
            'event_type' => $eventType,
            'runtime' => $runtime,
            'camera' => $runtime['cameras'][$location] ?? null,
            'stream_url' => $runtime['cameras'][$location]['stream_url'] ?? "http://127.0.0.1:8765/stream/{$location}",
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
        RfidIngestService $rfidIngestService,
        GuestPassService $guestPassService
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

            $readerName = $validated['reader_name']
                ?? ($location === 'exit' ? 'Exit Station RFID Reader' : 'Entrance Station RFID Reader');

            // Phase 3: the shared ingest service applies the station direction
            // (Entrance = ENTRY, Exit = EXIT), the per-tag cooldown (replaces
            // the old 8-second duplicate check) and the guest pass rules.
            $result = $rfidIngestService->ingest([
                ...$validated,
                'scan_location' => $location,
                'reader_name' => $readerName,
            ], 'station_reader', RfidIngestService::DIRECTION_STATION);

            return response()->json([
                ...$this->stationScanPayload($result->scanLog, $result->isDuplicate()),
                ...$result->toArray(),
                // Phase 4: data for the Entrance "Issue Guest Pass" pop-up and
                // the Exit "Card returned" reminder.
                'issue' => $result->requiresIssue() ? $this->issuePrompt($result, $guestPassService) : null,
                'card_return' => $result->outcome === RfidIngestResult::GUEST_PASS_EXIT && $result->guestVisit
                    ? [
                        'url' => route('guest-passes.visits.card-returned', $result->guestVisit),
                        'pass_label' => $result->scanLog->vehicleRfidTag?->label,
                        'id_presented' => $result->guestVisit->id_presented,
                        'plate' => $result->guestVisit->plate,
                        'driver_name' => $result->guestVisit->driver_name,
                    ]
                    : null,
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
     * Phase 4: prefill the Issue Guest Pass pop-up with the latest camera
     * snapshot and the plate/color the detector read at the Entrance.
     *
     * @return array<string, mixed>
     */
    protected function issuePrompt(RfidIngestResult $result, GuestPassService $guestPassService): array
    {
        $pass = $result->scanLog->vehicleRfidTag;
        $recentCapture = GuestVehicleObservation::query()
            ->where('location', 'entrance')
            ->where('observation_source', 'cctv')
            ->where('status', '!=', GuestVehicleObservation::STATUS_RESOLVED)
            ->where('created_at', '>=', now()->subMinutes(2))
            ->latest('created_at')
            ->first();

        return [
            'url' => route('guest-passes.issue', $pass),
            'rfid_scan_log_id' => $result->scanLog->id,
            'pass_label' => $pass?->label,
            'requires_id' => $guestPassService->requiresId(),
            'valid_minutes' => $guestPassService->validityMinutes(),
            'prefill' => [
                // Phase 5: issuing the pass resolves this no-pass alert.
                'observation_id' => $recentCapture?->id,
                'plate' => $recentCapture?->plate_number ?: $recentCapture?->plate_text,
                'color' => $recentCapture?->vehicle_color,
                'vehicle_type' => $recentCapture?->vehicle_type,
                'snapshot_url' => $recentCapture?->snapshot_path
                    ? $recentCapture->snapshot_url
                    : route('camera.frame', ['role' => 'entrance']).'?t='.now()->timestamp,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function recentLogs(int $limit = 14): array
    {
        $eventLogs = VehicleEvent::query()
            ->with(['camera', 'vehicle', 'rfidScanLog', 'guestVisit.rfidTag'])
            ->where('event_status', '!=', VehicleEvent::STATUS_PENDING_DETAILS)
            ->latest('created_at')
            ->latest('event_time')
            ->limit($limit * 5)
            ->get()
            ->map(function (VehicleEvent $event): array {
                $vehicle = $event->vehicle;
                $scanLog = $event->rfidScanLog;
                $plateNumber = $event->plate_text ?: $vehicle?->plate_number ?: 'GUEST';
                $entriesToday = $event->daily_entries_count
                    ?? $vehicle?->entries_today_count
                    ?? 0;
                $exitsToday = $event->daily_exits_count
                    ?? $vehicle?->exits_today_count
                    ?? 0;

                return [
                    'id' => $event->id,
                    'record_type' => 'vehicle_event',
                    'event_type' => $event->event_type,
                    'plate_number' => $plateNumber,
                    'owner_name' => $vehicle?->vehicle_owner_name ?: $vehicle?->owner_name ?: 'N/A',
                    'vehicle_type' => $event->display_vehicle_type,
                    'camera_role' => $event->camera?->camera_role,
                    'scan_location' => $scanLog?->scan_location,
                    'verification_label' => $scanLog?->verificationLabel
                        ?? ($event->vehicle_id ? 'Registered' : 'GUEST'),
                    'resulting_state' => $event->resulting_state ?: 'N/A',
                    'entries_today_count' => (int) $entriesToday,
                    'exits_today_count' => (int) $exitsToday,
                    'event_time' => $event->event_time?->toIso8601String(),
                    'display_time' => $event->event_time?->format('M d, Y • h:i:s A'),
                    'status' => $event->display_status_label,
                    'sort_time' => $this->sortTimestamp($event->created_at, $event->event_time),
                    // Phase 4: guest pass events show the pass number, not "GUEST / Owner N/A".
                    ...VehicleEvent::guestPassLogFields($event),
            // Phase 5: detector no-pass alerts.
            ...VehicleEvent::noPassLogFields($event),
                ];
            });

        $guestLogs = GuestVehicleObservation::query()
            ->with('camera')
            ->where(function ($query): void {
                $query->whereNull('external_event_key')
                    ->orWhereNotExists(function ($subquery): void {
                        $subquery->selectRaw('1')
                            ->from('vehicle_events')
                            ->whereColumn('vehicle_events.external_event_key', 'guest_vehicle_observations.external_event_key')
                            ->whereIn('vehicle_events.event_origin', ['guest_cctv', 'guest_manual']);
                    });
            })
            ->latest('created_at')
            ->latest('observed_at')
            ->limit($limit * 3)
            ->get()
            ->map(function (GuestVehicleObservation $observation): array {
                return [
                    'id' => 'guest-'.$observation->id,
                    'record_type' => 'guest_observation',
                    'event_type' => 'GUEST',
                    'plate_number' => $observation->plate_number ?: $observation->plate_text ?: 'GUEST',
                    'owner_name' => 'N/A',
                    'vehicle_type' => $observation->vehicle_type ?: 'Vehicle',
                    'camera_role' => $observation->camera?->camera_role,
                    'scan_location' => $observation->location,
                    'verification_label' => 'GUEST',
                    'resulting_state' => 'Guest',
                    'entries_today_count' => 0,
                    'exits_today_count' => 0,
                    'event_time' => $observation->observed_at?->toIso8601String(),
                    'display_time' => $observation->observed_at?->format('M d, Y • h:i:s A'),
                    'status' => 'Guest',
                    'snapshot_url' => $observation->snapshot_url,
                    'sort_time' => $this->sortTimestamp($observation->created_at, $observation->observed_at),
                ];
            });

        return $eventLogs
            ->concat($guestLogs)
            ->sortByDesc('sort_time')
            ->take($limit)
            ->map(function (array $log): array {
                unset($log['sort_time']);

                return $log;
            })
            ->values()
            ->all();
    }

    protected function sortTimestamp($createdAt, $eventAt): float
    {
        return (float) ($createdAt?->format('U.u') ?? $eventAt?->format('U.u') ?? 0);
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

    protected function validateLocation(string $location): string
    {
        abort_unless(in_array($location, ['entrance', 'exit'], true), 404);

        return $location;
    }

    protected function eventTypeForLocation(string $location): string
    {
        return $location === 'exit' ? 'EXIT' : 'ENTRY';
    }

    protected function stationLabel(string $location): string
    {
        return $location === 'exit' ? 'Exit Station' : 'Entrance Station';
    }
}
