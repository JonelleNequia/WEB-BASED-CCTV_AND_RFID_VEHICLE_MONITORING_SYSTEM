<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Models\VehicleCrossing;
use App\Services\CameraProbeService;
use App\Services\DeviceRegistryService;
use App\Services\Go2rtcService;
use App\Support\CameraFiles;
use App\Support\DeviceFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Shared camera work: every part (detector config, live view, gate card)
 * works for any number of gates by gate code; one camera on several gates is
 * ONE connection per stream (the VIGI-C240 refuses a 4th session per
 * stream); each gate keeps its own zone, direction and records; a refused
 * connection or an unsteady LAN is named on the card. RFC 5737 addresses.
 */
class SharedCameraTest extends TestCase
{
    use RefreshDatabase;

    protected const CAMERA_A = '34:F7:16:00:00:0A';

    protected const CAMERA_B = '34:F7:16:00:00:0B';

    protected User $admin;

    protected string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $this->storage = sys_get_temp_dir().'/shared-camera-test-'.uniqid();
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

        $camera = fn (string $mac, string $ip): array => [
            'key' => $mac, 'mac' => $mac, 'ip' => $ip, 'reachable' => true, 'kind' => 'camera', 'confidence' => 'confirmed', 'name' => 'VIGI C240',
            'camera' => ['rtsp_port' => 554, 'vendor_profile' => 'TP-Link VIGI', 'rtsp_paths' => ['main' => '/stream1', 'sub' => '/stream2']],
        ];
        app(DeviceRegistryService::class)->ingestScan(['scan' => ['complete' => true], 'devices' => [
            $camera(self::CAMERA_A, '198.51.100.20'),
            $camera(self::CAMERA_B, '198.51.100.30'),
        ]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(DeviceFiles::directory());
        File::delete(CameraFiles::statusPath());
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    public function test_two_gates_with_their_own_cameras_both_run_and_play(): void
    {
        $this->assign(self::CAMERA_A, 'gate-1');
        $this->assign(self::CAMERA_B, 'gate-2');

        $cameras = $this->runtimeCameras();
        $this->assertSame(['rtsp://198.51.100.20:554/stream2', 'rtsp://198.51.100.30:554/stream2'], [$cameras['gate-1']['source_value'], $cameras['gate-2']['source_value']]);
        $go2rtc = app(Go2rtcService::class);
        $this->assertSame(['gate-1', 'gate-2'], array_keys($go2rtc->cameraStreams()));
        $this->assertSame(['gate-1', 'gate-2'], [$go2rtc->streamFor('gate-1'), $go2rtc->streamFor('gate-2')]);

        // Both live when the detector says so; no "Shared camera" mark.
        $this->detectorStatus(['gate-1' => ['camera_running' => true], 'gate-2' => ['camera_running' => true]]);
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()->assertDontSee('data-shared-camera', false);
        $cards = collect(app(\App\Services\GateSetupService::class)->cards(\App\Models\Gate::ordered(), app(DeviceRegistryService::class)->panelPayload(), app(\App\Services\CalibrationService::class)->cameraPayload(), app(\App\Services\DetectorRuntimeService::class)->readStatus()))->keyBy('code');
        $this->assertSame([true, true], [$cards['gate-1']['camera']['online'], $cards['gate-2']['camera']['online']]);
    }

    public function test_one_camera_on_two_gates_is_one_live_stream_with_separate_zones_and_records(): void
    {
        $this->assign(self::CAMERA_A, 'gate-1');
        $this->assign(self::CAMERA_A, 'gate-2', ['share' => true]);

        // The detector gets both gates (its capture hub then opens each stream once).
        $cameras = $this->runtimeCameras();
        $this->assertSame($cameras['gate-1']['source_value'], $cameras['gate-2']['source_value']);
        $this->assertSame($cameras['gate-1']['snapshot_source_value'], $cameras['gate-2']['snapshot_source_value']);

        // go2rtc: ONE stream for the camera; Gate 2 plays Gate 1's.
        $go2rtc = app(Go2rtcService::class);
        $this->assertSame(['gate-1'], array_keys($go2rtc->cameraStreams()));
        $this->assertSame(['gate-1', 'gate-1'], [$go2rtc->streamFor('gate-1'), $go2rtc->streamFor('gate-2')]);
        $this->assertSame(['gate-1'], $go2rtc->sharedWith('gate-2'));
        $go2rtc->writeConfig();
        $this->assertSame(1, substr_count(File::get($go2rtc->configPath()), '@198.51.100.20:554/stream1'));

        Http::fake(['*' => Http::response('v=0 answer', 200)]);
        $this->actingAs($this->admin)->call('POST', route('live.webrtc', 'gate-2'), [], [], [], ['CONTENT_TYPE' => 'application/sdp'], "v=0\r\n")->assertOk();
        $this->actingAs($this->admin)->get(route('live.hls', ['file' => 'stream.m3u8', 'src' => 'gate-2']))->assertOk();
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/api/webrtc?src=gate-1'));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/api/stream.m3u8') && str_contains($request->url(), 'src=gate-1'));

        // Marked "Shared camera" on both cards.
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()
            ->assertSee('Shared camera')->assertSee('also on Gate 2')->assertSee('also on Gate 1');

        // Each gate keeps its own zone, direction and records.
        $zone = fn (float $y) => json_encode([['x' => 0, 'y' => $y], ['x' => 1, 'y' => $y], ['x' => 1, 'y' => 1], ['x' => 0, 'y' => 1]]);
        Camera::query()->forRole('gate-1')->update(['calibration_mask_json' => $zone(0.3)]);
        Camera::query()->forRole('gate-2')->update(['calibration_mask_json' => $zone(0.6)]);
        \App\Models\Gate::query()->where('code', 'gate-1')->update(['away_direction' => 'IN']);
        \App\Models\Gate::query()->where('code', 'gate-2')->update(['away_direction' => 'OUT']);
        app(\App\Services\SettingsService::class)->exportCameraRuntimeConfig();
        $cameras = $this->runtimeCameras();
        $this->assertSame([0.3, 0.6], [$cameras['gate-1']['calibration_mask'][0]['y'], $cameras['gate-2']['calibration_mask'][0]['y']]);
        $this->assertSame(['IN', 'OUT'], [$cameras['gate-1']['away_direction'], $cameras['gate-2']['away_direction']]);

        foreach (['gate-1' => 'IN', 'gate-2' => 'OUT'] as $gate => $direction) {
            $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->postJson(route('api.integration.crossings'), [
                'external_event_key' => "shared-$gate", 'camera_role' => $gate, 'direction' => $direction, 'event_time' => now()->toIso8601String(), 'track_id' => 1, 'confidence' => 0.9,
            ])->assertCreated();
        }
        $this->assertSame(['gate-1' => 'IN', 'gate-2' => 'OUT'], VehicleCrossing::query()->orderBy('gate')->pluck('direction', 'gate')->all());
    }

    public function test_a_new_gate_3_with_a_camera_is_in_the_detector_config_and_the_live_view_at_once(): void
    {
        $this->actingAs($this->admin)->post(route('settings.gates.store'), ['name' => 'Gate 3'])->assertRedirect();
        $this->assign(self::CAMERA_B, 'gate-3');

        $this->assertArrayHasKey('gate-3', $this->runtimeCameras());
        $this->assertSame('rtsp://198.51.100.30:554/stream2', $this->runtimeCameras()['gate-3']['source_value']);
        $this->assertSame('gate-3', app(Go2rtcService::class)->streamFor('gate-3'));
        $this->actingAs($this->admin)->get(route('gates.kiosk', 'gate-3'))->assertOk()->assertSee('data-gate="gate-3"', false);
    }

    public function test_moving_the_camera_from_gate_1_to_gate_2_leaves_gate_1_empty(): void
    {
        $this->assign(self::CAMERA_A, 'gate-1');
        $this->assign(self::CAMERA_A, 'gate-2', ['move' => true]);

        $cameras = $this->runtimeCameras();
        $this->assertSame(['none', 'rtsp://198.51.100.20:554/stream2'], [$cameras['gate-1']['source_type'], $cameras['gate-2']['source_value']]);
        $go2rtc = app(Go2rtcService::class);
        $this->assertSame(['gate-2'], array_keys($go2rtc->cameraStreams()));
        $this->assertNull($go2rtc->streamFor('gate-1'));
    }

    public function test_a_refused_connection_and_an_unsteady_lan_are_named_on_the_card_and_in_system_status(): void
    {
        $this->assign(self::CAMERA_A, 'gate-1');
        $this->detectorStatus(['gate-1' => ['camera_running' => false, 'error_code' => 'busy']]);
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()
            ->assertSee('Offline · The camera refused the connection: too many connections to it.')
            ->assertSee('or use one camera per gate');

        // The router lends the address for 60 s and the LAN dropped 4 times.
        File::ensureDirectoryExists(dirname(DeviceFiles::statusPath()));
        File::put(DeviceFiles::statusPath(), json_encode([
            'service_running' => true, 'updated_at' => now()->toIso8601String(),
            'network' => ['interfaces' => [['name' => 'en7', 'kind' => 'ethernet', 'ip' => '192.0.2.2', 'gateway' => '192.0.2.1', 'link_local' => false, 'network' => '192.0.2.0/24']],
                'lan_drops_10min' => 4, 'dhcp_lease_seconds' => 60],
        ]));
        $this->detectorStatus(['gate-1' => ['camera_running' => false, 'error_code' => 'timeout']]);
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()
            ->assertSee('The router lends this PC its LAN address for only 60 seconds (DHCP lease); the LAN dropped 4 times in the last 10 minutes.')
            ->assertSee('set the lease time to 1 day or more');
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'status']))->assertOk()
            ->assertSee('The LAN connection is not steady');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function assign(string $mac, string $gate, array $extra = []): void
    {
        $result = app(DeviceRegistryService::class)->assign(NetworkDevice::query()->where('mac', $mac)->firstOrFail(), $gate, 'camera', ['stream' => 'sub', 'username' => 'admin', 'password' => 'secret'] + $extra);
        $this->assertTrue($result['ok'], $result['message']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function runtimeCameras(): array
    {
        return json_decode(File::get(CameraFiles::path('camera_runtime_config.json')), true)['cameras'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $cameras
     */
    protected function detectorStatus(array $cameras): void
    {
        File::ensureDirectoryExists(dirname(CameraFiles::statusPath()));
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'cameras' => collect($cameras)->map(fn (array $camera, string $gate) => $camera + ['camera_role' => $gate, 'calibration_ready' => true])->all(),
        ]));
    }
}
