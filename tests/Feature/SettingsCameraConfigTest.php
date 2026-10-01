<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SettingsCameraConfigTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure saving settings writes the dual-camera runtime config file for Python.
     */
    public function test_saving_settings_writes_the_runtime_camera_config_file(): void
    {
        $this->seed(DatabaseSeeder::class);

        $configPath = app(\App\Services\SettingsService::class)->cameraRuntimeConfigPath();
        $configDir = dirname($configPath);
        File::ensureDirectoryExists($configDir);
        $originalContents = File::exists($configPath) ? File::get($configPath) : null;

        try {
            $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

            $this->actingAs($user)->put(route('settings.update'), [
                'matching_threshold_matched' => 80,
                'matching_threshold_manual_review' => 55,
                'operating_mode' => 'manual',
                'deployment_mode' => 'offline_local',
                'cctv_simulation_mode' => 'enabled',
                'rfid_simulation_mode' => 'enabled',
                'camera_source_placeholder' => 'rtsp://future-camera-source',
                'retention_days' => 30,
                'entrance_portal_label' => 'Main Entrance Portal',
                'exit_portal_label' => 'Main Exit Portal',
                'entrance_rfid_reader_name' => 'Entrance Reader Sim',
                'exit_rfid_reader_name' => 'Exit Reader Sim',
                'camera_configs' => [
                    'gate-1' => [
                        'camera_name' => 'Entrance Camera',
                        'source_type' => 'webcam',
                        'source_value' => '0',
                        'source_username' => '',
                        'source_password' => '',
                    ],
                    'gate-2' => [
                        'camera_name' => 'Exit Camera',
                        'source_type' => 'rtsp',
                        'source_value' => 'rtsp://192.168.1.50:554/stream1',
                        'source_username' => 'admin',
                        'source_password' => 'secret',
                    ],
                ],
            ])->assertRedirect();

            $this->assertTrue(File::exists($configPath));

            $config = json_decode((string) File::get($configPath), true);

            $this->assertIsArray($config);
            $this->assertSame('manual', $config['system_settings']['operating_mode']);
            $this->assertSame('offline_local', $config['system_settings']['deployment_mode']);
            $this->assertSame('enabled', $config['system_settings']['rfid_simulation_mode']);
            // Phase 1: gate names (the old label fields rename Gate 1 / Gate 2).
            $this->assertSame([['code' => 'gate-1', 'name' => 'Main Entrance Portal'], ['code' => 'gate-2', 'name' => 'Main Exit Portal']],
                array_map(fn (array $gate): array => ['code' => $gate['code'], 'name' => $gate['name']], $config['gates']));
            // Phase 6: the detector key is exported from .env, not saved in the database.
            $this->assertSame('test-detector-key', $config['system_settings']['python_api_key']);
            $this->assertDatabaseMissing('system_settings', ['setting_key' => 'python_api_key']);
            $this->assertSame('Entrance Camera', $config['cameras']['gate-1']['camera_name']);
            $this->assertSame('webcam', $config['cameras']['gate-1']['source_type']);
            $this->assertSame(0, $config['cameras']['gate-1']['source_value']);
            $this->assertSame('Exit Camera', $config['cameras']['gate-2']['camera_name']);
            $this->assertSame('rtsp', $config['cameras']['gate-2']['source_type']);
            $this->assertSame('rtsp://192.168.1.50:554/stream1', $config['cameras']['gate-2']['source_value']);
            $this->assertSame('admin', $config['cameras']['gate-2']['source_username']);
            $this->assertSame('secret', $config['cameras']['gate-2']['source_password']);

            $this->assertSame('Entrance Camera', Camera::query()->forRole('gate-1')->value('camera_name'));
            $this->assertSame('rtsp://192.168.1.50:554/stream1', Camera::query()->forRole('gate-2')->value('source_value'));
        } finally {
            if ($originalContents === null) {
                File::delete($configPath);
            } else {
                File::put($configPath, $originalContents);
            }
        }
    }

    /**
     * RTSP cameras must use the actual network stream URL, not the old webcam index.
     */
    public function test_rtsp_camera_source_rejects_webcam_index_value(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)->from(route('settings.index'))->put(route('settings.update'), [
            'matching_threshold_matched' => 80,
            'matching_threshold_manual_review' => 55,
            'operating_mode' => 'manual',
            'deployment_mode' => 'offline_local',
            'cctv_simulation_mode' => 'enabled',
            'rfid_simulation_mode' => 'enabled',
            'camera_source_placeholder' => 'rtsp://future-camera-source',
            'retention_days' => 30,
            'entrance_portal_label' => 'Main Entrance Portal',
            'exit_portal_label' => 'Main Exit Portal',
            'entrance_rfid_reader_name' => 'Entrance Reader Sim',
            'exit_rfid_reader_name' => 'Exit Reader Sim',
            'camera_configs' => [
                'gate-1' => [
                    'camera_name' => 'Entrance Camera',
                    'source_type' => 'rtsp',
                    'source_value' => '0',
                    'source_username' => '',
                    'source_password' => '',
                ],
                'gate-2' => [
                    'camera_name' => 'Exit Camera',
                    'source_type' => 'webcam',
                    'source_value' => '0',
                    'source_username' => '',
                    'source_password' => '',
                ],
            ],
        ])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHasErrors('camera_configs.gate-1.source_value');
    }
}
