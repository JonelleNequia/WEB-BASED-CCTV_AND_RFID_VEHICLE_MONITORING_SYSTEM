<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\Gate;
use App\Support\BackgroundProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Live view work: go2rtc passes each gate camera's MAIN stream to the
 * browser over WebRTC without re-encoding (full resolution and frame rate).
 *
 * - The bundled binary (tools/go2rtc, checked by SHA-256) is unpacked into
 *   storage/app/go2rtc on first start: no download, works offline.
 * - Its config is written from the gates' camera assignments (address and
 *   login) and rewritten when they change; the user never edits a file.
 * - Its API listens on 127.0.0.1 only: pages talk to it through signed-in
 *   Laravel routes (LiveViewController). The video uses the WebRTC port.
 */
class Go2rtcService
{
    protected const LAUNCH_COOLDOWN_SECONDS = 20;

    public function directory(): string
    {
        return storage_path('app/go2rtc');
    }

    public function configPath(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'go2rtc.yaml';
    }

    public function logPath(): string
    {
        return storage_path('logs/go2rtc.log');
    }

    public function apiUrl(string $path = ''): string
    {
        return 'http://127.0.0.1:'.config('monitoring.live.api_port').'/api'.$path;
    }

    public function enabled(): bool
    {
        return (bool) config('monitoring.live.enabled') && $this->platform() !== null;
    }

    /** The bundle for this PC: mac_arm64, mac_amd64 or win64 (null: none, e.g. Linux). */
    public function platform(): ?string
    {
        $arm = in_array(strtolower(php_uname('m')), ['arm64', 'aarch64'], true);

        return match (PHP_OS_FAMILY) {
            'Darwin' => $arm ? 'mac_arm64' : 'mac_amd64',
            'Windows' => 'win64',
            default => null,
        };
    }

    public function binaryPath(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'go2rtc.exe' : 'go2rtc');
    }

    /**
     * Unpack the bundled binary once (after checking its SHA-256).
     */
    public function installBinary(): string
    {
        $binary = $this->binaryPath();
        $version = (string) config('monitoring.live.version');
        $marker = dirname($binary).DIRECTORY_SEPARATOR.'VERSION';

        if (is_file($binary) && @file_get_contents($marker) === $version) {
            return $binary;
        }

        $bundle = config('monitoring.live.bundles.'.$this->platform()) ?? throw new RuntimeException('No go2rtc bundle for this PC.');
        $zip = base_path('tools/go2rtc/'.$bundle['file']);

        if (! is_file($zip) || hash_file('sha256', $zip) !== $bundle['sha256']) {
            throw new RuntimeException('The bundled go2rtc file is missing or changed: '.$bundle['file']);
        }

        File::ensureDirectoryExists(dirname($binary));
        $archive = new ZipArchive;
        if ($archive->open($zip) !== true || ! $archive->extractTo(dirname($binary))) {
            throw new RuntimeException('The bundled go2rtc file could not be unpacked.');
        }
        $archive->close();
        @chmod($binary, 0755);
        File::put($marker, $version);

        return $binary;
    }

    /**
     * Each gate's live source: the camera's main stream (full resolution).
     *
     * @return array<string, string> gate code => rtsp URL with the login
     */
    public function streams(): array
    {
        $streams = [];
        $cameraStreams = app(CameraStreams::class);

        foreach (Gate::codes() as $gate) {
            // Camera source work: the main stream, built from the camera's
            // current address (or the hand-typed full-resolution source).
            $source = $cameraStreams->forGate($gate);
            $camera = Camera::query()->forRole($gate)->first();
            if (! $camera || blank($source['main']) || ! str_starts_with(strtolower((string) $source['main']), 'rtsp')) {
                continue;  // no camera, or a non-RTSP URL source: the MJPEG view is used
            }

            $streams[$gate] = $this->withLogin((string) $source['main'], (string) $camera->source_username, (string) $camera->source_password);
        }

        return $streams;
    }

    protected function withLogin(string $url, string $username, string $password): string
    {
        $parts = parse_url($url);
        if (! $parts || blank($username) || isset($parts['user'])) {
            return $url;
        }

        $login = rawurlencode($username).($password !== '' ? ':'.rawurlencode($password) : '').'@';

        return preg_replace('#^(rtsps?://)#i', '$1'.$login, $url) ?? $url;
    }

    /**
     * go2rtc.yaml from the gates; true when it changed.
     */
    public function writeConfig(): bool
    {
        $lines = [
            '# Written by the vehicle monitoring system (Go2rtcService). Do not edit: it is rewritten',
            '# when a camera is added, removed or gets a new address or login.',
            'api:',
            '  listen: "127.0.0.1:'.config('monitoring.live.api_port').'"',
            'rtsp:',
            '  listen: ""',
            'webrtc:',
            '  listen: ":'.config('monitoring.live.webrtc_port').'"',
            'log:',
            '  level: warn',
            'streams:',
        ];
        foreach ($this->streams() as $gate => $url) {
            $lines[] = '  '.$gate.': '.json_encode($url, JSON_UNESCAPED_SLASHES);
        }
        $config = implode("\n", $lines)."\n";

        File::ensureDirectoryExists($this->directory());
        if (is_file($this->configPath()) && File::get($this->configPath()) === $config) {
            return false;
        }

        File::put($this->configPath(), $config);
        @chmod($this->configPath(), 0600);  // holds camera logins

        return true;
    }

    public function isRunning(): bool
    {
        try {
            return Http::timeout(1)->get($this->apiUrl())->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Start go2rtc (or restart it after a config change). Called by the
     * scheduler every minute, when a camera changes and when a page opens.
     */
    public function ensureRunning(bool $force = false): bool
    {
        if (! $this->enabled() || app()->runningUnitTests()) {
            return false;
        }

        try {
            $changed = $this->writeConfig();
            $running = $this->isRunning();

            if ($running && ! $changed) {
                return true;
            }
            if ($running && $changed) {
                // New camera address or login: go2rtc reads its config again.
                try {
                    Http::timeout(2)->post($this->apiUrl('/restart'));

                    return true;
                } catch (Throwable) {
                    BackgroundProcess::stop($this->pidPath());
                }
            }
            if (! $force && ! Cache::add('go2rtc-launch', true, self::LAUNCH_COOLDOWN_SECONDS)) {
                return false;
            }

            $binary = $this->installBinary();

            return BackgroundProcess::launch($binary, ['-config', $this->configPath()], $this->directory(), $this->logPath(), $this->pidPath());
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function pidPath(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'go2rtc.pid';
    }

    /**
     * Live view work: the video codec of the gate camera's main (live view)
     * and sub (detection) streams, read from the camera's RTSP answer.
     * WebRTC plays H264 only. Cached 10 minutes.
     *
     * @return array{main: ?string, sub: ?string}
     */
    public function codecs(string $gate): array
    {
        return Cache::remember("live-codecs.$gate", now()->addMinutes(10), function () use ($gate): array {
            $camera = Camera::query()->forRole($gate)->first();
            $main = $this->streams()[$gate] ?? null;
            if (! $camera || ! $main) {
                return ['main' => null, 'sub' => null];
            }
            $probe = app(CameraProbeService::class);
            $codec = fn (string $url): ?string => $probe->describe(preg_replace('#//[^@/]*@#', '//', $url) ?? $url, (string) $camera->source_username, (string) $camera->source_password)['codec'] ?? null;

            $sub = app(CameraStreams::class)->forGate($gate)['sub'];

            return ['main' => $codec($main), 'sub' => filled($sub) && $sub !== preg_replace('#//[^@/]*@#', '//', $main) ? $codec((string) $sub) : null];
        });
    }

    /**
     * The browser's WebRTC offer for one gate -> go2rtc's answer.
     */
    public function webrtcAnswer(string $gate, string $offer): ?string
    {
        $response = Http::timeout(10)->withBody($offer, 'application/sdp')
            ->post($this->apiUrl('/webrtc').'?src='.rawurlencode($gate));

        return $response->successful() ? $response->body() : null;
    }

    /**
     * Settings › System status › Live view: per gate, what the browsers
     * measured (latest of each mode), the camera's video codec and whether
     * go2rtc has the camera; go2rtc's CPU.
     *
     * @return array{enabled: bool, running: bool, cpu: ?float, gates: list<array<string, mixed>>}
     */
    public function report(): array
    {
        $status = $this->status();
        $streams = $this->streams();
        $gates = [];

        foreach (Gate::ordered() as $gate) {
            $measured = [];
            foreach (['webrtc' => 'WebRTC (full quality)', 'hls' => 'HLS', 'mjpeg' => 'Basic (MJPEG)'] as $mode => $label) {
                if ($stats = Cache::get("live-view-stats.{$gate->code}.$mode")) {
                    $measured[] = ['label' => $label] + $stats;
                }
            }
            $gates[] = [
                'code' => $gate->code,
                'label' => $gate->name,
                'webrtc' => array_key_exists($gate->code, $streams),
                'camera_connected' => (bool) data_get($status, "streams.{$gate->code}.online", false),
                'viewers' => (int) data_get($status, "streams.{$gate->code}.viewers", 0),
                'codecs' => array_key_exists($gate->code, $streams) ? Cache::get("live-codecs.{$gate->code}") : null,
                'measured' => $measured,
            ];
        }

        return [
            'enabled' => $status['enabled'],
            'running' => $status['running'],
            'cpu' => $status['running'] ? BackgroundProcess::cpuPercent($this->pidPath()) : null,
            'gates' => $gates,
        ];
    }

    /**
     * For System Status: running, streams with a producer (camera connected).
     *
     * @return array{enabled: bool, running: bool, streams: array<string, array{online: bool, viewers: int}>}
     */
    public function status(): array
    {
        $result = ['enabled' => $this->enabled(), 'running' => false, 'streams' => []];

        if (! $result['enabled']) {
            return $result;
        }

        try {
            $response = Http::timeout(1)->get($this->apiUrl('/streams'));
            $result['running'] = $response->successful();
            foreach ((array) $response->json() as $gate => $stream) {
                $result['streams'][$gate] = [
                    'online' => collect((array) data_get($stream, 'producers', []))->contains(fn ($producer): bool => filled(data_get($producer, 'medias')) || filled(data_get($producer, 'receivers'))),
                    'viewers' => count((array) data_get($stream, 'consumers', [])),
                ];
            }
        } catch (Throwable) {
            // Not running.
        }

        return $result;
    }
}
