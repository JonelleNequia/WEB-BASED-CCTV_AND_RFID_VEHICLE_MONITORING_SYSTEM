<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\Gate;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Services\CameraProbeService;
use App\Services\DeviceRegistryService;
use App\Support\CameraFiles;
use App\Support\DeviceFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 6 (Settings): a camera and a reader can be added to every gate (Gate
 * 1, Gate 2, a new Gate 3), and a device already on another gate is moved
 * only when asked ("Already the reader of Gate 1. Move it here?").
 * RFC 5737 addresses.
 */
class AddDeviceToGateTest extends TestCase
{
    use RefreshDatabase;

    protected const CAMERA_A = '34:F7:16:00:00:0A';

    protected const CAMERA_B = '34:F7:16:00:00:0B';

    protected const READER_A = '70:19:88:00:00:0A';

    protected const READER_B = '70:19:88:00:00:0B';

    protected const UNKNOWN = '02:00:00:00:00:0C';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        File::deleteDirectory(DeviceFiles::directory());
        $this->app->instance(CameraProbeService::class, new class extends CameraProbeService
        {
            public function describe(string $url, string $username = '', string $password = ''): array
            {
                return ['result' => self::OK, 'status' => 200, 'message' => 'ok', 'codec' => 'H264'];
            }

            public function onvifStreams(string $deviceServiceUrl, string $username, string $password): array
            {
                return ['result' => self::UNREACHABLE, 'streams' => [], 'message' => 'no onvif'];
            }
        });
        app(DeviceRegistryService::class)->ingestScan($this->scan());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(DeviceFiles::directory());
        File::delete(CameraFiles::statusPath());

        parent::tearDown();
    }

    public function test_a_camera_and_a_reader_are_added_to_gate_1_and_gate_2(): void
    {
        $this->assign(self::CAMERA_A, 'gate-1', 'camera')->assertOk()->assertJsonPath('ok', true);
        $this->assign(self::READER_A, 'gate-1', 'reader')->assertOk();
        $this->assign(self::CAMERA_B, 'gate-2', 'camera')->assertOk();
        $this->assign(self::READER_B, 'gate-2', 'reader')->assertOk();

        $this->assertSame([
            'gate-1' => ['camera' => self::CAMERA_A, 'reader' => self::READER_A],
            'gate-2' => ['camera' => self::CAMERA_B, 'reader' => self::READER_B],
        ], $this->assignments(['gate-1', 'gate-2']));
    }

    public function test_a_new_gate_gets_its_camera_and_reader_right_after_it_is_added(): void
    {
        // Added from the gate cards; its camera and reader are added at once (no other page opened in between).
        $this->actingAs($this->admin)->post(route('settings.gates.store'), ['name' => 'Back Gate'])->assertRedirect();
        $gate = Gate::query()->where('name', 'Back Gate')->firstOrFail();

        $this->assign(self::CAMERA_B, $gate->code, 'camera')->assertOk()->assertJsonPath('ok', true);
        $this->assign(self::READER_B, $gate->code, 'reader')->assertOk();

        $this->assertSame([$gate->code => ['camera' => self::CAMERA_B, 'reader' => self::READER_B]], $this->assignments([$gate->code]));
        $this->assertSame('Back Gate Camera', Camera::query()->forRole($gate->code)->value('camera_name'));
        $this->assertArrayHasKey($gate->code, json_decode(File::get(app(\App\Services\SettingsService::class)->cameraRuntimeConfigPath()), true)['cameras']);
    }

    public function test_a_reader_on_another_gate_is_moved_only_when_asked(): void
    {
        $this->assign(self::READER_A, 'gate-1', 'reader')->assertOk();

        // Asked first: never silently the reader of two gates.
        $this->assign(self::READER_A, 'gate-2', 'reader')
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('conflict.station', 'gate-1')
            ->assertJsonPath('conflict.can_share', false)
            ->assertJsonPath('message', 'This reader is already the reader of Gate 1. Move it to Gate 2?');
        $this->assertSame(['gate-1' => ['camera' => null, 'reader' => self::READER_A], 'gate-2' => ['camera' => null, 'reader' => null]], $this->assignments(['gate-1', 'gate-2']));

        $this->assign(self::READER_A, 'gate-2', 'reader', ['move' => true])->assertOk()
            ->assertJsonPath('message', 'Gate 2 reader assigned (moved from Gate 1). Tags read at this reader are logged at Gate 2.');
        $this->assertSame(['gate-1' => ['camera' => null, 'reader' => null], 'gate-2' => ['camera' => null, 'reader' => self::READER_A]], $this->assignments(['gate-1', 'gate-2']));
    }

    public function test_a_camera_on_another_gate_is_moved_or_shared_only_when_asked(): void
    {
        $this->assign(self::CAMERA_A, 'gate-1', 'camera')->assertOk();

        $this->assign(self::CAMERA_A, 'gate-2', 'camera')
            ->assertStatus(409)
            ->assertJsonPath('conflict.station', 'gate-1')
            ->assertJsonPath('conflict.can_share', true)
            ->assertJsonPath('message', 'This camera is already the camera of Gate 1. Move it to Gate 2?');

        // Testing with one camera: both gates may use it, when asked for.
        $this->assign(self::CAMERA_A, 'gate-2', 'camera', ['share' => true])->assertOk();
        $this->assertSame(['gate-1' => ['camera' => self::CAMERA_A, 'reader' => null], 'gate-2' => ['camera' => self::CAMERA_A, 'reader' => null]], $this->assignments(['gate-1', 'gate-2']));

        $this->assign(self::CAMERA_B, 'gate-1', 'camera')->assertOk();                 // the gate's own camera is replaced, as before
        $this->actingAs($this->admin)->post(route('settings.gates.store'), ['name' => 'Gate 3']);
        $gate3 = Gate::query()->where('name', 'Gate 3')->value('code');
        $this->assign(self::CAMERA_A, $gate3, 'camera', ['move' => true])->assertOk()
            ->assertJsonPath('message', 'Gate 3 camera assigned (moved from Gate 2). The live view connects in a few seconds.');
        $this->assertSame(['gate-1' => ['camera' => self::CAMERA_B, 'reader' => null], 'gate-2' => ['camera' => null, 'reader' => null], $gate3 => ['camera' => self::CAMERA_A, 'reader' => null]], $this->assignments(['gate-1', 'gate-2', $gate3]));
        $this->assertSame(Camera::SOURCE_NONE, Camera::query()->forRole('gate-2')->value('source_type'), 'Moved away: that gate has no camera now.');
    }

    public function test_a_device_not_identified_yet_can_be_added_from_the_gate_and_problems_are_explained(): void
    {
        $unknown = NetworkDevice::query()->where('mac', self::UNKNOWN)->firstOrFail();
        $this->assertSame('unknown', $unknown->kind);
        $panel = app(DeviceRegistryService::class)->panelPayload();
        $this->assertTrue(collect($panel['devices'])->firstWhere('mac', self::UNKNOWN)['maybe_reader']);

        // A wrong gate or role is explained (JSON), not a blank error.
        $this->assign(self::CAMERA_A, 'gate-9', 'camera')->assertUnprocessable()->assertJsonValidationErrors('station');
        $this->actingAs($this->admin)->postJson(route('settings.devices.assign', 999999), ['station' => 'gate-1', 'role' => 'camera'])
            ->assertNotFound()->assertJsonPath('message', 'This device is not in the list any more. Scan again and pick it from the new list.');
    }

    /**
     * The reader takes one connection at a time: a scan right after another
     * found its port busy (it still answered the module search). Measured
     * 2026-10-14 on the gate reader: "No reader port was found" on Add.
     */
    public function test_a_reader_whose_port_was_busy_during_the_scan_can_still_be_added(): void
    {
        $busy = fn (string $mac, string $ip): array => [
            'key' => $mac, 'mac' => $mac, 'ip' => $ip, 'reachable' => true, 'online' => true, 'kind' => 'rfid_reader', 'confidence' => 'possible',
            'name' => 'Reader module', 'reader' => [], 'open_ports' => ['tcp' => []],
            'module' => ['module' => 'USR IOT module search', 'raw_hex' => 'FF 24 01 00'],
        ];
        app(DeviceRegistryService::class)->ingestScan(['scan' => ['complete' => false], 'devices' => [
            $busy(self::READER_A, '198.51.100.30'),           // port found before, busy now
            $busy('70:19:88:00:00:0D', '198.51.100.32'),      // a known reader model, port never seen open
            $busy('02:00:00:00:00:0E', '198.51.100.33'),      // not a known reader model
        ]]);

        // The port found before is kept.
        $this->assertSame(49152, data_get(NetworkDevice::query()->where('mac', self::READER_A)->first()->details, 'reader.port'));
        $this->assign(self::READER_A, 'gate-1', 'reader')->assertOk()->assertJsonMissingPath('warning');

        // A known reader model gets its model's port, with a note to confirm it with a tag.
        $this->assign('70:19:88:00:00:0D', 'gate-2', 'reader')->assertOk()
            ->assertJsonPath('warning', "The reader's port (TCP 49152) was busy during the scan, so it was taken from the reader's model. Hold a tag near the reader: the first tag confirms it.");
        $options = DeviceAssignment::query()->where('station', 'gate-2')->where('role', 'reader')->firstOrFail()->options;
        $this->assertSame([49152, 'tcp', 'cc', 'active'], [$options['port'], $options['transport'], $options['protocol'], $options['work_mode']]);

        // Anything else: the real reason and what to do.
        $this->assign('02:00:00:00:00:0E', 'gate-1', 'reader')->assertUnprocessable()
            ->assertJsonPath('message', 'No reader port was found on this device. A reader takes one connection at a time, so its port can look closed for a few seconds after a scan. Wait about 10 seconds and press Scan again.');
        $this->assertSame(self::READER_A, $this->assignments(['gate-1'])['gate-1']['reader']);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function assign(string $mac, string $station, string $role, array $extra = []): TestResponse
    {
        $device = NetworkDevice::query()->where('mac', $mac)->firstOrFail();

        return $this->actingAs($this->admin)->postJson(route('settings.devices.assign', $device), ['station' => $station, 'role' => $role] + $extra);
    }

    /**
     * @param  list<string>  $gates
     * @return array<string, array{camera: ?string, reader: ?string}>
     */
    protected function assignments(array $gates): array
    {
        $rows = DeviceAssignment::query()->with('device')->get();

        return collect($gates)->mapWithKeys(fn (string $gate): array => [$gate => [
            'camera' => $rows->first(fn ($row) => $row->station === $gate && $row->role === 'camera')?->device?->mac,
            'reader' => $rows->first(fn ($row) => $row->station === $gate && $row->role === 'reader')?->device?->mac,
        ]])->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function scan(): array
    {
        $camera = fn (string $mac, string $ip): array => [
            'key' => $mac, 'mac' => $mac, 'ip' => $ip, 'reachable' => true, 'online' => true, 'kind' => 'camera', 'confidence' => 'confirmed', 'name' => 'VIGI C240',
            'camera' => ['rtsp_port' => 554, 'vendor_profile' => 'TP-Link VIGI', 'rtsp_paths' => ['main' => '/stream1', 'sub' => '/stream2']],
        ];
        $reader = fn (string $mac, string $ip): array => [
            'key' => $mac, 'mac' => $mac, 'ip' => $ip, 'reachable' => true, 'online' => true, 'kind' => 'rfid_reader', 'confidence' => 'confirmed', 'name' => 'UHF RFID reader',
            'reader' => ['transport' => 'tcp', 'port' => 49152, 'protocol' => 'cc', 'work_mode' => 'active', 'confirmed' => true],
        ];

        return ['scan' => ['complete' => true], 'devices' => [
            $camera(self::CAMERA_A, '198.51.100.20'),
            $camera(self::CAMERA_B, '198.51.100.21'),
            $reader(self::READER_A, '198.51.100.30'),
            $reader(self::READER_B, '198.51.100.31'),
            // A reader that has not answered with a tag yet: only its open port.
            ['key' => self::UNKNOWN, 'mac' => self::UNKNOWN, 'ip' => '198.51.100.40', 'reachable' => true, 'online' => true, 'kind' => 'unknown', 'name' => 'Unknown device',
                'open_ports' => ['tcp' => [49152]]],
        ]];
    }
}
