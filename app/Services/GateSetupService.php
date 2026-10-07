<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\Gate;
use App\Models\NetworkDevice;
use App\Support\DetectionStatus;
use App\Support\DisplayTime;
use Illuminate\Support\Collection;

/**
 * B1 (Settings): what each gate card on Settings › Gates shows: its camera,
 * its RFID reader and its detection zone, each with a status dot, one line
 * and the next step. No addresses or technical values.
 */
class GateSetupService
{
    /**
     * @param  Collection<int, Gate>  $gates
     * @param  array<string, mixed>  $devices  DeviceRegistryService::panelPayload()
     * @param  array<string, array<string, mixed>>  $cameraConfigs  CalibrationService::cameraPayload()
     * @param  array<string, mixed>  $runtime  detector status with viewer stream URLs
     * @return list<array<string, mixed>>
     */
    public function cards(Collection $gates, array $devices, array $cameraConfigs, array $runtime): array
    {
        return $gates->map(fn (Gate $gate): array => [
            'code' => $gate->code,
            'name' => $gate->name,
            'active' => (bool) $gate->is_active,
            'kiosk_url' => route('gates.kiosk', $gate->code),
            // B2: the live view the add-camera wizard shows once the camera is added.
            'stream_url' => app(DetectorRuntimeService::class)->streamUrlForRole($gate->code, $runtime, request()->getHost()),
            'camera' => $this->camera($gate, $devices, $cameraConfigs[$gate->code] ?? [], $runtime),
            'reader' => $this->reader($gate, $devices),
            'zone' => $this->zone($gate, $cameraConfigs[$gate->code] ?? []),
        ])->values()->all();
    }

    /**
     * Null when the gate has no camera (Remove, or a new gate).
     *
     * @return array<string, mixed>|null
     */
    protected function camera(Gate $gate, array $devices, array $config, array $runtime): ?array
    {
        $assigned = data_get($devices, "stations.{$gate->code}.camera");

        if (! $assigned && ($config['source_type'] ?? 'none') === Camera::SOURCE_NONE) {
            return null;
        }

        $live = (bool) data_get($runtime, "cameras.{$gate->code}.camera_running", false) && ($runtime['service_running'] ?? false);
        [$state, $line, $nextStep] = $live ? ['online', 'Online', ''] : $this->cameraProblem($gate->code, $runtime);
        $streams = app(CameraStreams::class);
        $device = $streams->forGate($gate->code)['device'];
        if (! $live && $device && $this->onOtherNetwork($device)) {
            // Camera source work: found by MAC, but with a fixed address from another network.
            [$state, $line, $nextStep] = ['offline', 'Offline · The camera still has an address from another network.',
                "Set the camera to DHCP (automatic address) in its own settings; the system then finds it by itself."];
        }
        // "Manual at first": a hand-typed camera that the scan found can be switched to automatic.
        $camera = Camera::query()->forRole($gate->code)->first();
        $detected = ! $assigned && $camera ? $streams->detectedForManual($camera) : null;

        // Live view work: WebRTC plays H.264 only (checked while the camera is online).
        $codecs = $live ? app(Go2rtcService::class)->codecs($gate->code) : ['main' => null, 'sub' => null];
        $notH264 = array_filter($codecs, fn (?string $codec): bool => $codec !== null && $codec !== 'H264');

        return [
            'codec_warning' => $notH264 === [] ? null : [
                'line' => 'The camera sends '.implode(' / ', array_unique(array_map(fn (string $codec): string => str_replace('H26', 'H.26', $codec), $notH264))).' video; the full-quality live view needs H.264.',
                'next_step' => "Open the camera's own settings page › Video › Encoding, and choose H.264 for both streams.",
            ],
            'name' => (string) ($config['camera_name'] ?? $gate->name.' Camera'),
            'source' => $assigned ? (string) $assigned['name'] : 'Added by hand (Advanced)',
            'managed' => (bool) $assigned,
            'detected' => $detected ? ['id' => $detected->id, 'name' => app(DeviceRegistryService::class)->friendlyName($detected)] : null,
            'online' => $live,
            // B4: green / yellow (starting) / red, one plain line and the next step.
            'state' => $state,
            'line' => $line,
            'next_step' => $nextStep,
            'preview_url' => (string) data_get($runtime, "cameras.{$gate->code}.stream_url", ''),
            'has_login' => filled($config['source_username'] ?? null),
            'username' => (string) ($config['source_username'] ?? ''),
            'can_test' => in_array($config['source_type'] ?? '', ['rtsp'], true) || (bool) $assigned,
        ];
    }

    /**
     * The camera's last address is not in any of this PC's networks (a fixed
     * address from the previous router), or it is reached only through an
     * extra address on this PC.
     */
    protected function onOtherNetwork(NetworkDevice $device): bool
    {
        if (filled(data_get($device->details, 'network_warning.network'))) {
            return true;
        }

        $networks = collect((array) data_get(app(DeviceServiceRuntime::class)->readStatus(), 'network.interfaces', []))
            ->pluck('network')->filter()->all();
        if ($networks === [] || blank($device->ip) || ($ip = ip2long((string) $device->ip)) === false) {
            return false;
        }

        foreach ($networks as $network) {
            [$base, $prefix] = array_pad(explode('/', (string) $network), 2, '32');
            $mask = (int) $prefix === 0 ? 0 : (~0 << (32 - (int) $prefix)) & 0xFFFFFFFF;
            if ((ip2long($base) & $mask) === ($ip & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * "Use automatically": the hand-typed camera becomes the detected device
     * (known by its MAC) with its saved login; the URL is not used any more.
     *
     * @return array{ok: bool, message: string}
     */
    public function useDetectedCamera(string $gate): array
    {
        $camera = Camera::query()->forRole($gate)->firstOrFail();
        $device = app(CameraStreams::class)->detectedForManual($camera);
        if (! $device) {
            return ['ok' => false, 'message' => 'This camera was not found on the network.'];
        }

        $result = app(DeviceRegistryService::class)->assign($device, $gate, DeviceAssignment::ROLE_CAMERA, [
            'username' => $camera->source_username, 'password' => $camera->source_password,
        ]);

        return ['ok' => $result['ok'], 'message' => $result['ok'] ? 'The camera is now found automatically.' : $result['message']];
    }

    /**
     * B4: why a camera has no picture, in plain words (no RTSP codes or
     * network terms), and what to do: [state, line, next step].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function cameraProblem(string $gate, array $runtime): array
    {
        if (! ($runtime['service_running'] ?? false)) {
            return ['starting', 'Starting · The detection program is starting.', 'Wait a minute; it starts by itself.'];
        }

        $status = DetectionStatus::forGate($runtime, $gate);
        if (in_array($status['code'], ['connecting', 'model_loading'], true)) {
            return ['starting', 'Connecting to the camera…', ''];
        }

        return match ((string) data_get($runtime, "cameras.$gate.error_code")) {
            'unauthorized' => ['offline', 'Offline · The camera rejected the login.', 'Open ⋯ › Change login and enter the camera\'s username and password.'],
            'not_found' => ['offline', 'Offline · The camera answers, but sends no video there.', 'Remove the camera and add it again.'],
            'no_frames' => ['offline', 'Offline · The camera sends no picture.', 'Wait a moment; if it stays, switch the camera off and on.'],
            'invalid_source' => ['offline', 'Offline · The camera is not set up correctly.', 'Remove the camera and add it again.'],
            default => ['offline', 'Offline · The camera does not answer.', "Check the camera's LAN cable and power."],
        };
    }

    /**
     * Null when the gate has no reader.
     *
     * @return array<string, mixed>|null
     */
    protected function reader(Gate $gate, array $devices): ?array
    {
        $assigned = data_get($devices, "stations.{$gate->code}.reader");
        $manual = (bool) $gate->reader_manual && filled($gate->reader_ip);

        if (! $assigned && ! $manual) {
            return null;
        }

        $serviceRunning = (bool) data_get($devices, 'service.running', false);
        $link = (array) ($assigned['link'] ?? []);
        $state = $link['state'] ?? null;
        $online = $serviceRunning && $state === 'connected';

        return [
            'name' => $gate->readerDisplayName(),
            'online' => $online,
            'state' => match (true) {
                $online => 'online',
                $serviceRunning && ($state === 'connecting' || $state === null) => 'starting',
                ! $serviceRunning => 'starting',
                default => 'offline',
            },
            'manual' => $manual && ! $assigned,
            'line' => match (true) {
                ! $serviceRunning => 'Starting · The device program is starting.',
                $online => 'Online',
                $state === 'connecting' || $state === null => 'Connecting…',
                default => 'Offline · The reader does not answer.',
            },
            'next_step' => match (true) {
                ! $serviceRunning => 'It starts by itself within a minute.',
                $online, $state === 'connecting', $state === null => '',
                default => "Check the reader's LAN cable and power.",
            },
            'last_tag' => filled($link['last_tag'] ?? null) ? '…'.substr((string) $link['last_tag'], -8) : null,
            'last_tag_time' => filled($link['last_tag_at'] ?? null) ? DisplayTime::time($link['last_tag_at']) : null,
            // RFID only with a vehicle: tags are recorded without the camera now.
            'rfid_only' => app(RfidIngestService::class)->rfidOnly($gate->code),
            // Phase 2: the reader is outside this PC's network (move it), or
            // reached through the temporary workaround.
            'network' => app(ReaderNetworkService::class)->state($gate),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function zone(Gate $gate, array $config): array
    {
        $mask = $config['calibration_mask'] ?? null;
        $line = $config['calibration_line'] ?? null;

        return [
            'ready' => ! empty($mask) && ! empty($line),
            'points' => collect((array) $mask)->map(fn ($point): string => round((float) data_get($point, 'x', 0), 4).','.round((float) data_get($point, 'y', 0), 4))->implode(' '),
            'line' => is_array($line) ? $line : null,
            'frame_url' => route('camera.frame', $gate->code),
            'setup_url' => route('settings.index', ['tab' => 'calibration', 'gate' => $gate->code]),
        ];
    }

    /**
     * B1: "Test" on a camera: ask it directly (the saved login is used).
     *
     * @return array{ok: bool, message: string}
     */
    public function testCamera(string $gate): array
    {
        $camera = Camera::query()->forRole($gate)->first();

        $source = app(CameraStreams::class)->forGate($gate);
        if (! $camera || $source['source'] === CameraStreams::SOURCE_NONE) {
            return ['ok' => false, 'message' => 'This gate has no camera yet.'];
        }

        if (! str_starts_with(strtolower((string) $source['live']), 'rtsp')) {
            $running = (bool) data_get(app(DetectorRuntimeService::class)->readStatus(), "cameras.$gate.camera_running", false);

            return $running
                ? ['ok' => true, 'message' => 'The camera is sending video.']
                : ['ok' => false, 'message' => 'No video from this camera yet.'];
        }

        $result = app(CameraProbeService::class)->describe(
            (string) $source['live'],
            (string) $camera->source_username,
            (string) $camera->source_password
        );

        return match ($result['result']) {
            CameraProbeService::OK => ['ok' => true, 'message' => 'The camera answers and the login is accepted.'],
            CameraProbeService::UNAUTHORIZED => ['ok' => false, 'message' => 'The camera rejected the login. Use "Change login".'],
            CameraProbeService::UNREACHABLE => ['ok' => false, 'message' => "The camera does not answer. Check its LAN cable and power."],
            CameraProbeService::NOT_FOUND => ['ok' => false, 'message' => 'The camera answers, but not at this video address. Add it again.'],
            default => ['ok' => false, 'message' => 'The camera answered with an error.'],
        };
    }

    /**
     * B1: "Remove" a camera: unassign it and leave the gate without one.
     */
    public function removeCamera(string $gate): void
    {
        if (DeviceAssignment::query()->where('station', $gate)->where('role', DeviceAssignment::ROLE_CAMERA)->exists()) {
            app(DeviceRegistryService::class)->unassign($gate, DeviceAssignment::ROLE_CAMERA);
        }

        Camera::query()->forRole($gate)->first()?->forceFill([
            'source_type' => Camera::SOURCE_NONE,
            'source_value' => '',
            'snapshot_source_value' => null,
        ])->save();
    }

    /**
     * B1: "Remove" a reader (assigned, or a manual address).
     */
    public function removeReader(string $gate): void
    {
        if (DeviceAssignment::query()->where('station', $gate)->where('role', DeviceAssignment::ROLE_READER)->exists()) {
            app(DeviceRegistryService::class)->unassign($gate, DeviceAssignment::ROLE_READER);
        }

        $model = Gate::query()->where('code', $gate)->first();
        if ($model?->reader_manual) {
            $model->forceFill(['reader_manual' => false])->save();
        }
    }
}
