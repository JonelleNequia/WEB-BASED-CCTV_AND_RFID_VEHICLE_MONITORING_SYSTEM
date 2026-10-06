<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\Gate;
use App\Models\User;
use App\Services\CameraProbeService;
use App\Services\SettingsService;
use App\Support\CameraFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * B1 + B3 (Settings): Gates (a card per gate: camera, RFID reader, detection
 * zone), General (gate names), Advanced (everything technical).
 */
class SettingsGatesLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    protected function tearDown(): void
    {
        File::delete(CameraFiles::statusPath());

        parent::tearDown();
    }

    public function test_three_tabs_and_older_links_still_open(): void
    {
        $html = $this->actingAs($this->admin)->get(route('settings.index'))->assertOk()->getContent();
        foreach (['Gates', 'General', 'Advanced'] as $tab) {
            $this->assertMatchesRegularExpression('/class="tab[^"]*"[^>]*>\s*'.$tab.'\s*</', $html, $tab);
        }
        $this->assertStringContainsString('data-gate-card="gate-1"', $html);

        $advanced = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'advanced']))->assertOk()->getContent();
        foreach (['System status', 'All network devices', 'Detection', 'Timing', 'Manual setup', 'Test Scan'] as $section) {
            $this->assertStringContainsString($section, $advanced);
        }
        // Older tab names: Gates & Readers -> Gates, Cameras -> Manual setup.
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'stations']))->assertOk()->assertSee('data-gate-card="gate-1"', false);
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'cameras']))->assertOk()->assertSee('Camera Sources');
    }

    public function test_the_gates_tab_has_no_technical_values(): void
    {
        $html = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()->getContent();
        $main = substr($html, strpos($html, 'gate-setup-grid'));

        foreach (['Reader type', 'Integration', 'Cooldown', 'MAC', 'RTSP', 'rtsp://', 'subnet', 'MJPEG', 'manual reader address', 'Open ports'] as $technical) {
            $this->assertStringNotContainsString($technical, $main, $technical);
        }
        foreach (['Camera', 'RFID Reader', 'Detection zone', '+ Add reader', 'Set up', '+ Add gate'] as $plain) {
            $this->assertStringContainsString($plain, $main, $plain);
        }
    }

    public function test_a_new_gate_starts_without_a_camera_and_has_add_buttons(): void
    {
        $this->actingAs($this->admin)->post(route('settings.gates.store'), ['name' => 'Back Gate']);
        $this->assertSame(Camera::SOURCE_NONE, Camera::query()->forRole('gate-3')->value('source_type'));

        $html = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()->getContent();
        $card = substr($html, strpos($html, 'data-gate-card="gate-3"'), 4000);
        $this->assertStringContainsString('+ Add camera', $card);
        $this->assertStringContainsString('+ Add reader', $card);
        $this->assertStringContainsString('Not set up yet', $card);
        $this->assertStringContainsString('data-add-device="camera"', $card);
    }

    public function test_camera_rename_login_test_and_remove(): void
    {
        File::ensureDirectoryExists(CameraFiles::directory());
        File::put(CameraFiles::statusPath(), json_encode(['service_running' => true, 'updated_at' => now()->toIso8601String(), 'cameras' => [
            'gate-1' => ['camera_running' => false, 'last_error' => 'The camera is not reachable from this PC (Host is down). Check it.',
                'detection_status' => ['code' => 'camera_offline', 'label' => 'Camera offline', 'message' => 'The camera is not reachable from this PC (Host is down).', 'next_step' => "Check the camera's LAN cable and power.", 'retry_in' => 8]],
        ]]));
        Camera::query()->forRole('gate-1')->first()->forceFill(['source_type' => 'rtsp', 'source_value' => 'rtsp://camera.test:554/stream1'])->save();

        $card = $this->card('gate-1');
        $this->assertStringContainsString('Offline · The camera is not reachable from this PC (Host is down).', $card);
        $this->assertStringContainsString('→ Check the camera&#039;s LAN cable and power.', $card);
        foreach (['>Rename<', '>Change login<', '>Test<', '>Remove…<'] as $item) {
            $this->assertStringContainsString($item, $card);
        }

        $this->actingAs($this->admin)->patch(route('settings.gate.camera.name', 'gate-1'), ['camera_name' => 'Front Camera'])->assertRedirect();
        $this->mock(CameraProbeService::class, fn ($mock) => $mock->shouldReceive('describe')
            ->andReturn(['result' => CameraProbeService::UNAUTHORIZED, 'status' => 401, 'message' => '']));
        $this->actingAs($this->admin)->patch(route('settings.gate.camera.login', 'gate-1'), ['source_username' => 'guard', 'source_password' => 's3cret-pass'])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'rejected the login'));
        $camera = Camera::query()->forRole('gate-1')->first();
        $this->assertSame(['Front Camera', 'guard', 's3cret-pass'], [$camera->camera_name, $camera->source_username, $camera->source_password]);
        $this->assertStringNotContainsString('s3cret-pass', (string) DB::table('cameras')->where('camera_role', 'gate-1')->value('source_password'));
        $this->assertStringNotContainsString('s3cret-pass', $this->card('gate-1'));

        $this->actingAs($this->admin)->postJson(route('settings.gate.camera.test', 'gate-1'))
            ->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('message', 'The camera rejected the login. Use "Change login".');

        $this->actingAs($this->admin)->delete(route('settings.gate.camera.remove', 'gate-1'))->assertRedirect();
        $this->assertSame(Camera::SOURCE_NONE, Camera::query()->forRole('gate-1')->value('source_type'));
        $this->assertStringContainsString('+ Add camera', $this->card('gate-1'));
        // The exported detector config says the gate has no camera.
        $config = json_decode(File::get(app(SettingsService::class)->cameraRuntimeConfigPath()), true);
        $this->assertSame('none', $config['cameras']['gate-1']['source_type']);
    }

    public function test_reader_rename_and_remove_keep_names_stable(): void
    {
        $device = DB::table('network_devices')->insertGetId(['device_key' => 'AA:BB:CC:00:00:09', 'mac' => 'AA:BB:CC:00:00:09', 'ip' => '192.0.2.50', 'kind' => 'rfid_reader', 'name' => 'UHF RFID Reader', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('device_assignments')->insert(['station' => 'gate-1', 'role' => DeviceAssignment::ROLE_READER, 'network_device_id' => $device, 'created_at' => now(), 'updated_at' => now()]);

        $card = $this->card('gate-1');
        $this->assertStringContainsString('data-reader-test="gate-1"', $card);
        $this->assertStringContainsString('No tag read yet', $card);

        $this->actingAs($this->admin)->patch(route('settings.gate.reader.name', 'gate-1'), ['reader_name' => 'Front Reader'])->assertRedirect();
        // Saving General (gate names) keeps the reader's own name.
        $this->actingAs($this->admin)->put(route('settings.update'), ['section' => 'general', 'gates' => ['gate-1' => ['name' => 'Front Gate', 'is_active' => '1']]])->assertRedirect();
        $gate = Gate::query()->where('code', 'gate-1')->firstOrFail();
        $this->assertSame(['Front Gate', 'Front Reader'], [$gate->name, $gate->reader_name]);

        $this->actingAs($this->admin)->delete(route('settings.gate.reader.remove', 'gate-1'))->assertRedirect();
        $this->assertSame(0, DeviceAssignment::query()->where('station', 'gate-1')->where('role', DeviceAssignment::ROLE_READER)->count());
        $this->assertStringContainsString('+ Add reader', $this->card('gate-1'));
    }

    public function test_advanced_sections_save_and_restore_defaults(): void
    {
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'timing']))->assertOk()
            ->assertSee('Same tag ignored for (seconds)')->assertSee('Restore defaults');
        $this->actingAs($this->admin)->put(route('settings.update'), ['section' => 'timing', 'rfid_cooldown_seconds' => 120, 'rfid_lookback_seconds' => 8, 'rfid_lookahead_seconds' => 5])
            ->assertSessionHas('status', 'Timing saved.');
        $this->assertSame('120', app(SettingsService::class)->get('rfid_cooldown_seconds'));

        $this->actingAs($this->admin)->post(route('settings.restore-defaults'), ['section' => 'timing'])
            ->assertRedirect(route('settings.index', ['tab' => 'timing']));
        $this->assertSame(['60', '10', '4'], [app(SettingsService::class)->get('rfid_cooldown_seconds'), app(SettingsService::class)->get('rfid_lookback_seconds'), app(SettingsService::class)->get('rfid_lookahead_seconds')]);
        $this->actingAs($this->admin)->post(route('settings.restore-defaults'), ['section' => 'manual'])->assertSessionHasErrors('section');

        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'detection']))->assertOk()
            ->assertSee('Vehicle type')->assertSee('Counting')->assertSee('Live view performance');
    }

    public function test_set_up_opens_the_calibration_of_that_gate_only(): void
    {
        $html = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'calibration', 'gate' => 'gate-2']))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="gate-2"', $html);
        $this->assertStringNotContainsString('data-role="gate-1"', $html);
        $this->assertStringContainsString('Back to Gates', $html);
    }

    protected function card(string $gate): string
    {
        $html = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()->getContent();
        $start = strpos($html, 'data-gate-card="'.$gate.'"');
        $end = strpos($html, 'data-gate-card="', $start + 20);

        return substr($html, $start, ($end ?: strpos($html, 'gate-setup-add')) - $start);
    }
}
