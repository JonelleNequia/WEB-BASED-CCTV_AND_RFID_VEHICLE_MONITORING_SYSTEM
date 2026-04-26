<?php

namespace App\Services;

use App\Models\ActiveSession;
use App\Models\Camera;
use App\Models\RfidScanLog;
use App\Models\VehicleEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EventService
{
    public function __construct(
        protected MatchingService $matchingService,
        protected LocalStorageService $localStorageService,
        protected VehicleRegistryService $vehicleRegistryService
    ) {
    }

    /**
     * Create a manual entry or exit event and immediately complete its workflow.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): VehicleEvent
    {
        return DB::transaction(function () use ($data): VehicleEvent {
            $linkedVehicle = $this->vehicleRegistryService->resolveVehicleByPlate($data['plate_text']);

            $event = VehicleEvent::query()->create([
                'event_type' => $data['event_type'],
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'event_origin' => 'manual',
                'plate_text' => $this->normalizeDisplayPlate($data['plate_text']),
                'plate_confidence' => $data['plate_confidence'] ?: null,
                'vehicle_id' => $linkedVehicle?->id,
                'vehicle_type' => $data['vehicle_type'],
                'detected_vehicle_type' => $data['vehicle_type'],
                'vehicle_color' => $data['vehicle_color'],
                'vehicle_category' => $linkedVehicle?->category,
                'camera_id' => $data['camera_id'],
                'roi_name' => $data['roi_name'],
                'event_time' => $data['event_time'],
                'vehicle_image_path' => $this->storeImage($data['vehicle_image'] ?? null, 'vehicle'),
                'plate_image_path' => $this->storeImage($data['plate_image'] ?? null, 'plate'),
                'match_status' => $data['event_type'] === 'ENTRY' ? 'open' : 'unmatched',
                'details_completed_at' => now(),
            ]);

            return $this->finalizeCompletedEvent($event);
        });
    }

    /**
     * Create one auto-detected event that still needs manual details.
     *
     * @param  array<string, mixed>  $data
     */
    public function createDetectedEvent(array $data): VehicleEvent
    {
        $existing = VehicleEvent::query()
            ->where('external_event_key', $data['external_event_key'])
            ->first();

        if ($existing) {
            return $existing->load(['camera', 'matchedEntry.camera', 'activeSession']);
        }

        return DB::transaction(function () use ($data): VehicleEvent {
            $camera = $this->resolveCamera($data);
            $eventType = $camera->camera_role === 'exit' ? 'EXIT' : 'ENTRY';
            $detectedVehicleType = $this->normalizeVehicleType((string) $data['detected_vehicle_type']);

            return VehicleEvent::query()->create([
                'event_type' => $eventType,
                'event_status' => VehicleEvent::STATUS_PENDING_DETAILS,
                'event_origin' => 'cctv_detected',
                'plate_text' => null,
                'plate_confidence' => null,
                'vehicle_id' => null,
                'vehicle_type' => $detectedVehicleType,
                'detected_vehicle_type' => $detectedVehicleType,
                'vehicle_color' => null,
                'camera_id' => $camera->id,
                'external_event_key' => $data['external_event_key'],
                'detection_metadata_json' => $data['detection_metadata_json'] ?? null,
                'roi_name' => $data['roi_name'] ?? ($camera->camera_role === 'exit' ? 'Exit Trigger Line' : 'Entrance Trigger Line'),
                'event_time' => $data['event_time'],
                'vehicle_image_path' => $data['vehicle_image_path'],
                'plate_image_path' => null,
                'match_status' => VehicleEvent::STATUS_PENDING_DETAILS,
            ])->load('camera');
        });
    }

    /**
     * Complete a pending auto-detected event with manual details.
     *
     * @param  array<string, mixed>  $data
     */
    public function completePendingEvent(VehicleEvent $vehicleEvent, array $data): VehicleEvent
    {
        if ($vehicleEvent->event_status !== VehicleEvent::STATUS_PENDING_DETAILS) {
            throw ValidationException::withMessages([
                'vehicle_event' => 'Only incomplete records can be completed through this action.',
            ]);
        }

        return DB::transaction(function () use ($vehicleEvent, $data): VehicleEvent {
            $linkedVehicle = $this->vehicleRegistryService->resolveVehicleByPlate($data['plate_text']);

            $vehicleEvent->fill([
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'event_origin' => $vehicleEvent->event_origin ?: 'cctv_detected',
                'plate_text' => $this->normalizeDisplayPlate($data['plate_text']),
                'plate_confidence' => $data['plate_confidence'] ?: null,
                'vehicle_id' => $linkedVehicle?->id,
                'vehicle_type' => $this->normalizeVehicleType($data['vehicle_type']),
                'vehicle_color' => $data['vehicle_color'],
                'vehicle_category' => $linkedVehicle?->category,
                'match_status' => $vehicleEvent->event_type === 'ENTRY' ? 'open' : 'unmatched',
                'details_completed_at' => now(),
            ]);

            if (! $vehicleEvent->detected_vehicle_type) {
                $vehicleEvent->detected_vehicle_type = $this->normalizeVehicleType($data['vehicle_type']);
            }

            if (! empty($data['plate_image'])) {
                $vehicleEvent->plate_image_path = $this->storeImage($data['plate_image'], 'plate');
            }

            $vehicleEvent->save();

            return $this->finalizeCompletedEvent($vehicleEvent);
        });
    }

    /**
     * Create one completed vehicle event directly from a verified RFID state transition.
     *
     * @param  array<string, mixed>  $transition
     */
    public function createFromRfidScan(RfidScanLog $scanLog, array $transition): ?VehicleEvent
    {
        $scanLog->loadMissing('vehicle');

        if ($scanLog->verification_status !== 'verified' || ! $scanLog->vehicle) {
            return null;
        }

        $existing = VehicleEvent::query()
            ->where('rfid_scan_log_id', $scanLog->id)
            ->first();

        if ($existing) {
            return $existing->load([
                'camera',
                'vehicle',
                'rfidScanLog',
                'matchedEntry.camera',
                'activeSession',
            ]);
        }

        return DB::transaction(function () use ($scanLog, $transition): VehicleEvent {
            $vehicle = $scanLog->vehicle;
            $cameraId = Camera::query()
                ->forRole($scanLog->scan_location)
                ->value('id');
            $eventType = strtoupper((string) ($transition['event_type'] ?? 'ENTRY'));
            $resultingState = (string) ($transition['resulting_state'] ?? '');

            $event = VehicleEvent::query()->create([
                'event_type' => $eventType,
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'event_origin' => $scanLog->source_mode === 'hardware_placeholder'
                    ? 'rfid_hardware'
                    : 'rfid_simulated',
                'plate_text' => $this->normalizeDisplayPlate($vehicle->plate_number),
                'plate_confidence' => null,
                'vehicle_id' => $vehicle->id,
                'rfid_scan_log_id' => $scanLog->id,
                'vehicle_type' => $vehicle->vehicle_type,
                'detected_vehicle_type' => $vehicle->vehicle_type,
                'vehicle_color' => null,
                'vehicle_category' => $vehicle->category,
                'camera_id' => $cameraId,
                'roi_name' => $scanLog->scan_location === 'exit'
                    ? 'Exit RFID Reader'
                    : 'Entrance RFID Reader',
                'event_time' => $scanLog->scan_time,
                'vehicle_image_path' => null,
                'plate_image_path' => null,
                'match_status' => $eventType === 'ENTRY' ? 'open' : 'closed',
                'resulting_state' => $resultingState,
                'daily_entries_count' => $transition['daily_entries_count'] ?? null,
                'daily_exits_count' => $transition['daily_exits_count'] ?? null,
                'details_completed_at' => now(),
            ]);

            $finalizedEvent = $this->applyRfidSessionState($event);

            if ($scanLog->correlated_vehicle_event_id !== $finalizedEvent->id) {
                $scanLog->update([
                    'correlated_vehicle_event_id' => $finalizedEvent->id,
                ]);
            }

            if ($finalizedEvent->rfid_scan_log_id !== $scanLog->id) {
                $finalizedEvent->update([
                    'rfid_scan_log_id' => $scanLog->id,
                ]);
            }

            return $finalizedEvent->fresh([
                'camera',
                'vehicle',
                'rfidScanLog',
                'matchedEntry.camera',
                'activeSession',
            ]);
        });
    }

    /**
     * Apply lightweight session updates for state-based RFID events.
     */
    protected function applyRfidSessionState(VehicleEvent $event): VehicleEvent
    {
        if ($event->event_type === 'ENTRY') {
            ActiveSession::query()->firstOrCreate(
                ['entry_event_id' => $event->id],
                [
                    'plate_text' => $event->plate_text,
                    'plate_number' => $event->plate_text,
                    'vehicle_type' => $event->vehicle_type,
                    'vehicle_color' => $event->vehicle_color,
                    'entry_time' => $event->event_time,
                    'status' => 'open',
                ]
            );

            return $event->fresh(['camera', 'activeSession']);
        }

        $session = ActiveSession::query()
            ->where('status', 'open')
            ->where('entry_time', '<=', $event->event_time)
            ->where(function ($query) use ($event): void {
                $query->whereHas('entryEvent', function ($entryQuery) use ($event): void {
                    $entryQuery->where('vehicle_id', $event->vehicle_id);
                });

                if ($event->plate_text) {
                    $query->orWhere('plate_text', $event->plate_text);
                }
            })
            ->orderByDesc('entry_time')
            ->first();

        if (! $session) {
            return $event->fresh(['camera']);
        }

        $session->update(['status' => 'closed']);

        $event->forceFill([
            'matched_entry_id' => $session->entry_event_id,
            'match_status' => 'closed',
        ])->save();

        $session->entryEvent?->update(['match_status' => 'closed']);

        return $event->fresh(['camera', 'matchedEntry.camera']);
    }

    /**
     * Finish the session or matching workflow once a record has all required details.
     */
    protected function finalizeCompletedEvent(VehicleEvent $event): VehicleEvent
    {
        if ($event->event_type === 'ENTRY') {
            ActiveSession::query()->firstOrCreate(
                ['entry_event_id' => $event->id],
                [
                    'plate_text' => $event->plate_text,
                    'plate_number' => $event->plate_text,
                    'vehicle_type' => $event->vehicle_type,
                    'vehicle_color' => $event->vehicle_color,
                    'entry_time' => $event->event_time,
                    'status' => 'open',
                ]
            );

            $event->forceFill([
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'match_status' => 'open',
                'details_completed_at' => $event->details_completed_at ?? now(),
            ])->save();

            return $event->fresh(['camera', 'activeSession']);
        }

        $match = $this->resolveExitMatch($event);

        $event->forceFill([
            'event_status' => VehicleEvent::STATUS_COMPLETED,
            'matched_entry_id' => $match['matched_entry_id'],
            'match_score' => $match['match_score'],
            'match_status' => $match['match_status'],
            'details_completed_at' => $event->details_completed_at ?? now(),
        ])->save();

        if ($match['match_status'] === 'matched' && $match['session']) {
            $match['session']->update(['status' => 'closed']);
            $match['session']->entryEvent?->update(['match_status' => 'closed']);
        }

        return $event->fresh(['camera', 'matchedEntry.camera', 'activeSession']);
    }

    /**
     * Route EXIT records through direct RFID matching first, then fall back to score-based matching.
     *
     * @return array{matched_entry_id:int|null,match_score:int|null,match_status:string,session:ActiveSession|null}
     */
    protected function resolveExitMatch(VehicleEvent $event): array
    {
        return $this->matchingService->matchExitEvent($event->load('camera'));
    }

    /**
     * Resolve one manual-review EXIT decision.
     */
    public function resolveManualReview(VehicleEvent $event, string $decision): VehicleEvent
    {
        if ($event->event_type !== 'EXIT' || $event->match_status !== 'manual_review') {
            throw ValidationException::withMessages([
                'vehicle_event' => 'Only review queue EXIT records can be resolved through this action.',
            ]);
        }

        if (! in_array($decision, ['matched', 'unmatched'], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Invalid review decision.',
            ]);
        }

        return DB::transaction(function () use ($event, $decision): VehicleEvent {
            $event->refresh();

            if ($decision === 'unmatched') {
                $event->forceFill([
                    'matched_entry_id' => null,
                    'match_status' => 'unmatched',
                    'event_status' => VehicleEvent::STATUS_COMPLETED,
                ])->save();

                return $event->fresh(['camera', 'matchedEntry.camera']);
            }

            $matchedEntryId = $event->matched_entry_id;

            if (! $matchedEntryId) {
                $rematch = $this->matchingService->matchExitEvent($event->load('camera'));
                $matchedEntryId = $rematch['matched_entry_id'];

                if (! $matchedEntryId) {
                    throw ValidationException::withMessages([
                        'vehicle_event' => 'No entry candidate is available to mark this record as matched.',
                    ]);
                }

                $event->forceFill([
                    'matched_entry_id' => $matchedEntryId,
                    'match_score' => $rematch['match_score'],
                ])->save();
            }

            $session = ActiveSession::query()
                ->where('entry_event_id', $matchedEntryId)
                ->where('status', 'open')
                ->first();

            if ($session) {
                $session->update(['status' => 'closed']);
            }

            $event->forceFill([
                'match_status' => 'matched',
                'event_status' => VehicleEvent::STATUS_COMPLETED,
            ])->save();

            VehicleEvent::query()
                ->whereKey($matchedEntryId)
                ->update(['match_status' => 'closed']);

            return $event->fresh(['camera', 'matchedEntry.camera']);
        });
    }

    /**
     * Resolve the configured camera from either a role or direct camera ID.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveCamera(array $data): Camera
    {
        if (! empty($data['camera_id'])) {
            return Camera::query()->findOrFail($data['camera_id']);
        }

        return Camera::query()
            ->forRole((string) ($data['camera_role'] ?? 'entrance'))
            ->firstOrFail();
    }

    /**
     * Store uploaded images on the public disk.
     */
    protected function storeImage(?UploadedFile $file, string $directory): ?string
    {
        if (! $file) {
            return null;
        }

        $this->localStorageService->ensureBaseDirectories();

        return $this->localStorageService->storeEventImage($file, $directory);
    }

    /**
     * Keep saved plate text consistent without stripping the visible formatting.
     */
    protected function normalizeDisplayPlate(string $plateText): string
    {
        $normalized = preg_replace('/\s+/', ' ', $plateText) ?? $plateText;

        return Str::upper(trim($normalized));
    }

    /**
     * Keep vehicle type labels readable across manual and detected records.
     */
    protected function normalizeVehicleType(string $vehicleType): string
    {
        $normalized = Str::of($vehicleType)
            ->replace(['_', '-'], ' ')
            ->trim()
            ->lower();

        return match ((string) $normalized) {
            'motorbike' => 'Motorcycle',
            'pickup truck' => 'Truck',
            'car van' => 'Car',
            default => (string) $normalized->title(),
        };
    }
}
