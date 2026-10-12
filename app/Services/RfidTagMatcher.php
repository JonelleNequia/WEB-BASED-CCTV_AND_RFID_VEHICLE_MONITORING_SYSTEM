<?php

namespace App\Services;

use App\Models\Gate;
use App\Models\RfidTag;
use App\Support\DeviceFiles;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * RFID only with a vehicle: picks the tag that belongs to a vehicle pass
 * the camera saw, from the reads waiting in the buffer.
 *
 * - The buffer: the device service's rfid_buffer.json (UHF readers, every
 *   read with its RSSI) plus reads that reached Laravel (kiosk USB reader; cache).
 * - A "presence" is one continuous stay of a tag at a gate (see
 *   devices/tag_buffer.py). ROI work, Phase 3: it belongs to a pass when it
 *   was read from `lookback` before the vehicle was Detected until
 *   `lookahead` after the pass was ready to be Recorded (the whole pass).
 * - The best one: its strongest read (the vehicle closest to the antenna)
 *   inside the pass, or nearest it; then a stronger signal and more reads;
 *   a registered tag before an unknown one.
 * - One tag = one pass: a presence is given to one pass only (claimed by
 *   its event key). A stationary tag (a vehicle parked near the gate) is
 *   never given to a passing vehicle.
 */
class RfidTagMatcher
{
    protected const CLAIM_TTL_MINUTES = 15;

    protected const READS_PREFIX = 'rfid-read-buffer.';

    public function __construct(protected RfidCameraFusionService $fusion)
    {
    }

    /**
     * Presences at a gate: the device service's buffer, then reads that reached Laravel.
     *
     * @return list<array<string, mixed>>
     */
    public function presences(string $gate): array
    {
        $buffer = $this->buffer();
        $presences = array_values((array) data_get($buffer, "gates.$gate", []));
        $seen = array_column($presences, 'epc');

        foreach ((array) Cache::get(self::READS_PREFIX.$gate, []) as $presence) {
            if (! in_array($presence['epc'], $seen, true)) {
                $presences[] = $presence;
            }
        }

        return $presences;
    }

    /**
     * The device service's buffer (empty when it stopped writing it).
     *
     * @return array<string, mixed>
     */
    public function buffer(): array
    {
        $path = DeviceFiles::rfidBufferPath();

        try {
            $buffer = is_file($path) ? (array) json_decode((string) File::get($path), true) : [];
        } catch (Throwable) {
            return [];
        }

        $age = $this->seconds(now()) - (float) ($buffer['generated_at'] ?? 0);

        return $age <= (float) config('monitoring.rfid.buffer_stale_seconds', 5) ? $buffer : [];
    }

    /**
     * A read that reached Laravel (kiosk USB reader, or a reader event when
     * the device service's buffer is not there): it joins the buffer for a
     * while.
     */
    public function addRead(string $gate, string $epc, CarbonInterface $at, ?float $rssi = null): void
    {
        $key = self::READS_PREFIX.$gate;
        $time = $this->seconds($at);
        $absent = (float) config('monitoring.rfid.absent_seconds', 5);
        $window = (float) max((int) config('monitoring.rfid.buffer_seconds', 15), $this->fusion->pendingTimeoutSeconds());
        $presences = collect((array) Cache::get($key, []))
            ->filter(fn (array $presence): bool => $time - $presence['last_seen'] <= $window)
            ->keyBy('epc');

        $presence = $presences->get($epc);
        if (! $presence || $time - $presence['last_seen'] > $absent) {
            $presence = ['epc' => $epc, 'session' => round($time, 3), 'first_seen' => $time, 'reads' => 0, 'max_rssi' => null, 'peak_at' => $time, 'stationary' => false];
        }
        $presence['last_seen'] = $time;
        $presence['reads']++;
        if ($rssi !== null && ($presence['max_rssi'] === null || $rssi > $presence['max_rssi'])) {
            $presence['max_rssi'] = $rssi;
            $presence['peak_at'] = $time;
        }
        $presence['stationary'] = $time - $presence['first_seen'] > $this->stationarySeconds();
        $presences->put($epc, $presence);

        Cache::put($key, $presences->values()->all(), now()->addSeconds((int) ceil($window) + 5));
    }

    /**
     * The presence for a pass at this gate (not claimed by another pass), or
     * null. The pass runs from $detectedAt (the vehicle entered the zone) to
     * $until (it is ready to be Recorded; default: $detectedAt). Adds 'tag'
     * (RfidTag|null) and 'registered'.
     *
     * @return array<string, mixed>|null
     */
    public function pick(string $gate, CarbonInterface $detectedAt, ?string $eventKey = null, bool $registeredOnly = false, ?CarbonInterface $until = null): ?array
    {
        $start = $this->seconds($detectedAt);
        $end = max($start, $until ? $this->seconds($until) : $start);
        $from = $start - $this->fusion->lookbackSeconds();
        $to = $end + $this->fusion->lookaheadSeconds();
        $candidates = [];

        foreach ($this->presences($gate) as $presence) {
            $mine = $eventKey !== null && $this->claimedBy($gate, $presence) === $eventKey;
            // This pass's own tag stays its tag (even if it has been read long enough to look parked now).
            if (! $mine && (($presence['stationary'] ?? false) || $presence['last_seen'] < $from || $presence['first_seen'] > $to)) {
                continue;
            }
            if (! $mine && $this->isClaimed($gate, $presence)) {
                continue; // one tag = one pass
            }

            $tag = $this->tag($presence['epc']);
            $registered = $tag?->vehicle !== null && $tag->status === RfidTag::STATUS_ASSIGNED && $tag->vehicle->status === 'active';
            if ($registeredOnly && ! $registered) {
                continue;
            }
            if ($mine) {
                return [...$presence, 'tag' => $tag, 'registered' => $registered, 'score' => -1.0];
            }
            $candidates[] = [...$presence, 'tag' => $tag, 'registered' => $registered];
        }

        // RSSI is compared between tags of the same reader only (scales differ).
        $strongest = collect($candidates)->pluck('max_rssi')->filter(fn ($rssi) => $rssi !== null)->max();
        $best = null;

        foreach ($candidates as $candidate) {
            // Its strongest read (closest to the antenna) inside the pass, or
            // nearest it; then a stronger signal and more reads; registered first.
            $peak = (float) $candidate['peak_at'];
            $score = ($peak < $start ? $start - $peak : ($peak > $end ? $peak - $end : 0.0))
                + ($strongest !== null && $candidate['max_rssi'] !== null ? ($strongest - $candidate['max_rssi']) * 0.05 : 0.0)
                - min((int) $candidate['reads'], 30) * 0.02
                + ($candidate['registered'] ? 0.0 : 1.5);

            if ($best === null || $score < $best['score']) {
                $best = [...$candidate, 'score' => $score];
            }
        }

        return $best;
    }

    /**
     * Give this presence to the crossing (no other crossing can take it).
     *
     * @param  array<string, mixed>  $presence
     */
    public function claim(string $gate, array $presence, ?string $eventKey, CarbonInterface $crossedAt): void
    {
        $isNew = ! $this->isClaimed($gate, $presence);

        Cache::put($this->claimKey($gate, $presence), [
            'event_key' => $eventKey,
            'at' => $this->seconds($crossedAt),
        ], now()->addMinutes(self::CLAIM_TTL_MINUTES));

        if ($isNew) {
            $this->count($gate, 'attached');
        }
    }

    /**
     * @param  array<string, mixed>  $presence
     */
    public function isClaimed(string $gate, array $presence): bool
    {
        return Cache::has($this->claimKey($gate, $presence));
    }

    /**
     * The event key of the pass this presence was given to, or null.
     *
     * @param  array<string, mixed>  $presence
     */
    public function claimedBy(string $gate, array $presence): ?string
    {
        return Cache::get($this->claimKey($gate, $presence))['event_key'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $presence
     */
    protected function claimKey(string $gate, array $presence): string
    {
        return 'rfid-claim.'.$gate.'.'.$presence['epc'].'.'.$presence['session'];
    }

    public function stationarySeconds(): int
    {
        return max(10, app(SettingsService::class)->getInt('rfid_stationary_seconds', 60));
    }

    protected function tag(string $epc): ?RfidTag
    {
        return RfidTag::query()->with('vehicle')->whereRaw('upper(uid) = ?', [strtoupper($epc)])->first();
    }

    /** Today's diagnostic counters (Settings › Advanced › System status). */
    public function count(string $gate, string $name): void
    {
        $key = $this->counterKey($gate, $name);
        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key);
    }

    protected function counterKey(string $gate, string $name): string
    {
        return 'rfid-diag.'.now()->toDateString().'.'.$gate.'.'.$name;
    }

    protected function seconds(CarbonInterface $time): float
    {
        return $time->getTimestamp() + $time->micro / 1_000_000;
    }

    /**
     * Settings › System status: raw reads, attached and discarded passes,
     * stationary tags, per gate.
     *
     * @return array<string, array<string, mixed>>
     */
    public function diagnostics(): array
    {
        $buffer = $this->buffer();
        $rows = [];

        foreach (Gate::codes() as $gate) {
            $ended = collect((array) ($buffer['ended'] ?? []))->where('station', $gate);
            $rows[$gate] = [
                'label' => Gate::labelFor($gate),
                'buffer_running' => $buffer !== [],
                'raw_reads' => (int) data_get($buffer, "raw_reads.$gate", 0),
                'passes' => (int) data_get($buffer, "presences_started.$gate", 0),
                'attached' => (int) Cache::get($this->counterKey($gate, 'attached'), 0),
                'discarded_recent' => $ended->reject(fn (array $presence): bool => $this->isClaimed($gate, $presence))->count(),
                'recent_ended' => $ended->count(),
                'unknown_while_offline' => (int) Cache::get($this->counterKey($gate, 'unknown_offline'), 0),
                'rfid_only' => (int) Cache::get($this->counterKey($gate, 'rfid_only'), 0),
                'in_buffer' => count((array) data_get($buffer, "gates.$gate", [])),
                'stationary' => collect((array) data_get($buffer, "gates.$gate", []))->where('stationary', true)
                    ->map(fn (array $presence): array => ['epc' => $presence['epc'], 'minutes' => round(($presence['last_seen'] - $presence['first_seen']) / 60, 1)])
                    ->values()->all(),
            ];
        }

        return $rows;
    }
}
