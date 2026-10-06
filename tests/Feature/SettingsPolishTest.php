<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use App\Support\CameraFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * B4 (Settings): plain words on the main tabs, a colored status with one
 * line and the next step, confirmations before Remove, saved = toast.
 */
class SettingsPolishTest extends TestCase
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

    public function test_the_main_tabs_use_plain_words(): void
    {
        Camera::query()->forRole('gate-1')->first()->forceFill(['source_type' => 'rtsp', 'source_value' => 'rtsp://camera.test:554/stream1'])->save();
        $this->writeStatus(['camera_running' => false, 'error_code' => 'unreachable', 'last_error' => 'The camera is not reachable from this PC (Host is down). Check its cable and power, or scan again in Settings › Devices.']);

        foreach (['gates', 'general'] as $tab) {
            $html = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => $tab]))->assertOk()->getContent();
            // What a user reads: the page text without scripts.
            $main = strip_tags(preg_replace('#<script\b.*?</script>#s', '', substr($html, strpos($html, 'settings-page'))));
            foreach (['RTSP', 'subnet', 'MJPEG', 'MAC', 'Host is down', 'ONVIF', 'DHCP', 'IP address'] as $word) {
                $this->assertStringNotContainsString($word, $main, "{$tab}: {$word}");
            }
            $this->assertDoesNotMatchRegularExpression('/\bport\b|\d\s?ms\b|rtsp:/i', $main, $tab);
        }
    }

    public function test_each_problem_has_a_color_a_line_and_a_next_step(): void
    {
        Camera::query()->forRole('gate-1')->first()->forceFill(['source_type' => 'rtsp', 'source_value' => 'rtsp://camera.test:554/stream1'])->save();

        $this->writeStatus(['camera_running' => false, 'error_code' => 'unauthorized', 'last_error' => 'Camera login rejected (RTSP 401).',
            'detection_status' => ['code' => 'camera_offline', 'label' => 'Camera offline', 'message' => 'x', 'next_step' => 'y']]);
        $card = $this->card();
        $this->assertStringContainsString('class="status-dot"', $card); // red
        $this->assertStringContainsString('Offline · The camera rejected the login.', $card);
        $this->assertStringContainsString('→ Open ⋯ › Change login', $card);

        $this->writeStatus(['camera_running' => false, 'detection_status' => ['code' => 'connecting', 'label' => 'Connecting', 'message' => 'x', 'next_step' => '']]);
        $card = $this->card();
        $this->assertStringContainsString('class="status-dot is-warning"', $card); // yellow
        $this->assertStringContainsString('Connecting to the camera…', $card);

        $this->writeStatus(['camera_running' => true]);
        $this->assertStringContainsString('class="status-dot is-online"', $this->card()); // green
    }

    public function test_remove_and_restore_ask_first_and_saving_shows_a_message(): void
    {
        Camera::query()->forRole('gate-1')->first()->forceFill(['source_type' => 'rtsp', 'source_value' => 'rtsp://camera.test:554/stream1'])->save();
        $html = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()->getContent();
        $this->assertStringContainsString('data-confirm-title="Remove the camera?" data-confirm-label="Remove camera"', $html);
        $this->assertStringContainsString('class="settings-page"', $html);
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'timing']))->assertOk()->assertSee('data-confirm-label="Restore defaults"', false);

        $this->actingAs($this->admin)->put(route('settings.update'), ['section' => 'general', 'gates' => ['gate-1' => ['name' => 'Gate 1', 'is_active' => '1']]])
            ->assertSessionHas('status', 'General settings saved.');
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'general']))->assertSee('data-initial-toasts', false);
    }

    protected function writeStatus(array $gate1): void
    {
        File::ensureDirectoryExists(CameraFiles::directory());
        File::put(CameraFiles::statusPath(), json_encode(['service_running' => true, 'updated_at' => now()->toIso8601String(), 'cameras' => ['gate-1' => $gate1]]));
    }

    protected function card(): string
    {
        $html = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()->getContent();
        $start = strpos($html, 'data-part="camera"');

        return substr($html, $start, strpos($html, 'data-part="reader"', $start) - $start);
    }
}
