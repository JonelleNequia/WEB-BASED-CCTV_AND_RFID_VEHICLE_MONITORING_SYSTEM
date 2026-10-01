<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Support\CameraFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Phase 6: camera files behind login, detector key from .env, protected log APIs.
 */
class Phase6SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $this->guard = User::query()->create([
            'name' => 'Gate Guard',
            'email' => 'guard@philcst.local',
            'password' => 'password',
            'role' => 'guard',
        ]);

        // Never touch the real storage/app/camera (phpunit.xml sets CAMERA_FILES_PATH).
        $this->assertStringContainsString('framework/testing', CameraFiles::directory());
        File::deleteDirectory(CameraFiles::directory());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(CameraFiles::directory());

        parent::tearDown();
    }

    public function test_camera_files_are_stored_outside_public(): void
    {
        $this->assertStringStartsNotWith(public_path(), CameraFiles::statusPath());
        $this->assertStringStartsNotWith(public_path(), CameraFiles::framePath('gate-1'));
        $this->assertStringStartsNotWith(public_path(), CameraFiles::framePath('gate-2', 'annotated'));
    }

    public function test_camera_frame_needs_a_signed_in_user(): void
    {
        File::ensureDirectoryExists(dirname(CameraFiles::framePath('gate-1')));
        File::put(CameraFiles::framePath('gate-1'), 'jpeg-bytes');

        $this->get(route('camera.frame', ['role' => 'gate-1']))
            ->assertRedirect(route('login'));

        $response = $this->actingAs($this->guard)
            ->get(route('camera.frame', ['role' => 'gate-1']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->actingAs($this->guard)
            ->get(route('camera.frame', ['role' => 'gate-2', 'kind' => 'annotated']))
            ->assertNotFound();
    }

    public function test_camera_status_is_admin_only_and_hides_camera_passwords(): void
    {
        File::ensureDirectoryExists(dirname(CameraFiles::statusPath()));
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => false,
            'cameras' => [
                'gate-2' => ['source_value' => 'rtsp://admin:secret@192.168.1.64:554/stream1'],
            ],
        ]));

        $this->getJson(route('camera.status'))->assertUnauthorized();
        $this->actingAs($this->guard)->getJson(route('camera.status'))->assertForbidden();

        $this->actingAs($this->admin)
            ->getJson(route('camera.status'))
            ->assertOk()
            ->assertJsonPath('cameras.gate-2.source_value', 'rtsp://***@192.168.1.64:554/stream1')
            ->assertDontSee('secret');
    }

    public function test_log_feeds_need_login_and_admin_logs_need_admin(): void
    {
        foreach (['api.recent-station-logs', 'api.recent-guest-logs', 'api.recent-event-logs'] as $route) {
            $this->getJson(route($route))->assertUnauthorized();
        }

        $this->actingAs($this->guard)->getJson(route('api.recent-station-logs'))->assertOk();
        $this->actingAs($this->guard)->getJson(route('api.recent-guest-logs'))->assertForbidden();
        $this->actingAs($this->guard)->getJson(route('api.recent-event-logs'))->assertForbidden();

        $this->actingAs($this->admin)->getJson(route('api.recent-guest-logs'))->assertOk();
        $this->actingAs($this->admin)->getJson(route('api.recent-event-logs'))->assertOk();
    }

    public function test_detector_api_uses_the_env_key_and_rejects_the_old_demo_key(): void
    {
        $query = ['camera_role' => 'gate-1', 'event_time' => now()->toIso8601String()];

        $this->withHeaders(['X-Api-Key' => 'PHILCST-DEMO-KEY'])
            ->getJson(route('api.latest-scan', $query))
            ->assertUnauthorized();

        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])
            ->getJson(route('api.latest-scan', $query))
            ->assertOk();

        $this->assertDatabaseMissing('system_settings', ['setting_key' => 'python_api_key']);
    }

    public function test_keyless_local_access_is_refused_in_production(): void
    {
        config(['services.detector.api_key' => '']);
        $query = ['camera_role' => 'gate-1', 'event_time' => now()->toIso8601String()];

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson(route('api.latest-scan', $query))
            ->assertOk();

        $this->app['env'] = 'production';

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson(route('api.latest-scan', $query))
            ->assertUnauthorized();
    }

    public function test_migration_removes_the_stored_demo_key(): void
    {
        SystemSetting::query()->create(['setting_key' => 'python_api_key', 'setting_value' => 'PHILCST-DEMO-KEY']);

        (require database_path('migrations/2026_09_26_000004_remove_stored_detector_api_key.php'))->up();

        $this->assertDatabaseMissing('system_settings', ['setting_key' => 'python_api_key']);
    }

    public function test_settings_page_does_not_show_the_key(): void
    {
        $this->actingAs($this->admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Set in .env (DETECTOR_API_KEY)')
            ->assertDontSee('test-detector-key');
    }
}
