<?php

namespace App\Services;

use App\Models\DeviceAssignment;
use App\Models\Gate;
use Illuminate\Support\Str;
use Throwable;

/**
 * Live view work, Phase 2: an RFID reader that works without a terminal.
 *
 * - "Move reader to this network": the device service reads the reader's
 *   network settings through its module's setup protocol (UDP broadcast,
 *   devices/module_config.py), shows them with a free address in the PC's
 *   network, and after the user confirms writes only the address, restarts
 *   the reader and finds it again by MAC.
 * - Temporary workaround (development): while the reader is still on
 *   another subnet, the device service adds an extra address on the PC's
 *   card (devices/workaround.py), if the permission was set up once.
 */
class ReaderNetworkService
{
    public function __construct(
        protected DeviceRegistryService $registry,
        protected DeviceServiceRuntime $runtime,
        protected SettingsService $settingsService
    ) {
    }

    /**
     * For the gate card: is the reader outside the PC's network, the
     * workaround state and the last move request.
     *
     * @return array<string, mixed>|null null: no reader added from the device list
     */
    public function state(Gate $gate): ?array
    {
        $assignment = DeviceAssignment::query()->with('device')
            ->where('station', $gate->code)->where('role', DeviceAssignment::ROLE_READER)->first();
        $device = $assignment?->device;
        if (! $device || blank($device->mac)) {
            return null;
        }

        $status = $this->runtime->readStatus();
        $routerNetworks = collect((array) data_get($status, 'network.interfaces', []))
            ->filter(fn ($item): bool => is_array($item) && filled($item['gateway'] ?? null) && ! ($item['link_local'] ?? false))
            ->pluck('network')->unique()->values()->all();
        $outside = filled($device->ip) && $routerNetworks !== [] && ! $this->inAny((string) $device->ip, $routerNetworks);
        $request = (array) (data_get($status, 'reader_network') ?? []);

        return [
            'mac' => $device->mac,
            'ip' => $device->ip,
            'outside' => $outside,
            'pc_networks' => $routerNetworks,
            'workaround' => (array) data_get($status, "reader_workaround.{$gate->code}", []),
            'workaround_enabled' => $this->workaroundEnabled(),
            'move' => ($request['station'] ?? null) === $gate->code ? $request : null,
        ];
    }

    public function workaroundEnabled(): bool
    {
        return $this->settingsService->get('reader_workaround', '1') === '1';
    }

    /**
     * Step 1: read the current settings and a proposal (nothing changes).
     *
     * @param  array{username?: ?string, password?: ?string}  $login
     */
    public function requestRead(Gate $gate, array $login = []): string
    {
        return $this->send($gate, ['action' => 'read'] + $this->login($login));
    }

    /**
     * Step 2 (after the user confirmed): write the new address.
     *
     * @param  array{username?: ?string, password?: ?string}  $login
     */
    public function requestApply(Gate $gate, string $mode, ?string $ip, array $login = []): string
    {
        return $this->send($gate, ['action' => 'apply', 'mode' => $mode, 'ip' => $mode === 'static' ? $ip : null] + $this->login($login));
    }

    /**
     * @param  array<string, mixed>  $request
     */
    protected function send(Gate $gate, array $request): string
    {
        $state = $this->state($gate) ?? abort(404);
        $target = (array) data_get(json_decode((string) @file_get_contents(\App\Support\DeviceFiles::runtimeConfigPath()), true), "stations.{$gate->code}.reader", []);
        $id = (string) Str::uuid();

        $this->registry->exportRuntimeConfig(readerNetworkRequest: [
            'id' => $id,
            'station' => $gate->code,
            'mac' => $state['mac'],
            'port' => $target['port'] ?? null,
            'requested_at' => now()->toIso8601String(),
            ...$request,
        ]);

        try {
            $this->runtime->ensureRunning();
        } catch (Throwable) {
            // The service picks the request up when it starts.
        }

        return $id;
    }

    /**
     * @param  array{username?: ?string, password?: ?string}  $login
     * @return array{username?: string, password?: string}
     */
    protected function login(array $login): array
    {
        return array_filter(['username' => $login['username'] ?? null, 'password' => $login['password'] ?? null], 'filled');
    }

    /**
     * @param  list<string>  $networks
     */
    protected function inAny(string $ip, array $networks): bool
    {
        $address = ip2long($ip);
        if ($address === false) {
            return false;
        }

        foreach ($networks as $network) {
            [$base, $prefix] = array_pad(explode('/', (string) $network), 2, '32');
            $mask = (int) $prefix === 0 ? 0 : (~0 << (32 - (int) $prefix)) & 0xFFFFFFFF;
            if ((ip2long($base) & $mask) === ($address & $mask)) {
                return true;
            }
        }

        return false;
    }
}
