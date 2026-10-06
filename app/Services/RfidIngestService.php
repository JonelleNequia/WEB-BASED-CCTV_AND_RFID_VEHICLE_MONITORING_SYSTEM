<?php

namespace App\Services;

use App\Models\Gate;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
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
 * - Phase 1 (gates): every gate records IN and OUT.
 * - Phase 3 (visitor model): a registered read takes its direction from the
 *   camera's crossing (RfidCameraFusionService); the vehicle's state decides
 *   only when the camera cannot.
 * - Cooldown per tag + gate (setting rfid_cooldown_seconds, default 60, at
 *   least 10): also one "Unknown tag" event per cooldown.
 * - An unknown tag is never a visitor record: it is flagged with "Register
 *   this tag".
 *
 * Guest passes were removed (Phase 0 of the visitor model): every tag is a
 * vehicle tag; vehicles without a tag are handled by the camera.
 *
 * RFID only with a vehicle: a read is not a record. While the gate's camera
 * is watching, a read only joins the buffer (RfidTagMatcher); the camera's
 * crossing takes the right tag from it (forCrossing()) and makes ONE record.
 * With the camera offline longer than `rfid_offline_grace_seconds` and
 * `rfid_offline_fallback` on, a registered tag is recorded "RFID only"
 * (direction from the vehicle's state); an unknown tag only counts.
 */
class RfidIngestService
{
    /**
     * A gate reader. Gates have no fixed direction: the camera (Phase 3), or
     * the vehicle's state, gives it. Kept for existing callers.
     */
    public const DIRECTION_STATION = 'station';

    /** INSIDE -> EXIT, OUTSIDE -> ENTRY. */
    public const DIRECTION_TOGGLE = 'toggle';

    public function __construct(
        protected SettingsService $settingsService,
        protected LocalStorageService $localStorageService,
        protected VehicleRegistryService $vehicleRegistryService,
        protected EventService $eventService,
        protected RfidCameraFusionService $fusionService,
        protected RfidTagMatcher $tagMatcher,
        protected VisitorRecordService $visitorRecordService
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

        // Every read joins the buffer (the detector's window may still claim
        // it); while the camera is watching, that is all it does.
        $rssi = data_get($data, 'payload_json.rssi');
        $this->tagMatcher->addRead($scanLocation, $requestedUid, $scanTime, is_numeric($rssi) ? (float) $rssi : null);

        if (! $this->rfidOnly($scanLocation)) {
            return $this->attachLateRead($scanLocation, $requestedUid, $scanTime)
                ?? $this->buffered($data, $sourceMode, $scanLocation, $scanTime);
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

            // RFID only: an unknown tag without a vehicle is not recorded.
            if ($this->resolveVerificationStatus($tag, $tag?->vehicle) === 'unknown_tag') {
                $this->tagMatcher->count($scanLocation, 'unknown_offline');

                return new RfidIngestResult(
                    $this->unsavedScan($data, $sourceMode, $scanLocation, $scanTime, $tag),
                    RfidIngestResult::UNKNOWN_TAG,
                    'Unknown tag '.$this->requestedUid($data).' read while the camera is offline. Not recorded.'
                );
            }

            $result = $this->handleVehicleTag($tag, $data, $sourceMode, $directionMode, $scanLocation, $scanTime);
            $this->tagMatcher->count($scanLocation, 'rfid_only');

            return $result;
        });
    }

    /**
     * RFID only with a vehicle: is this gate's camera offline long enough
     * (and the fallback on) to record registered tags without it?
     */
    public function rfidOnly(string $gate): bool
    {
        if ($this->settingsService->get('rfid_offline_fallback', '1') !== '1') {
            return false;
        }

        $offline = $this->fusionService->cameraOfflineSeconds($gate);

        return $offline !== null && $offline >= max(0, $this->settingsService->getInt('rfid_offline_grace_seconds', 10));
    }

    /**
     * The camera's crossing: the tag of this vehicle from the buffer, and the
     * one record for it. A registered tag -> its IN / OUT with the camera's
     * direction; a lost, disabled or unassigned tag -> a flagged read linked
     * to the crossing; an unknown tag or none -> null (the caller makes the
     * Unregistered Visitor record, with 'unknown_tag' as a note).
     *
     * @return array{scan: ?RfidScanLog, unknown_tag: ?string}
     */
    public function forCrossing(VehicleCrossing $crossing): array
    {
        $presence = $this->tagMatcher->pick($crossing->gate, $crossing->crossed_at, $crossing->external_event_key);

        if ($presence === null) {
            return ['scan' => null, 'unknown_tag' => null];
        }

        $this->tagMatcher->claim($crossing->gate, $presence, $crossing->external_event_key, $crossing->crossed_at);

        // Already recorded (RFID only while the camera was offline, or this
        // vehicle's earlier crossing with a new track ID).
        if ($this->recordedSince($crossing->gate, $presence['epc'], (float) $presence['first_seen'])) {
            return ['scan' => null, 'unknown_tag' => null];
        }

        return $this->recordPresence($crossing, $presence);
    }

    /**
     * @param  array<string, mixed>  $presence
     * @return array{scan: ?RfidScanLog, unknown_tag: ?string}
     */
    protected function recordPresence(VehicleCrossing $crossing, array $presence): array
    {
        return DB::transaction(function () use ($crossing, $presence): array {
            $data = [
                'tag_uid' => $presence['epc'],
                'scan_location' => $crossing->gate,
                'payload_json' => [
                    'source' => 'rfid_buffer',
                    'reads' => $presence['reads'],
                    'max_rssi' => $presence['max_rssi'],
                    'first_seen' => $presence['first_seen'],
                    'peak_at' => $presence['peak_at'],
                ],
            ];
            $scanTime = Carbon::createFromTimestamp((float) $presence['peak_at'], config('app.timezone'));
            $tag = $this->resolveTag($data);
            $vehicle = $tag?->vehicle;
            $status = $this->resolveVerificationStatus($tag, $vehicle);

            if ($status === 'unknown_tag') {
                return ['scan' => null, 'unknown_tag' => $this->requestedUid($data)];
            }

            $sourceMode = 'hardware_placeholder';
            $scan = $this->createScanLog($data, $sourceMode, $crossing->gate, $scanTime, $tag, [
                'vehicle' => $vehicle,
                'verification_status' => $status,
                'resolved_event_type' => null,
                'resulting_state' => $vehicle?->current_state ?: null,
                'anomaly_reason' => $status === 'verified' ? null : $this->tagAnomalyReason($tag, $status, $crossing->gate),
            ]);

            if ($status === 'verified') {
                $this->fusionService->applyMovement($scan, $crossing);
            } else {
                $scan->forceFill([
                    'vehicle_crossing_id' => $crossing->id,
                    'outcome' => $status === 'inactive_tag' ? RfidIngestResult::ALERT : RfidIngestResult::ANOMALY,
                    'fusion_note' => 'Read when the camera saw a vehicle go '.$crossing->direction.'. No IN/OUT recorded for this tag.',
                ])->save();
                $crossing->forceFill(['rfid_scan_log_id' => $scan->id])->save();
                $this->visitorRecordService->dismissForCrossing($crossing, 'Flagged tag '.$scan->tag_uid.' read for it.');
            }

            return ['scan' => $scan->fresh(['vehicle']), 'unknown_tag' => null];
        });
    }

    /**
     * A tag read a little after a crossing that was already sent (the
     * crossing found no tag then): it still belongs to that vehicle.
     */
    protected function attachLateRead(string $gate, string $uid, Carbon $scanTime): ?RfidIngestResult
    {
        $crossings = VehicleCrossing::query()
            ->atGate($gate)
            ->whereNull('rfid_scan_log_id')
            ->whereBetween('crossed_at', [$scanTime->copy()->subSeconds($this->fusionService->lookaheadSeconds()), $scanTime])
            ->orderByDesc('crossed_at')
            ->get();

        foreach ($crossings as $crossing) {
            $presence = $this->tagMatcher->pick($gate, $crossing->crossed_at, $crossing->external_event_key);

            if ($presence === null || strtoupper((string) $presence['epc']) !== strtoupper($uid)) {
                continue;
            }

            $this->tagMatcher->claim($gate, $presence, $crossing->external_event_key, $crossing->crossed_at);
            $recorded = $this->recordPresence($crossing, $presence);

            if ($recorded['unknown_tag'] !== null) {
                $this->visitorRecordService->noteUnknownTag($crossing, $recorded['unknown_tag']);

                return new RfidIngestResult(
                    $this->unsavedScan(['tag_uid' => $uid], 'hardware_placeholder', $gate, $scanTime, null),
                    RfidIngestResult::UNKNOWN_TAG,
                    'Unknown tag '.$uid.' read for the vehicle the camera just saw. Register this tag.'
                );
            }

            $scan = $recorded['scan'];

            return $this->result($scan, (string) $scan->outcome, $scan->vehicle
                ? "{$scan->resolved_event_type} recorded for {$scan->vehicle->plate_number}."
                : (string) $scan->anomaly_reason);
        }

        return null;
    }

    /**
     * A read while the camera is watching: it waits in the buffer for a
     * vehicle; nothing is saved.
     *
     * @param  array<string, mixed>  $data
     */
    protected function buffered(array $data, string $sourceMode, string $scanLocation, Carbon $scanTime): RfidIngestResult
    {
        $tag = $this->resolveTag($data);
        $scan = $this->unsavedScan($data, $sourceMode, $scanLocation, $scanTime, $tag);
        $plate = $scan->vehicle?->plate_number;

        return new RfidIngestResult(
            $scan,
            RfidIngestResult::BUFFERED,
            ($plate ? "Tag read for {$plate}." : 'Tag '.$scan->tag_uid.' read.').' Recorded only when the camera sees the vehicle cross.'
        );
    }

    /**
     * Settings › Test Scan: what this tag is, without saving anything.
     *
     * @param  array<string, mixed>  $data
     */
    public function preview(array $data): RfidIngestResult
    {
        $scanLocation = $this->normalizeLocation((string) ($data['scan_location'] ?? ''));
        $uid = $this->requestedUid($data);

        if ($uid === '') {
            throw ValidationException::withMessages(['tag_uid' => 'Scan or select an RFID tag first.']);
        }

        $tag = $this->resolveTag($data);
        $scan = $this->unsavedScan($data, 'simulated', $scanLocation, now(), $tag);
        $vehicle = $scan->vehicle;
        $status = $scan->verification_status;
        $next = $vehicle && strtoupper((string) $vehicle->current_state) === Vehicle::STATE_INSIDE ? 'OUT' : 'IN';

        $message = match (true) {
            $status === 'verified' => "Registered tag: {$vehicle->plate_number}. It is recorded ({$next} by the vehicle's state, or the camera's direction) only when the camera sees the vehicle cross.",
            $status === 'unknown_tag' => "Unknown tag {$uid}: not in the registry. Register this tag.",
            default => (string) $this->tagAnomalyReason($tag, $status, $scanLocation),
        };

        return new RfidIngestResult($scan, RfidIngestResult::PREVIEW, 'Preview only, nothing saved. '.$message);
    }

    /**
     * An RfidScanLog that is not saved (buffered reads, Test Scan preview).
     *
     * @param  array<string, mixed>  $data
     */
    protected function unsavedScan(array $data, string $sourceMode, string $scanLocation, Carbon $scanTime, ?RfidTag $tag): RfidScanLog
    {
        $vehicle = $tag?->vehicle;
        $status = $this->resolveVerificationStatus($tag, $vehicle);
        $scan = new RfidScanLog([
            'vehicle_id' => $vehicle?->id,
            'vehicle_rfid_tag_id' => $tag?->id,
            'tag_uid' => $tag?->uid ?: $this->requestedUid($data),
            'scan_location' => $scanLocation,
            'scan_time' => $scanTime,
            'verification_status' => $status,
            'source_mode' => $sourceMode,
            'anomaly_reason' => $status === 'unknown_tag' ? null : $this->tagAnomalyReason($tag, $status, $scanLocation),
        ]);
        $scan->setRelation('vehicle', $vehicle);
        $scan->setRelation('vehicleRfidTag', $tag);
        $scan->setRelation('correlatedVehicleEvent', null);
        $scan->setRelation('guestVehicleObservation', null);

        return $scan;
    }

    /** A read of this tag at this gate was recorded during this presence. */
    protected function recordedSince(string $gate, string $uid, float $since): bool
    {
        return RfidScanLog::query()
            ->where('scan_location', $gate)
            ->whereRaw('upper(tag_uid) = ?', [strtoupper($uid)])
            ->where('scan_time', '>=', Carbon::createFromTimestamp($since - 2, config('app.timezone')))
            ->exists();
    }

    /** Phase 3: never below 10 s (0 recorded every read of a tag again). */
    public const MIN_COOLDOWN_SECONDS = 10;

    public function cooldownSeconds(): int
    {
        return max(self::MIN_COOLDOWN_SECONDS, $this->settingsService->getInt('rfid_cooldown_seconds', 60));
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

        if ($verificationStatus === 'verified' && $vehicle) {
            // Phase 3: no movement yet; the camera's crossing (or the
            // vehicle's state) gives the direction.
            $scanLog = $this->createScanLog($data, $sourceMode, $scanLocation, $scanTime, $tag, [
                'vehicle' => $vehicle,
                'verification_status' => $verificationStatus,
                'resolved_event_type' => null,
                'resulting_state' => $vehicle->current_state ?: null,
                'anomaly_reason' => null,
            ]);
            $outcome = $this->fusionService->registerScan($scanLog);
            $scanLog->refresh();
            $plate = $vehicle->plate_number;

            $message = match ($outcome) {
                RfidIngestResult::PENDING => "Tag read for {$plate}. Waiting for the camera to see the vehicle cross.",
                RfidIngestResult::ANOMALY => "{$scanLog->resolved_event_type} recorded for {$plate}, flagged for review: {$scanLog->anomaly_reason}",
                default => "{$scanLog->resolved_event_type} recorded for {$plate}.",
            };

            return $this->result($scanLog, $outcome, $message);
        }

        if ($verificationStatus === 'unknown_tag') {
            $reason = $tag
                ? ($tag->label ?? 'Tag')." ({$tag->uid}) is not assigned to a vehicle. Register this tag."
                : 'Unknown tag '.$this->requestedUid($data).' is not in the registry. Register this tag.';
            $scanLog = $this->createScanLog($data, $sourceMode, $scanLocation, $scanTime, $tag, [
                'verification_status' => $verificationStatus,
                'anomaly_reason' => $reason,
                'outcome' => RfidIngestResult::UNKNOWN_TAG,
            ]);

            return $this->result($scanLog, RfidIngestResult::UNKNOWN_TAG, $reason);
        }

        $anomalyReason = $this->tagAnomalyReason($tag, $verificationStatus, $scanLocation);
        $scanLog = $this->createScanLog($data, $sourceMode, $scanLocation, $scanTime, $tag, [
            'vehicle' => $vehicle,
            'verification_status' => $verificationStatus,
            'resolved_event_type' => null,
            'resulting_state' => $vehicle?->current_state ?: null,
            'anomaly_reason' => $anomalyReason,
        ]);

        $outcome = in_array($verificationStatus, ['inactive_tag'], true) ? RfidIngestResult::ALERT : RfidIngestResult::ANOMALY;
        $scanLog->forceFill(['outcome' => $outcome])->save();

        return $this->result($scanLog, $outcome, (string) $anomalyReason);
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

        // Phase 3: not in the registry, or an inventory tag with no vehicle.
        if (! $tag || ! $vehicle) {
            return 'unknown_tag';
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
        $station = Gate::labelFor($scanLocation);

        return match ($verificationStatus) {
            'inactive_tag' => ($tag?->label ?? 'Tag').' is '.strtoupper((string) $tag?->status)." but was scanned at the {$station}.",
            'unassigned_tag' => ($tag?->label ?? 'Tag')." is not assigned to any vehicle but was scanned at the {$station}.",
            'inactive_vehicle' => "The vehicle for this tag is inactive but was scanned at the {$station}.",
            // Phase 8: no guest record any more; the Registry entry needs a category.
            'guest', 'non_recurring_category' => "The vehicle for this tag has the old Guest category; set Faculty & Staff or Registered Visitor in the Registry.",
            default => null,
        };
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
