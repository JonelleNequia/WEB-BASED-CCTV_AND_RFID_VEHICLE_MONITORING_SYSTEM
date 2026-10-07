<?php

namespace App\Services;

use App\Support\DeviceFiles;
use App\Models\Gate;
use App\Support\DisplayTime;
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
        // Windows install kit: the device service is a Windows service.
        if (app()->runningUnitTests() || $this->isRunning() || config('monitoring.services.managed')) {
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

    /**
     * UHF readers assigned to a station: connected or not, and the last tag
     * read (EPC, RSSI, time). Shown in the sidebar and polled by it.
     *
     * @return list<array{station: string, label: string, ok: bool, state: string, detail: string, epc: ?string, rssi: mixed, read_at: ?string, read_display: ?string, tag_line: string}>
     */
    public function uhfReaders(): array
    {
        $status = $this->readStatus();
        $running = (bool) ($status['service_running'] ?? false);
        $rows = [];

        foreach (Gate::codes() as $station) {
            $link = (array) data_get($status, "readers.$station", []);

            if (empty($link['target'])) {
                continue;
            }

            $connected = $running && ($link['state'] ?? null) === 'connected';
            $rows[] = [
                'station' => $station,
                'label' => Gate::labelFor($station).' UHF',
                'ok' => $connected,
                'state' => $running ? (string) ($link['state'] ?? 'connecting') : 'stopped',
                'detail' => match (true) {
                    ! $running => 'Service off',
                    $connected => 'Connected',
                    ($link['state'] ?? null) === 'connecting' => 'Connecting…',
                    default => 'Offline',
                },
                'epc' => $link['last_tag'] ?? null,
                'rssi' => $link['last_rssi'] ?? null,
                'read_at' => $link['last_tag_at'] ?? null,
                'read_display' => filled($link['last_tag_at'] ?? null) ? DisplayTime::time($link['last_tag_at']) : null,
            ];
            $last = end($rows);
            $rows[key($rows)]['tag_line'] = $last['epc']
                ? implode(' · ', array_filter([
                    '…'.substr((string) $last['epc'], -8),
                    $last['rssi'] !== null ? $last['rssi'].' dBm' : null,
                    $last['read_display'],
                ]))
                : 'No tag read yet';
        }

        return $rows;
    }

    /**
     * Distinct tags the UHF readers read after a moment (Unix seconds), newest
     * first: Registry "Read with UHF reader" fills the tag field from these.
     *
     * @return array{connected: bool, reads: list<array{epc: string, rssi: mixed, station: string, epoch: float}>, now: float}
     */
    public function recentUhfReads(float $after): array
    {
        $status = $this->readStatus();
        $running = (bool) ($status['service_running'] ?? false);
        $reads = [];
        $connected = false;

        foreach ((array) ($status['readers'] ?? []) as $station => $link) {
            if (! is_array($link)) {
                continue;
            }
            $connected = $connected || ($running && ($link['state'] ?? null) === 'connected');

            foreach ((array) ($link['recent_tags'] ?? []) as $read) {
                if (is_array($read) && filled($read['epc'] ?? null) && (float) ($read['epoch'] ?? 0) > $after) {
                    $reads[] = [
                        'epc' => strtoupper((string) $read['epc']),
                        'rssi' => $read['rssi'] ?? null,
                        'station' => (string) $station,
                        'epoch' => (float) $read['epoch'],
                    ];
                }
            }
        }

        usort($reads, fn (array $a, array $b): int => $b['epoch'] <=> $a['epoch']);

        // The service writes its own clock; the browser may be on another PC.
        return ['connected' => $connected, 'reads' => $reads, 'now' => microtime(true)];
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
