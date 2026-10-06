<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Services\CameraProbeService;
use App\Services\DeviceRegistryService;
use App\Services\Go2rtcService;
use App\Support\CameraFiles;
use App\Support\DeviceFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Live view work (Phase 1): go2rtc passes each gate camera's main stream to
 * the browser over WebRTC; detection stays on the sub stream; the overlay is
 * drawn in the browser. Addresses from RFC 5737.
 */
class LiveViewWebrtcTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        // Never touch the real go2rtc folder.
        $this->storage = sys_get_temp_dir().'/live-view-test-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/logs');
        $this->app->useStoragePath($this->storage);
        File::deleteDirectory(DeviceFiles::directory());

        $this->app->instance(CameraProbeService::class, new class extends CameraProbeService
        {
            public function describe(string $url, string $username = '', string $password = ''): array
            {
                return ['result' => self::OK, 'status' => 200, 'message' => 'ok', 'codec' => 'H264'];
            }

            public function onvifStreams(string $deviceServiceUrl, string $username, string $password): array
            {
                return ['result' => self::ERROR, 'streams' => [], 'message' => 'no'];
            }
        });

        app(DeviceRegistryService::class)->ingestScan(['scan' => ['complete' => true], 'devices' => [[
            'key' => '34:F7:16:00:00:01', 'mac' => '34:F7:16:00:00:01', 'ip' => '198.51.100.20', 'reachable' => true,
            'kind' => 'camera', 'confidence' => 'confirmed', 'name' => 'VIGI C240',
            'camera' => ['rtsp_port' => 554, 'vendor_profile' => 'TP-Link VIGI', 'rtsp_paths' => ['main' => '/stream1', 'sub' => '/stream2']],
        ]]]);
        app(DeviceRegistryService::class)->assign(NetworkDevice::query()->firstOrFail(), 'gate-1', 'camera', ['username' => 'admin', 'password' => 'p@ss:word']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(DeviceFiles::directory());
        File::delete(CameraFiles::statusPath());
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    public function test_go2rtc_config_comes_from_the_gate_cameras_and_follows_changes(): void
    {
        $go2rtc = app(Go2rtcService::class);

        // Live view = the MAIN stream with the login; detection keeps the sub stream.
        $this->assertSame(['gate-1' => 'rtsp://admin:p%40ss%3Aword@198.51.100.20:554/stream1'], $go2rtc->streams());
        $this->assertSame('rtsp://198.51.100.20:554/stream2', Camera::query()->forRole('gate-1')->value('source_value'));

        $this->assertTrue($go2rtc->writeConfig());
        $config = File::get($go2rtc->configPath());
        $this->assertStringContainsString('listen: "127.0.0.1:'.config('monitoring.live.api_port').'"', $config);
        $this->assertStringContainsString('  gate-1: "rtsp://admin:p%40ss%3Aword@198.51.100.20:554/stream1"', $config);
        $this->assertStringNotContainsString('gate-2', $config, 'A gate without a camera has no live stream.');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($go2rtc->configPath())), -4), 'The file holds camera logins.');
        $this->assertFalse($go2rtc->writeConfig(), 'Unchanged: go2rtc is not restarted.');

        // A new address (DHCP) or login is written by itself.
        NetworkDevice::query()->firstOrFail()->update(['ip' => '198.51.100.21']);
        $this->assertTrue($go2rtc->writeConfig());
        $this->assertStringContainsString('@198.51.100.21:554/stream1', File::get($go2rtc->configPath()));

        // Never started in tests; the bundles are the checked files.
        $this->assertFalse($go2rtc->ensureRunning());
        foreach (config('monitoring.live.bundles') as $bundle) {
            $this->assertSame($bundle['sha256'], hash_file('sha256', base_path('tools/go2rtc/'.$bundle['file'])));
        }
    }

    public function test_video_codec_is_read_from_the_camera_answer(): void
    {
        $sdp = "v=0\r\nm=audio 0 RTP/AVP 8\r\na=rtpmap:8 PCMA/8000\r\nm=video 0 RTP/AVP 96\r\na=control:track1\r\na=rtpmap:96 %s/90000\r\n";

        $this->assertSame('H264', CameraProbeService::videoCodec(sprintf($sdp, 'H264')));
        $this->assertSame('H265', CameraProbeService::videoCodec(sprintf($sdp, 'H265')));
        $this->assertSame('H265', CameraProbeService::videoCodec(sprintf($sdp, 'HEVC')));
        $this->assertNull(CameraProbeService::videoCodec("v=0\r\nm=audio 0 RTP/AVP 8\r\n"));
    }

    public function test_h265_camera_shows_a_warning_with_the_fix_on_the_gate_card(): void
    {
        $this->cameraOnline();
        Cache::put('live-codecs.gate-1', ['main' => 'H265', 'sub' => 'H264'], now()->addMinutes(10));

        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()
            ->assertSee('The camera sends H.265 video; the full-quality live view needs H.264.')
            ->assertSee('choose H.264 for both streams');
    }

    public function test_live_routes_are_signed_in_only_and_checked(): void
    {
        $this->post(route('live.webrtc', 'gate-1'))->assertRedirect(route('login'));
        $this->get(route('live.hls', ['file' => 'stream.m3u8', 'src' => 'gate-1']))->assertRedirect(route('login'));

        $this->actingAs($this->admin)->call('POST', route('live.webrtc', 'gate-1'), [], [], [], ['CONTENT_TYPE' => 'application/sdp'], 'not an offer')
            ->assertStatus(422);
        $this->actingAs($this->admin)->post(route('live.webrtc', 'gate-9'))->assertNotFound();
        // Only go2rtc's HLS files pass, never its API (it lists camera logins).
        $this->actingAs($this->admin)->get(route('live.hls', ['file' => 'streams']))->assertNotFound();
        $this->actingAs($this->admin)->get(route('live.hls', ['file' => 'stream.m3u8', 'src' => 'gate-9']))->assertNotFound();
    }

    public function test_player_measurements_are_shown_on_system_status(): void
    {
        $this->actingAs($this->admin)->postJson(route('live.stats', 'gate-1'), ['mode' => 'webrtc', 'width' => 2560, 'height' => 1440, 'fps' => 25, 'delay_ms' => 74, 'page' => 'gate-monitor'])
            ->assertOk();
        $this->actingAs($this->admin)->postJson(route('live.stats', 'gate-1'), ['mode' => 'mjpeg', 'width' => 736, 'height' => 416, 'page' => 'kiosk'])->assertOk();
        $this->actingAs($this->admin)->postJson(route('live.stats', 'gate-1'), ['mode' => 'flash'])->assertUnprocessable();

        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'status']))->assertOk()
            ->assertSee('data-live-view-status', false)
            ->assertSee('WebRTC (full quality)')->assertSee('2560 × 1440')->assertSee('74 ms')
            ->assertSee('Basic (MJPEG)')->assertSee('736 × 416')
            ->assertSee('No camera stream for the full-quality view');
    }

    public function test_pages_use_the_webrtc_player_with_fallbacks_and_the_overlay_toggle(): void
    {
        $html = $this->actingAs($this->admin)->get(route('gates.index'))->assertOk()
            ->assertSee('data-overlay-toggle', false)->assertSee('Show detection boxes')
            ->assertSee('js/live-video.js')->assertSee('vendor/hls/hls.light.min.js')
            ->getContent();
        $this->assertMatchesRegularExpression('#data-gate="gate-1"\s+data-webrtc="1"#', $html);
        $this->assertStringContainsString('data-mjpeg-url=', $html);
        $this->assertStringContainsString('data-hls-url="'.e(route('live.hls', ['file' => 'stream.m3u8', 'src' => 'gate-1'])).'"', $html);

        $this->actingAs($this->admin)->get(route('gates.kiosk', 'gate-1'))->assertOk()->assertSee('data-live-video', false);
        // Calibration shows the video without the boxes (its own drawing tools).
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'calibration', 'gate' => 'gate-1']))->assertOk()
            ->assertSee('data-overlay="0"', false);
    }

    public function test_go2rtc_and_the_device_service_are_kept_running_by_the_scheduler(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('go2rtc:start')->expectsOutputToContain('devices:ensure-running')->assertSuccessful();
        $this->assertStringContainsString('go2rtc:start', File::get(base_path('tools/start/start-system.bat')));
        $this->assertStringContainsString('Register-ScheduledTask', File::get(base_path('tools/start/install-windows-autostart.ps1')));
    }

    protected function cameraOnline(): void
    {
        File::ensureDirectoryExists(dirname(CameraFiles::statusPath()));
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'cameras' => ['gate-1' => ['camera_role' => 'gate-1', 'camera_running' => true, 'calibration_ready' => true]],
        ]));
    }
}
