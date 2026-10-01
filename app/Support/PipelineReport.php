<?php

namespace App\Support;

/**
 * Live-latency work: turns the detector's pipeline metrics into rows and a
 * plain-language "where does the time go" verdict for Settings › System Status.
 */
final class PipelineReport
{
    /**
     * @param  array<string, mixed>  $status  detector status (camera_status.json)
     * @param  array<string, int|float|string>  $performance  exported performance settings
     * @return array<string, mixed>
     */
    public static function build(array $status, array $performance): array
    {
        $cameras = [];
        $findings = [];
        $metrics = (array) ($status['metrics'] ?? []);
        $cpu = (array) ($status['cpu'] ?? []);
        $streamBudget = 1000 / max(1, (float) ($performance['stream_fps'] ?? 15));
        $detectBudget = 1000 / max(0.5, (float) ($performance['detection_fps'] ?? 8));

        // Phase 1: one entry per gate camera the detector reports.
        foreach (array_keys($metrics) as $role) {
            $data = (array) ($metrics[$role] ?? []);
            $fps = (array) ($data['fps'] ?? []);
            $ms = (array) ($data['ms'] ?? []);
            $values = (array) ($data['values'] ?? []);
            $avg = fn (string $key): ?float => isset($ms[$key]['avg']) ? (float) $ms[$key]['avg'] : null;
            $p95 = fn (string $key): ?float => isset($ms[$key]['p95']) ? (float) $ms[$key]['p95'] : null;

            if ($data === []) {
                continue;
            }

            $publishCost = ($avg('overlay') ?? 0) + ($avg('resize') ?? 0) + ($avg('encode') ?? 0);
            $backlog = isset($values['decode_backlog_ms']) ? (int) $values['decode_backlog_ms'] : null;
            $label = \App\Models\Gate::labelFor((string) $role);

            if ($backlog !== null && $backlog > 500) {
                $findings[] = "{$label}: decoding falls behind the camera by {$backlog} ms. Use the sub stream for the live view.";
            }
            if ($publishCost > $streamBudget) {
                $findings[] = sprintf('%s: making each live JPEG takes %.0f ms, more than the %.0f ms per frame budget. Lower the live view width or quality.', $label, $publishCost, $streamBudget);
            }
            if (($avg('yolo') ?? 0) > $detectBudget) {
                $findings[] = sprintf('%s: detection takes %.0f ms, so it cannot reach %s runs per second. Lower the detection input size or rate.', $label, $avg('yolo'), $performance['detection_fps'] ?? 8);
            }
            if (($values['decoder_threads'] ?? null) === 'auto') {
                $findings[] = "{$label}: the live stream is decoded with FFmpeg's frame threads, which hold frames back about 0.35 s. Use the sub stream.";
            }

            $cameras[$role] = [
                'label' => $label,
                'resolution' => $values['resolution'] ?? '—',
                'decoder' => ($values['decoder_threads'] ?? null) === 1 ? '1 thread (low delay)' : 'FFmpeg threads',
                'capture_fps' => $fps['capture'] ?? null,
                'published_fps' => $fps['stream_published'] ?? null,
                'sent_fps' => $fps['stream_sent'] ?? null,
                'detection_fps' => $fps['detection'] ?? null,
                'steps' => array_filter([
                    'Wait for camera frame' => [$avg('read'), $p95('read')],
                    'Overlay' => [$avg('overlay'), $p95('overlay')],
                    'Resize' => [$avg('resize'), $p95('resize')],
                    'JPEG encode' => [$avg('encode'), $p95('encode')],
                    'YOLO ('.($values['yolo_device'] ?? '—').', '.($values['yolo_input'] ?? '—').')' => [$avg('yolo'), $p95('yolo')],
                    'Save latest frame' => [$avg('save'), $p95('save')],
                ], fn (array $pair): bool => $pair[0] !== null),
                'backlog' => $backlog,
                'pipeline' => [$avg('pipeline'), $p95('pipeline')],
                'detection_age' => [$avg('detection_age'), $p95('detection_age')],
                'jpeg_kb' => $values['jpeg_kb'] ?? null,
                'hires' => isset($ms['hires_open'])
                    ? sprintf('%s, opened in %.1f s', $values['hires_resolution'] ?? '—', ($avg('hires_open') ?? 0) / 1000)
                    : (($values['hires_error'] ?? null) ?: 'Not used yet (opens when a vehicle is in the zone)'),
            ];
        }

        $cores = max(1, (int) ($cpu['cores'] ?? 1));
        if (isset($cpu['process']) && (float) $cpu['process'] > 85 * $cores) {
            $findings[] = 'The detector uses almost all CPU cores.';
        }

        if ($cameras !== [] && $findings === []) {
            $worst = collect($cameras)->map(fn (array $camera): float => (float) ($camera['pipeline'][0] ?? 0))->max();
            $findings[] = sprintf(
                'No bottleneck on this PC: each frame reaches the browser %.0f ms after it is decoded. Any delay left comes from the camera\'s own encoder (see the recommended camera settings).',
                $worst
            );
        }

        return [
            'cameras' => $cameras,
            'cpu' => $cpu,
            'findings' => $findings,
            'performance' => $performance,
        ];
    }
}
