<?php

namespace App\Support;

/**
 * Plug-and-detect: camera source helpers.
 */
final class CameraSource
{
    /**
     * Hide credentials embedded in a URL (rtsp://user:pass@host → rtsp://***@host).
     */
    public static function withoutCredentials(?string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@\s]+@#i', '$1***@', (string) $url);
    }

    /**
     * What a person should see for a camera source (type + address, no password).
     */
    public static function display(?string $type, mixed $value): string
    {
        $type = strtolower((string) $type);

        if ($type === 'webcam') {
            return 'Webcam '.$value;
        }

        return strtoupper($type).' · '.self::withoutCredentials((string) $value);
    }

    /**
     * RTSP URL for a device's current IP. Only the host changes when the
     * camera gets a new address; port, path and query stay.
     */
    public static function rtspUrl(string $ip, ?int $port, ?string $path): string
    {
        $path = '/'.ltrim((string) $path, '/');
        $host = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        return 'rtsp://'.$host.($port ? ':'.$port : '').$path;
    }
}
