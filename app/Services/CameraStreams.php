<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\NetworkDevice;
use App\Support\CameraSource;

/**
 * Camera source work (Phase 1): where a gate's video comes from, built when
 * it is needed instead of a typed or stored URL.
 *
 * - An assigned camera (Settings › Gates "+ Add camera") is a DEVICE: known by
 *   its MAC, its current address comes from the device scan (it follows a new
 *   DHCP address or another router), and its main / sub stream paths were
 *   read from the camera over ONVIF when it was added (or are the brand's
 *   known paths). The URLs are built from those every time.
 * - A camera added by hand (Settings › Advanced › Manual setup) keeps the URL
 *   that was typed: only for a camera that cannot be found automatically.
 *
 * URLs here have no login; the login is added only for the detector and
 * go2rtc (never shown on a page).
 */
class CameraStreams
{
    public const SOURCE_DEVICE = 'device';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_NONE = 'none';

    /** Testing only: this PC's webcam stands in for the gate's CCTV. */
    public const SOURCE_WEBCAM = 'webcam';

    /**
     * @return array{source: string, live: ?string, main: ?string, sub: ?string, snapshot: ?string, stream: string, device: ?NetworkDevice}
     */
    public function forGate(string $gate): array
    {
        // Testing: the webcam wins over the CCTV until it is switched off
        // (the CCTV assignment is kept).
        $webcam = Camera::query()->forRole($gate)->value('test_webcam_index');
        if ($webcam !== null) {
            return ['source' => self::SOURCE_WEBCAM, 'live' => (string) $webcam, 'main' => null, 'sub' => null, 'snapshot' => null, 'stream' => 'sub', 'device' => null];
        }

        $assignment = DeviceAssignment::query()->with('device')
            ->where('station', $gate)->where('role', DeviceAssignment::ROLE_CAMERA)->first();
        $device = $assignment?->device;

        if ($device) {
            $options = (array) $assignment->options;
            $paths = (array) ($options['paths'] ?? []);
            $mainPath = $paths['main'] ?? ($options['snapshot_path'] ?? ($options['path'] ?? null));
            $subPath = $paths['sub'] ?? ($options['path'] ?? $mainPath);
            $stream = ($options['stream'] ?? 'sub') === 'main' ? 'main' : 'sub';
            $port = isset($options['rtsp_port']) ? (int) $options['rtsp_port'] : null;
            $url = fn (?string $path): ?string => filled($device->ip) && filled($path)
                ? CameraSource::rtspUrl((string) $device->ip, $port, (string) $path)
                : null;
            $main = $url($mainPath);
            $sub = $url($subPath) ?? $main;
            $live = $stream === 'main' ? $main : $sub;

            return [
                'source' => self::SOURCE_DEVICE,
                'live' => $live,
                'main' => $main,
                'sub' => $sub,
                // Full-resolution snapshots on a crossing, unless the live stream is already the main one.
                'snapshot' => ($options['snapshots'] ?? true) && $main !== $live ? $main : null,
                'stream' => $stream,
                'device' => $device,
            ];
        }

        $camera = Camera::query()->forRole($gate)->first();
        if ($camera && in_array($camera->source_type, ['rtsp', 'url'], true) && filled($camera->source_value)) {
            $snapshot = filled($camera->snapshot_source_value) ? (string) $camera->snapshot_source_value : null;

            return [
                'source' => self::SOURCE_MANUAL,
                'live' => (string) $camera->source_value,
                'main' => $snapshot ?? (string) $camera->source_value,
                'sub' => (string) $camera->source_value,
                'snapshot' => $snapshot,
                'stream' => 'sub',
                'device' => null,
            ];
        }

        return ['source' => self::SOURCE_NONE, 'live' => null, 'main' => null, 'sub' => null, 'snapshot' => null, 'stream' => 'sub', 'device' => null];
    }

    /**
     * Add the camera's saved login to a URL (detector and go2rtc only).
     */
    public static function withLogin(?string $url, ?string $username, ?string $password): ?string
    {
        if (blank($url) || blank($username) || parse_url((string) $url, PHP_URL_USER) !== null) {
            return $url;
        }

        $login = rawurlencode((string) $username).(filled($password) ? ':'.rawurlencode((string) $password) : '').'@';

        return preg_replace('#^([a-z][a-z0-9+.-]*://)#i', '$1'.$login, (string) $url) ?? $url;
    }

    /**
     * A detected camera at the address a hand-typed source points to: the
     * gate card offers to use it automatically from then on.
     */
    public function detectedForManual(Camera $camera): ?NetworkDevice
    {
        if (! in_array($camera->source_type, ['rtsp', 'url'], true)) {
            return null;
        }

        $host = parse_url((string) $camera->source_value, PHP_URL_HOST);
        if (blank($host)) {
            return null;
        }

        return NetworkDevice::query()
            ->where('kind', NetworkDevice::KIND_CAMERA)
            ->where('ip', trim((string) $host, '[]'))
            ->whereNotNull('mac')
            ->first();
    }
}
