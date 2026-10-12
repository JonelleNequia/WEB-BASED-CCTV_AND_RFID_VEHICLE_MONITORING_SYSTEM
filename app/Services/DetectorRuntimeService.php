<?php

namespace App\Services;

use App\Models\Gate;
use App\Support\CameraFiles;
use App\Support\CameraSource;
use App\Support\PythonLauncher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DetectorRuntimeService
{
    protected const STATUS_STALE_AFTER_SECONDS = 20;

    protected const LAUNCH_COOLDOWN_SECONDS = 15;

    protected const STATION_VIEWER_IDLE_AFTER_SECONDS = 10;

    protected const LOCK_AFTER_FAILED_ATTEMPTS = 3;

    protected const FAILURE_LOCK_MINUTES = 30;

    /**
     * Ensure the detector status is available and, when possible, auto-start Python in the background.
     *
     * @return array<string, mixed>
     */
    public function ensureRunning(bool $force = false): array
    {
        $this->settingsService->ensureCameraRuntimeConfigExists();

        $status = $this->withStationActivity($this->readStatus());

        $running = ($status['service_running'] ?? false) && $this->isFresh($status['updated_at'] ?? null);

        // Startup check: an older detector still runs after the code was
        // updated (it never loads the new code by itself if it predates the
        // self-restart). Stop it, then start the current code below.
        if ($running && $this->codeCheck($status)['state'] === 'old' && $this->stopOldDetector($status)) {
            $running = false;
        }

        if ($running) {
            $this->registerLaunchSuccess();
            // Plug-and-detect: keep the detector log from growing without limit.
            PythonLauncher::rotateLog($this->runtimeLogPath());

            return [
                ...$status,
                'auto_start_attempted' => false,
                'auto_start_message' => 'Detector service is already running.',
            ];
        }

        // Windows install kit: a Windows service runs the detector (and
        // restarts it); a web request never starts a process.
        if (config('monitoring.services.managed')) {
            return [
                ...$status,
                'auto_start_attempted' => false,
                'auto_start_message' => 'Starting up… The detector service starts by itself.',
            ];
        }

        if (app()->runningUnitTests()) {
            return [
                ...$status,
                'auto_start_attempted' => false,
                'auto_start_message' => 'Auto-start is skipped during automated tests.',
            ];
        }

        // Phase 1: removed the "standby until a Station page is open" gate.
        // Vehicle detection must run whenever the system is on; only the live
        // MJPEG stream idles when nobody is watching (handled in Python).

        // A camera was just assigned or moved: try now, even during the
        // cool-down that follows failed starts.
        if ($force) {
            $this->writeLaunchState(['last_attempt_at' => null, 'failed_attempts' => 0, 'lock_until' => null]);
        }

        if (! $this->canAttemptLaunch()) {
            return [
                ...$status,
                'auto_start_attempted' => false,
                'auto_start_message' => $this->cooldownMessage(),
            ];
        }

        $this->registerLaunchAttempt();
        $launched = $this->launchBackgroundProcess();
        usleep(1200000);
        $refreshedStatus = $this->withStationActivity($this->readStatus());
        $runningAfterAttempt = ($refreshedStatus['service_running'] ?? false)
            && $this->isFresh($refreshedStatus['updated_at'] ?? null);

        if ($runningAfterAttempt) {
            $this->registerLaunchSuccess();
        } else {
            $this->registerLaunchFailure();
        }

        return [
            ...$refreshedStatus,
            'auto_start_attempted' => true,
            'auto_start_message' => $runningAfterAttempt
                ? 'Camera status service is running.'
                : ($launched
                    ? 'Camera status service is starting. If it stays unavailable, check Python dependencies.'
                    : 'Camera status service could not be started. Check Python dependencies and runtime setup.'),
        ];
    }

    /**
     * Read the last detector status JSON or return a safe fallback.
     *
     * @return array<string, mixed>
     */
    public function readStatus(): array
    {
        $fallback = $this->fallbackStatus();
        $statusPath = $this->statusPath();

        if (! File::exists($statusPath)) {
            return $fallback;
        }

        $decoded = json_decode((string) File::get($statusPath), true);

        if (! is_array($decoded)) {
            return [
                ...$fallback,
                'service_message' => 'Detector status file exists but could not be parsed.',
            ];
        }

        $decoded['service_running'] = (bool) ($decoded['service_running'] ?? false);
        $decoded['service_message'] = (string) ($decoded['service_message'] ?? $fallback['service_message']);
        $decoded['updated_at'] = $decoded['updated_at'] ?? null;
        $decoded['cameras'] = is_array($decoded['cameras'] ?? null)
            ? $decoded['cameras']
            : $fallback['cameras'];

        // Plug-and-detect: status reaches Station pages; never pass a login inside a URL.
        foreach ($decoded['cameras'] as $role => $camera) {
            if (is_array($camera) && is_string($camera['source_value'] ?? null)) {
                $decoded['cameras'][$role]['source_value'] = CameraSource::withoutCredentials($camera['source_value']);
            }
        }

        if (($decoded['service_running'] ?? false) && ! $this->isFresh($decoded['updated_at'] ?? null)) {
            $decoded['service_running'] = false;
            $decoded['service_message'] = 'Detector status is stale. A restart will be attempted while monitoring stays online.';
        }

        return array_replace_recursive($fallback, $decoded);
    }

    /**
     * The detector code on disk, worked out like the detector does
     * (school-vehicle-monitoring-detector/code_version.py): the first 12 hex
     * digits of a SHA-1 over every top-level .py file except the programs
     * that are not the detector.
     */
    public function codeVersion(): ?string
    {
        $files = collect(File::glob(PythonLauncher::directory().DIRECTORY_SEPARATOR.'*.py'))
            ->reject(fn (string $path): bool => in_array(basename($path), ['device_service.py', 'camera_service.py', 'video_test.py'], true))
            ->sortBy(fn (string $path): string => basename($path), SORT_STRING)
            ->values();

        if ($files->isEmpty()) {
            return null;
        }

        return substr(sha1($files->map(fn (string $path): string => basename($path)."\0".sha1_file($path)."\n")->implode('')), 0, 12);
    }

    /**
     * Is the running detector the code on disk? state: "current", "old"
     * (an older version, or one from before versions were reported) or
     * "unknown" (no detector running).
     *
     * @param  array<string, mixed>  $status
     * @return array{state: string, running: ?string, on_disk: ?string, started_at: ?string}
     */
    public function codeCheck(array $status): array
    {
        $onDisk = $this->codeVersion();
        $running = filled($status['code_version'] ?? null) ? (string) $status['code_version'] : null;
        $alive = ($status['service_running'] ?? false) && $this->isFresh($status['updated_at'] ?? null);

        return [
            'state' => ! $alive ? 'unknown' : ($running !== null && $running === $onDisk ? 'current' : 'old'),
            'running' => $running,
            'on_disk' => $onDisk,
            'started_at' => $status['started_at'] ?? null,
        ];
    }

    /**
     * Stop a detector running old code, so the current code starts. Only
     * this project's detector process (its pid from the status), at most
     * once every two minutes; never on a Windows service install (the
     * service restarts it) or in tests.
     *
     * @param  array<string, mixed>  $status
     */
    protected function stopOldDetector(array $status): bool
    {
        $pid = (int) ($status['pid'] ?? 0);
        if ($pid <= 1 || config('monitoring.services.managed') || app()->runningUnitTests() || ! function_exists('posix_kill')) {
            return false;
        }
        if (! Cache::add('detector-old-code-restart', true, now()->addMinutes(2))) {
            return false;
        }

        $command = (string) @shell_exec('ps -ww -o command= -p '.$pid);
        if (! str_contains($command, PythonLauncher::directory()) || ! preg_match('/(camera|detector)_service\.py/', $command)) {
            return false;
        }

        Log::warning('Stopping a detector that runs old code.', ['pid' => $pid, 'running' => $status['code_version'] ?? 'unknown (before versions)', 'on_disk' => $this->codeVersion()]);
        posix_kill($pid, 15);
        for ($i = 0; $i < 25 && posix_kill($pid, 0); $i++) {
            usleep(200000);
        }
        if (posix_kill($pid, 0)) {
            posix_kill($pid, 9);
        }
        // Start the new one right away (no cool-down from earlier attempts).
        $this->writeLaunchState(['last_attempt_at' => null, 'failed_attempts' => 0, 'lock_until' => null]);
        // The stream port is free now: the new detector may take it at once.
        File::put($this->statusPath(), json_encode([...$status, 'service_running' => false, 'service_message' => 'Restarting with the updated detector code.'], JSON_PRETTY_PRINT));

        return true;
    }

    public function statusPath(): string
    {
        // Phase 6: moved out of public/ (served by camera.status for admins).
        return CameraFiles::statusPath();
    }

    public function runtimeLogPath(): string
    {
        return storage_path('logs/detector-runtime.log');
    }

    public function stationActivityPath(): string
    {
        return CameraFiles::path('station_activity.json');
    }

    /**
     * Rewrite loopback MJPEG URLs when the station page is viewed from another LAN device.
     *
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    public function withViewerStreamUrls(array $status, ?string $viewerHost): array
    {
        foreach (array_keys((array) ($status['cameras'] ?? [])) as $role) {
            if (! isset($status['cameras'][$role]) || ! is_array($status['cameras'][$role])) {
                continue;
            }

            $status['cameras'][$role]['stream_url'] = $this->streamUrlForRole($role, $status, $viewerHost);
        }

        return $status;
    }

    /**
     * Resolve a stream URL that is reachable from the current browser.
     *
     * @param  array<string, mixed>  $status
     */
    public function streamUrlForRole(string $role, array $status = [], ?string $viewerHost = null): string
    {
        if ($proxied = $this->proxiedStreamUrl($role)) {
            return $proxied;
        }

        $defaultUrl = $this->defaultStreamUrl($role);
        $streamUrl = (string) data_get($status, "cameras.$role.stream_url", $defaultUrl);
        $viewerHost = trim((string) $viewerHost);

        if ($viewerHost === '' || $this->isLoopbackHost($viewerHost)) {
            return $streamUrl ?: $defaultUrl;
        }

        $parts = parse_url($streamUrl ?: $defaultUrl);
        $streamHost = (string) ($parts['host'] ?? '');

        if ($streamHost !== '' && ! $this->isLoopbackHost($streamHost) && $streamHost !== '0.0.0.0') {
            return $streamUrl;
        }

        $port = ':'.($parts['port'] ?? $this->streamPort());
        $path = $parts['path'] ?? "/stream/{$role}";
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return 'http://'.$viewerHost.$port.$path.$query;
    }

    /**
     * Plug-and-detect: the stream host/port come from config/monitoring.php
     * (DETECTOR_STREAM_HOST / DETECTOR_STREAM_PORT), not from the code.
     */
    /**
     * Short reason for the sidebar and pages when the detector is not running.
     */
    public function notRunningReason(): string
    {
        $state = $this->readLaunchState();

        if ($this->isFuture($state['lock_until'] ?? null)) {
            return 'Paused after failed starts';
        }

        return $this->isRecent($state['last_attempt_at'] ?? null, self::LAUNCH_COOLDOWN_SECONDS) ? 'Starting…' : 'Not running';
    }

    public function defaultStreamUrl(string $role): string
    {
        if ($proxied = $this->proxiedStreamUrl($role)) {
            return $proxied;
        }

        $host = (string) config('monitoring.stream.host', 'localhost');

        return 'http://'.$host.':'.$this->streamPort().'/stream/'.$role;
    }

    /**
     * Windows install kit: "/detector/stream/{gate}" on the page's own
     * address (the web server checks the sign-in and passes it on), so it
     * works from any PC on the LAN without opening the detector's port.
     */
    protected function proxiedStreamUrl(string $role): ?string
    {
        return config('monitoring.stream.proxy')
            ? rtrim((string) config('monitoring.stream.proxy_prefix'), '/').'/stream/'.$role
            : null;
    }

    public function streamPort(): int
    {
        return (int) config('monitoring.stream.port');
    }

    public function markStationViewerActive(string $location): void
    {
        $location = Gate::resolveCode($location);

        if ($location === null) {
            return;
        }

        $this->markCameraViewerActive([$location]);
    }

    public function markCalibrationViewerActive(): void
    {
        $this->markCameraViewerActive(Gate::codes());
    }

    /**
     * @param  list<string>  $locationsToMark
     */
    protected function markCameraViewerActive(array $locationsToMark): void
    {
        $now = now();
        $locationsToMark = array_values(array_intersect($locationsToMark, Gate::codes()));

        if ($locationsToMark === []) {
            return;
        }

        $locations = $this->readStationActivityPayload()['locations'] ?? [];
        $locations = is_array($locations) ? $locations : [];

        foreach ($locationsToMark as $location) {
            $locations[$location] = $now->toIso8601String();
        }

        $activeSince = $now->copy()->subSeconds(self::STATION_VIEWER_IDLE_AFTER_SECONDS);

        $locations = collect($locations)
            ->filter(function ($seenAt) use ($activeSince): bool {
                try {
                    return Carbon::parse((string) $seenAt)->gte($activeSince);
                } catch (\Throwable) {
                    return false;
                }
            })
            ->all();

        $this->writeJsonAtomic(
            $this->stationActivityPath(),
            [
                'updated_at' => $now->toIso8601String(),
                'last_seen_at' => $now->toIso8601String(),
                'active_until' => $now->copy()->addSeconds(self::STATION_VIEWER_IDLE_AFTER_SECONDS)->toIso8601String(),
                'locations' => $locations,
            ]
        );
    }

    /**
     * Phase 1: write JSON through a temp file + rename.
     *
     * Python reads station_activity.json many times per second. File::put()
     * truncates first, so Python sometimes read an empty file, treated it as
     * "no Station page open", paused the camera and dropped the RTSP
     * connection. rename() replaces the file in one step.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function writeJsonAtomic(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));

        $temporaryPath = $path.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';
        File::put($temporaryPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (! @rename($temporaryPath, $path)) {
            // Windows cannot rename over a file another process has open.
            File::put($path, (string) File::get($temporaryPath));
            File::delete($temporaryPath);
        }
    }

    /**
     * @return array{active: bool, active_locations: list<string>, last_seen_at: string|null, active_until: string|null, seconds_until_idle: int}
     */
    public function stationActivity(): array
    {
        $payload = $this->readStationActivityPayload();
        $locations = is_array($payload['locations'] ?? null) ? $payload['locations'] : [];
        $activeSince = now()->subSeconds(self::STATION_VIEWER_IDLE_AFTER_SECONDS);
        $activeLocations = [];
        $lastSeenAt = null;

        foreach ($locations as $location => $seenAt) {
            try {
                $seen = Carbon::parse((string) $seenAt);
            } catch (\Throwable) {
                continue;
            }

            if ($lastSeenAt === null || $seen->gt($lastSeenAt)) {
                $lastSeenAt = $seen;
            }

            if ($seen->gte($activeSince)) {
                $activeLocations[] = (string) $location;
            }
        }

        try {
            $activeUntil = filled($payload['active_until'] ?? null)
                ? Carbon::parse((string) $payload['active_until'])
                : ($lastSeenAt?->copy()->addSeconds(self::STATION_VIEWER_IDLE_AFTER_SECONDS));
        } catch (\Throwable) {
            $activeUntil = $lastSeenAt?->copy()->addSeconds(self::STATION_VIEWER_IDLE_AFTER_SECONDS);
        }

        $active = $activeLocations !== [] || ($activeUntil !== null && $activeUntil->isFuture());

        return [
            'active' => $active,
            'active_locations' => array_values(array_unique($activeLocations)),
            'last_seen_at' => $lastSeenAt?->toIso8601String(),
            'active_until' => $activeUntil?->toIso8601String(),
            'seconds_until_idle' => $activeUntil !== null
                ? (int) max(0, now()->diffInSeconds($activeUntil, false))
                : 0,
        ];
    }

    public function __construct(
        protected SettingsService $settingsService
    ) {
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    protected function withStationActivity(array $status): array
    {
        $activity = $this->stationActivity();

        return [
            ...$status,
            'station_activity' => $activity,
            'camera_power_mode' => $activity['active'] ? 'active' : 'standby',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function readStationActivityPayload(): array
    {
        $path = $this->stationActivityPath();

        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function isLoopbackHost(string $host): bool
    {
        $host = trim($host, '[]');

        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * Build a fallback detector status from the saved Laravel camera settings.
     *
     * @return array<string, mixed>
     */
    protected function fallbackStatus(): array
    {
        $cameras = $this->settingsService->cameraConfigurations();

        return [
            'service_running' => false,
            'service_message' => 'Vehicle detector is not running yet.',
            'updated_at' => null,
            'cameras' => collect($cameras)->map(fn (array $camera): array => $this->fallbackCameraStatus($camera))->all(),
        ];
    }

    /**
     * Build one safe camera status payload.
     *
     * @param  array<string, mixed>  $camera
     * @return array<string, mixed>
     */
    protected function fallbackCameraStatus(array $camera): array
    {
        return [
            'camera_role' => $camera['camera_role'],
            'camera_name' => $camera['camera_name'],
            'camera_running' => false,
            'detection_ready' => false,
            'source_type' => $camera['source_type'],
            'source_value' => $camera['source_value'],
            'stream_url' => $this->defaultStreamUrl($camera['camera_role']),
            'calibration_ready' => ! empty($camera['calibration_mask']),
            'last_capture_time' => null,
            'last_error' => 'Detector has not processed this camera yet.',
            'retry_count' => 0,
            'processed_frames' => 0,
            'detections_seen' => 0,
            'active_detections' => 0,
            'crossings_logged' => 0,
        ];
    }

    /**
     * Best-effort local background launch for the Python detector service.
     */
    protected function launchBackgroundProcess(): bool
    {
        File::ensureDirectoryExists(dirname($this->launchStatePath()));

        return PythonLauncher::launch('camera_service.py', $this->runtimeLogPath());
    }

    protected function launchStatePath(): string
    {
        return CameraFiles::path('detector_launch_state.json');
    }

    protected function canAttemptLaunch(): bool
    {
        $state = $this->readLaunchState();

        if ($this->isFuture($state['lock_until'] ?? null)) {
            return false;
        }

        return ! $this->isRecent($state['last_attempt_at'] ?? null, self::LAUNCH_COOLDOWN_SECONDS);
    }

    protected function cooldownMessage(): string
    {
        $state = $this->readLaunchState();

        if ($this->isFuture($state['lock_until'] ?? null)) {
            return 'Auto-start is temporarily paused after repeated failed launch attempts.';
        }

        return 'Camera status auto-start is cooling down after a recent launch attempt.';
    }

    protected function readLaunchState(): array
    {
        $path = $this->launchStatePath();

        if (! File::exists($path)) {
            return [
                'last_attempt_at' => null,
                'failed_attempts' => 0,
                'lock_until' => null,
            ];
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            return [
                'last_attempt_at' => null,
                'failed_attempts' => 0,
                'lock_until' => null,
            ];
        }

        return [
            'last_attempt_at' => $decoded['last_attempt_at'] ?? null,
            'failed_attempts' => (int) ($decoded['failed_attempts'] ?? 0),
            'lock_until' => $decoded['lock_until'] ?? null,
        ];
    }

    protected function writeLaunchState(array $state): void
    {
        $this->writeJsonAtomic($this->launchStatePath(), $state);
    }

    protected function registerLaunchAttempt(): void
    {
        $state = $this->readLaunchState();
        $state['last_attempt_at'] = now()->toIso8601String();
        $this->writeLaunchState($state);
    }

    protected function registerLaunchSuccess(): void
    {
        $this->writeLaunchState([
            'last_attempt_at' => now()->toIso8601String(),
            'failed_attempts' => 0,
            'lock_until' => null,
        ]);
    }

    protected function registerLaunchFailure(): void
    {
        $state = $this->readLaunchState();
        $failedAttempts = ((int) ($state['failed_attempts'] ?? 0)) + 1;
        $lockUntil = null;

        if ($failedAttempts >= self::LOCK_AFTER_FAILED_ATTEMPTS) {
            $lockUntil = now()->addMinutes(self::FAILURE_LOCK_MINUTES)->toIso8601String();
            $failedAttempts = 0;
        }

        $this->writeLaunchState([
            'last_attempt_at' => now()->toIso8601String(),
            'failed_attempts' => $failedAttempts,
            'lock_until' => $lockUntil,
        ]);
    }

    protected function isFresh(?string $timestamp): bool
    {
        if (blank($timestamp)) {
            return false;
        }

        try {
            return Carbon::parse($timestamp)->gte(now()->subSeconds(self::STATUS_STALE_AFTER_SECONDS));
        } catch (\Throwable) {
            return false;
        }
    }

    protected function isRecent(?string $timestamp, int $seconds): bool
    {
        if (blank($timestamp)) {
            return false;
        }

        try {
            return Carbon::parse($timestamp)->gte(now()->subSeconds($seconds));
        } catch (\Throwable) {
            return false;
        }
    }

    protected function isFuture(?string $timestamp): bool
    {
        if (blank($timestamp)) {
            return false;
        }

        try {
            return Carbon::parse($timestamp)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }
}
