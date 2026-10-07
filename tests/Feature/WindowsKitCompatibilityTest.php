<?php

namespace Tests\Feature;

use App\Services\DetectorRuntimeService;
use App\Services\DeviceServiceRuntime;
use App\Support\CameraFiles;
use App\Support\DeviceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Windows install kit (Phase 1): services run the background programs,
 * SQLite shares its file safely, paths work on Windows.
 */
class WindowsKitCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_managed_services_a_page_never_starts_a_process(): void
    {
        config(['monitoring.services.managed' => true]);

        $status = app(DetectorRuntimeService::class)->ensureRunning();
        $this->assertFalse($status['auto_start_attempted']);
        $this->assertSame('Starting up… The detector service starts by itself.', $status['auto_start_message']);
        $this->assertFalse(app(DeviceServiceRuntime::class)->ensureRunning());
    }

    public function test_development_still_starts_them_from_the_app_by_default(): void
    {
        $this->assertFalse(config('monitoring.services.managed'));
    }

    public function test_sqlite_uses_wal_and_waits_for_a_busy_file(): void
    {
        $sqlite = config('database.connections.sqlite');

        $this->assertSame(['wal', 5000, 'normal'], [$sqlite['journal_mode'], (int) $sqlite['busy_timeout'], $sqlite['synchronous']]);
    }

    public function test_windows_absolute_folders_are_used_as_they_are(): void
    {
        config(['monitoring.camera_files_path' => 'C:\\PHILCST-VMS\\data\\camera', 'monitoring.devices.files_path' => 'D:/data/devices']);

        $this->assertSame('C:\\PHILCST-VMS\\data\\camera', CameraFiles::directory());
        $this->assertSame('D:/data/devices', DeviceFiles::directory());

        config(['monitoring.camera_files_path' => 'storage/app/camera']);
        $this->assertSame(base_path('storage/app/camera'), CameraFiles::directory());
    }

    public function test_windows_scripts_keep_crlf_and_the_env_template_fits_the_kit(): void
    {
        $attributes = (string) file_get_contents(base_path('.gitattributes'));
        foreach (['*.bat', '*.cmd', '*.ps1'] as $pattern) {
            $this->assertStringContainsString("$pattern text eol=crlf", $attributes);
        }

        $env = (string) file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
        $this->assertStringContainsString('QUEUE_CONNECTION=sync', $env);
        $this->assertStringContainsString('MONITORING_SERVICES=app', $env);
    }
}
