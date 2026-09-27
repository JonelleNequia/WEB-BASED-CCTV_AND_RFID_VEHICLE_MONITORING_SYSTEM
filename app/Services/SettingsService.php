<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\File;

class SettingsService
{
    public function __construct(
        protected CalibrationService $calibrationService,
        protected LocalStorageService $localStorageService
    ) {
    }

    /**
     * Get the known default settings for the prototype.
     *
     * @return array<string, string>
     */
    public function defaults(): array
    {
        return [
            'matching_threshold_matched' => '75',
            'matching_threshold_manual_review' => '50',
            'operating_mode' => 'manual',
            'deployment_mode' => 'offline_local',
            'cctv_simulation_mode' => 'enabled',
            'rfid_simulation_mode' => 'enabled',
            'camera_source_placeholder' => 'rtsp://future-camera-source',
            'retention_days' => '30',
            'entrance_portal_label' => 'PHILCST Entrance Portal',
            'exit_portal_label' => 'PHILCST Exit Portal',
            'entrance_rfid_reader_name' => 'Entrance RFID Reader (Simulated)',
            'exit_rfid_reader_name' => 'Exit RFID Reader (Simulated)',
            // Phase 3: RFID cooldown and guest pass rules (edited in Settings in Phase 4).
            'rfid_cooldown_seconds' => '60',
            'guest_pass_validity_minutes' => '240',
            'guest_pass_overstay_grace_minutes' => '0',
            'guest_pass_require_id' => '1',
            // Phase 4: reader configuration per station (UHF is config only for now).
            'entrance_reader_type' => 'nfc',
            'entrance_reader_ip' => '',
            'entrance_reader_port' => '',
            'exit_reader_type' => 'nfc',
            'exit_reader_ip' => '',
            'exit_reader_port' => '',
            // Plug-and-detect: the manual address is used only when this is '1'
            // (otherwise the reader assigned in Settings › Devices is used).
            'entrance_reader_manual' => '0',
            'exit_reader_manual' => '0',
            'entrance_reader_transport' => 'tcp',
            'exit_reader_transport' => 'tcp',
        ];
    }

    /**
     * Get all settings merged with defaults.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $stored = SystemSetting::query()
            ->pluck('setting_value', 'setting_key')
            ->map(fn (?string $value): string => $value ?? '')
            ->all();

        return array_merge($this->defaults(), $stored);
    }

    /**
     * Get a setting value with a fallback.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Phase 6: the detector / RFID adapter key comes from .env (DETECTOR_API_KEY).
     */
    public function detectorApiKey(): string
    {
        return trim((string) config('services.detector.api_key', ''));
    }

    /**
     * Without a key, only this PC may call the integration API, and never in production.
     */
    public function allowsKeylessLocalIntegration(): bool
    {
        return $this->detectorApiKey() === ''
            && ! app()->isProduction()
            && $this->get('deployment_mode', 'offline_local') === 'offline_local';
    }

    /**
     * Get a numeric setting as an integer.
     */
    public function getInt(string $key, int $default): int
    {
        return (int) ($this->get($key, (string) $default) ?? $default);
    }

    /**
     * Persist system settings and camera source settings.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $this->localStorageService->ensureBaseDirectories();

        foreach ($this->defaults() as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            SystemSetting::query()->updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => (string) ($values[$key] ?? $default)]
            );
        }

        $this->saveCameraConfigurations($values['camera_configs'] ?? []);
        $this->exportCameraRuntimeConfig($this->all());
    }

    /**
     * Ensure the Python bridge has camera records and a runtime config file to read.
     */
    public function ensureCameraRuntimeConfigExists(): void
    {
        $this->calibrationService->ensureRequiredCameras();
        $this->localStorageService->ensureBaseDirectories();
        $this->exportCameraRuntimeConfig($this->all());
    }

    /**
     * Get the current entrance and exit camera configuration for the settings page.
     *
     * @return array<string, array<string, mixed>>
     */
    public function cameraConfigurations(): array
    {
        $cameras = $this->calibrationService->cameraPayload(withSecrets: true);

        return [
            'entrance' => $cameras['entrance'],
            'exit' => $cameras['exit'],
        ];
    }

    /**
     * Export dual-camera configuration and calibration data for Python.
     *
     * @param  array<string, string>|null  $settings
     */
    public function exportCameraRuntimeConfig(?array $settings = null): void
    {
        $settings ??= $this->all();
        $cameraConfigurations = $this->cameraConfigurations();
        $integrationBaseUrl = $this->integrationBaseUrl($settings);

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'system_settings' => [
                'operating_mode' => $settings['operating_mode'] ?? 'manual',
                'deployment_mode' => $settings['deployment_mode'] ?? 'offline_local',
                'cctv_simulation_mode' => $settings['cctv_simulation_mode'] ?? 'enabled',
                'rfid_simulation_mode' => $settings['rfid_simulation_mode'] ?? 'enabled',
                'python_api_key' => $this->detectorApiKey(),
                'stream_host' => (string) config('monitoring.stream.host'),
                'stream_port' => (int) config('monitoring.stream.port'),
                'app_url' => $integrationBaseUrl,
                'event_ingest_url' => $integrationBaseUrl.'/api/v1/integration/events',
                'guest_observation_url' => $integrationBaseUrl.'/api/guest-observation',
                'rfid_match_url' => $integrationBaseUrl.'/api/latest-scan',
                'status_url' => $integrationBaseUrl.'/api/v1/integration/status',
                'rfid_ingest_url' => $integrationBaseUrl.'/api/v1/integration/rfid-scans',
                'entrance_portal_label' => $settings['entrance_portal_label'] ?? 'PHILCST Entrance Portal',
                'exit_portal_label' => $settings['exit_portal_label'] ?? 'PHILCST Exit Portal',
                'entrance_rfid_reader_name' => $settings['entrance_rfid_reader_name'] ?? 'Entrance RFID Reader (Simulated)',
                'exit_rfid_reader_name' => $settings['exit_rfid_reader_name'] ?? 'Exit RFID Reader (Simulated)',
            ],
            'storage' => $this->localStorageService->storageSummary(),
            'cameras' => [
                'entrance' => $this->runtimeCameraPayload($cameraConfigurations['entrance']),
                'exit' => $this->runtimeCameraPayload($cameraConfigurations['exit']),
            ],
        ];

        File::ensureDirectoryExists(dirname($this->cameraRuntimeConfigPath()));
        File::put(
            $this->cameraRuntimeConfigPath(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Get the runtime JSON config path used by the Python service.
     */
    public function cameraRuntimeConfigPath(): string
    {
        return storage_path('app/camera/camera_runtime_config.json');
    }

    /**
     * Save per-camera source connection settings.
     *
     * @param  array<string, mixed>  $cameraConfigurations
     */
    protected function saveCameraConfigurations(array $cameraConfigurations): void
    {
        $this->calibrationService->ensureRequiredCameras();

        foreach (['entrance', 'exit'] as $role) {
            if (! isset($cameraConfigurations[$role])) {
                continue;
            }

            $cameraData = $cameraConfigurations[$role];

            $camera = Camera::query()->forRole($role)->first();

            if ($camera === null) {
                continue;
            }

            $camera->fill([
                'camera_name' => (string) ($cameraData['camera_name'] ?? ($role === 'entrance' ? 'PHILCST Entrance Camera' : 'PHILCST Exit Camera')),
                'source_username' => (string) ($cameraData['source_username'] ?? ''),
                'status' => 'active',
            ]);

            // Plug-and-detect: an assigned network camera keeps the address
            // the device service found; the manual source is ignored.
            $managed = DeviceAssignment::query()
                ->where('station', $role)
                ->where('role', DeviceAssignment::ROLE_CAMERA)
                ->exists();

            if (! $managed) {
                $camera->fill([
                    'source_type' => (string) ($cameraData['source_type'] ?? 'webcam'),
                    'source_value' => (string) ($cameraData['source_value'] ?? '0'),
                ]);
            }

            // The password is never shown again: blank keeps the saved one.
            if (filled($cameraData['source_password'] ?? null)) {
                $camera->source_password = (string) $cameraData['source_password'];
            } elseif (! empty($cameraData['clear_password'])) {
                $camera->source_password = null;
            }

            // Saved through the model so the encrypted cast applies.
            $camera->save();
        }
    }

    /**
     * Resolve the URL Python should use when calling Laravel from this same PC.
     *
     * @param  array<string, string>  $settings
     */
    protected function integrationBaseUrl(array $settings): string
    {
        $explicitUrl = trim((string) env('PYTHON_INTEGRATION_URL', ''));

        if ($explicitUrl !== '') {
            return rtrim($explicitUrl, '/');
        }

        // Plug-and-detect: derived from APP_URL only (no fixed address or
        // port in the code). "localhost" becomes the IPv4 loopback so Python
        // does not try IPv6 first.
        $appUrl = rtrim((string) config('app.url'), '/');
        $parts = parse_url($appUrl) ?: [];

        if (($settings['deployment_mode'] ?? 'offline_local') === 'offline_local'
            && strtolower((string) ($parts['host'] ?? '')) === 'localhost') {
            $appUrl = (string) preg_replace('#//localhost#i', '//127.0.0.1', $appUrl, 1);
        }

        return $appUrl;
    }

    public function integrationUrl(): string
    {
        return $this->integrationBaseUrl($this->all());
    }

    /**
     * Prepare one camera record for the Python runtime JSON file.
     *
     * @param  array<string, mixed>  $cameraConfiguration
     * @return array<string, mixed>
     */
    protected function runtimeCameraPayload(array $cameraConfiguration): array
    {
        $sourceType = (string) ($cameraConfiguration['source_type'] ?? 'webcam');
        $sourceValue = $cameraConfiguration['source_value'] ?? '0';

        return [
            'camera_id' => $cameraConfiguration['id'],
            'id' => $cameraConfiguration['id'],
            'camera_role' => $cameraConfiguration['camera_role'],
            'camera_name' => $cameraConfiguration['camera_name'],
            'source_type' => $sourceType,
            'source_value' => $sourceType === 'webcam' && is_numeric((string) $sourceValue)
                ? (int) $sourceValue
                : (string) $sourceValue,
            'source_username' => (string) ($cameraConfiguration['source_username'] ?? ''),
            'source_password' => (string) ($cameraConfiguration['source_password'] ?? ''),
            'browser_device_id' => $cameraConfiguration['browser_device_id'],
            'browser_label' => $cameraConfiguration['browser_label'],
            'calibration_mask' => $cameraConfiguration['calibration_mask'],
            'calibration_line' => $cameraConfiguration['calibration_line'],
        ];
    }
}
