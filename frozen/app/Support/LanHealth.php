<?php

namespace App\Support;

/**
 * Shared camera work: an unstable LAN, from the device service's status
 * (network.lan_drops_10min, network.dhcp_lease_seconds).
 *
 * Measured 2026-10-15: the router lent this PC its LAN address for only
 * 60 seconds; the address was lost every one to two minutes, and every
 * camera and reader on the LAN went offline each time.
 */
final class LanHealth
{
    /** A lease shorter than this is too short to keep the LAN steady. */
    public const SHORT_LEASE_SECONDS = 300;

    /** This many drops in 10 minutes is not a single unplugged cable. */
    public const DROPS_TO_WARN = 3;

    public const FIX = "In the router's settings (DHCP), set the lease time to 1 day or more, or give this PC a fixed LAN address (and reserve the cameras' and readers' addresses). Also check the LAN cable and adapter.";

    /**
     * @param  array<string, mixed>  $network  the device service's network status
     * @return array{line: string, next_step: string}|null
     */
    public static function problem(array $network): ?array
    {
        $drops = (int) ($network['lan_drops_10min'] ?? 0);
        $lease = isset($network['dhcp_lease_seconds']) ? (int) $network['dhcp_lease_seconds'] : null;

        if ($lease !== null && $lease > 0 && $lease < self::SHORT_LEASE_SECONDS) {
            return [
                'line' => 'The router lends this PC its LAN address for only '.self::duration($lease).' (DHCP lease)'
                    .($drops > 0 ? "; the LAN dropped {$drops} ".($drops === 1 ? 'time' : 'times').' in the last 10 minutes.' : ', so the LAN can drop every minute or two.'),
                'next_step' => self::FIX,
            ];
        }

        if ($drops >= self::DROPS_TO_WARN) {
            return [
                'line' => "This PC's LAN connection dropped {$drops} times in the last 10 minutes: cameras and readers go offline each time.",
                'next_step' => self::FIX,
            ];
        }

        return null;
    }

    protected static function duration(int $seconds): string
    {
        return $seconds < 120 ? $seconds.' seconds' : intdiv($seconds, 60).' minutes';
    }
}
