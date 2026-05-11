<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;

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
    public function ensureRunning(): array
    {
        $this->settingsService->ensureCameraRuntimeConfigExists();

        $status = $this->withStationActivity($this->readStatus());

        if (($status['service_running'] ?? false) && $this->isFresh($status['updated_at'] ?? null)) {
            $this->registerLaunchSuccess();

            return [
                ...$status,
                'auto_start_attempted' => false,
                'auto_start_message' => 'Detector service is already running.',
            ];
        }

        if (app()->runningUnitTests()) {
            return [
                ...$status,
                'auto_start_attempted' => false,
                'auto_start_message' => 'Auto-start is skipped during automated tests.',
            ];
        }

        if (! ($status['station_activity']['active'] ?? false)) {
            return [
                ...$status,
                'auto_start_attempted' => false,
                'auto_start_message' => 'Detector is in standby until a Station page is open.',
            ];
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

        if (($decoded['service_running'] ?? false) && ! $this->isFresh($decoded['updated_at'] ?? null)) {
            $decoded['service_running'] = false;
            $decoded['service_message'] = 'Detector status is stale. A restart will be attempted while monitoring stays online.';
        }

        return array_replace_recursive($fallback, $decoded);
    }

    public function statusPath(): string
    {
        return public_path('camera/camera_status.json');
    }

    public function runtimeLogPath(): string
    {
        return storage_path('logs/detector-runtime.log');
    }

    public function stationActivityPath(): string
    {
        return storage_path('app/camera/station_activity.json');
    }

    public function markStationViewerActive(string $location): void
    {
        if (! in_array($location, ['entrance', 'exit'], true)) {
            return;
        }

        $this->markCameraViewerActive([$location]);
    }

    public function markCalibrationViewerActive(): void
    {
        $this->markCameraViewerActive(['entrance', 'exit']);
    }

    /**
     * @param  list<string>  $locationsToMark
     */
    protected function markCameraViewerActive(array $locationsToMark): void
    {
        $now = now();
        $locationsToMark = array_values(array_intersect($locationsToMark, ['entrance', 'exit']));

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

        File::ensureDirectoryExists(dirname($this->stationActivityPath()));
        File::put(
            $this->stationActivityPath(),
            json_encode([
                'updated_at' => $now->toIso8601String(),
                'last_seen_at' => $now->toIso8601String(),
                'active_until' => $now->copy()->addSeconds(self::STATION_VIEWER_IDLE_AFTER_SECONDS)->toIso8601String(),
                'locations' => $locations,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
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
            'cameras' => [
                'entrance' => $this->fallbackCameraStatus($cameras['entrance']),
                'exit' => $this->fallbackCameraStatus($cameras['exit']),
            ],
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
            'stream_url' => 'http://127.0.0.1:8765/stream/'.$camera['camera_role'],
            'calibration_ready' => ! empty($camera['calibration_mask']) && ! empty($camera['calibration_line']),
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
        $scriptPath = base_path('school-vehicle-monitoring-detector/camera_service.py');

        if (! File::exists($scriptPath)) {
            return false;
        }

        File::ensureDirectoryExists(dirname($this->runtimeLogPath()));
        File::ensureDirectoryExists(dirname($this->launchStatePath()));

        $workingDirectory = base_path('school-vehicle-monitoring-detector');
        $pythonExecutable = $this->detectPythonExecutable();
        $logPath = $this->runtimeLogPath();

        $command = match (PHP_OS_FAMILY) {
            'Windows' => 'cd /d '.escapeshellarg($workingDirectory)
                .' && start "" /B '.escapeshellarg($pythonExecutable)
                .' '.escapeshellarg($scriptPath)
                .' >> '.escapeshellarg($logPath).' 2>&1',
            default => 'cd '.escapeshellarg($workingDirectory)
                .' && nohup '.escapeshellarg($pythonExecutable)
                .' '.escapeshellarg($scriptPath)
                .' >> '.escapeshellarg($logPath).' 2>&1 &',
        };

        $shellCommand = PHP_OS_FAMILY === 'Windows'
            ? 'cmd /c '.$command
            : '/bin/sh -lc '.escapeshellarg($command);

        $process = @proc_open($shellCommand, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($process)) {
            return false;
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        proc_close($process);

        return true;
    }

    protected function launchStatePath(): string
    {
        return storage_path('app/camera/detector_launch_state.json');
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
        File::ensureDirectoryExists(dirname($this->launchStatePath()));
        File::put(
            $this->launchStatePath(),
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
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

    protected function detectPythonExecutable(): string
    {
        $candidates = [
            base_path('school-vehicle-monitoring-detector/.venv/bin/python'),
            base_path('school-vehicle-monitoring-detector/.venv/Scripts/python.exe'),
            'python3',
            'python',
        ];

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, DIRECTORY_SEPARATOR)) {
                if (File::exists($candidate)) {
                    return $candidate;
                }

                continue;
            }

            return $candidate;
        }

        return 'python3';
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
