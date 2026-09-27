<?php

namespace App\Support;

/**
 * Plug-and-detect: files shared with the Python device service
 * (school-vehicle-monitoring-detector/devices/paths.py uses the same names).
 */
final class DeviceFiles
{
    public static function directory(): string
    {
        $path = rtrim((string) config('monitoring.devices.files_path', storage_path('app/devices')), '/\\');

        // Absolute on macOS/Linux ("/...") or Windows ("C:\..."), else relative to the project.
        return preg_match('#^([A-Za-z]:[\\\\/]|/|\\\\)#', $path) ? $path : base_path($path);
    }

    public static function statusPath(): string
    {
        return self::directory().DIRECTORY_SEPARATOR.'device_service_status.json';
    }

    public static function scanResultPath(): string
    {
        return self::directory().DIRECTORY_SEPARATOR.'last_scan.json';
    }

    public static function runtimeConfigPath(): string
    {
        return self::directory().DIRECTORY_SEPARATOR.'device_runtime_config.json';
    }

    public static function captureLogPath(): string
    {
        return self::directory().DIRECTORY_SEPARATOR.'reader_capture.log';
    }

    /**
     * Editable discovery data (ports, packets, camera stream paths).
     *
     * @return array<string, mixed>
     */
    public static function profiles(): array
    {
        $path = (string) config('monitoring.devices.profiles_path');
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
