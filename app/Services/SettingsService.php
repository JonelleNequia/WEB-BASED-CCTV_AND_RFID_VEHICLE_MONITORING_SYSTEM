<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\Gate;
use App\Support\CameraFiles;
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
            // Phase 3: RFID cooldown (guest pass rules removed in Phase 0).
            // Phase 1 (gates): names and readers are per gate in the gates table.
            'rfid_cooldown_seconds' => '60',
            // Phase 3 (visitor model): a tag read belongs to a camera crossing
            // from this long before it to this long after it.
            'rfid_lookback_seconds' => '10',
            'rfid_lookahead_seconds' => '4',
            // Live-latency work: live view and detection tuning (Settings › Cameras).
            'perf_stream_fps' => '15',
            'perf_stream_width' => '960',
            'perf_jpeg_quality' => '70',
            'perf_detection_fps' => '8',
            'perf_yolo_imgsz' => '480',
            'perf_yolo_device' => 'auto',
            'perf_roi_crop' => '1',
            'perf_hires_on_trigger' => '1',
            // A2 (detection): vehicle type (Car / Motorcycle / Truck/Bus).
            'perf_type_second_pass' => '1',
            'perf_type_model' => 'yolov8s.pt',
            'perf_type_truck_min_height' => '35',
            'perf_type_car_min_aspect' => '1.05',
            // A3 (detection): one vehicle = one event.
            'perf_cross_margin' => '5',
            'perf_cross_min_points' => '3',
            'perf_cross_min_move' => '10',
            // Detector debug view (Settings › Calibration): raw detections on the live view.
            'detector_debug_overlay' => '0',
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

        $this->saveGates($values['gates'] ?? []);
        $this->saveCameraConfigurations($values['camera_configs'] ?? []);
        $this->exportCameraRuntimeConfig($this->all());
    }

    /**
     * Phase 1: gate names, reader type and manual reader address
     * (Settings › Gates & Readers), keyed by gate code.
     *
     * @param  array<string, array<string, mixed>>  $gates
     */
    protected function saveGates(array $gates): void
    {
        foreach ($gates as $code => $values) {
            $gate = Gate::query()->where('code', $code)->first();

            if (! $gate || ! is_array($values)) {
                continue;
            }

            $type = (string) ($values['reader_type'] ?? $gate->reader_type);
            $suffix = fn (?string $readerType): string => match ($readerType) {
                'uhf_ethernet' => 'UHF Reader',
                'simulated' => 'Reader (Simulated)',
                default => 'Reader',
            };
            // B1: a reader renamed on the Gates tab keeps its name; the default name follows the gate.
            $defaultName = $gate->reader_name === null || $gate->reader_name === $gate->name.' '.$suffix($gate->reader_type);
            $gate->fill(array_filter([
                'name' => isset($values['name']) ? trim((string) $values['name']) : null,
                'reader_type' => $type,
                'reader_manual' => array_key_exists('reader_manual', $values) ? (string) $values['reader_manual'] === '1' : null,
                'reader_transport' => $values['reader_transport'] ?? null,
                'is_active' => array_key_exists('is_active', $values) ? (string) $values['is_active'] === '1' : null,
            ], fn ($value): bool => $value !== null && $value !== ''));

            if (array_key_exists('reader_ip', $values)) {
                $gate->reader_ip = filled($values['reader_ip']) ? (string) $values['reader_ip'] : null;
            }
            if (array_key_exists('reader_port', $values)) {
                $gate->reader_port = filled($values['reader_port']) ? (int) $values['reader_port'] : null;
            }

            // The name shown in logs follows the gate name and reader type, unless it was renamed.
            if (filled($values['reader_name'] ?? null)) {
                $gate->reader_name = (string) $values['reader_name'];
            } elseif ($defaultName) {
                $gate->reader_name = $gate->name.' '.$suffix($type);
            }
            $gate->save();
        }

        if ($gates !== []) {
            // A renamed or (de)activated gate: camera rows follow.
            $this->calibrationService->ensureRequiredCameras();
        }
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
     * Phase 1: camera configuration of every active gate, keyed by gate code.
     *
     * @return array<string, array<string, mixed>>
     */
    public function cameraConfigurations(): array
    {
        return $this->calibrationService->cameraPayload(withSecrets: true);
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
                'performance' => $this->performanceSettings($settings),
                'stream_host' => (string) config('monitoring.stream.host'),
                'stream_port' => (int) config('monitoring.stream.port'),
                'app_url' => $integrationBaseUrl,
                'event_ingest_url' => $integrationBaseUrl.'/api/v1/integration/events',
                'guest_observation_url' => $integrationBaseUrl.'/api/guest-observation',
                'rfid_match_url' => $integrationBaseUrl.'/api/latest-scan',
                'status_url' => $integrationBaseUrl.'/api/v1/integration/status',
                'rfid_ingest_url' => $integrationBaseUrl.'/api/v1/integration/rfid-scans',
                'crossing_url' => $integrationBaseUrl.'/api/v1/integration/crossings',
                'visitor_plate_url' => $integrationBaseUrl.'/api/v1/integration/visitor-plates',
            ],
            // Phase 1: the detector runs one camera per gate, in this order.
            'gates' => Gate::ordered()->map(fn (Gate $gate): array => [
                'code' => $gate->code,
                'name' => $gate->name,
                'reader_name' => $gate->readerDisplayName(),
            ])->values()->all(),
            'storage' => $this->localStorageService->storageSummary(),
            'cameras' => collect($cameraConfigurations)
                ->map(fn (array $camera): array => $this->runtimeCameraPayload($camera))
                ->all(),
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
        return CameraFiles::path('camera_runtime_config.json');
    }

    /**
     * Save per-camera source connection settings.
     *
     * @param  array<string, mixed>  $cameraConfigurations
     */
    protected function saveCameraConfigurations(array $cameraConfigurations): void
    {
        $this->calibrationService->ensureRequiredCameras();

        foreach (Gate::codes() as $role) {
            if (! isset($cameraConfigurations[$role])) {
                continue;
            }

            $cameraData = $cameraConfigurations[$role];

            $camera = Camera::query()->forRole($role)->first();

            if ($camera === null) {
                continue;
            }

            $camera->fill([
                'camera_name' => (string) ($cameraData['camera_name'] ?? $camera->camera_name),
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
                    'source_value' => ($cameraData['source_type'] ?? '') === Camera::SOURCE_NONE ? '' : (string) ($cameraData['source_value'] ?? '0'),
                    // Optional full-resolution stream for trigger snapshots (manual sources).
                    'snapshot_source_value' => filled($cameraData['snapshot_source_value'] ?? null)
                        ? (string) $cameraData['snapshot_source_value'] : null,
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
    /**
     * @param  array<string, string>  $settings
     * @return array<string, int|float|string>
     */
    public function performanceSettings(array $settings): array
    {
        return [
            'stream_fps' => (float) ($settings['perf_stream_fps'] ?? 15),
            'stream_width' => (int) ($settings['perf_stream_width'] ?? 960),
            'jpeg_quality' => (int) ($settings['perf_jpeg_quality'] ?? 70),
            'detection_fps' => (float) ($settings['perf_detection_fps'] ?? 8),
            'yolo_imgsz' => (int) ($settings['perf_yolo_imgsz'] ?? 480),
            'yolo_device' => (string) ($settings['perf_yolo_device'] ?? 'auto'),
            'roi_crop' => (int) ($settings['perf_roi_crop'] ?? 1),
            'hires_on_trigger' => (int) ($settings['perf_hires_on_trigger'] ?? 1),
            'debug_overlay' => (int) ($settings['detector_debug_overlay'] ?? 0),
            'type_second_pass' => (int) ($settings['perf_type_second_pass'] ?? 1),
            'type_model' => (string) ($settings['perf_type_model'] ?? 'yolov8s.pt'),
            'type_truck_min_height' => round(((float) ($settings['perf_type_truck_min_height'] ?? 35)) / 100, 3),
            'type_car_min_aspect' => (float) ($settings['perf_type_car_min_aspect'] ?? 1.05),
            'cross_margin' => round(((float) ($settings['perf_cross_margin'] ?? 5)) / 100, 3),
            'cross_min_points' => (int) ($settings['perf_cross_min_points'] ?? 3),
            'cross_min_move' => round(((float) ($settings['perf_cross_min_move'] ?? 10)) / 100, 3),
        ];
    }

    protected function runtimeCameraPayload(array $cameraConfiguration): array
    {
        $sourceType = (string) ($cameraConfiguration['source_type'] ?? 'webcam');
        $sourceValue = $cameraConfiguration['source_value'] ?? '0';
        // One decoder thread (lowest delay) unless the live source is a camera's
        // full-resolution main stream, which needs FFmpeg's threading to keep up.
        $liveStream = data_get(DeviceAssignment::query()
            ->where('station', $cameraConfiguration['camera_role'])
            ->where('role', DeviceAssignment::ROLE_CAMERA)
            ->first()?->options, 'stream');

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
            'snapshot_source_value' => (string) ($cameraConfiguration['snapshot_source_value'] ?? ''),
            'decoder_threads' => $liveStream === 'main' ? 0 : 1,
            'browser_device_id' => $cameraConfiguration['browser_device_id'],
            'browser_label' => $cameraConfiguration['browser_label'],
            'calibration_mask' => $cameraConfiguration['calibration_mask'],
            'calibration_line' => $cameraConfiguration['calibration_line'],
            // Phase 3: how long the detector waits for a tag read after a
            // crossing, and how far back a read still counts.
            'rfid_window_seconds' => max(1, min(10, $this->getInt('rfid_lookahead_seconds', 4))),
            'rfid_lookback_seconds' => max(1, min(15, $this->getInt('rfid_lookback_seconds', 10))),
        ];
    }
}
