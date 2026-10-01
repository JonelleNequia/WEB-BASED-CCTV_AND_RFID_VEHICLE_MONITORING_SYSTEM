<?php

namespace App\Services;

use App\Models\Gate;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\Vehicle;
use App\Support\PhilippineTime;
use App\Support\RfidIngestResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 3: the single place that turns one RFID read into records.
 *
 * Moved out of RfidService::ingest() so the Station page, the RFID Desk, the
 * hardware API and the future UHF TCP listener all share the same rules:
 *
 * - Phase 1 (gates): every gate records IN and OUT, so the vehicle's state
 *   decides the direction (OUTSIDE -> ENTRY, INSIDE -> EXIT) until the camera
 *   gives the direction of the crossing (Phase 3).
 * - Cooldown per tag + gate (setting rfid_cooldown_seconds, default 60).
 *
 * Guest passes were removed (Phase 0 of the visitor model): every tag is a
 * vehicle tag; vehicles without a tag are handled by the camera.
 */
class RfidIngestService
{
    /**
     * A gate reader. Phase 1: gates have no fixed direction, so this is the
     * same as the toggle until the camera gives the direction (Phase 3).
     */
    public const DIRECTION_STATION = 'station';

    /** INSIDE -> EXIT, OUTSIDE -> ENTRY. */
    public const DIRECTION_TOGGLE = 'toggle';

    public function __construct(
        protected SettingsService $settingsService,
        protected LocalStorageService $localStorageService,
        protected VehicleRegistryService $vehicleRegistryService,
        protected EventService $eventService,
        protected GuestObservationService $guestObservationService
    ) {
    }

    /**
     * Ingest one RFID read.
     *
     * @param  array<string, mixed>  $data  tag_uid | vehicle_rfid_tag_id, scan_location, scan_time?, reader_name?, notes?, payload_json?
     */
    public function ingest(
        array $data,
        string $sourceMode = 'station_reader',
        string $directionMode = self::DIRECTION_STATION
    ): RfidIngestResult {
        $this->localStorageService->ensureBaseDirectories();

        $scanLocation = $this->normalizeLocation((string) ($data['scan_location'] ?? ''));
        $scanTime = isset($data['scan_time']) ? Carbon::parse((string) $data['scan_time']) : now();
        $requestedUid = $this->requestedUid($data);

        if ($requestedUid === '') {
            throw ValidationException::withMessages([
                'tag_uid' => 'Scan or select an RFID tag first.',
            ]);
        }

        if ($duplicate = $this->recentScanWithinCooldown($requestedUid, $scanLocation)) {
            return new RfidIngestResult(
                $duplicate->loadMissing(['vehicle.rfidTag', 'vehicleRfidTag', 'correlatedVehicleEvent', 'guestVehicleObservation']),
                RfidIngestResult::DUPLICATE,
                'Duplicate read of '.$duplicate->tag_uid.' ignored (cooldown '.$this->cooldownSeconds().'s).'
            );
        }

        return DB::transaction(function () use ($data, $sourceMode, $directionMode, $scanLocation, $scanTime): RfidIngestResult {
            $tag = $this->resolveTag($data);

            return $this->handleVehicleTag($tag, $data, $sourceMode, $directionMode, $scanLocation, $scanTime);
        });
    }

    public function cooldownSeconds(): int
    {
        return max(0, $this->settingsService->getInt('rfid_cooldown_seconds', 60));
    }

    /**
     * Registered vehicle tags (and unknown tags).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleVehicleTag(
        ?RfidTag $tag,
        array $data,
        string $sourceMode,
        string $directionMode,
        string $scanLocation,
        Carbon $scanTime
    ): RfidIngestResult {
        $vehicle = $tag?->vehicle;
        $verificationStatus = $this->resolveVerificationStatus($tag, $vehicle);
        $transition = $verificationStatus === 'verified' && $vehicle
            ? $this->resolveStateTransition($vehicle, $scanLocation, $directionMode, $scanTime)
            : null;

        $anomalyReason = $transition['anomaly_reason'] ?? $this->tagAnomalyReason($tag, $verificationStatus, $scanLocation);
        // No movement (unknown or flagged tag): no direction either.
        $eventType = $transition['event_type'] ?? null;

        $scanLog = $this->createScanLog($data, $sourceMode, $scanLocation, $scanTime, $tag, [
            'vehicle' => $vehicle,
            'verification_status' => $verificationStatus,
            'resolved_event_type' => $eventType,
            'resulting_state' => $transition['resulting_state'] ?? ($vehicle?->current_state ?: null),
            'anomaly_reason' => $anomalyReason,
        ]);

        if ($transition !== null) {
            $event = $this->eventService->createFromRfidScan($scanLog, $transition);
            $scanLog->forceFill([
                'correlated_vehicle_event_id' => $event?->id,
                'outcome' => $anomalyReason ? RfidIngestResult::ANOMALY : RfidIngestResult::RECORDED,
            ])->save();

            $plate = $vehicle->plate_number;
            $message = $anomalyReason
                ? "{$eventType} recorded for {$plate}, flagged for review: {$anomalyReason}"
                : "{$eventType} recorded for {$plate}.";

            return $this->result($scanLog, $anomalyReason ? RfidIngestResult::ANOMALY : RfidIngestResult::RECORDED, $message);
        }

        if (in_array($verificationStatus, ['guest', 'non_recurring_category'], true)) {
            $observation = $this->guestObservationService->createFromUnrecognizedRfidScan($scanLog);
            $scanLog->forceFill([
                'guest_vehicle_observation_id' => $observation->id,
                'outcome' => RfidIngestResult::GUEST,
            ])->save();

            return $this->result($scanLog, RfidIngestResult::GUEST, 'Unknown tag recorded as GUEST. A guest observation was created.');
        }

        $outcome = in_array($verificationStatus, ['inactive_tag'], true) ? RfidIngestResult::ALERT : RfidIngestResult::ANOMALY;
        $scanLog->forceFill(['outcome' => $outcome])->save();

        return $this->result($scanLog, $outcome, (string) $anomalyReason);
    }

    /**
     * Resolve ENTRY/EXIT for a verified vehicle and update its state.
     *
     * @return array{event_type: string, resulting_state: string, daily_entries_count: int, daily_exits_count: int, anomaly_reason: string|null}
     */
    protected function resolveStateTransition(Vehicle $vehicle, string $scanLocation, string $directionMode, Carbon $scanTime): array
    {
        $vehicle = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();

        $this->resetDailyCountersIfNeeded($vehicle, $scanTime);
        $currentState = $this->normalizeVehicleState($vehicle->current_state);
        $anomalyReason = null;

        // Phase 1: both modes toggle (gates record IN and OUT). Phase 3 adds
        // the camera's direction, with this as the fallback.
        $eventType = $currentState === Vehicle::STATE_INSIDE ? 'EXIT' : 'ENTRY';

        $updates = [
            'current_state' => $eventType === 'ENTRY' ? Vehicle::STATE_INSIDE : Vehicle::STATE_OUTSIDE,
            'last_seen_at' => $scanTime,
            'daily_count_date' => PhilippineTime::localDateString($scanTime),
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
            'anomaly_reason' => $anomalyReason,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $resolved
     */
    protected function createScanLog(
        array $data,
        string $sourceMode,
        string $scanLocation,
        Carbon $scanTime,
        ?RfidTag $tag,
        array $resolved
    ): RfidScanLog {
        $vehicle = $resolved['vehicle'] ?? null;
        $tagUid = $tag?->uid ?: $this->requestedUid($data);
        $readerName = (string) ($data['reader_name'] ?? $this->defaultReaderName($scanLocation));
        $scanDirection = ($resolved['resolved_event_type'] ?? null) === 'EXIT' ? 'exit' : 'entry';
        $category = $resolved['vehicle_category'] ?? $vehicle?->category;

        $payload = [
            'tag_uid' => $tagUid,
            'scan_location' => $scanLocation,
            'scan_direction' => $scanDirection,
            'resolved_event_type' => $resolved['resolved_event_type'] ?? null,
            'reader_name' => $readerName,
            'scan_time' => $scanTime->toIso8601String(),
            'source_mode' => $sourceMode,
            'vehicle_id' => $vehicle?->id,
            'vehicle_plate_number' => $vehicle?->plate_number,
            'vehicle_category' => $category,
            'tag_type' => $tag?->tag_type,
            'resulting_state' => $resolved['resulting_state'] ?? null,
            'verification_status' => $resolved['verification_status'],
            'anomaly_reason' => $resolved['anomaly_reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'extra_payload' => $data['payload_json'] ?? null,
        ];

        $scanLog = RfidScanLog::query()->create([
            'vehicle_id' => $vehicle?->id,
            'vehicle_rfid_tag_id' => $tag?->id,
            'tag_uid' => $tagUid,
            'scan_location' => $scanLocation,
            'scan_direction' => $scanDirection,
            'resolved_event_type' => $resolved['resolved_event_type'] ?? null,
            'resulting_state' => $resolved['resulting_state'] ?? null,
            'vehicle_category' => $category,
            'reader_name' => $readerName,
            'scan_time' => $scanTime,
            'verification_status' => $resolved['verification_status'],
            'source_mode' => $sourceMode,
            'payload_json' => $payload,
            'payload_file_path' => $this->localStorageService->storeRfidPayload($payload, $scanLocation),
            'notes' => $data['notes'] ?? null,
            'is_anomaly' => filled($resolved['anomaly_reason'] ?? null),
            'anomaly_reason' => $resolved['anomaly_reason'] ?? null,
            'outcome' => $resolved['outcome'] ?? null,
        ]);

        $tag?->forceFill(['last_scanned_at' => $scanTime])->save();

        return $scanLog;
    }

    protected function result(RfidScanLog $scanLog, string $outcome, string $message): RfidIngestResult
    {
        return new RfidIngestResult(
            $scanLog->fresh(['vehicle.rfidTag', 'vehicleRfidTag', 'correlatedVehicleEvent.camera', 'guestVehicleObservation.camera']),
            $outcome,
            $message
        );
    }

    /**
     * The most recent read of this tag at this station inside the cooldown.
     */
    protected function recentScanWithinCooldown(string $uid, string $scanLocation): ?RfidScanLog
    {
        $cooldown = $this->cooldownSeconds();

        if ($cooldown === 0) {
            return null;
        }

        return RfidScanLog::query()
            ->where('tag_uid', $uid)
            ->where('scan_location', $scanLocation)
            ->where('created_at', '>=', now()->subSeconds($cooldown))
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function requestedUid(array $data): string
    {
        if (! empty($data['vehicle_rfid_tag_id'])) {
            $tag = RfidTag::query()->find($data['vehicle_rfid_tag_id']);

            if ($tag) {
                return (string) $tag->uid;
            }
        }

        return $this->vehicleRegistryService->normalizeTagUid((string) ($data['tag_uid'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveTag(array $data): ?RfidTag
    {
        if (! empty($data['vehicle_rfid_tag_id'])) {
            $tag = RfidTag::query()->with('vehicle')->find($data['vehicle_rfid_tag_id']);

            return $tag ? $this->ensureVehicleTagConnection($tag) : null;
        }

        $uid = $this->requestedUid($data);

        if ($uid === '') {
            return null;
        }

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

        $tagUpdates = ['vehicle_id' => $vehicle->id];

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
        $vehicle = Vehicle::query()->where('rfid_tag_uid', $uid)->first();

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

    protected function resolveVerificationStatus(?RfidTag $tag, ?Vehicle $vehicle): string
    {
        if ($tag && in_array($tag->status, [RfidTag::STATUS_DISABLED, RfidTag::STATUS_LOST], true)) {
            return 'inactive_tag';
        }

        if (! $tag || ! $vehicle) {
            return 'guest';
        }

        if ($tag->status !== RfidTag::STATUS_ASSIGNED) {
            return 'unassigned_tag';
        }

        if ($vehicle->status !== 'active') {
            return 'inactive_vehicle';
        }

        if (strtolower((string) $vehicle->category) === 'guest') {
            return 'guest';
        }

        if (! $vehicle->isRfidRecurring()) {
            return 'non_recurring_category';
        }

        return 'verified';
    }

    protected function tagAnomalyReason(?RfidTag $tag, string $verificationStatus, string $scanLocation): ?string
    {
        $station = ucfirst($scanLocation);

        return match ($verificationStatus) {
            'inactive_tag' => ($tag?->label ?? 'Tag').' is '.strtoupper((string) $tag?->status)." but was scanned at the {$station}.",
            'unassigned_tag' => ($tag?->label ?? 'Tag')." is not assigned to any vehicle but was scanned at the {$station}.",
            'inactive_vehicle' => "The vehicle for this tag is inactive but was scanned at the {$station}.",
            default => null,
        };
    }

    protected function resetDailyCountersIfNeeded(Vehicle $vehicle, Carbon $scanTime): void
    {
        $scanDate = PhilippineTime::localDateString($scanTime);

        if ($vehicle->daily_count_date?->toDateString() === $scanDate) {
            return;
        }

        $vehicle->fill([
            'daily_count_date' => $scanDate,
            'entries_today_count' => 0,
            'exits_today_count' => 0,
            'first_entry_today_at' => null,
            'last_exit_today_at' => null,
        ]);
    }

    protected function normalizeVehicleState(?string $state): string
    {
        return strtoupper(trim((string) $state)) === Vehicle::STATE_INSIDE
            ? Vehicle::STATE_INSIDE
            : Vehicle::STATE_OUTSIDE;
    }

    protected function defaultReaderName(string $scanLocation): string
    {
        return Gate::query()->where('code', $scanLocation)->first()?->readerDisplayName() ?? 'Gate Reader';
    }

    /**
     * A gate code; the old "entrance"/"exit" mean Gate 1 / Gate 2.
     */
    protected function normalizeLocation(string $scanLocation): string
    {
        return Gate::normalizeCode($scanLocation);
    }
}
