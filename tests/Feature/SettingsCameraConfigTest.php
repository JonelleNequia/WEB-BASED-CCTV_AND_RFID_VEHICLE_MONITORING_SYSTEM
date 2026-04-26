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

        $configPath = storage_path('app/camera/camera_runtime_config.json');
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
                'python_api_key' => 'PHILCST-DEMO-KEY',
                'camera_source_placeholder' => 'rtsp://future-camera-source',
                'retention_days' => 30,
                'entrance_portal_label' => 'Main Entrance Portal',
                'exit_portal_label' => 'Main Exit Portal',
                'entrance_rfid_reader_name' => 'Entrance Reader Sim',
                'exit_rfid_reader_name' => 'Exit Reader Sim',
                'camera_configs' => [
                    'entrance' => [
                        'camera_name' => 'Entrance Camera',
                        'source_type' => 'webcam',
                        'source_value' => '0',
                        'source_username' => '',
                        'source_password' => '',
                    ],
                    'exit' => [
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
            $this->assertSame('Main Entrance Portal', $config['system_settings']['entrance_portal_label']);
            $this->assertSame('Entrance Camera', $config['cameras']['entrance']['camera_name']);
            $this->assertSame('webcam', $config['cameras']['entrance']['source_type']);
            $this->assertSame(0, $config['cameras']['entrance']['source_value']);
            $this->assertSame('Exit Camera', $config['cameras']['exit']['camera_name']);
            $this->assertSame('rtsp', $config['cameras']['exit']['source_type']);
            $this->assertSame('rtsp://192.168.1.50:554/stream1', $config['cameras']['exit']['source_value']);
            $this->assertSame('admin', $config['cameras']['exit']['source_username']);
            $this->assertSame('secret', $config['cameras']['exit']['source_password']);

            $this->assertSame('Entrance Camera', Camera::query()->forRole('entrance')->value('camera_name'));
            $this->assertSame('rtsp://192.168.1.50:554/stream1', Camera::query()->forRole('exit')->value('source_value'));
        } finally {
            if ($originalContents === null) {
                File::delete($configPath);
            } else {
                File::put($configPath, $originalContents);
            }
        }
    }
}
