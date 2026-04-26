<?php

namespace App\Services;

use App\Models\Camera;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CalibrationService
{
    /**
     * Required cameras for the dual-camera browser demo.
     *
     * @var array<string, array<string, string>>
     */
    protected array $requiredCameras = [
        'entrance' => [
            'camera_name' => 'PHILCST Entrance Camera',
            'source_type' => 'webcam',
            'source_value' => '0',
        ],
        'exit' => [
            'camera_name' => 'PHILCST Exit Camera',
            'source_type' => 'webcam',
            'source_value' => '1',
        ],
    ];

    /**
     * Ensure the required entrance and exit camera records exist.
     */
    public function ensureRequiredCameras(): Collection
    {
        foreach ($this->requiredCameras as $role => $defaults) {
            Camera::query()->firstOrCreate(
                ['camera_role' => $role],
                [
                    'camera_name' => $defaults['camera_name'],
                    'camera_role' => $role,
                    'source_type' => $defaults['source_type'],
                    'source_value' => $defaults['source_value'],
                    'status' => 'active',
                    'last_connection_status' => 'unknown',
                    'last_connection_message' => 'Waiting for browser camera access.',
                ]
            );
        }

        $roleOrder = array_keys($this->requiredCameras);

        return Camera::query()
            ->whereIn('camera_role', $roleOrder)
            ->get()
            ->sortBy(fn (Camera $camera): int => array_search($camera->camera_role, $roleOrder, true))
            ->values();
    }

    /**
     * Get the frontend-ready camera payload keyed by role.
     *
     * @return array<string, array<string, mixed>>
     */
    public function cameraPayload(): array
    {
        return $this->ensureRequiredCameras()
            ->mapWithKeys(fn (Camera $camera): array => [
                $camera->camera_role => $this->transformCamera($camera),
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
                'calibration_line_json' => $data['calibration_line'] ?? null,
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
    protected function transformCamera(Camera $camera): array
    {
        return [
            'id' => $camera->id,
            'camera_name' => $camera->camera_name,
            'camera_role' => $camera->camera_role,
            'role_label' => $camera->camera_role === 'entrance' ? 'Entrance Camera' : 'Exit Camera',
            'source_type' => $camera->source_type ?: 'webcam',
            'source_value' => $camera->source_value ?: ($camera->camera_role === 'entrance' ? '0' : '1'),
            'source_username' => $camera->source_username ?? '',
            'source_password' => $camera->source_password ?? '',
            'browser_device_id' => $camera->browser_device_id,
            'browser_label' => $camera->browser_label,
            'calibration_mask' => $camera->calibration_mask_json,
            'calibration_line' => $camera->calibration_line_json,
            'last_connection_status' => $camera->last_connection_status ?: 'unknown',
            'last_connection_message' => $camera->last_connection_message ?: 'Waiting for browser camera access.',
            'last_connected_at' => $camera->last_connected_at?->toIso8601String(),
            'last_connected_at_display' => $camera->last_connected_at?->format('M d, Y h:i A') ?? 'Not connected yet',
            'status' => $camera->status,
        ];
    }
}
