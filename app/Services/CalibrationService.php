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
                    'source_type' => 'webcam',
                    'source_value' => '0',
                    'status' => 'active',
                    'last_connection_status' => 'unknown',
                    'last_connection_message' => 'Waiting for browser camera access.',
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
     * Save browser-selected device details and calibration shapes for one camera.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): Camera
    {
        return DB::transaction(function () use ($data): Camera {
            $camera = Camera::query()->findOrFail($data['camera_id']);
            $connectionStatus = (string) ($data['last_connection_status'] ?? 'connected');

            $camera->fill([
                'browser_device_id' => $data['browser_device_id'] ?? null,
                'browser_label' => $data['browser_label'] ?? null,
                'calibration_mask_json' => $data['calibration_mask'] ?? null,
                'calibration_line_json' => $this->lineWithInSide($data['calibration_line'] ?? null),
                'last_connection_status' => $connectionStatus,
                'last_connection_message' => $data['last_connection_message'] ?? null,
            ]);

            if ($connectionStatus === 'connected') {
                $camera->last_connected_at = now();
            }

            $camera->save();

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
                    'snapshot_url' => $crossing->snapshot_url,
                ])
                ->all();
        }

        return $crossings;
    }

    /**
     * Phase 2: every saved line says which side is IN (+1 unless flipped).
     *
     * @param  array<string, mixed>|null  $line
     * @return array<string, mixed>|null
     */
    protected function lineWithInSide(?array $line): ?array
    {
        if (! $line) {
            return null;
        }

        return [
            'x1' => (float) $line['x1'],
            'y1' => (float) $line['y1'],
            'x2' => (float) $line['x2'],
            'y2' => (float) $line['y2'],
            'in_side' => (int) ($line['in_side'] ?? 1) < 0 ? -1 : 1,
        ];
    }

    /**
     * Save the latest browser connection state even when calibration is unchanged.
     *
     * @param  array<string, mixed>  $data
     */
    public function syncBrowserState(array $data): Camera
    {
        return DB::transaction(function () use ($data): Camera {
            $camera = Camera::query()->findOrFail($data['camera_id']);
            $connectionStatus = (string) ($data['last_connection_status'] ?? 'unknown');

            $camera->fill([
                'browser_device_id' => $data['browser_device_id'] ?? $camera->browser_device_id,
                'browser_label' => $data['browser_label'] ?? $camera->browser_label,
                'last_connection_status' => $connectionStatus,
                'last_connection_message' => $data['last_connection_message'] ?? null,
            ]);

            if ($connectionStatus === 'connected') {
                $camera->last_connected_at = now();
            }

            $camera->save();

            return $camera->fresh();
        });
    }

    /**
     * Convert a camera record into a simple array for Blade and JavaScript.
     *
     * @return array<string, mixed>
     */
    protected function transformCamera(Camera $camera, bool $withSecrets = false): array
    {
        $sourceType = $camera->source_type ?: 'webcam';
        $sourceValue = $camera->source_value ?: '0';

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
            'source_display' => CameraSource::display($sourceType, $sourceValue),
            'source_username' => $camera->source_username ?? '',
            'has_password' => filled($camera->source_password),
            'browser_device_id' => $camera->browser_device_id,
            'browser_label' => $camera->browser_label,
            'calibration_mask' => $camera->calibration_mask_json,
            'calibration_line' => $camera->calibration_line_json,
            'last_connection_status' => $camera->last_connection_status ?: 'unknown',
            'last_connection_message' => $camera->last_connection_message ?: 'Waiting for browser camera access.',
            'last_connected_at' => $camera->last_connected_at?->toIso8601String(),
            'last_connected_at_display' => DisplayTime::datetime($camera->last_connected_at, 'Not connected yet'),
            'status' => $camera->status,
        ];
    }
}
