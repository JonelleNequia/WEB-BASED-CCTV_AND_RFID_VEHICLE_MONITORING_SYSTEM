<?php

namespace App\Services;

use App\Models\RfidScanLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 5: decide whether a vehicle the detector saw crossing the trigger
 * line had a pass (registered vehicle tag or guest pass).
 *
 * - Lookback: scans from ~10 seconds BEFORE the crossing count, because a UHF
 *   reader reads the tag while the vehicle is still approaching.
 * - One scan confirms one vehicle: when a detector window takes a scan, a
 *   different vehicle crossing later cannot reuse it. A second window within
 *   a few seconds of the first (YOLO track-id swap on the same car) may.
 */
class DetectorRfidMatchService
{
    public const DEFAULT_LOOKBACK_SECONDS = 10;

    public const MAX_LOOKBACK_SECONDS = 15;

    /** Scans from the other station only count this close to the crossing. */
    public const OTHER_STATION_LOOKBACK_SECONDS = 3;

    /** Same car, new YOLO track id: may reuse the claim within this gap. */
    public const SAME_VEHICLE_REUSE_SECONDS = 3;

    protected const CLAIM_TTL_SECONDS = 300;

    /** Registered vehicle tag reads. */
    public const REGISTERED_STATUSES = ['verified'];

    /** Any guest pass read means the vehicle had a pass (alerts are raised by the Station). */
    public const GUEST_PASS_STATUSES = [
        'guest_pass_available',
        'guest_pass_entry',
        'guest_pass_exit',
        'guest_pass_duplicate',
        'guest_pass_lost',
        'guest_pass_disabled',
        'guest_pass_not_issued',
    ];

    public const GUEST_PASS_ALERT_STATUSES = [
        'guest_pass_lost',
        'guest_pass_disabled',
        'guest_pass_not_issued',
    ];

    public function find(
        string $cameraRole,
        Carbon $eventTime,
        int $windowSeconds = 4,
        int $lookbackSeconds = self::DEFAULT_LOOKBACK_SECONDS,
        ?string $eventKey = null
    ): ?RfidScanLog {
        $eventTime = $eventTime->copy()->setTimezone(config('app.timezone', 'UTC'));
        $lookbackSeconds = max(0, min(self::MAX_LOOKBACK_SECONDS, $lookbackSeconds));
        $windowEnd = $eventTime->copy()->addSeconds($windowSeconds);
        $to = now()->lessThan($windowEnd) ? now() : $windowEnd;

        $candidates = $this->candidates($eventTime->copy()->subSeconds($lookbackSeconds), $to)
            ->where('scan_location', $cameraRole)
            ->get();

        // Legacy single-camera setups: a read at the other station still
        // counts, but only right around the crossing.
        $candidates = $candidates->concat(
            $this->candidates($eventTime->copy()->subSeconds(min($lookbackSeconds, self::OTHER_STATION_LOOKBACK_SECONDS)), $to)
                ->where('scan_location', '!=', $cameraRole)
                ->get()
        );

        foreach ($candidates as $scan) {
            if ($this->claim($scan, $eventKey, $eventTime)) {
                return $scan;
            }
        }

        return null;
    }

    public function isGuestPassScan(?RfidScanLog $scan): bool
    {
        return $scan !== null && in_array($scan->verification_status, self::GUEST_PASS_STATUSES, true);
    }

    /**
     * Label the detector draws on the live feed.
     *
     * @return array<string, mixed>
     */
    public function overlay(?RfidScanLog $scan, ?int $eventId = null): array
    {
        if ($this->isGuestPassScan($scan)) {
            $label = $scan->vehicleRfidTag?->label ?? 'Guest Pass';
            $alert = in_array($scan->verification_status, self::GUEST_PASS_ALERT_STATUSES, true);

            return [
                'verification' => $alert ? 'pass_alert' : 'guest_pass',
                'label' => ($alert ? 'PASS ALERT - ' : 'GUEST PASS - ').$this->shortPassLabel($label),
                'color' => $alert ? 'red' : 'green',
                'event_id' => $eventId ?? $scan->correlated_vehicle_event_id,
                'rfid_scan_id' => $scan->id,
                'vehicle' => null,
            ];
        }

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
            'label' => 'NO PASS',
            'color' => 'red',
            'vehicle' => null,
            ...$extra,
        ];
    }

    /**
     * Scans that prove a pass, newest first. updated_at covers a guest pass
     * whose Issue form was submitted after the card was tapped.
     */
    protected function candidates(Carbon $from, Carbon $to)
    {
        return RfidScanLog::query()
            ->with(['vehicle.rfidTag', 'vehicleRfidTag'])
            ->whereIn('verification_status', [...self::REGISTERED_STATUSES, ...self::GUEST_PASS_STATUSES])
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('scan_time', [$from, $to])
                    ->orWhereBetween('created_at', [$from, $to])
                    ->orWhereBetween('updated_at', [$from, $to]);
            })
            ->latest('scan_time')
            ->latest('id');
    }

    protected function claim(RfidScanLog $scan, ?string $eventKey, Carbon $eventTime): bool
    {
        // Callers without an event key (manual checks) only look.
        if (blank($eventKey)) {
            return true;
        }

        $cacheKey = 'detector-rfid-claim:'.$scan->id;
        $claim = ['event_key' => $eventKey, 'event_time' => $eventTime->getTimestamp()];

        if (Cache::add($cacheKey, $claim, self::CLAIM_TTL_SECONDS)) {
            return true;
        }

        $existing = Cache::get($cacheKey);

        if (! is_array($existing) || ($existing['event_key'] ?? null) === $eventKey) {
            return true;
        }

        return abs(((int) ($existing['event_time'] ?? 0)) - $eventTime->getTimestamp()) <= self::SAME_VEHICLE_REUSE_SECONDS;
    }

    protected function shortPassLabel(string $label): string
    {
        return trim((string) preg_replace('/^Guest Pass\s*#?/i', '', $label)) ?: $label;
    }
}
