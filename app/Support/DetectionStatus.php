<?php

namespace App\Support;

/**
 * A1 (detection): one line per gate: what its detection is doing, why, and
 * what to do next. The detector writes it (detection_status in
 * camera_status.json); this adds the cases the detector cannot report itself.
 */
final class DetectionStatus
{
    public const TONES = [
        'running' => 'success',
        'connecting' => 'info',
        'model_loading' => 'info',
        'no_zone' => 'warning',
        'stalled' => 'warning',
        'camera_offline' => 'critical',
        'model_error' => 'critical',
        'error' => 'critical',
        'detector_off' => 'critical',
    ];

    /**
     * @param  array<string, mixed>  $runtime  detector status (DetectorRuntimeService::readStatus)
     * @return array{code: string, label: string, message: string, next_step: string, retry_in: ?int, tone: string}
     */
    public static function forGate(array $runtime, string $gate): array
    {
        $camera = (array) ($runtime['cameras'][$gate] ?? []);
        $status = (array) ($camera['detection_status'] ?? []);

        if (! ($runtime['service_running'] ?? false)) {
            $status = [
                'code' => 'detector_off',
                'label' => 'Detector off',
                'message' => 'The detector is not running.',
                'next_step' => 'It starts by itself within a minute (or run php artisan detector:start).',
            ];
        } elseif (! isset($status['code'])) {
            // A detector from before A1 (no detection_status): derive it.
            $fps = $camera['detection']['detection_fps'] ?? null;
            $status = ($camera['camera_running'] ?? false)
                ? ($fps !== null
                    ? ['code' => 'running', 'label' => 'Running', 'message' => sprintf('Watching for vehicles (%.1f checks per second).', $fps)]
                    : ['code' => 'connecting', 'label' => 'Starting', 'message' => 'Detection is starting.'])
                : ['code' => 'camera_offline', 'label' => 'Camera offline', 'message' => PipelineReport::firstSentence((string) ($camera['last_error'] ?? '')) ?: 'The camera is not connected.', 'next_step' => "Check the camera's LAN cable and power."];
        }

        return [
            'code' => (string) $status['code'],
            'label' => (string) ($status['label'] ?? ucfirst(str_replace('_', ' ', (string) $status['code']))),
            'message' => (string) ($status['message'] ?? ''),
            'next_step' => (string) ($status['next_step'] ?? ''),
            'retry_in' => isset($status['retry_in']) ? (int) $status['retry_in'] : null,
            'tone' => self::TONES[$status['code']] ?? 'neutral',
        ];
    }
}
