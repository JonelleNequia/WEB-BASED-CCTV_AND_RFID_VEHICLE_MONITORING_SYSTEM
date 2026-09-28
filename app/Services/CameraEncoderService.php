<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\DeviceAssignment;

/**
 * Live-latency work: "Optimize camera settings" (Settings › Cameras).
 *
 * Reads the camera's H.264 encoder settings over ONVIF and proposes values
 * that suit live monitoring: 15 fps, an I-frame every second, and a bitrate
 * that fits the resolution. Nothing is written until the admin confirms.
 * Smart Coding (H.264+) is not reachable over ONVIF and stays a manual step.
 */
class CameraEncoderService
{
    public const TARGET_FPS = 15;

    public function __construct(protected CameraProbeService $probe)
    {
    }

    /**
     * @return array{ok: bool, message: string, encoders?: list<array<string, mixed>>}
     */
    public function preview(string $station): array
    {
        [$target, $error] = $this->target($station);

        if ($error) {
            return ['ok' => false, 'message' => $error];
        }

        $read = $this->probe->encoderConfigurations($target['xaddr'], $target['username'], $target['password']);

        if ($read['result'] !== CameraProbeService::OK) {
            return ['ok' => false, 'message' => $read['message']];
        }

        return [
            'ok' => true,
            'message' => 'Current and recommended camera settings.',
            'encoders' => array_values(array_map(fn (array $encoder): array => [
                'token' => $encoder['token'],
                'name' => $encoder['name'],
                'encoding' => $encoder['encoding'],
                'resolution' => $encoder['width'].'×'.$encoder['height'],
                'current' => ['fps' => $encoder['fps'], 'gov' => $encoder['gov'], 'bitrate' => $encoder['bitrate']],
                'proposed' => $this->proposal($encoder),
            ], array_filter($read['encoders'], fn (array $encoder): bool => strtoupper((string) $encoder['encoding']) === 'H264'))),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function optimize(string $station): array
    {
        [$target, $error] = $this->target($station);

        if ($error) {
            return ['ok' => false, 'message' => $error];
        }

        $read = $this->probe->encoderConfigurations($target['xaddr'], $target['username'], $target['password']);

        if ($read['result'] !== CameraProbeService::OK) {
            return ['ok' => false, 'message' => $read['message']];
        }

        $changed = [];
        foreach ($read['encoders'] as $encoder) {
            if (strtoupper((string) $encoder['encoding']) !== 'H264') {
                continue;
            }

            $proposal = $this->proposal($encoder);

            if ($proposal == ['fps' => $encoder['fps'], 'gov' => $encoder['gov'], 'bitrate' => $encoder['bitrate']]) {
                continue;
            }

            if (! $this->probe->setEncoderConfiguration($read['media_url'], $encoder, $proposal, $target['username'], $target['password'])) {
                return ['ok' => false, 'message' => "The camera refused the new settings for stream \"{$encoder['name']}\". Nothing else was changed after it."];
            }

            $changed[] = $encoder['name'];
        }

        return [
            'ok' => true,
            'message' => $changed === []
                ? 'The camera already uses the recommended settings.'
                : 'Camera updated ('.implode(', ', $changed).'). The live view reconnects in a few seconds.',
        ];
    }

    /**
     * @param  array<string, mixed>  $encoder
     * @return array{fps: int, gov: int, bitrate: int}
     */
    public function proposal(array $encoder): array
    {
        $fps = min(max(1, (int) $encoder['fps']), self::TARGET_FPS);
        $width = (int) $encoder['width'];
        $bitrate = match (true) {
            $width >= 1920 => 3072,
            $width >= 1280 => 2048,
            default => 768,
        };

        // I-frame every second: the live view starts fast and recovers quickly.
        return ['fps' => $fps, 'gov' => $fps, 'bitrate' => $bitrate];
    }

    /**
     * @return array{0: array{xaddr: string, username: string, password: string}|null, 1: string|null}
     */
    protected function target(string $station): array
    {
        $assignment = DeviceAssignment::query()->with('device')
            ->where('station', $station)->where('role', DeviceAssignment::ROLE_CAMERA)->first();
        $device = $assignment?->device;
        $xaddr = (string) data_get($device?->cameraDetails(), 'onvif_xaddr', '');

        if (! $device || $xaddr === '') {
            return [null, 'This camera was not found over ONVIF, so its settings cannot be changed from here. Use the camera\'s own web page.'];
        }

        $camera = Camera::query()->forRole($station)->first();

        return [[
            'xaddr' => (string) preg_replace('#^(https?://)(\[[^\]]+\]|[^/:]+)#i', '${1}'.$device->ip, $xaddr),
            'username' => (string) ($camera?->source_username ?? ''),
            'password' => (string) ($camera?->source_password ?? ''),
        ], null];
    }
}
