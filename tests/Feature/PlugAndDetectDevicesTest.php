<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\NetworkDevice;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CameraProbeService;
use App\Services\DeviceRegistryService;
use App\Support\DeviceFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Plug-and-detect Phase 2: devices by MAC, station assignment, encrypted
 * camera login, runtime config for the Python device service.
 *
 * Addresses below are from the documentation ranges (RFC 5737).
 */
class PlugAndDetectDevicesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        File::deleteDirectory(DeviceFiles::directory());

        // Camera login check without a real camera: only admin/secret works.
        $this->app->instance(CameraProbeService::class, new class extends CameraProbeService
        {
            public array $calls = [];

            public function describe(string $url, string $username = '', string $password = ''): array
            {
                $this->calls[] = [$url, $username];

                return $username === 'admin' && $password === 'secret'
                    ? ['result' => self::OK, 'status' => 200, 'message' => 'ok']
                    : ['result' => self::UNAUTHORIZED, 'status' => 401, 'message' => 'rejected'];
            }

            public function onvifStreams(string $deviceServiceUrl, string $username, string $password): array
            {
                return ['result' => self::ERROR, 'streams' => [], 'message' => 'no onvif'];
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(DeviceFiles::directory());

        parent::tearDown();
    }

    public function test_scan_results_need_the_key_and_are_stored_by_mac(): void
    {
        $this->postJson(route('api.integration.devices'), $this->scan())->assertUnauthorized();

        $this->postJson(route('api.integration.devices'), $this->scan(), ['X-Api-Key' => 'test-detector-key'])
            ->assertOk()
            ->assertJsonPath('summary.created', 3);

        $camera = NetworkDevice::query()->where('mac', '34:F7:16:00:00:01')->firstOrFail();
        $this->assertSame(NetworkDevice::KIND_CAMERA, $camera->kind);
        $this->assertSame('198.51.100.20', $camera->ip);
        $this->assertTrue($camera->is_new);

        $reader = NetworkDevice::query()->where('mac', 'D8:A0:1D:00:00:02')->firstOrFail();
        $this->assertSame('r2000', $reader->readerDetails()['protocol']);

        $other = NetworkDevice::query()->where('device_key', 'ip:203.0.113.60')->firstOrFail();
        $this->assertSame(NetworkDevice::STATUS_UNREACHABLE, $other->status);
    }

    public function test_camera_login_is_asked_once_saved_encrypted_and_reused(): void
    {
        $registry = app(DeviceRegistryService::class);
        $registry->ingestScan($this->scan());
        $camera = NetworkDevice::query()->where('mac', '34:F7:16:00:00:01')->firstOrFail();

        $this->actingAs($this->admin)
            ->postJson(route('settings.devices.assign', $camera), ['station' => 'entrance', 'role' => 'camera'])
            ->assertStatus(422)
            ->assertJsonPath('needs_credentials', true);

        $this->actingAs($this->admin)
            ->postJson(route('settings.devices.assign', $camera), ['station' => 'entrance', 'role' => 'camera', 'username' => 'admin', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('needs_credentials', true);

        $this->actingAs($this->admin)
            ->postJson(route('settings.devices.assign', $camera), ['station' => 'entrance', 'role' => 'camera', 'username' => 'admin', 'password' => 'secret', 'stream' => 'main'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $entrance = Camera::query()->forRole('entrance')->firstOrFail();
        $this->assertSame('rtsp', $entrance->source_type);
        $this->assertSame('rtsp://198.51.100.20:554/stream1', $entrance->source_value);
        $this->assertSame('secret', $entrance->source_password);
        $raw = (string) DB::table('cameras')->where('id', $entrance->id)->value('source_password');
        $this->assertNotSame('secret', $raw);
        $this->assertSame('secret', Crypt::decryptString($raw));

        // The exit station reuses the saved login: no question this time.
        $this->actingAs($this->admin)
            ->postJson(route('settings.devices.assign', $camera), ['station' => 'exit', 'role' => 'camera', 'stream' => 'sub'])
            ->assertOk();
        $this->assertSame('rtsp://198.51.100.20:554/stream2', Camera::query()->forRole('exit')->value('source_value'));

        // Python still receives the password it needs to connect.
        $runtime = json_decode(File::get(app(\App\Services\SettingsService::class)->cameraRuntimeConfigPath()), true);
        $this->assertSame('secret', $runtime['cameras']['entrance']['source_password']);
    }

    public function test_new_ip_for_the_same_mac_keeps_the_station_and_moves_the_stream(): void
    {
        $registry = app(DeviceRegistryService::class);
        $registry->ingestScan($this->scan());
        $camera = NetworkDevice::query()->where('mac', '34:F7:16:00:00:01')->firstOrFail();
        $reader = NetworkDevice::query()->where('mac', 'D8:A0:1D:00:00:02')->firstOrFail();

        $registry->assign($camera, 'entrance', 'camera', ['username' => 'admin', 'password' => 'secret']);
        $registry->assign($reader, 'entrance', 'reader');

        // The network changed (e.g. another router): same MACs, new addresses.
        $summary = $registry->ingestScan($this->scan('192.0.2.'));

        $this->assertSame(2, $summary['moved']);
        // Live view on the sub stream (default); the main stream only for trigger snapshots.
        $this->assertSame('rtsp://192.0.2.20:554/stream2', Camera::query()->forRole('entrance')->value('source_value'));
        $this->assertSame('rtsp://192.0.2.20:554/stream1', Camera::query()->forRole('entrance')->value('snapshot_source_value'));
        $this->assertSame(1, DeviceAssignment::query()->where('station', 'entrance')->where('role', 'camera')->count());
        $this->assertSame($camera->id, DeviceAssignment::query()->where('station', 'entrance')->where('role', 'camera')->value('network_device_id'));

        $config = json_decode(File::get(DeviceFiles::runtimeConfigPath()), true);
        $this->assertSame('192.0.2.30', $config['stations']['entrance']['reader']['ip']);
        $this->assertSame('D8:A0:1D:00:00:02', $config['stations']['entrance']['reader']['mac']);
        $this->assertSame(6000, $config['stations']['entrance']['reader']['port']);
        $this->assertSame('r2000', $config['stations']['entrance']['reader']['protocol']);
        $this->assertStringEndsWith('/api/v1/integration/rfid-scans', $config['app']['rfid_ingest_url']);
    }

    public function test_one_reader_per_station_and_unassign_returns_to_nfc(): void
    {
        $registry = app(DeviceRegistryService::class);
        $scan = $this->scan();
        $scan['devices'][] = [
            'key' => 'D8:A0:1D:00:00:09', 'mac' => 'D8:A0:1D:00:00:09', 'ip' => '198.51.100.31', 'reachable' => true,
            'kind' => 'rfid_reader', 'confidence' => 'confirmed', 'reader' => ['transport' => 'tcp', 'port' => 4001, 'protocol' => 'chafon', 'confirmed' => true],
        ];
        $registry->ingestScan($scan);
        $first = NetworkDevice::query()->where('mac', 'D8:A0:1D:00:00:02')->firstOrFail();
        $second = NetworkDevice::query()->where('mac', 'D8:A0:1D:00:00:09')->firstOrFail();

        $this->actingAs($this->admin)
            ->postJson(route('settings.devices.assign', $first), ['station' => 'entrance', 'role' => 'reader'])->assertOk();
        $this->actingAs($this->admin)
            ->postJson(route('settings.devices.assign', $second), ['station' => 'entrance', 'role' => 'reader'])->assertOk();

        $this->assertSame([$second->id], DeviceAssignment::query()->where('station', 'entrance')->where('role', 'reader')->pluck('network_device_id')->all());
        $this->assertSame('uhf_ethernet', SystemSetting::query()->where('setting_key', 'entrance_reader_type')->value('setting_value'));

        $this->actingAs($this->admin)
            ->postJson(route('settings.devices.unassign'), ['station' => 'entrance', 'role' => 'reader'])->assertOk();

        $this->assertSame(0, DeviceAssignment::query()->count());
        $this->assertSame('nfc', SystemSetting::query()->where('setting_key', 'entrance_reader_type')->value('setting_value'));
        $config = json_decode(File::get(DeviceFiles::runtimeConfigPath()), true);
        $this->assertNull($config['stations']['entrance']['reader']);
    }

    public function test_a_full_scan_marks_missing_devices_offline_without_losing_them(): void
    {
        $registry = app(DeviceRegistryService::class);
        $registry->ingestScan($this->scan());
        $registry->ingestScan(['scan' => ['complete' => true], 'devices' => []]);

        $this->assertSame(3, NetworkDevice::query()->count());
        $this->assertSame(3, NetworkDevice::query()->where('status', NetworkDevice::STATUS_OFFLINE)->count());
    }

    public function test_devices_panel_scan_request_and_guidance(): void
    {
        app(DeviceRegistryService::class)->ingestScan($this->scan());

        $this->actingAs($this->admin)
            ->get(route('settings.index', ['tab' => 'stations']))
            ->assertOk()
            ->assertSee('Devices')
            ->assertSee('Scan again')
            ->assertSee('DHCP (automatic IP)', false)
            ->assertSee('data-devices-panel', false);

        $this->actingAs($this->admin)
            ->getJson(route('settings.devices.index'))
            ->assertOk()
            ->assertJsonPath('counts.cameras', 2)
            ->assertJsonPath('counts.readers', 1)
            ->assertJsonPath('devices.0.kind', 'camera')
            ->assertJsonPath('devices.0.is_new', true)
            ->assertJson(fn ($json) => $json->where('devices', fn ($devices) => collect($devices)
                ->contains(fn ($device) => $device['status'] === 'unreachable' && str_contains($device['guidance']['text'], 'DHCP')))
                ->etc());

        $this->actingAs($this->admin)->postJson(route('settings.devices.scan'))->assertOk();
        $config = json_decode(File::get(DeviceFiles::runtimeConfigPath()), true);
        $this->assertNotEmpty($config['scan_request']['id']);

        $this->actingAs($this->admin)->postJson(route('settings.devices.acknowledge'))->assertOk();
        $this->assertSame(0, NetworkDevice::query()->where('is_new', true)->count());
    }

    public function test_diagnostics_explain_an_empty_lan(): void
    {
        File::ensureDirectoryExists(DeviceFiles::directory());
        File::put(DeviceFiles::scanResultPath(), json_encode([
            'scan' => ['finished_at' => now()->toIso8601String(), 'trigger' => 'network_change', 'duration_seconds' => 5.8, 'complete' => true],
            'devices' => [],
            'diagnostics' => [
                'interfaces' => [[
                    'name' => 'en7', 'label' => 'USB LAN', 'kind' => 'ethernet', 'ip' => '198.51.100.2', 'network' => '198.51.100.0/24',
                    'gateway' => '198.51.100.1', 'link_local' => false, 'hosts_swept' => 253,
                    'os_hosts' => [['ip' => '198.51.100.1', 'mac' => 'F4:2D:06:A2:2F:70']], 'only_gateway' => true,
                ]],
                'local_network' => ['result' => 'ok'],
                'firewall' => ['enabled' => true, 'python_allowed' => true],
                'warnings' => [['code' => 'lan_only_router', 'level' => 'warning', 'message' => 'Only the router answered on USB LAN.']],
            ],
        ]));

        $this->actingAs($this->admin)
            ->getJson(route('settings.devices.index'))
            ->assertOk()
            ->assertJsonPath('diagnostics.interfaces.0.hosts_swept', 253)
            ->assertJsonPath('diagnostics.interfaces.0.os_hosts.0.ip', '198.51.100.1')
            ->assertJsonPath('diagnostics.local_network', 'ok')
            // No status file = service not running: said first, then the scan's own warnings.
            ->assertJsonPath('diagnostics.warnings.0.code', 'service_stopped')
            ->assertJsonPath('diagnostics.warnings.1.code', 'lan_only_router');

        $this->actingAs($this->admin)
            ->get(route('settings.index', ['tab' => 'stations']))
            ->assertOk()
            ->assertSee('data-devices-diagnostics', false);
    }

    public function test_one_camera_for_both_stations_and_real_camera_errors(): void
    {
        $registry = app(DeviceRegistryService::class);
        $registry->ingestScan($this->scan());
        $camera = NetworkDevice::query()->where('mac', '34:F7:16:00:00:01')->firstOrFail();
        $registry->assign($camera, 'entrance', 'camera', ['username' => 'admin', 'password' => 'secret', 'stream' => 'main']);
        $registry->assign($camera, 'exit', 'camera', ['stream' => 'sub']);

        // What the detector reports: Entrance live, Exit rejected the login.
        File::ensureDirectoryExists(\App\Support\CameraFiles::directory());
        File::put(\App\Support\CameraFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'cameras' => [
                'entrance' => ['camera_running' => true, 'last_error' => '', 'error_code' => null],
                'exit' => ['camera_running' => false, 'error_code' => 'unauthorized',
                    'last_error' => 'Camera login rejected (RTSP 401). Enter the camera username and password in Settings › Stations & Readers › Devices.'],
            ],
        ]));

        try {
            $this->actingAs($this->admin)
                ->getJson(route('settings.devices.index'))
                ->assertOk()
                ->assertJsonPath('stations.entrance.camera.camera_running', true)
                ->assertJsonPath('stations.entrance.camera.shared_with.station', 'exit')
                ->assertJsonPath('stations.entrance.camera.shared_with.stream', 'sub')
                ->assertJsonPath('stations.exit.camera.error_code', 'unauthorized')
                ->assertJsonPath('stations.exit.camera.camera_error', fn ($error) => str_contains($error, 'RTSP 401'));

            $this->assertSame('rtsp://198.51.100.20:554/stream2', Camera::query()->forRole('exit')->value('source_value'));

            // Sidebar: live count with the reason.
            $this->actingAs($this->admin)
                ->get(route('dashboard.index'))
                ->assertSee('1/2 live · Login rejected');
        } finally {
            File::delete(\App\Support\CameraFiles::statusPath());
        }
    }

    public function test_identify_reader_request_reaches_the_device_service(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('settings.devices.identify'))
            ->assertOk()
            ->assertJsonPath('seconds', fn ($seconds) => $seconds > 0);

        $config = json_decode(File::get(DeviceFiles::runtimeConfigPath()), true);
        $this->assertNotEmpty($config['identify_request']['id']);
        $this->assertGreaterThan(0, $config['identify_request']['seconds']);
    }

    public function test_find_my_reader_request_and_progress(): void
    {
        $this->actingAs($this->admin)->postJson(route('settings.devices.find'))->assertOk();
        $config = json_decode(File::get(DeviceFiles::runtimeConfigPath()), true);
        $this->assertSame(90, $config['find_request']['seconds']);

        File::put(DeviceFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'find' => [
                'running' => false, 'phase' => 'done', 'result' => 'other_subnet', 'baseline_count' => 2,
                'message' => 'The new device uses the fixed IP 203.0.113.190.',
                'passive' => ['available' => true, 'interface' => 'en7'],
                'new_devices' => [['mac' => 'D8:A0:1D:00:00:02', 'ips' => ['203.0.113.190'], 'reachable' => false]],
                'other_subnet' => ['ip' => '203.0.113.190', 'network' => '203.0.113.0/24', 'pc_ip' => '203.0.113.254'],
            ],
        ]));

        $this->actingAs($this->admin)->getJson(route('settings.devices.index'))
            ->assertOk()
            ->assertJsonPath('find.result', 'other_subnet')
            ->assertJsonPath('find.new_devices.0.mac', 'D8:A0:1D:00:00:02')
            ->assertJsonPath('find.other_subnet.pc_ip', '203.0.113.254');

        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'stations']))
            ->assertOk()->assertSee('Find my reader');
    }

    public function test_camera_password_is_never_sent_to_the_pages(): void
    {
        Camera::query()->forRole('entrance')->firstOrFail()
            ->fill(['source_type' => 'rtsp', 'source_value' => 'rtsp://198.51.100.20:554/stream1', 'source_username' => 'admin', 'source_password' => 'Sup3rSecret'])
            ->save();

        foreach (['cameras', 'calibration', 'stations'] as $tab) {
            $this->actingAs($this->admin)
                ->get(route('settings.index', ['tab' => $tab]))
                ->assertOk()
                ->assertDontSee('Sup3rSecret');
        }

        $this->actingAs($this->admin)->get(route('stations.entrance'))->assertOk()->assertDontSee('Sup3rSecret');

        // Blank password on save keeps the saved one.
        $this->actingAs($this->admin)
            ->put(route('settings.update'), [
                'section' => 'cameras',
                'camera_configs' => [
                    'entrance' => ['camera_name' => 'Entrance Camera', 'source_type' => 'rtsp', 'source_value' => 'rtsp://198.51.100.20:554/stream1', 'source_username' => 'admin', 'source_password' => ''],
                    'exit' => ['camera_name' => 'Exit Camera', 'source_type' => 'webcam', 'source_value' => '0', 'source_username' => '', 'source_password' => ''],
                ],
            ])->assertSessionHasNoErrors();
        $this->assertSame('Sup3rSecret', Camera::query()->forRole('entrance')->firstOrFail()->source_password);
    }

    public function test_plaintext_passwords_are_encrypted_by_the_migration(): void
    {
        $id = Camera::query()->forRole('exit')->value('id');
        DB::table('cameras')->where('id', $id)->update(['source_password' => 'plain-old']);

        $migration = require database_path('migrations/2026_09_27_000002_encrypt_camera_passwords.php');
        $migration->up();

        $raw = (string) DB::table('cameras')->where('id', $id)->value('source_password');
        $this->assertNotSame('plain-old', $raw);
        $this->assertSame('plain-old', Camera::query()->findOrFail($id)->source_password);

        $migration->up(); // running twice does not double-encrypt
        $this->assertSame('plain-old', Camera::query()->findOrFail($id)->source_password);
    }

    public function test_no_device_address_is_hardcoded_in_app_views_or_scripts(): void
    {
        $offenders = [];
        foreach ([app_path(), resource_path('views'), public_path('js')] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                foreach (file($file->getPathname()) as $number => $line) {
                    if (preg_match_all('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', $line, $matches)) {
                        foreach ($matches[0] as $address) {
                            // Loopback ("this PC") and the any-address are not device addresses.
                            if (! in_array($address, ['127.0.0.1', '0.0.0.0'], true)) {
                                $offenders[] = $file->getRelativePathname().':'.($number + 1).' '.$address;
                            }
                        }
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * @return array<string, mixed>
     */
    protected function scan(string $prefix = '198.51.100.'): array
    {
        return [
            'scan' => ['id' => 'scan-'.$prefix, 'complete' => true],
            'devices' => [
                [
                    'key' => '34:F7:16:00:00:01', 'mac' => '34:F7:16:00:00:01', 'ip' => $prefix.'20', 'reachable' => true, 'online' => true,
                    'kind' => 'camera', 'confidence' => 'confirmed', 'vendor' => 'TP-LINK TECHNOLOGIES CO.,LTD.', 'brand' => 'TP-Link VIGI', 'name' => 'VIGI C340',
                    'camera' => ['rtsp_port' => 554, 'vendor_profile' => 'TP-Link VIGI', 'rtsp_paths' => ['main' => '/stream1', 'sub' => '/stream2']],
                    'open_ports' => ['tcp' => [80, 554]],
                ],
                [
                    'key' => 'D8:A0:1D:00:00:02', 'mac' => 'D8:A0:1D:00:00:02', 'ip' => $prefix.'30', 'reachable' => true, 'online' => true,
                    'kind' => 'rfid_reader', 'confidence' => 'confirmed', 'name' => 'UHF RFID reader',
                    'reader' => ['transport' => 'tcp', 'port' => 6000, 'protocol' => 'r2000', 'work_mode' => 'active', 'confirmed' => true],
                    'open_ports' => ['tcp' => [6000]],
                ],
                [
                    'key' => 'ip:203.0.113.60', 'mac' => null, 'ip' => '203.0.113.60', 'reachable' => false,
                    'kind' => 'camera', 'confidence' => 'confirmed', 'name' => 'Camera on another subnet',
                    'camera' => ['rtsp_port' => null, 'onvif_xaddr' => 'http://203.0.113.60:2020/onvif/device_service'],
                ],
            ],
        ];
    }
}
