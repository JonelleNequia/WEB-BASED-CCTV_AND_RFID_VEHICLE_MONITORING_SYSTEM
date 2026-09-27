<?php

namespace App\Services;

use App\Support\DeviceFiles;
use App\Support\PythonLauncher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Plug-and-detect: status and background start of the Python device service
 * (school-vehicle-monitoring-detector/device_service.py).
 */
class DeviceServiceRuntime
{
    protected const LAUNCH_COOLDOWN_SECONDS = 30;

    /**
     * Last status written by the service, or a "not running" fallback.
     *
     * @return array<string, mixed>
     */
    public function readStatus(): array
    {
        $fallback = [
            'service_running' => false,
            'state' => 'stopped',
            'message' => 'The device service is not running yet. It starts automatically.',
            'updated_at' => null,
            'network' => ['interfaces' => [], 'wired_connected' => false],
            'scan' => ['running' => false, 'last' => null],
            'readers' => [],
            'temporary_ip_suggestions' => [],
        ];

        $path = DeviceFiles::statusPath();
        $decoded = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;

        if (! is_array($decoded)) {
            return $fallback;
        }

        $status = array_replace($fallback, $decoded);

        if (($status['service_running'] ?? false) && ! $this->isFresh($status['updated_at'] ?? null)) {
            $status['service_running'] = false;
            $status['state'] = 'stopped';
            $status['message'] = 'The device service stopped responding. It will be restarted.';
        }

        return $status;
    }

    public function isRunning(): bool
    {
        return (bool) ($this->readStatus()['service_running'] ?? false);
    }

    /**
     * Start the service in the background when it is not running.
     */
    public function ensureRunning(): bool
    {
        if (app()->runningUnitTests() || $this->isRunning()) {
            return false;
        }

        if (! Cache::add('device-service-launch', true, self::LAUNCH_COOLDOWN_SECONDS)) {
            return false;
        }

        try {
            app(DeviceRegistryService::class)->exportRuntimeConfig();
        } catch (Throwable) {
            // The service also starts without a config and waits for one.
        }

        PythonLauncher::rotateLog($this->logPath());

        return PythonLauncher::launch('device_service.py', $this->logPath());
    }

    public function logPath(): string
    {
        return storage_path('logs/device-service.stdout.log');
    }

    protected function isFresh(mixed $timestamp): bool
    {
        if (blank($timestamp)) {
            return false;
        }

        try {
            return Carbon::parse((string) $timestamp)
                ->gte(now()->subSeconds((int) config('monitoring.devices.status_stale_after_seconds', 20)));
        } catch (Throwable) {
            return false;
        }
    }
}
