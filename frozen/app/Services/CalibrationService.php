<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\Gate;
use App\Models\VehicleCrossing;
use App\Support\CameraSource;
use App\Support\DisplayTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CalibrationService
{
    /**
     * Phase 1: one camera record per active gate (camera_role = gate code),
     * in gate order. A new gate gets a webcam placeholder until a camera is
     * assigned in Settings › Devices.
     */
    public function ensureRequiredCameras(): Collection
    {
        $gates = Gate::ordered();

        foreach ($gates as $gate) {
            Camera::query()->firstOrCreate(
                ['camera_role' => $gate->code],
                [
                    'camera_name' => $gate->name.' Camera',
                    'camera_role' => $gate->code,
                    // B1: a new gate starts without a camera ("+ Add camera"), not this PC's webcam.
                    'source_type' => Camera::SOURCE_NONE,
                    'source_value' => '',
                    'status' => 'active',
                    'last_connection_status' => 'unknown',
                ]
            );
        }

        $order = $gates->pluck('code')->all();

        return Camera::query()
            ->with('gate')
            ->whereIn('camera_role', $order)
            ->get()
            ->sortBy(fn (Camera $camera): int => array_search($camera->camera_role, $order, true))
            ->values();
    }

    /**
     * Get the frontend-ready camera payload keyed by role.
     *
     * @return array<string, array<string, mixed>>
     */
    public function cameraPayload(bool $withSecrets = false): array
    {
        return $this->ensureRequiredCameras()
            ->mapWithKeys(fn (Camera $camera): array => [
                $camera->camera_role => $this->transformCamera($camera, $withSecrets),
            ])
            ->all();
    }

    /**
     * Save one camera's zone (normalized 0-1 to the camera picture) and its
     * gate's direction: a vehicle moving away from the camera is IN or OUT.
     * Calibration work: drawn on the gate's own camera stream, so no browser
     * camera details are kept.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): Camera
    {
        return DB::transaction(function () use ($data): Camera {
            $camera = Camera::query()->findOrFail($data['camera_id']);

            $camera->fill(['calibration_mask_json' => $data['calibration_mask'] ?? null]);
            $camera->save();

            if (array_key_exists('away_direction', $data)) {
                Gate::query()->where('code', $camera->camera_role)->update(['away_direction' => $data['away_direction'] ?: null]);
            }

            return $camera->fresh();
        });
    }

    /**
     * Phase 2: the latest crossings per gate, to check the IN direction
     * right after calibrating (drive through once, see IN or OUT here).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function recentCrossings(int $limit = 5): array
    {
        $crossings = [];

        foreach (Gate::codes() as $gate) {
            $crossings[$gate] = VehicleCrossing::query()
                ->atGate($gate)
                ->latest('crossed_at')
                ->latest('id')
                ->limit($limit)
                ->get()
                ->map(fn (VehicleCrossing $crossing): array => [
                    'id' => $crossing->id,
                    'direction' => $crossing->direction,
                    'direction_label' => $crossing->directionLabel(),
                    'reason' => $crossing->direction_reason,
                    'time' => DisplayTime::datetimeSeconds($crossing->crossed_at),
                    'track_id' => $crossing->track_id,
                    'confidence' => $crossing->confidence !== null ? round($crossing->confidence, 2) : null,
                    'vehicle_type' => $crossing->vehicle_type,
                    'type_label' => filled($crossing->vehicle_type) ? ucfirst(str_replace('_', ' ', (string) $crossing->vehicle_type)) : 'Vehicle',
                    'snapshot_url' => $crossing->snapshot_url,
                ])
                ->all();
        }

        return $crossings;
    }

    /**
     * Convert a camera record into a simple array for Blade and JavaScript.
     *
     * @return array<string, mixed>
     */
    protected function transformCamera(Camera $camera, bool $withSecrets = false): array
    {
        $sourceType = $camera->source_type ?: Camera::SOURCE_NONE;
        $sourceValue = in_array($sourceType, ['rtsp', 'url'], true) ? (string) $camera->source_value : '';
        // Camera source work: an assigned camera is shown by name and address, never by URL.
        $device = app(CameraStreams::class)->forGate($camera->camera_role)['device'];

        // Plug-and-detect: pages and page JSON never get the camera password
        // or credentials embedded in the URL; only the Python export does.
        $secrets = $withSecrets
            ? ['source_value' => $sourceValue, 'source_password' => $camera->source_password ?? '',
                'snapshot_source_value' => (string) ($camera->snapshot_source_value ?? '')]
            : ['source_value' => CameraSource::withoutCredentials($sourceValue),
                'snapshot_source_value' => CameraSource::withoutCredentials((string) ($camera->snapshot_source_value ?? ''))];

        return [
            'id' => $camera->id,
            'camera_name' => $camera->camera_name,
            'camera_role' => $camera->camera_role,
            // Phase 1: the gate's name ("Main Gate").
            'gate_name' => $camera->gate?->name ?? Gate::labelFor($camera->camera_role),
            'role_label' => $camera->gate?->name ?? Gate::labelFor($camera->camera_role),
            'source_type' => $sourceType,
            ...$secrets,
            'source_display' => $device
                ? 'Automatic · '.app(DeviceRegistryService::class)->friendlyName($device).($device->ip ? ' ('.$device->ip.')' : '')
                : CameraSource::display($sourceType, $sourceValue),
            'automatic' => $device !== null,
            'source_username' => $camera->source_username ?? '',
            'has_password' => filled($camera->source_password),
            'calibration_mask' => $camera->calibration_mask_json,
            // ROI work: a vehicle moving away from the camera is "IN" / "OUT" (null: not set).
            'away_direction' => Gate::query()->where('code', $camera->camera_role)->first()?->awayDirection(),
            'status' => $camera->status,
        ];
    }
}
