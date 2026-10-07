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
 * Calibration work (Phase 2): calibration draws on the gate's own camera,
 * the same live stream as its kiosk and Gate Monitor, never the browser's
 * camera. Its status is the detector's (as on System status); while the
 * camera is offline the last picture is shown. Addresses from RFC 5737.
 */
class CalibrationCameraSourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        File::deleteDirectory(CameraFiles::directory());

        Camera::query()->forRole('gate-1')->firstOrFail()->update([
            'source_type' => 'rtsp', 'source_value' => 'rtsp://198.51.100.20:554/stream2', 'test_webcam_index' => null,
            'calibration_mask_json' => [['x' => 0.1, 'y' => 0.2], ['x' => 0.8, 'y' => 0.2], ['x' => 0.8, 'y' => 0.9]],
            'calibration_line_json' => ['x1' => 0.1, 'y1' => 0.6, 'x2' => 0.9, 'y2' => 0.6, 'in_side' => -1],
        ]);
        Camera::query()->forRole('gate-2')->firstOrFail()->update(['source_type' => Camera::SOURCE_NONE, 'source_value' => '', 'test_webcam_index' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(CameraFiles::directory());

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $gate1
     */
    protected function detector(array $gate1, bool $running = true): void
    {
        File::ensureDirectoryExists(dirname(CameraFiles::statusPath()));
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => $running,
            'updated_at' => now()->toIso8601String(),
            'cameras' => [
                'gate-1' => ['camera_role' => 'gate-1', ...$gate1],
                'gate-2' => ['camera_role' => 'gate-2', 'camera_running' => false, 'error_code' => 'no_camera'],
            ],
        ]));
    }

    protected function lastPicture(string $gate): void
    {
        File::ensureDirectoryExists(dirname(CameraFiles::framePath($gate)));
        File::put(CameraFiles::framePath($gate), 'jpeg bytes');
    }

    protected function page(string $gate = 'gate-1'): string
    {
        return $this->actingAs($this->admin)
            ->get(route('settings.index', ['tab' => 'calibration', 'gate' => $gate]))
            ->assertOk()
            ->getContent();
    }

    protected function mjpegUrl(string $html): string
    {
        $this->assertSame(1, preg_match('/data-mjpeg-url="([^"]*)"/', $html, $match));

        return html_entity_decode($match[1]);
    }

    public function test_a_connected_camera_shows_the_gates_live_stream_without_the_browser_camera(): void
    {
        $this->detector(['camera_running' => true]);
        $html = $this->page();

        // The gate's live player, with the same stream as the gate's kiosk.
        $this->assertMatchesRegularExpression('#data-live-video\s+data-gate="gate-1"#', $html);
        $kiosk = $this->actingAs($this->admin)->get(route('gates.kiosk', 'gate-1'))->assertOk()->getContent();
        $this->assertSame($this->mjpegUrl($kiosk), $this->mjpegUrl($html));
        $this->assertStringContainsString('Connected', $html);

        // No browser camera anywhere: no helper, no picker, no permission prompt.
        foreach (['browser-camera-common.js', 'data-device-select', 'Calibration Stream', 'Allow camera access', 'getUserMedia'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
        $this->assertFileDoesNotExist(public_path('js/browser-camera-common.js'));
        $script = File::get(public_path('js/calibration-page.js'));
        $this->assertStringNotContainsString('getUserMedia', $script);
        $this->assertStringNotContainsString('enumerateDevices', $script);
        $this->assertStringNotContainsString('/stream/entrance', $script);
        $this->assertStringNotContainsString('127.0.0.1', $script);

        // The drawing canvas has its own attribute: the live player's root
        // also has data-overlay, and picking it stopped the whole page.
        $this->assertStringContainsString('<canvas class="camera-overlay" data-calibration-canvas>', $html);
        $this->assertStringNotContainsString("querySelector('[data-overlay]')", $script);
    }

    public function test_the_saved_zone_line_and_in_side_are_on_the_page(): void
    {
        $this->detector(['camera_running' => true]);
        $html = $this->page();

        $this->assertSame(1, preg_match('#<script id="camera-calibration-data" type="application/json">(.*?)</script>#s', $html, $match));
        $camera = json_decode($match[1], true)['cameras']['gate-1'];
        $this->assertCount(3, $camera['calibration_mask']);
        $this->assertSame(-1, $camera['calibration_line']['in_side']);
        $this->assertArrayNotHasKey('browser_device_id', $camera);
        $this->assertStringContainsString('3-point zone saved', $html);
        $this->assertStringContainsString('Line saved', $html);
    }

    public function test_an_offline_camera_shows_its_last_picture_and_why(): void
    {
        $this->detector([
            'camera_running' => false,
            'error_code' => 'timeout',
            'last_error' => 'The camera did not answer. Check its LAN cable and power.',
            'offline_since' => now()->subMinutes(5)->toIso8601String(),
        ]);
        $this->lastPicture('gate-1');
        $html = $this->page();

        $this->assertStringContainsString('Offline', $html);
        $this->assertStringContainsString('The camera did not answer.', $html);
        $this->assertStringContainsString('src="'.route('camera.frame', ['gate-1', 'latest'], false).'?t=', $html);
        $this->assertStringContainsString('data-picture-badge', $html);

        $this->actingAs($this->admin)->getJson(route('calibration.heartbeat'))
            ->assertOk()
            ->assertJsonPath('gates.gate-1.has_camera', true)
            ->assertJsonPath('gates.gate-1.connection.state', 'offline')
            ->assertJsonPath('gates.gate-1.connection.label', 'Offline')
            ->assertJsonPath('gates.gate-1.connection.reason', 'The camera did not answer.')
            ->assertJsonPath('gates.gate-1.snapshot_url', fn ($url) => str_starts_with((string) $url, '/camera/gate-1/frame/latest?t='));

        // The picture itself is served to signed-in users.
        $this->actingAs($this->admin)->get(route('camera.frame', ['gate-1', 'latest']))->assertOk();
    }

    public function test_a_camera_that_just_dropped_is_reconnecting_and_a_stopped_detector_is_offline(): void
    {
        $this->detector(['camera_running' => false, 'last_error' => 'Reconnecting to the camera.', 'offline_since' => now()->subSeconds(10)->toIso8601String()]);
        $this->actingAs($this->admin)->getJson(route('calibration.heartbeat'))
            ->assertJsonPath('gates.gate-1.connection.state', 'reconnecting')
            ->assertJsonPath('gates.gate-1.connection.label', 'Reconnecting');

        $this->detector(['camera_running' => true], running: false);
        $this->actingAs($this->admin)->getJson(route('calibration.heartbeat'))
            ->assertJsonPath('gates.gate-1.connection.state', 'offline')
            ->assertJsonPath('gates.gate-1.connection.reason', 'The detector is not running.');
    }

    public function test_a_gate_without_a_camera_offers_to_add_one(): void
    {
        $this->detector(['camera_running' => true]);
        $this->lastPicture('gate-2'); // an old picture from a removed camera is not shown
        $html = $this->page('gate-2');

        $this->assertStringContainsString('This gate has no camera yet.', $html);
        $this->assertStringContainsString('href="'.e(route('settings.index', ['tab' => 'devices', 'gate' => 'gate-2', 'role' => 'camera'])).'"', $html);
        $this->assertStringContainsString('+ Add camera', $html);
        $this->assertStringNotContainsString('data-live-video', $html);
        $this->assertStringNotContainsString('data-save', $html);

        $this->actingAs($this->admin)->getJson(route('calibration.heartbeat'))
            ->assertJsonPath('gates.gate-2.has_camera', false)
            ->assertJsonPath('gates.gate-2.connection.state', 'no_camera')
            ->assertJsonPath('gates.gate-2.snapshot_url', null);
    }

    public function test_the_browser_camera_state_route_is_gone(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('camera-browser.state'));
        $this->actingAs($this->admin)->putJson('/camera-browser/state', ['camera_id' => 1])->assertNotFound();
    }

    public function test_the_dashboard_counts_cameras_the_detector_has_connected(): void
    {
        $this->detector(['camera_running' => true]);
        Camera::query()->forRole('gate-1')->update(['last_connection_status' => 'not_connected']);

        $this->actingAs($this->admin)->getJson(route('dashboard.live-state'))
            ->assertOk()
            ->assertJsonPath('camera_summary.connected', 1);
    }
}
