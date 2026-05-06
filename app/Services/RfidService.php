<?php

namespace App\Services;

use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RfidService
{
    public function __construct(
        protected SettingsService $settingsService,
        protected LocalStorageService $localStorageService,
        protected VehicleRegistryService $vehicleRegistryService,
        protected EventService $eventService,
        protected GuestObservationService $guestObservationService
    ) {
    }

    /**
     * Create one simulated RFID scan for development without real readers.
     *
     * @param  array<string, mixed>  $data
     */
    public function simulate(array $data): RfidScanLog
    {
        if (($this->settingsService->get('rfid_simulation_mode', 'enabled') ?? 'enabled') !== 'enabled') {
            throw ValidationException::withMessages([
                'rfid_simulation_mode' => 'RFID simulation mode is disabled in Settings.',
            ]);
        }

        return $this->ingest($data, 'simulated');
    }

    /**
     * Ingest one RFID scan from either simulation or future hardware adapters.
     *
     * @param  array<string, mixed>  $data
     */
    public function ingest(array $data, string $sourceMode = 'simulated'): RfidScanLog
    {
        $this->localStorageService->ensureBaseDirectories();

        return DB::transaction(function () use ($data, $sourceMode): RfidScanLog {
            $scanLocation = $this->normalizeLocation((string) ($data['scan_location'] ?? 'entrance'));
            $scanTime = isset($data['scan_time'])
                ? Carbon::parse((string) $data['scan_time'])
                : now();

            $rawTagUid = (string) ($data['tag_uid'] ?? '');
            $tag = $this->resolveTag($data);
            $vehicle = $tag?->vehicle;
            $tagUid = $tag?->uid ?: $this->vehicleRegistryService->normalizeTagUid($rawTagUid);

            if ($tagUid === '') {
                throw ValidationException::withMessages([
                    'tag_uid' => 'Scan or select an RFID tag first.',
                ]);
            }

            $verificationStatus = $this->resolveVerificationStatus($tag, $vehicle);
            $stateTransition = $this->resolveStateTransition($verificationStatus, $vehicle, $scanTime);
            $scanDirection = $stateTransition
                ? strtolower($stateTransition['event_type'])
                : $this->normalizeDirection((string) ($data['scan_direction'] ?? ($scanLocation === 'exit' ? 'exit' : 'entry')));
            $resolvedEventType = $stateTransition['event_type'] ?? strtoupper($scanDirection);
            $resultingState = $stateTransition['resulting_state'] ?? ($vehicle?->current_state ?: null);
            $readerName = (string) ($data['reader_name'] ?? $this->defaultReaderName($scanLocation));
            $payload = [
                'tag_uid' => $tagUid,
                'scan_location' => $scanLocation,
                'scan_direction' => $scanDirection,
                'resolved_event_type' => $resolvedEventType,
                'reader_name' => $readerName,
                'scan_time' => $scanTime->toIso8601String(),
                'source_mode' => $sourceMode,
                'vehicle_id' => $vehicle?->id,
                'vehicle_plate_number' => $vehicle?->plate_number,
                'vehicle_category' => $vehicle?->category,
                'resulting_state' => $resultingState,
                'verification_status' => $verificationStatus,
                'notes' => $data['notes'] ?? null,
                'extra_payload' => $data['payload_json'] ?? null,
            ];
            $payloadFilePath = $this->localStorageService->storeRfidPayload($payload, $scanLocation);

            $scanLog = RfidScanLog::query()->create([
                'vehicle_id' => $vehicle?->id,
                'vehicle_rfid_tag_id' => $tag?->id,
                'correlated_vehicle_event_id' => null,
                'tag_uid' => $tagUid,
                'scan_location' => $scanLocation,
                'scan_direction' => $scanDirection,
                'resolved_event_type' => $resolvedEventType,
                'resulting_state' => $resultingState,
                'vehicle_category' => $vehicle?->category,
                'reader_name' => $readerName,
                'scan_time' => $scanTime,
                'verification_status' => $verificationStatus,
                'source_mode' => $sourceMode,
                'payload_json' => $payload,
                'payload_file_path' => $payloadFilePath,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($tag) {
                $tag->update(['last_scanned_at' => $scanTime]);
            }

            $correlatedEvent = null;

            if ($this->shouldCreateVehicleLog($verificationStatus, $vehicle, $stateTransition)) {
                $correlatedEvent = $this->eventService->createFromRfidScan($scanLog, $stateTransition);

                if ($correlatedEvent) {
                    $scanLog->update([
                        'correlated_vehicle_event_id' => $correlatedEvent->id,
                    ]);
                }
            }

            if ($verificationStatus === 'guest') {
                $guestObservation = $this->guestObservationService->createFromUnrecognizedRfidScan($scanLog);
                $scanLog->update([
                    'guest_vehicle_observation_id' => $guestObservation->id,
                ]);
            }

            return $scanLog->fresh(['vehicle', 'vehicleRfidTag', 'correlatedVehicleEvent.camera', 'guestVehicleObservation.camera']);
        });
    }

    /**
     * Get recent RFID scans for the admin page or portal views.
     *
     * @return Collection<int, RfidScanLog>
     */
    public function recentScans(int $limit = 10, ?string $scanLocation = null): Collection
    {
        return RfidScanLog::query()
            ->with(['vehicle', 'vehicleRfidTag', 'correlatedVehicleEvent.camera'])
            ->with('guestVehicleObservation.camera')
            ->when($scanLocation, fn ($query) => $query->where('scan_location', $scanLocation))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Find a scanner bounce so one physical read does not create two movements.
     */
    public function recentDuplicateScan(
        string $tagUid,
        string $scanLocation,
        int $withinSeconds = 8,
        ?string $sourceMode = null
    ): ?RfidScanLog {
        $uid = $this->vehicleRegistryService->normalizeTagUid($tagUid);

        if ($uid === '') {
            return null;
        }

        return RfidScanLog::query()
            ->with(['vehicle.rfidTag', 'vehicleRfidTag', 'correlatedVehicleEvent', 'guestVehicleObservation'])
            ->where('tag_uid', $uid)
            ->where('scan_location', $this->normalizeLocation($scanLocation))
            ->when($sourceMode, fn ($query) => $query->where('source_mode', $sourceMode))
            ->where('created_at', '>=', now()->subSeconds($withinSeconds))
            ->latest('created_at')
            ->first();
    }

    /**
     * Searchable RFID scan history for the RFID Desk table.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, RfidScanLog>
     */
    public function scanHistory(array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        return RfidScanLog::query()
            ->with(['vehicle', 'vehicleRfidTag', 'correlatedVehicleEvent.camera', 'guestVehicleObservation.camera'])
            ->when(! empty($filters['history_q']), function ($query) use ($filters): void {
                $term = '%'.trim((string) $filters['history_q']).'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('tag_uid', 'like', $term)
                        ->orWhereHas('vehicle', function ($vehicleQuery) use ($term): void {
                            $vehicleQuery->where('plate_number', 'like', $term)
                                ->orWhere('vehicle_owner_name', 'like', $term)
                                ->orWhere('owner_name', 'like', $term);
                        })
                        ->orWhereHas('vehicleRfidTag', function ($tagQuery) use ($term): void {
                            $tagQuery->where('uid', 'like', $term)
                                ->orWhere('tag_uid', 'like', $term);
                        });
                });
            })
            ->when(! empty($filters['scan_location']), function ($query) use ($filters): void {
                $query->where('scan_location', $this->normalizeLocation((string) $filters['scan_location']));
            })
            ->when(! empty($filters['verification_status']), function ($query) use ($filters): void {
                $query->where('verification_status', $filters['verification_status']);
            })
            ->orderByDesc('scan_time')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Get recent verified recurring RFID activity for vehicle status widgets.
     *
     * @return Collection<int, RfidScanLog>
     */
    public function recentRegisteredActivity(int $limit = 10, ?string $scanLocation = null): Collection
    {
        return RfidScanLog::query()
            ->with(['vehicle', 'vehicleRfidTag', 'correlatedVehicleEvent.camera'])
            ->with('guestVehicleObservation.camera')
            ->where('verification_status', 'verified')
            ->where(function ($query): void {
                $query->whereNull('vehicle_category')
                    ->orWhere('vehicle_category', '!=', 'guest');
            })
            ->when($scanLocation, fn ($query) => $query->where('scan_location', $scanLocation))
            ->orderByDesc('scan_time')
            ->limit($limit)
            ->get();
    }

    /**
     * Build small dashboard-friendly RFID counts.
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        $today = today()->toDateString();
        return [
            'registered_vehicles' => Vehicle::query()
                ->where('category', '!=', 'guest')
                ->count(),
            'guest_vehicles' => Vehicle::query()
                ->where('category', 'guest')
                ->count(),
            'vehicles_inside' => Vehicle::query()
                ->where('category', '!=', 'guest')
                ->where('status', 'active')
                ->where('current_state', Vehicle::STATE_INSIDE)
                ->count(),
            'entries_today' => (int) Vehicle::query()
                ->where('category', '!=', 'guest')
                ->whereDate('daily_count_date', $today)
                ->sum('entries_today_count'),
            'exits_today' => (int) Vehicle::query()
                ->where('category', '!=', 'guest')
                ->whereDate('daily_count_date', $today)
                ->sum('exits_today_count'),
            'registered_tags' => RfidTag::query()->count(),
            'available_tags' => RfidTag::query()
                ->where('status', RfidTag::STATUS_AVAILABLE)
                ->count(),
            'scans_today' => RfidScanLog::query()->whereDate('scan_time', today())->count(),
            'registered_scans_today' => RfidScanLog::query()
                ->whereDate('scan_time', today())
                ->where('verification_status', 'verified')
                ->where(function ($query): void {
                    $query->whereNull('vehicle_category')
                        ->orWhere('vehicle_category', '!=', 'guest');
                })
                ->count(),
            'verified_today' => RfidScanLog::query()
                ->whereDate('scan_time', today())
                ->where('verification_status', 'verified')
                ->count(),
            'attention_today' => RfidScanLog::query()
                ->whereDate('scan_time', today())
                ->where('verification_status', '!=', 'verified')
                ->count(),
            'simulated_today' => RfidScanLog::query()
                ->whereDate('scan_time', today())
                ->where('source_mode', 'simulated')
                ->count(),
        ];
    }

    /**
     * Resolve one tag from a selected ID or raw UID input.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveTag(array $data): ?RfidTag
    {
        if (! empty($data['vehicle_rfid_tag_id'])) {
            $tag = RfidTag::query()->with('vehicle')->find($data['vehicle_rfid_tag_id']);

            return $tag ? $this->ensureVehicleTagConnection($tag) : null;
        }

        if (blank($data['tag_uid'] ?? null)) {
            return null;
        }

        $uid = $this->vehicleRegistryService->normalizeTagUid((string) $data['tag_uid']);

        $tag = RfidTag::query()
            ->with('vehicle')
            ->where('uid', $uid)
            ->orWhere('tag_uid', $uid)
            ->first();

        if ($tag) {
            return $this->ensureVehicleTagConnection($tag);
        }

        return $this->createTagFromVehicleLegacyUid($uid);
    }

    /**
     * Keep the inventory tag row and vehicle row connected for legacy records.
     */
    protected function ensureVehicleTagConnection(RfidTag $tag): RfidTag
    {
        if ($tag->vehicle) {
            return $tag;
        }

        $uid = $tag->uid ?: $tag->tag_uid;

        $vehicle = Vehicle::query()
            ->where('rfid_tag_id', $tag->id)
            ->when($uid, function ($query) use ($uid): void {
                $query->orWhere('rfid_tag_uid', $uid);
            })
            ->first();

        if (! $vehicle) {
            return $tag;
        }

        $tagUpdates = [
            'vehicle_id' => $vehicle->id,
        ];

        if ($tag->status === RfidTag::STATUS_AVAILABLE) {
            $tagUpdates['status'] = RfidTag::STATUS_ASSIGNED;
            $tagUpdates['assigned_at'] = $tag->assigned_at ?: now();
        }

        $tag->forceFill($tagUpdates)->save();
        $this->syncVehicleTagColumns($vehicle, $tag);

        return $tag->fresh('vehicle');
    }

    /**
     * Build the missing inventory tag row from an older vehicle.rfid_tag_uid value.
     */
    protected function createTagFromVehicleLegacyUid(string $uid): ?RfidTag
    {
        $vehicle = Vehicle::query()
            ->where('rfid_tag_uid', $uid)
            ->first();

        if (! $vehicle) {
            return null;
        }

        $tag = RfidTag::query()->create([
            'uid' => $uid,
            'status' => RfidTag::STATUS_ASSIGNED,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now(),
        ]);

        $this->syncVehicleTagColumns($vehicle, $tag);

        return $tag->fresh('vehicle');
    }

    protected function syncVehicleTagColumns(Vehicle $vehicle, RfidTag $tag): void
    {
        if ((int) $vehicle->rfid_tag_id === (int) $tag->id && $vehicle->rfid_tag_uid === $tag->uid) {
            return;
        }

        $vehicle->forceFill([
            'rfid_tag_id' => $tag->id,
            'rfid_tag_uid' => $tag->uid,
        ])->save();
    }

    /**
     * Evaluate whether the scan should be treated as verified or attention-needed.
     */
    protected function resolveVerificationStatus(?RfidTag $tag, ?Vehicle $vehicle): string
    {
        if (! $tag || ! $vehicle) {
            return 'guest';
        }

        if ($tag->status === RfidTag::STATUS_INACTIVE) {
            return 'inactive_tag';
        }

        if ($tag->status !== RfidTag::STATUS_ASSIGNED) {
            return 'unassigned_tag';
        }

        if ($vehicle->status !== 'active') {
            return 'inactive_vehicle';
        }

        if (! $vehicle->isRfidRecurring()) {
            return 'non_recurring_category';
        }

        return 'verified';
    }

    /**
     * Resolve the vehicle state transition for recurring RFID vehicles.
     */
    protected function resolveStateTransition(string $verificationStatus, ?Vehicle $vehicle, Carbon $scanTime): ?array
    {
        if ($verificationStatus !== 'verified' || ! $vehicle) {
            return null;
        }

        $vehicle = Vehicle::query()
            ->whereKey($vehicle->id)
            ->lockForUpdate()
            ->firstOrFail();

        $this->resetDailyCountersIfNeeded($vehicle, $scanTime);
        $currentState = $this->normalizeVehicleState($vehicle->current_state);
        $eventType = $currentState === Vehicle::STATE_INSIDE ? 'EXIT' : 'ENTRY';
        $resultingState = $eventType === 'ENTRY'
            ? Vehicle::STATE_INSIDE
            : Vehicle::STATE_OUTSIDE;

        $updates = [
            'current_state' => $resultingState,
            'last_seen_at' => $scanTime,
            'daily_count_date' => $scanTime->toDateString(),
        ];

        if ($eventType === 'ENTRY') {
            $updates['entries_today_count'] = ((int) $vehicle->entries_today_count) + 1;
            $updates['last_entry_at'] = $scanTime;
            $updates['first_entry_today_at'] = $vehicle->first_entry_today_at ?: $scanTime;
        } else {
            $updates['exits_today_count'] = ((int) $vehicle->exits_today_count) + 1;
            $updates['last_exit_at'] = $scanTime;
            $updates['last_exit_today_at'] = $scanTime;
        }

        $vehicle->forceFill($updates)->save();

        return [
            'event_type' => $eventType,
            'resulting_state' => $vehicle->current_state,
            'daily_entries_count' => (int) $vehicle->entries_today_count,
            'daily_exits_count' => (int) $vehicle->exits_today_count,
        ];
    }

    /**
     * Normalize legacy lowercase/null states before deciding the next action.
     */
    protected function normalizeVehicleState(?string $state): string
    {
        $normalized = strtoupper(trim((string) $state));

        return $normalized === Vehicle::STATE_INSIDE
            ? Vehicle::STATE_INSIDE
            : Vehicle::STATE_OUTSIDE;
    }

    /**
     * Reset today's counters when the scan day changes.
     */
    protected function resetDailyCountersIfNeeded(Vehicle $vehicle, Carbon $scanTime): void
    {
        if ($vehicle->daily_count_date?->toDateString() === $scanTime->toDateString()) {
            return;
        }

        $vehicle->fill([
            'daily_count_date' => $scanTime->toDateString(),
            'entries_today_count' => 0,
            'exits_today_count' => 0,
            'first_entry_today_at' => null,
            'last_exit_today_at' => null,
        ]);
    }

    /**
     * Resolve a default reader label from saved settings.
     */
    protected function defaultReaderName(string $scanLocation): string
    {
        return $scanLocation === 'exit'
            ? (string) ($this->settingsService->get('exit_rfid_reader_name', 'Exit RFID Reader (Simulated)') ?? 'Exit RFID Reader (Simulated)')
            : (string) ($this->settingsService->get('entrance_rfid_reader_name', 'Entrance RFID Reader (Simulated)') ?? 'Entrance RFID Reader (Simulated)');
    }

    protected function shouldCreateVehicleLog(string $verificationStatus, ?Vehicle $vehicle, ?array $stateTransition): bool
    {
        return $verificationStatus === 'verified'
            && $vehicle !== null
            && $stateTransition !== null;
    }

    protected function normalizeLocation(string $scanLocation): string
    {
        return $scanLocation === 'exit' ? 'exit' : 'entrance';
    }

    protected function normalizeDirection(string $scanDirection): string
    {
        return $scanDirection === 'exit' ? 'exit' : 'entry';
    }
}
