<?php

namespace App\Support;

/**
 * Live-latency work: turns the detector's pipeline metrics into rows and a
 * plain-language "where does the time go" verdict for Settings › System Status.
 *
 * UI Phase 3: one state per gate, OK / Delayed / Offline with a short
 * reason, so the page never says "no bottleneck" next to a 5 s delay.
 */
final class PipelineReport
{
    /**
     * @param  array<string, mixed>  $status  detector status (camera_status.json)
     * @param  array<string, int|float|string>  $performance  exported performance settings
     * @return array<string, mixed>
     */
    /** Frames older than this when they reach the browser = Delayed. */
    public const DELAYED_AFTER_MS = 1000;

    public static function build(array $status, array $performance): array
    {
        $cameras = [];
        $findings = [];
        $gateFindings = [];
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
                $findings[] = $gateFindings[$role][] = "{$label}: decoding falls behind the camera by {$backlog} ms. Use the sub stream for the live view.";
            }
            if ($publishCost > $streamBudget) {
                $findings[] = $gateFindings[$role][] = sprintf('%s: making each live JPEG takes %.0f ms, more than the %.0f ms per frame budget. Lower the live view width or quality.', $label, $publishCost, $streamBudget);
            }
            if (($avg('yolo') ?? 0) > $detectBudget) {
                $findings[] = $gateFindings[$role][] = sprintf('%s: detection takes %.0f ms, so it cannot reach %s runs per second. Lower the detection input size or rate.', $label, $avg('yolo'), $performance['detection_fps'] ?? 8);
            }
            if (($values['decoder_threads'] ?? null) === 'auto') {
                $findings[] = $gateFindings[$role][] = "{$label}: the live stream is decoded with FFmpeg's frame threads, which hold frames back about 0.35 s. Use the sub stream.";
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

        $gates = self::gateStates($status, $cameras, $gateFindings);

        // Only when every gate is OK (it used to say this next to a 5 s delay).
        if ($cameras !== [] && $findings === [] && collect($gates)->every(fn (array $gate): bool => $gate['state'] === 'ok')) {
            $findings[] = 'No delay on this PC. Any delay left comes from the camera\'s own encoder (see the recommended camera settings).';
        }

        return [
            'gates' => $gates,
            'cameras' => $cameras,
            'cpu' => $cpu,
            'findings' => $findings,
            'performance' => $performance,
        ];
    }

    /**
     * OK / Delayed / Offline per gate, with one short reason.
     *
     * @param  array<string, mixed>  $status
     * @param  array<string, array<string, mixed>>  $cameras
     * @param  array<string, list<string>>  $gateFindings
     * @return array<string, array{label: string, state: string, state_label: string, tone: string, reason: string}>
     */
    protected static function gateStates(array $status, array $cameras, array $gateFindings): array
    {
        $gates = [];

        foreach (\App\Models\Gate::options() as $code => $name) {
            $camera = (array) ($status['cameras'][$code] ?? []);
            $pipeline = $cameras[$code]['pipeline'][0] ?? null;
            $backlog = $cameras[$code]['backlog'] ?? null;

            [$state, $reason] = match (true) {
                ! ($status['service_running'] ?? false) => ['offline', 'The detector is not running.'],
                ! ($camera['camera_running'] ?? false) => ['offline', self::short((string) ($camera['last_error'] ?? '')) ?: 'The camera is not connected.'],
                ($gateFindings[$code] ?? []) !== [] => ['delayed', self::short(preg_replace('/^[^:]+:\s*/', '', $gateFindings[$code][0]) ?? '')],
                $pipeline !== null && $pipeline > self::DELAYED_AFTER_MS => ['delayed', sprintf('The live view is %.1f s behind.', $pipeline / 1000)],
                $pipeline !== null => ['ok', sprintf('Live view about %.0f ms behind.', $pipeline)],
                default => ['ok', 'Running.'],
            };

            $gates[$code] = [
                'label' => $name,
                // A1 (detection): what detection is doing at this gate, and why.
                'detection' => DetectionStatus::forGate($status, $code),
                'state' => $state,
                'state_label' => ['ok' => 'OK', 'delayed' => 'Delayed', 'offline' => 'Offline'][$state],
                'tone' => ['ok' => 'success', 'delayed' => 'warning', 'offline' => 'critical'][$state],
                'reason' => $reason,
            ];
        }

        return $gates;
    }

    public static function firstSentence(string $text): string
    {
        return self::short($text);
    }

    /** First sentence, at most 140 characters. */
    protected static function short(string $text): string
    {
        $text = trim(preg_split('/(?<=\.)\s/', trim($text))[0] ?? '');

        return mb_strlen($text) > 140 ? mb_substr($text, 0, 137).'…' : $text;
    }
}
