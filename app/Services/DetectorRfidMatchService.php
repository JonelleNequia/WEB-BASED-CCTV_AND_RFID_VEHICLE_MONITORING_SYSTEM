<?php

namespace App\Services;

use App\Models\RfidScanLog;
use Carbon\Carbon;

/**
 * Phase 5: decide whether a vehicle the detector saw crossing the trigger
 * line had a registered RFID tag (guest passes were removed in Phase 0).
 *
 * RFID only with a vehicle: the tag comes from the read buffer
 * (RfidTagMatcher), not from saved reads.
 * - Lookback: reads from ~10 seconds BEFORE the crossing count, because a UHF
 *   reader reads the tag while the vehicle is still approaching.
 * - One tag presence confirms one vehicle: when a detector window takes it, a
 *   different vehicle crossing later cannot reuse it. A second window within
 *   a few seconds of the first (YOLO track-id swap on the same car) may.
 * - The returned RfidScanLog is not saved: the crossing makes the record.
 */
class DetectorRfidMatchService
{
    public const DEFAULT_LOOKBACK_SECONDS = 10;

    public const MAX_LOOKBACK_SECONDS = 15;

    /** Same car, new YOLO track id: may reuse the claim within this gap. */
    public const SAME_VEHICLE_REUSE_SECONDS = RfidTagMatcher::SAME_VEHICLE_REUSE_SECONDS;

    public function __construct(protected RfidTagMatcher $tagMatcher)
    {
    }

    /**
     * The registered tag of the vehicle in this detector window, or null.
     * The window and lookback come from Settings › Timing (the detector
     * sends the same values).
     */
    public function find(
        string $cameraRole,
        Carbon $eventTime,
        int $windowSeconds = 4,
        int $lookbackSeconds = self::DEFAULT_LOOKBACK_SECONDS,
        ?string $eventKey = null
    ): ?RfidScanLog {
        $presence = $this->tagMatcher->pick($cameraRole, $eventTime, $eventKey, registeredOnly: true);

        if ($presence === null) {
            return null;
        }

        // Callers without an event key (manual checks) only look.
        if (filled($eventKey)) {
            $this->tagMatcher->claim($cameraRole, $presence, $eventKey, $eventTime);
        }

        $tag = $presence['tag'];
        $scan = new RfidScanLog([
            'vehicle_id' => $tag->vehicle_id,
            'vehicle_rfid_tag_id' => $tag->id,
            'tag_uid' => $tag->uid,
            'scan_location' => $cameraRole,
            'scan_time' => Carbon::createFromTimestamp((float) $presence['peak_at'], config('app.timezone')),
            'verification_status' => 'verified',
        ]);
        $scan->setRelation('vehicle', $tag->vehicle->loadMissing('rfidTag'));
        $scan->setRelation('vehicleRfidTag', $tag);

        return $scan;
    }

    /**
     * Label the detector draws on the live feed.
     *
     * @return array<string, mixed>
     */
    public function overlay(?RfidScanLog $scan, ?int $eventId = null): array
    {
        if (! $scan || ! $scan->vehicle) {
            return self::noPassOverlay(['event_id' => $eventId, 'rfid_scan_id' => null]);
        }

        $vehicle = $scan->vehicle;

        return [
            'verification' => 'registered',
            'label' => 'REGISTERED - '.$vehicle->plate_number,
            'color' => 'green',
            'event_id' => $eventId ?? $scan->correlated_vehicle_event_id,
            'rfid_scan_id' => $scan->id,
            'action_taken' => $scan->resolved_event_type,
            'new_state' => $scan->resulting_state,
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
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function noPassOverlay(array $extra = []): array
    {
        return [
            'verification' => 'no_pass',
            'label' => 'UNREGISTERED',
            'color' => 'red',
            'vehicle' => null,
            ...$extra,
        ];
    }
}
