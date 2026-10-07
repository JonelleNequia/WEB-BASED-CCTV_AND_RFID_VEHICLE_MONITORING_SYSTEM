<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Services\CameraProbeService;
use App\Services\CameraStreams;
use App\Services\DeviceRegistryService;
use App\Services\Go2rtcService;
use App\Services\SettingsService;
use App\Support\CameraFiles;
use App\Support\DeviceFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Camera source work (Phase 1): the device (MAC) is a camera's source; its
 * stream URLs are built from its current address. RFC 5737 addresses.
 */
class CameraDeviceSourceTest extends TestCase
{
    use RefreshDatabase;

    protected const MAC = '34:F7:16:00:00:01';

    protected User $admin;

    public bool $onvif = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $this->app->useStoragePath($storage = sys_get_temp_dir().'/camera-source-test-'.uniqid());
        File::ensureDirectoryExists($storage.'/logs');
        File::deleteDirectory(DeviceFiles::directory());
        $test = $this;

        $this->app->instance(CameraProbeService::class, new class($test) extends CameraProbeService
        {
            public function __construct(private $test)
            {
            }

            public function describe(string $url, string $username = '', string $password = ''): array
            {
                return $username === 'admin' && $password === 'secret'
                    ? ['result' => self::OK, 'status' => 200, 'message' => 'ok', 'codec' => 'H264']
                    : ['result' => self::UNAUTHORIZED, 'status' => 401, 'message' => 'rejected'];
            }

            public function onvifStreams(string $deviceServiceUrl, string $username, string $password): array
            {
                // GetProfiles + GetStreamUri of a camera with two profiles (widest first).
                return $this->test->onvif
                    ? ['result' => self::OK, 'message' => 'ok', 'streams' => [
                        ['token' => 'main', 'uri' => 'rtsp://198.51.100.20:8554/h264/ch1/main', 'width' => 2560],
                        ['token' => 'minor', 'uri' => 'rtsp://198.51.100.20:8554/h264/ch1/sub', 'width' => 736],
                        // Measured on the VIGI C240: a third, smaller profile (stream6) that is not the sub stream.
                        ['token' => 'third', 'uri' => 'rtsp://198.51.100.20:8554/h264/ch1/third', 'width' => 640],
                    ]]
                    : ['result' => self::UNREACHABLE, 'streams' => [], 'message' => 'no onvif'];
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(DeviceFiles::directory());
        File::delete(CameraFiles::statusPath());

        parent::tearDown();
    }

    public function test_assigning_a_camera_reads_its_streams_over_onvif_without_typing_a_url(): void
    {
        $this->assign();

        $camera = Camera::query()->forRole('gate-1')->firstOrFail();
        $this->assertSame(['device', ''], [$camera->source_type, (string) $camera->source_value]);
        $options = DeviceAssignment::query()->where('station', 'gate-1')->where('role', 'camera')->value('options');
        $this->assertSame([['main' => '/h264/ch1/main', 'sub' => '/h264/ch1/sub'], 'onvif', 8554], [$options['paths'], $options['paths_from'], $options['rtsp_port']]);

        // Detection: the sub stream; snapshots: the main one; the login goes only to the detector.
        $detector = $this->detectorCamera();
        $this->assertSame(['rtsp', 'rtsp://198.51.100.20:8554/h264/ch1/sub', 'rtsp://198.51.100.20:8554/h264/ch1/main', 'secret'],
            [$detector['source_type'], $detector['source_value'], $detector['snapshot_source_value'], $detector['source_password']]);
        // Live view (go2rtc): the main stream.
        $this->assertSame(['gate-1' => 'rtsp://admin:secret@198.51.100.20:8554/h264/ch1/main'], app(Go2rtcService::class)->streams());
    }

    public function test_without_onvif_the_brands_known_paths_are_used(): void
    {
        $this->onvif = false;
        $this->assign();

        $options = DeviceAssignment::query()->where('station', 'gate-1')->where('role', 'camera')->value('options');
        $this->assertSame([['main' => '/stream1', 'sub' => '/stream2'], 'brand'], [$options['paths'], $options['paths_from']]);
        $this->assertSame('rtsp://198.51.100.20:554/stream2', $this->detectorCamera()['source_value']);
    }

    public function test_a_new_address_for_the_same_mac_keeps_working_on_another_network(): void
    {
        $this->assign();
        $calibration = ['x1' => 0.1, 'y1' => 0.6, 'x2' => 0.9, 'y2' => 0.6, 'in_side' => -1];
        Camera::query()->forRole('gate-1')->update(['calibration_line_json' => json_encode($calibration)]);

        // Plugged into another router: the scan finds the same MAC at another address.
        app(DeviceRegistryService::class)->ingestScan($this->scan('203.0.113.'));

        $detector = $this->detectorCamera();
        $this->assertSame('rtsp://203.0.113.20:8554/h264/ch1/sub', $detector['source_value']);
        $this->assertSame('secret', $detector['source_password'], 'The login stays.');
        $this->assertSame(-1, $detector['calibration_line']['in_side'], 'The calibration stays.');
        $this->assertSame(['gate-1' => 'rtsp://admin:secret@203.0.113.20:8554/h264/ch1/main'], app(Go2rtcService::class)->streams());
        $this->assertSame('', (string) Camera::query()->forRole('gate-1')->value('source_value'), 'Still no stored URL.');
    }

    public function test_a_hand_typed_camera_stays_manual_until_it_is_switched_to_automatic(): void
    {
        app(DeviceRegistryService::class)->ingestScan($this->scan());
        Camera::query()->forRole('gate-1')->firstOrFail()->forceFill([
            'source_type' => 'rtsp', 'source_value' => 'rtsp://198.51.100.20:554/stream1', 'source_username' => 'admin', 'source_password' => 'secret',
        ])->save();

        // Manual at first: nothing is assigned by itself, the card offers it.
        $this->assertSame(0, DeviceAssignment::query()->count());
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()
            ->assertSee('This camera was found on the network')->assertSee('Use automatically');

        $this->actingAs($this->admin)->post(route('settings.gate.camera.automatic', 'gate-1'))
            ->assertSessionHas('status', 'The camera is now found automatically.');
        $camera = Camera::query()->forRole('gate-1')->firstOrFail();
        $this->assertSame(['device', 'secret'], [$camera->source_type, $camera->source_password]);
        $this->assertSame(NetworkDevice::query()->where('mac', self::MAC)->value('id'), DeviceAssignment::query()->where('station', 'gate-1')->value('network_device_id'));
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertDontSee('Use automatically');
    }

    public function test_settings_show_the_device_not_a_url_and_have_no_webcam(): void
    {
        $this->assign();

        $html = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'manual']))->assertOk()->getContent();
        $gate1 = substr($html, strpos($html, 'Gate 1 Camera'), 6000);
        $this->assertStringContainsString('Automatic: found by its MAC address '.self::MAC, $gate1);
        $this->assertStringNotContainsString('name="camera_configs[gate-1][source_value]"', $html);
        // A gate without a camera: manual source in Advanced, with the warning, no webcam.
        $this->assertStringContainsString('name="camera_configs[gate-2][source_value]"', $html);
        $this->assertStringContainsString('A typed address does not follow the camera', $html);
        foreach (['Webcam', 'Saved Browser Device', 'a number such as 0'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $html, $legacy);
        }

        // Saving the page keeps the automatic camera (it sends no source for it).
        $this->actingAs($this->admin)->put(route('settings.update'), [
            'section' => 'manual',
            'camera_configs' => [
                'gate-1' => ['camera_name' => 'Gate 1 Camera', 'source_username' => 'admin', 'source_password' => ''],
                'gate-2' => ['camera_name' => 'Gate 2 Camera', 'source_type' => 'webcam', 'source_value' => '0'],
            ],
        ])->assertSessionHasErrors('camera_configs.gate-2.source_type');
        $this->actingAs($this->admin)->put(route('settings.update'), [
            'section' => 'manual',
            'camera_configs' => [
                'gate-1' => ['camera_name' => 'Gate 1 Camera', 'source_username' => 'admin', 'source_password' => ''],
                'gate-2' => ['camera_name' => 'Gate 2 Camera', 'source_type' => 'none', 'source_value' => ''],
            ],
        ])->assertSessionHasNoErrors();
        $this->assertSame('device', Camera::query()->forRole('gate-1')->value('source_type'));
        $this->assertSame('rtsp://198.51.100.20:8554/h264/ch1/sub', $this->detectorCamera()['source_value']);
    }

    public function test_a_camera_with_an_address_from_another_network_says_to_use_dhcp(): void
    {
        $this->assign();
        File::ensureDirectoryExists(DeviceFiles::directory());
        File::put(DeviceFiles::statusPath(), json_encode(['service_running' => true, 'updated_at' => now()->toIso8601String(),
            'network' => ['interfaces' => [['name' => 'eth0', 'kind' => 'ethernet', 'ip' => '203.0.113.2', 'network' => '203.0.113.0/24', 'gateway' => '203.0.113.1']]]]));
        File::ensureDirectoryExists(dirname(CameraFiles::statusPath()));
        File::put(CameraFiles::statusPath(), json_encode(['service_running' => true, 'cameras' => ['gate-1' => ['camera_running' => false, 'error_code' => 'unreachable']]]));

        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()
            ->assertSee('The camera still has an address from another network.')
            ->assertSee('Set the camera to DHCP');
    }

    public function test_the_migration_turns_assigned_cameras_into_devices_and_drops_webcams(): void
    {
        $this->assign();
        DB::table('cameras')->where('camera_role', 'gate-1')->update(['source_type' => 'rtsp', 'source_value' => 'rtsp://198.51.100.20:554/stream2']);
        DB::table('device_assignments')->update(['options' => json_encode(['stream' => 'sub', 'path' => '/stream2', 'snapshot_path' => '/stream1', 'rtsp_port' => 554])]);
        DB::table('cameras')->where('camera_role', 'gate-2')->update(['source_type' => 'webcam', 'source_value' => '0']);

        (require database_path('migrations/2026_10_07_000001_camera_device_sources.php'))->up();

        $this->assertSame(['device', ''], [DB::table('cameras')->where('camera_role', 'gate-1')->value('source_type'), DB::table('cameras')->where('camera_role', 'gate-1')->value('source_value')]);
        $this->assertSame('none', DB::table('cameras')->where('camera_role', 'gate-2')->value('source_type'));
        $this->assertSame(['main' => '/stream1', 'sub' => '/stream2'], json_decode(DB::table('device_assignments')->value('options'), true)['paths']);
        $this->assertSame('rtsp://198.51.100.20:554/stream2', app(CameraStreams::class)->forGate('gate-1')['live']);
    }

    public function test_a_gate_can_use_this_pcs_webcam_for_testing_and_go_back_to_its_cctv(): void
    {
        $this->assign();

        $this->actingAs($this->admin)->post(route('settings.gate.camera.webcam', 'gate-1'), ['enabled' => 1])
            ->assertSessionHas('status', "Gate 1 uses this PC's webcam for testing. The picture appears in a few seconds.");
        $detector = $this->detectorCamera();
        $this->assertSame(['webcam', 0], [$detector['source_type'], $detector['source_value']]);
        $this->assertSame([], app(Go2rtcService::class)->streams(), 'The webcam uses the basic live view.');
        $card = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()->getContent();
        $this->assertStringContainsString(e("This PC's webcam (testing) · the CCTV is used again when you stop it"), $card);
        $this->assertStringContainsString('Use the CCTV again', $card);
        $this->assertTrue(DeviceAssignment::query()->where('station', 'gate-1')->where('role', 'camera')->exists(), 'The CCTV stays assigned.');

        $this->actingAs($this->admin)->post(route('settings.gate.camera.webcam', 'gate-1'), ['enabled' => 0])
            ->assertSessionHas('status', 'Gate 1 uses its CCTV again.');
        $this->assertSame('rtsp://198.51.100.20:8554/h264/ch1/sub', $this->detectorCamera()['source_value']);

        // A gate without any camera offers the webcam under "+ Add camera".
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertSee("or use this PC's webcam for testing", false);
    }

    protected function assign(): void
    {
        app(DeviceRegistryService::class)->ingestScan($this->scan());
        $this->actingAs($this->admin)
            ->postJson(route('settings.devices.assign', NetworkDevice::query()->where('mac', self::MAC)->firstOrFail()), [
                'station' => 'gate-1', 'role' => 'camera', 'username' => 'admin', 'password' => 'secret',
            ])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    protected function detectorCamera(): array
    {
        app(SettingsService::class)->exportCameraRuntimeConfig();

        return json_decode(File::get(app(SettingsService::class)->cameraRuntimeConfigPath()), true)['cameras']['gate-1'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function scan(string $prefix = '198.51.100.'): array
    {
        return ['scan' => ['complete' => true], 'devices' => [[
            'key' => self::MAC, 'mac' => self::MAC, 'ip' => $prefix.'20', 'reachable' => true, 'online' => true,
            'kind' => 'camera', 'confidence' => 'confirmed', 'name' => 'VIGI C240',
            'camera' => ['rtsp_port' => 554, 'onvif_xaddr' => 'http://'.$prefix.'20:2020/onvif/device_service', 'vendor_profile' => 'TP-Link VIGI', 'rtsp_paths' => ['main' => '/stream1', 'sub' => '/stream2']],
        ]]];
    }
}
