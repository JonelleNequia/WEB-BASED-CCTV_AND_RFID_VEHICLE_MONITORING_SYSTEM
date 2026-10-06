<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\Gate;
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

        return [
            'name' => (string) ($config['camera_name'] ?? $gate->name.' Camera'),
            'source' => $assigned
                ? (string) $assigned['name']
                : match ($config['source_type'] ?? '') {
                    'webcam' => 'Webcam on this PC',
                    default => 'Added by hand (Advanced)',
                },
            'managed' => (bool) $assigned,
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

        if (! $camera || $camera->source_type === Camera::SOURCE_NONE) {
            return ['ok' => false, 'message' => 'This gate has no camera yet.'];
        }

        if ($camera->source_type !== 'rtsp') {
            $running = (bool) data_get(app(DetectorRuntimeService::class)->readStatus(), "cameras.$gate.camera_running", false);

            return $running
                ? ['ok' => true, 'message' => 'The camera is sending video.']
                : ['ok' => false, 'message' => 'No video from this camera yet.'];
        }

        $result = app(CameraProbeService::class)->describe(
            (string) $camera->source_value,
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
