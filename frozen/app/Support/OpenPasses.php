<?php

namespace App\Support;

/**
 * ROI work, Phase 3: vehicles Detected at a gate and not Recorded yet, from
 * the detector's status ("Vehicle detected · checking RFID…" on the kiosk
 * and the Gate Monitor until the pass is Recorded). Only while the detector
 * and the gate's camera run: a stale status shows none.
 */
final class OpenPasses
{
    /**
     * @param  array<string, mixed>  $status  the detector status (DetectorRuntimeService)
     * @return list<array<string, mixed>>
     */
    public static function at(array $status, string $gate): array
    {
        $camera = $status['cameras'][$gate] ?? [];
        if (! ($status['service_running'] ?? false) || ! ($camera['camera_running'] ?? false)) {
            return [];
        }

        return array_values(array_filter(
            (array) ($camera['open_passes'] ?? []),
            fn ($pass): bool => is_array($pass) && filled($pass['event_key'] ?? null)
        ));
    }
}
