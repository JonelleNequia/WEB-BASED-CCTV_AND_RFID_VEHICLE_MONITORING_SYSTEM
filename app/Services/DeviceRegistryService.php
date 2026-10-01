<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\Gate;
use App\Models\NetworkDevice;
use App\Support\CameraSource;
use App\Support\DeviceFiles;
use App\Support\DisplayTime;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Plug-and-detect: devices found by the Python device service.
 *
 * - Scan results arrive here (API or `devices:scan`) and are stored by MAC.
 * - A station uses one camera and one reader (DeviceAssignment). When an
 *   assigned device gets a new IP, the camera source and the reader target
 *   follow it automatically; the assignment itself never changes.
 * - device_runtime_config.json tells Python which reader belongs to which
 *   station and when Settings asked for a new scan.
 */
class DeviceRegistryService
{
    public function __construct(
        protected SettingsService $settingsService,
        protected CameraProbeService $cameraProbe,
        protected DeviceServiceRuntime $runtime
    ) {
    }

    // ------------------------------------------------------------------
    // Scan results
    // ------------------------------------------------------------------

    /**
     * Store one scan (keyed by MAC) and follow assigned devices to new IPs.
     *
     * @param  array<string, mixed>  $payload
     * @return array{created: int, updated: int, moved: int, offline: int}
     */
    public function ingestScan(array $payload): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'moved' => 0, 'offline' => 0];
        $complete = (bool) data_get($payload, 'scan.complete', true);
        $now = now();
        $seen = [];

        DB::transaction(function () use ($payload, $complete, $now, &$summary, &$seen): void {
            foreach ((array) ($payload['devices'] ?? []) as $data) {
                if (! is_array($data) || blank($data['ip'] ?? null)) {
                    continue;
                }

                $device = $this->storeDevice($data, $now, $summary);
                $seen[] = $device->id;
            }

            // A full scan that did not see a device means it is off or unplugged.
            if ($complete) {
                $summary['offline'] = NetworkDevice::query()
                    ->whereNotIn('id', $seen)
                    ->where('status', '!=', NetworkDevice::STATUS_OFFLINE)
                    ->update(['status' => NetworkDevice::STATUS_OFFLINE]);
            }
        });

        $this->syncAssignments();

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, int>  $summary
     */
    protected function storeDevice(array $data, $now, array &$summary): NetworkDevice
    {
        $mac = $data['mac'] ?? null;
        $key = $mac ?: (string) ($data['key'] ?? 'ip:'.$data['ip']);

        $device = NetworkDevice::query()->where('device_key', $key)->first()
            // A device first seen by IP only (other subnet) keeps its record once its MAC is known.
            ?? ($mac ? NetworkDevice::query()->where('device_key', 'ip:'.$data['ip'])->first() : null);

        $isNew = $device === null;
        $device ??= new NetworkDevice(['first_seen_at' => $now, 'is_new' => true]);

        $reachable = (bool) ($data['reachable'] ?? true);
        $online = (bool) ($data['online'] ?? true);
        $status = ! $reachable
            ? NetworkDevice::STATUS_UNREACHABLE
            : ($online ? NetworkDevice::STATUS_ONLINE : NetworkDevice::STATUS_OFFLINE);

        // A weaker result (e.g. RTSP timed out this time) never downgrades a
        // device that was already identified.
        $kind = (string) ($data['kind'] ?? NetworkDevice::KIND_UNKNOWN);
        $confidence = (string) ($data['confidence'] ?? 'none');
        if (! $isNew && $this->strength($device->kind, $device->confidence) > $this->strength($kind, $confidence)) {
            $kind = $device->kind;
            $confidence = $device->confidence;
        }

        $details = array_replace(
            (array) ($device->details ?? []),
            Arr::only($data, ['camera', 'reader', 'http', 'module', 'open_ports', 'discovered_by', 'via_temporary_ip', 'randomized_mac', 'is_gateway', 'network_warning'])
        );

        $newIp = (string) $data['ip'];
        if (! $isNew && $device->ip && $device->ip !== $newIp && $reachable) {
            $device->ip_changed_at = $now;
            $summary['moved']++;
        }

        $device->fill([
            'device_key' => $key,
            'mac' => $mac ?: $device->mac,
            'ip' => $newIp,
            'kind' => $kind,
            'confidence' => $confidence,
            'status' => $status,
            'vendor' => $data['vendor'] ?? $device->vendor,
            'brand' => $data['brand'] ?? $device->brand,
            'model' => $data['model'] ?? $device->model,
            'name' => $data['name'] ?? $device->name,
            'interface' => $data['interface'] ?? $device->interface,
            'subnet' => $data['subnet'] ?? $device->subnet,
            'details' => $details,
        ]);

        if ($status !== NetworkDevice::STATUS_OFFLINE) {
            $device->last_seen_at = $now;
        }

        $device->save();
        $summary[$isNew ? 'created' : 'updated']++;

        return $device;
    }

    protected function strength(?string $kind, ?string $confidence): int
    {
        return match (true) {
            in_array($kind, [NetworkDevice::KIND_CAMERA, NetworkDevice::KIND_READER], true) && $confidence === 'confirmed' => 3,
            $kind === NetworkDevice::KIND_READER => 2,
            $kind === NetworkDevice::KIND_ROUTER => 1,
            default => 0,
        };
    }

    /**
     * Latest scan result file (written by Python) into the database.
     */
    public function ingestLastScanFile(): ?array
    {
        $path = DeviceFiles::scanResultPath();
        $decoded = is_file($path) ? json_decode((string) File::get($path), true) : null;

        return is_array($decoded) ? $this->ingestScan($decoded) : null;
    }

    // ------------------------------------------------------------------
    // Assignment
    // ------------------------------------------------------------------

    /**
     * Assign a device to a station as its camera or reader.
     *
     * @param  array{stream?: string|null, username?: string|null, password?: string|null, port?: int|null, transport?: string|null}  $input
     * @return array{ok: bool, message: string, warning?: string, needs_credentials?: bool}
     */
    public function assign(NetworkDevice $device, string $station, string $role, array $input = []): array
    {
        // Phase 1: a gate code (the old "entrance"/"exit" mean Gate 1 / Gate 2).
        $station = Gate::resolveCode($station) ?? abort(422);
        abort_unless(in_array($role, [DeviceAssignment::ROLE_CAMERA, DeviceAssignment::ROLE_READER], true), 422);

        $result = $role === DeviceAssignment::ROLE_CAMERA
            ? $this->assignCamera($device, $station, $input)
            : $this->assignReader($device, $station, $input);

        if ($result['ok']) {
            $device->forceFill(['is_new' => false])->save();
            $this->syncAssignments(force: true);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, message: string, warning?: string, needs_credentials?: bool}
     */
    protected function assignCamera(NetworkDevice $device, string $station, array $input): array
    {
        $camera = Camera::query()->forRole($station)->firstOrFail();
        // Live-latency work: the sub stream is the default live/detection stream;
        // the main stream is used for full-resolution snapshots on a trigger.
        $stream = ($input['stream'] ?? 'sub') === 'main' ? 'main' : 'sub';
        $typed = filled($input['username'] ?? null);

        // Try the login typed now, else the one saved for this station, else the other station's.
        $logins = $typed
            ? [[(string) $input['username'], (string) ($input['password'] ?? '')]]
            : $this->savedLogins($station);

        $resolved = null;
        $lastProblem = null;

        foreach ($logins ?: [['', '']] as [$username, $password]) {
            $attempt = $this->resolveCameraStream($device, $stream, $username, $password);

            if ($attempt['result'] === CameraProbeService::OK || $attempt['result'] === CameraProbeService::UNREACHABLE) {
                $resolved = $attempt + ['username' => $username, 'password' => $password];
                break;
            }

            $lastProblem = $attempt;
        }

        if ($resolved === null) {
            if (($lastProblem['result'] ?? null) === CameraProbeService::UNAUTHORIZED) {
                return [
                    'ok' => false,
                    'needs_credentials' => true,
                    'message' => $typed
                        ? 'The camera rejected this username or password. Check them and try again.'
                        : 'Enter the camera username and password once. They are saved encrypted.',
                ];
            }

            return ['ok' => false, 'message' => $lastProblem['message'] ?? 'The camera stream could not be found.'];
        }

        DeviceAssignment::query()->updateOrCreate(
            ['station' => $station, 'role' => DeviceAssignment::ROLE_CAMERA],
            ['network_device_id' => $device->id, 'options' => [
                'stream' => $stream,
                'rtsp_port' => $resolved['port'],
                'path' => $resolved['path'],
                'snapshots' => (bool) ($input['snapshots'] ?? true),
                'snapshot_path' => $this->vendorPaths($device->cameraDetails())['main'] ?? null,
                'paths' => $this->vendorPaths($device->cameraDetails()),
            ]]
        );

        $camera->fill([
            'source_type' => 'rtsp',
            'source_value' => CameraSource::rtspUrl((string) $device->ip, $resolved['port'], $resolved['path']),
        ]);

        if ($resolved['username'] !== '') {
            $camera->source_username = $resolved['username'];
            $camera->source_password = $resolved['password'];
        }

        $camera->save();

        $label = Gate::labelFor($station);

        if ($resolved['result'] === CameraProbeService::UNREACHABLE) {
            return [
                'ok' => true,
                'message' => "{$label} camera assigned.",
                'warning' => 'The camera did not answer yet, so its login was not checked. The stream starts when it is reachable.',
            ];
        }

        return ['ok' => true, 'message' => "{$label} camera assigned. The live view connects in a few seconds."];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    protected function savedLogins(string $station): array
    {
        return Camera::query()
            ->whereIn('camera_role', DeviceAssignment::stations())
            ->get()
            ->sortBy(fn (Camera $camera): int => $camera->camera_role === $station ? 0 : 1)
            ->filter(fn (Camera $camera): bool => filled($camera->source_username))
            ->map(fn (Camera $camera): array => [(string) $camera->source_username, (string) ($camera->source_password ?? '')])
            ->unique(fn (array $login): string => implode("\0", $login))
            ->values()
            ->all();
    }

    /**
     * Find the stream path/port: ONVIF first, then the vendor paths (checked by RTSP DESCRIBE).
     *
     * @return array{result: string, message: string, port: int|null, path: string}
     */
    protected function resolveCameraStream(NetworkDevice $device, string $stream, string $username, string $password): array
    {
        $details = $device->cameraDetails();
        $ip = (string) $device->ip;
        $port = isset($details['rtsp_port']) ? (int) $details['rtsp_port'] : $this->profileRtspPort();
        $paths = $this->vendorPaths($details);
        $fallbackPath = $paths[$stream] ?? reset($paths) ?: '/';

        if (! $device->isReachable() || $device->status === NetworkDevice::STATUS_OFFLINE) {
            return ['result' => CameraProbeService::UNREACHABLE, 'message' => 'Camera not reachable.', 'port' => $port, 'path' => $fallbackPath];
        }

        if (filled($details['onvif_xaddr'] ?? null)) {
            $xaddr = $this->withHost((string) $details['onvif_xaddr'], $ip);
            $onvif = $this->cameraProbe->onvifStreams($xaddr, $username, $password);

            if ($onvif['result'] === CameraProbeService::UNAUTHORIZED) {
                return ['result' => CameraProbeService::UNAUTHORIZED, 'message' => $onvif['message'], 'port' => $port, 'path' => $fallbackPath];
            }

            if ($onvif['result'] === CameraProbeService::OK) {
                $chosen = $onvif['streams'][$stream === 'sub' ? min(1, count($onvif['streams']) - 1) : 0];
                $parts = parse_url($chosen['uri']) ?: [];
                $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
                $port = isset($parts['port']) ? (int) $parts['port'] : $port;
                $check = $this->cameraProbe->describe(CameraSource::rtspUrl($ip, $port, $path), $username, $password);

                return ['result' => $check['result'] === CameraProbeService::NOT_FOUND ? CameraProbeService::OK : $check['result'],
                    'message' => $check['message'], 'port' => $port, 'path' => $path];
            }
        }

        // No ONVIF: try the vendor's paths for the chosen stream first.
        $candidates = array_values(array_unique(array_filter([$paths[$stream] ?? null, ...array_values($paths)])));
        $last = ['result' => CameraProbeService::NOT_FOUND, 'message' => 'The camera has no stream at the known paths.'];

        foreach ($candidates ?: ['/'] as $path) {
            $check = $this->cameraProbe->describe(CameraSource::rtspUrl($ip, $port, $path), $username, $password);

            if (in_array($check['result'], [CameraProbeService::OK, CameraProbeService::UNAUTHORIZED, CameraProbeService::UNREACHABLE], true)) {
                return ['result' => $check['result'], 'message' => $check['message'], 'port' => $port, 'path' => $path];
            }

            $last = $check;
        }

        return ['result' => $last['result'], 'message' => $last['message'], 'port' => $port, 'path' => $fallbackPath];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, string>
     */
    protected function vendorPaths(array $details): array
    {
        if (! empty($details['rtsp_paths']) && is_array($details['rtsp_paths'])) {
            return $details['rtsp_paths'];
        }

        $vendors = (array) data_get(DeviceFiles::profiles(), 'camera.vendors', []);
        $profile = collect($vendors)->firstWhere('name', $details['vendor_profile'] ?? null)
            ?? collect($vendors)->first(fn (array $vendor): bool => empty($vendor['match']));

        return (array) ($profile['rtsp_paths'] ?? []);
    }

    protected function profileRtspPort(): ?int
    {
        $port = data_get(DeviceFiles::profiles(), 'camera.rtsp_ports.0');

        return $port ? (int) $port : null;
    }

    protected function withHost(string $url, string $ip): string
    {
        return (string) preg_replace('#^(https?://)(\[[^\]]+\]|[^/:]+)#i', '${1}'.$ip, $url);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, message: string, warning?: string}
     */
    protected function assignReader(NetworkDevice $device, string $station, array $input): array
    {
        $reader = $device->readerDetails();
        $port = (int) ($input['port'] ?? 0) ?: (int) ($reader['port'] ?? 0);
        $transport = in_array($input['transport'] ?? null, ['tcp', 'udp'], true) ? $input['transport'] : ($reader['transport'] ?? 'tcp');

        if ($port <= 0) {
            $open = (array) data_get($device->details, 'open_ports.tcp', []);
            $port = (int) (collect($open)->intersect((array) data_get(DeviceFiles::profiles(), 'uhf_reader.tcp_ports', []))->first() ?? 0);
        }

        $clientMode = ($reader['work_mode'] ?? null) === 'client';

        if ($port <= 0 && ! $clientMode) {
            return ['ok' => false, 'message' => 'No reader port was found on this device yet. Hold a tag near the reader and press Scan again.'];
        }

        DeviceAssignment::query()->updateOrCreate(
            ['station' => $station, 'role' => DeviceAssignment::ROLE_READER],
            ['network_device_id' => $device->id, 'options' => [
                'port' => $port ?: null,
                'transport' => $transport,
                'protocol' => $reader['protocol'] ?? null,
                'work_mode' => $reader['work_mode'] ?? null,
            ]]
        );

        $gate = Gate::query()->where('code', $station)->firstOrFail();
        $gate->forceFill([
            'reader_type' => 'uhf_ethernet',
            'reader_manual' => false,
            'reader_name' => $gate->name.' UHF Reader',
        ])->save();

        $result = ['ok' => true, 'message' => $gate->name.' reader assigned. Tags read at this reader are logged at '.$gate->name.'.'];

        if (($reader['confirmed'] ?? false) !== true) {
            $result['warning'] = 'This reader is not confirmed yet. Hold a tag near it: the first tag confirms the data format.';
        }

        return $result;
    }

    /**
     * Settings › Cameras: which stream feeds the live view/detection, and
     * whether full-resolution snapshots come from the main stream.
     */
    public function updateCameraStreams(string $station, string $stream, bool $snapshots): void
    {
        $station = Gate::normalizeCode($station);
        $assignment = DeviceAssignment::query()->with('device')
            ->where('station', $station)->where('role', DeviceAssignment::ROLE_CAMERA)->first();

        if (! $assignment?->device) {
            return;
        }

        $options = (array) $assignment->options;
        $paths = (array) ($options['paths'] ?? $this->vendorPaths($assignment->device->cameraDetails()));
        $stream = $stream === 'main' ? 'main' : 'sub';

        $assignment->options = [
            ...$options,
            'stream' => $stream,
            'path' => $paths[$stream] ?? ($options['path'] ?? '/'),
            'snapshots' => $snapshots,
            'snapshot_path' => $paths['main'] ?? ($options['snapshot_path'] ?? null),
            'paths' => $paths,
        ];
        $assignment->save();

        $this->syncAssignments(force: true);
    }

    public function unassign(string $station, string $role): void
    {
        $station = Gate::normalizeCode($station);
        DeviceAssignment::query()->where('station', $station)->where('role', $role)->delete();

        $gate = Gate::query()->where('code', $station)->first();
        if ($role === DeviceAssignment::ROLE_READER && $gate && ! $gate->reader_manual) {
            // Phase 3: still a UHF gate, waiting for another reader.
            $gate->forceFill(['reader_type' => 'uhf_ethernet', 'reader_name' => $gate->name.' UHF Reader'])->save();
        }

        // An unassigned camera keeps its last address as a manual source
        // (Settings › Cameras › Advanced) until another one is assigned.
        $this->syncAssignments(force: true);
    }

    /**
     * Follow assigned devices to their current IP and refresh both runtime files.
     */
    public function syncAssignments(bool $force = false): void
    {
        $cameraChanged = false;

        DeviceAssignment::query()
            ->with('device')
            ->where('role', DeviceAssignment::ROLE_CAMERA)
            ->get()
            ->each(function (DeviceAssignment $assignment) use (&$cameraChanged): void {
                $device = $assignment->device;
                $camera = Camera::query()->forRole($assignment->station)->first();

                if (! $device || ! $camera || blank($device->ip) || ! $device->isReachable()) {
                    return;
                }

                $options = (array) $assignment->options;
                $url = CameraSource::rtspUrl((string) $device->ip, $options['rtsp_port'] ?? null, $options['path'] ?? '/');
                // Full-resolution main stream for trigger snapshots, only when the
                // live stream is not already the main stream.
                $snapshot = ($options['snapshots'] ?? false) && filled($options['snapshot_path'] ?? null)
                    && ($options['snapshot_path'] ?? null) !== ($options['path'] ?? null)
                    ? CameraSource::rtspUrl((string) $device->ip, $options['rtsp_port'] ?? null, $options['snapshot_path'])
                    : null;

                if ($camera->source_type !== 'rtsp' || $camera->source_value !== $url || $camera->snapshot_source_value !== $snapshot) {
                    $camera->forceFill(['source_type' => 'rtsp', 'source_value' => $url, 'snapshot_source_value' => $snapshot])->save();
                    $cameraChanged = true;
                }
            });

        if ($cameraChanged || $force) {
            // The detector sees the new source and reconnects on its own. If it
            // is not running (or paused after failed starts), start it now.
            $this->settingsService->exportCameraRuntimeConfig();

            try {
                app(DetectorRuntimeService::class)->ensureRunning(force: true);
            } catch (\Throwable) {
                // Assignment is saved either way; the page check retries the start.
            }
        }

        $this->exportRuntimeConfig();
    }

    // ------------------------------------------------------------------
    // Python runtime config
    // ------------------------------------------------------------------

    /**
     * "Identify reader": the device service listens to every candidate device
     * for tag data while someone holds a UHF tag near the reader.
     */
    public function requestIdentify(int $seconds): string
    {
        $id = (string) Str::uuid();
        $this->exportRuntimeConfig(null, ['id' => $id, 'seconds' => $seconds, 'requested_at' => now()->toIso8601String()]);

        return $id;
    }

    /**
     * "Find my reader": baseline, then watch for a device that appears.
     */
    public function requestFind(int $seconds): string
    {
        $id = (string) Str::uuid();
        $this->exportRuntimeConfig(null, null, ['id' => $id, 'seconds' => $seconds, 'requested_at' => now()->toIso8601String()]);

        return $id;
    }

    public function requestScan(): string
    {
        $id = (string) Str::uuid();
        $this->exportRuntimeConfig(['id' => $id, 'requested_at' => now()->toIso8601String()]);

        return $id;
    }

    /**
     * @param  array<string, string>|null  $scanRequest
     */
    public function exportRuntimeConfig(?array $scanRequest = null, ?array $identifyRequest = null, ?array $findRequest = null): void
    {
        $path = DeviceFiles::runtimeConfigPath();
        $current = is_file($path) ? (array) json_decode((string) File::get($path), true) : [];
        $baseUrl = $this->settingsService->integrationUrl();
        $settings = $this->settingsService->all();

        $payload = [
            'app' => [
                'devices_url' => $baseUrl.'/api/v1/integration/devices',
                'rfid_ingest_url' => $baseUrl.'/api/v1/integration/rfid-scans',
                'api_key' => $this->settingsService->detectorApiKey(),
            ],
            'scan_request' => $scanRequest ?? ($current['scan_request'] ?? null),
            'identify_request' => $identifyRequest ?? ($current['identify_request'] ?? null),
            'find_request' => $findRequest ?? ($current['find_request'] ?? null),
            // Phase 1: one entry per gate (key = gate code).
            'stations' => Gate::ordered()->mapWithKeys(fn (Gate $gate): array => [
                $gate->code => [
                    'label' => $gate->name,
                    'reader' => $this->readerTarget($gate, $settings),
                    'camera' => $this->cameraTarget($gate->code),
                ],
            ])->all(),
        ];

        // Rewrite only on a real change so Python does not reload for nothing.
        $withoutTime = Arr::except($current, ['generated_at']);
        if ($withoutTime == $payload) {
            return;
        }

        File::ensureDirectoryExists(dirname($path));
        $temporary = $path.'.'.getmypid().'.tmp';
        File::put($temporary, json_encode(['generated_at' => now()->toIso8601String()] + $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (! @rename($temporary, $path)) {
            File::put($path, (string) File::get($temporary));
            File::delete($temporary);
        }
    }

    /**
     * @param  array<string, string>  $settings
     * @return array<string, mixed>|null
     */
    protected function readerTarget(Gate $gate, array $settings): ?array
    {
        $station = $gate->code;
        $readerName = $gate->readerDisplayName();
        // Debounce in the reader link: one event per EPC within the RFID cooldown (Settings).
        $cooldown = max(0, (int) ($settings['rfid_cooldown_seconds'] ?? 60));

        // Settings › Advanced: manual address wins only when switched on.
        if ($gate->reader_manual && filled($gate->reader_ip) && filled($gate->reader_port)) {
            return [
                'source' => 'manual',
                'mac' => null,
                'ip' => $gate->reader_ip,
                'port' => (int) $gate->reader_port,
                'transport' => $gate->reader_transport ?: 'tcp',
                'protocol' => null,
                'work_mode' => null,
                'reader_name' => $readerName,
                'cooldown_seconds' => $cooldown,
            ];
        }

        $assignment = DeviceAssignment::query()->with('device')
            ->where('station', $station)->where('role', DeviceAssignment::ROLE_READER)->first();

        if (! $assignment?->device) {
            return null;
        }

        $options = (array) $assignment->options;
        $reader = $assignment->device->readerDetails();

        return [
            'source' => 'device',
            'device_id' => $assignment->device->id,
            'mac' => $assignment->device->mac,
            'ip' => $assignment->device->ip,
            'port' => $options['port'] ?? ($reader['port'] ?? null),
            'transport' => $options['transport'] ?? ($reader['transport'] ?? 'tcp'),
            // A protocol learned later (first real tag) replaces the one saved at assignment.
            'protocol' => $reader['protocol'] ?? ($options['protocol'] ?? null),
            'work_mode' => $reader['work_mode'] ?? ($options['work_mode'] ?? null),
            'reader_name' => $readerName,
            'cooldown_seconds' => $cooldown,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function cameraTarget(string $station): ?array
    {
        $assignment = DeviceAssignment::query()->with('device')
            ->where('station', $station)->where('role', DeviceAssignment::ROLE_CAMERA)->first();

        return $assignment?->device
            ? ['device_id' => $assignment->device->id, 'mac' => $assignment->device->mac, 'ip' => $assignment->device->ip]
            : null;
    }

    // ------------------------------------------------------------------
    // Settings › Devices payload
    // ------------------------------------------------------------------

    public function acknowledge(): void
    {
        NetworkDevice::query()->where('is_new', true)->update(['is_new' => false]);
    }

    /**
     * Everything the Devices panel shows (also polled as JSON).
     *
     * @return array<string, mixed>
     */
    public function panelPayload(): array
    {
        $status = $this->runtime->readStatus();
        $this->detectorStatus = null;
        $assignments = DeviceAssignment::query()->with('device')->get();
        $suggestions = collect((array) ($status['temporary_ip_suggestions'] ?? []))->keyBy('device_ip');
        $interfaces = (array) data_get($status, 'network.interfaces', []);
        // A reader the device service is connected to right now is online,
        // whatever the last scan said.
        $this->liveReaderIds = collect((array) ($status['readers'] ?? []))
            ->filter(fn ($link): bool => is_array($link) && ($link['state'] ?? null) === 'connected')
            ->map(fn (array $link): mixed => data_get($link, 'target.device_id'))
            ->filter()
            ->values()
            ->all();

        $devices = NetworkDevice::query()
            ->with('assignments')
            ->get()
            ->sortBy(fn (NetworkDevice $device): string => $this->sortKey($device))
            ->values()
            ->map(fn (NetworkDevice $device): array => $this->devicePayload($device, $suggestions, $interfaces));

        return [
            'service' => [
                'running' => (bool) ($status['service_running'] ?? false),
                'state' => $status['state'] ?? 'stopped',
                'message' => $status['message'] ?? '',
                'admin' => (bool) ($status['admin'] ?? false),
                'platform' => $status['platform'] ?? null,
            ],
            'network' => [
                'interfaces' => collect($interfaces)->map(fn (array $interface): array => Arr::only($interface, [
                    'name', 'label', 'kind', 'ip', 'prefix', 'network', 'gateway', 'link_local',
                ]))->values()->all(),
                'wired_connected' => (bool) data_get($status, 'network.wired_connected', false),
            ],
            'scan' => [
                'running' => (bool) data_get($status, 'scan.running', false),
                'progress' => data_get($status, 'scan.progress'),
                'trigger' => data_get($status, 'scan.trigger'),
                'last_finished_at' => data_get($status, 'scan.last.finished_at'),
                'last_finished_display' => DisplayTime::datetime(data_get($status, 'scan.last.finished_at'), 'Not scanned yet'),
                'error' => data_get($status, 'scan.error') ?: data_get($status, 'scan.post_error'),
            ],
            'diagnostics' => $this->diagnostics($status),
            'identify' => $this->identifyPayload($status),
            'find' => is_array($status['find'] ?? null) ? Arr::only($status['find'], [
                'running', 'started_at', 'finished_at', 'seconds', 'phase', 'phase_label', 'baseline_count', 'interfaces',
                'passive', 'new_devices', 'events', 'result', 'message', 'waiting_until', 'other_subnet',
            ]) : null,
            // Phase 1: one card per gate.
            'stations' => Gate::ordered()->mapWithKeys(fn (Gate $gate): array => [
                $gate->code => [
                    'label' => $gate->name,
                    'camera' => $this->assignmentPayload($assignments, $gate->code, DeviceAssignment::ROLE_CAMERA, $status),
                    'reader' => $this->assignmentPayload($assignments, $gate->code, DeviceAssignment::ROLE_READER, $status),
                    'manual_reader' => (bool) $gate->reader_manual,
                ],
            ])->all(),
            'devices' => $devices->all(),
            'counts' => [
                'cameras' => $devices->where('kind', NetworkDevice::KIND_CAMERA)->count(),
                'readers' => $devices->where('kind', NetworkDevice::KIND_READER)->count(),
                'other' => $devices->whereNotIn('kind', [NetworkDevice::KIND_CAMERA, NetworkDevice::KIND_READER])->count(),
                'new' => $devices->where('is_new', true)->count(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Why the last scan found what it found (see devices/diagnostics.py),
     * plus problems only Laravel can see (service stopped, results not received).
     *
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    protected function diagnostics(array $status): array
    {
        // The newest scan wins: the background service or `devices:scan`.
        $path = DeviceFiles::scanResultPath();
        $scan = is_file($path) ? (array) json_decode((string) File::get($path), true) : [];
        $diagnostics = (array) ($scan['diagnostics'] ?? $status['diagnostics'] ?? []);
        $warnings = array_values((array) ($diagnostics['warnings'] ?? []));

        if (! ($status['service_running'] ?? false)) {
            array_unshift($warnings, ['code' => 'service_stopped', 'level' => 'critical',
                'message' => 'The device service is not running, so nothing is scanned. It starts on its own within a minute; if not, run php artisan devices:start and check storage/logs/device-service.stdout.log.']);
        }

        if ($postError = data_get($status, 'scan.post_error')) {
            $warnings[] = ['code' => 'post_failed', 'level' => 'warning',
                'message' => 'Scan results could not be sent to this app: '.$postError];
        }

        return [
            'scanned_at' => data_get($scan, 'scan.finished_at'),
            'scanned_display' => DisplayTime::datetime(data_get($scan, 'scan.finished_at'), 'Not scanned yet'),
            'trigger' => data_get($scan, 'scan.trigger'),
            'duration' => data_get($scan, 'scan.duration_seconds'),
            'interfaces' => array_values((array) ($diagnostics['interfaces'] ?? [])),
            'local_network' => data_get($diagnostics, 'local_network.result', 'unknown'),
            'firewall' => (array) ($diagnostics['firewall'] ?? []),
            'admin' => (bool) ($diagnostics['admin'] ?? false),
            'onvif_replies' => (int) ($diagnostics['onvif_replies'] ?? 0),
            'module_replies' => (int) ($diagnostics['module_replies'] ?? 0),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>|null
     */
    protected function identifyPayload(array $status): ?array
    {
        $identify = $status['identify'] ?? null;

        if (! is_array($identify)) {
            return null;
        }

        return Arr::only($identify, [
            'running', 'started_at', 'finished_at', 'seconds', 'phase', 'message', 'candidates', 'open_ports', 'found', 'unknown_data',
        ]);
    }

    /** @var list<int> */
    protected array $liveReaderIds = [];

    /** @var array<string, mixed>|null Detector status, read once per payload. */
    protected ?array $detectorStatus = null;

    protected function effectiveStatus(NetworkDevice $device): string
    {
        return in_array($device->id, $this->liveReaderIds, true) ? NetworkDevice::STATUS_ONLINE : $device->status;
    }

    protected function sortKey(NetworkDevice $device): string
    {
        $kind = ['camera' => 0, 'rfid_reader' => 1, 'unknown' => 2, 'router' => 3][$device->kind] ?? 4;
        $status = ['online' => 0, 'unreachable' => 1, 'offline' => 2][$device->status] ?? 3;

        return $kind.$status.sprintf('%015s', (string) ip2long((string) $device->ip));
    }

    /**
     * @param  Collection<string, mixed>  $suggestions
     * @param  array<int, array<string, mixed>>  $interfaces
     * @return array<string, mixed>
     */
    protected function devicePayload(NetworkDevice $device, Collection $suggestions, array $interfaces): array
    {
        $details = (array) $device->details;
        $reader = $device->readerDetails();
        $camera = $device->cameraDetails();

        return [
            'id' => $device->id,
            'mac' => $device->mac,
            'ip' => $device->ip,
            'kind' => $device->kind,
            'kind_label' => $device->kindLabel(),
            'confidence' => $device->confidence,
            'status' => $this->effectiveStatus($device),
            'status_label' => ucfirst($this->effectiveStatus($device)),
            'brand' => $device->brand ?: $device->vendor,
            'vendor' => $device->vendor,
            'model' => $device->model,
            'name' => $device->name ?: ($device->brand ?: $device->vendor ?: 'Device'),
            'subnet' => $device->subnet,
            'is_new' => (bool) $device->is_new,
            'randomized_mac' => (bool) ($details['randomized_mac'] ?? false),
            'last_seen' => DisplayTime::datetime($device->last_seen_at, 'Never'),
            'ip_changed' => $device->ip_changed_at ? DisplayTime::datetime($device->ip_changed_at) : null,
            'assigned' => $device->assignments->map(fn (DeviceAssignment $assignment): array => [
                'station' => $assignment->station,
                'role' => $assignment->role,
                'stream' => data_get($assignment->options, 'stream'),
                'label' => Gate::labelFor($assignment->station).' '.($assignment->role === 'camera' ? 'camera' : 'reader')
                    .($assignment->role === 'camera' && data_get($assignment->options, 'stream') === 'sub' ? ' (sub)' : ''),
            ])->values()->all(),
            'camera' => $camera ? Arr::only($camera, ['rtsp_port', 'onvif_xaddr', 'vendor_profile']) + [
                'streams' => array_keys((array) ($camera['rtsp_paths'] ?? [])) ?: ['main', 'sub'],
            ] : null,
            'reader' => $reader ? Arr::only($reader, ['transport', 'port', 'protocol', 'work_mode', 'confirmed', 'confirmed_by', 'signature', 'sample_tags']) : null,
            'open_ports' => (array) data_get($details, 'open_ports.tcp', []),
            'network_warning' => $this->networkWarning($device),
            'guidance' => $device->status === NetworkDevice::STATUS_UNREACHABLE
                ? $this->unreachableGuidance($device, $suggestions->get($device->ip), $interfaces)
                : null,
            'assign_url' => route('settings.devices.assign', $device),
        ];
    }

    /**
     * The device answers only because this PC has an extra, manually added
     * address on its network (see scanner._extra_address): it stops working
     * after a restart and on the deployment PC. Explain how to fix it for good.
     *
     * @return array<string, mixed>|null
     */
    protected function networkWarning(NetworkDevice $device): ?array
    {
        $warning = data_get($device->details, 'network_warning');

        if (! is_array($warning) || empty($warning['network'])) {
            return null;
        }

        $what = $device->kind === NetworkDevice::KIND_CAMERA ? 'camera' : 'reader';
        $lan = $warning['lan_network'] ?? null;
        $steps = $lan
            ? [
                "Open the {$what}'s network settings (its web page or setup tool) and give it a free address in {$lan}"
                    .(filled($warning['lan_gateway'] ?? null) ? " with gateway {$warning['lan_gateway']}" : '')
                    .', or switch it to DHCP (automatic).',
                'Keep the same work mode and port, then press Scan again: the system finds it by its MAC address.',
            ]
            : [
                'This network card has no DHCP address (169.254.x.x): there is no router on this cable, or it is off.',
                "Connect the PC and the {$what} to the router, then give the {$what} an address in the router's network or switch it to DHCP.",
                'Keep the same work mode and port, then press Scan again: the system finds it by its MAC address.',
            ];

        return [
            'title' => 'Different network: works only through an extra address',
            'text' => "This {$what} ({$device->ip}) is on {$warning['network']}, not on this PC's LAN"
                .($lan ? " ({$lan})" : '').". It answers only because this PC has the extra address {$warning['pc_ip']} on "
                .($warning['interface_label'] ?? $warning['interface'] ?? 'its network card')
                .'. That address is lost after a restart and does not exist on another PC.',
            'steps' => $steps,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $suggestion
     * @param  array<int, array<string, mixed>>  $interfaces
     * @return array<string, mixed>
     */
    protected function unreachableGuidance(NetworkDevice $device, ?array $suggestion, array $interfaces): array
    {
        $networks = collect($interfaces)->pluck('network')->filter()->implode(', ') ?: 'none';
        $platform = PHP_OS_FAMILY === 'Windows' ? 'windows' : (PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux');

        return [
            'text' => "Found at {$device->ip}, but that is not on this PC's network ({$networks}). "
                .'Set the device to DHCP (automatic IP) in its own settings, then press Scan again.',
            'network' => $suggestion['network'] ?? null,
            'pc_ip' => $suggestion['pc_ip'] ?? null,
            'commands' => $suggestion['commands'][$platform] ?? null,
        ];
    }

    /**
     * @param  Collection<int, DeviceAssignment>  $assignments
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>|null
     */
    protected function assignmentPayload(Collection $assignments, string $station, string $role, array $status): ?array
    {
        $assignment = $assignments->first(fn (DeviceAssignment $item): bool => $item->station === $station && $item->role === $role);
        $device = $assignment?->device;

        if (! $device) {
            return null;
        }

        $payload = [
            'device_id' => $device->id,
            'name' => $device->name ?: ($device->brand ?: 'Device'),
            'ip' => $device->ip,
            'mac' => $device->mac,
            'status' => $this->effectiveStatus($device),
            'options' => (array) $assignment->options,
        ];

        if ($role === DeviceAssignment::ROLE_CAMERA) {
            // What the detector reports for this station's camera (live, or why not).
            $detector = $this->detectorStatus ??= app(DetectorRuntimeService::class)->readStatus();
            $camera = (array) data_get($detector, "cameras.$station", []);
            $payload['assign_url'] = route('settings.devices.assign', $device);
            $payload['detector_running'] = (bool) ($detector['service_running'] ?? false);
            $payload['camera_running'] = (bool) ($camera['camera_running'] ?? false);
            $payload['camera_error'] = ($camera['camera_running'] ?? false) ? null : ($camera['last_error'] ?? null);
            $payload['error_code'] = ($camera['camera_running'] ?? false) ? null : ($camera['error_code'] ?? null);
            // One physical camera used by both stations (testing with one camera).
            $other = $assignments->first(fn (DeviceAssignment $item): bool => $item->station !== $station
                && $item->role === DeviceAssignment::ROLE_CAMERA && $item->network_device_id === $device->id);
            $payload['shared_with'] = $other ? [
                'station' => $other->station,
                'stream' => data_get($other->options, 'stream', 'main'),
            ] : null;
        }

        if ($role === DeviceAssignment::ROLE_READER) {
            $link = (array) data_get($status, "readers.$station", []);
            $payload['link'] = Arr::only($link, [
                'state', 'protocol', 'work_mode', 'last_tag', 'last_tag_at', 'last_rssi', 'tags_read', 'events_sent',
                'unknown_frames', 'last_unknown_hex', 'last_error', 'transport', 'ip', 'port',
            ]);
            $payload['link']['last_tag_display'] = filled($link['last_tag_at'] ?? null) ? DisplayTime::datetime($link['last_tag_at']) : null;
            $payload['network_warning'] = $this->networkWarning($device);
        }

        return $payload;
    }
}
