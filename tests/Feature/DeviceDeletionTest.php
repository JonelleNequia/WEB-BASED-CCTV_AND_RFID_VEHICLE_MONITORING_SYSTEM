<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\NetworkDevice;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\CameraProbeService;
use App\Services\DeviceRegistryService;
use App\Services\Go2rtcService;
use App\Services\RfidIngestService;
use App\Services\SettingsService;
use App\Support\CameraFiles;
use App\Support\DeviceFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Delete device work: deleting a camera or reader removes the hardware's own
 * data only; every IN/OUT, visitor and tag-read record stays and says
 * "(removed)". RFC 5737 addresses.
 */
class DeviceDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected const CAMERA_MAC = '34:F7:16:00:00:01';

    protected const READER_MAC = '70:19:88:00:00:02';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $this->app->useStoragePath($storage = sys_get_temp_dir().'/device-delete-test-'.uniqid());
        File::ensureDirectoryExists($storage.'/logs');
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

        $registry = app(DeviceRegistryService::class);
        $registry->ingestScan($this->scan());
        $this->actingAs($this->admin)->postJson(route('settings.devices.assign', $this->device(self::CAMERA_MAC)), [
            'station' => 'gate-1', 'role' => 'camera', 'username' => 'admin', 'password' => 'secret',
        ])->assertOk();
        $this->actingAs($this->admin)->postJson(route('settings.devices.assign', $this->device(self::READER_MAC)), ['station' => 'gate-1', 'role' => 'reader'])->assertOk();
        Camera::query()->forRole('gate-1')->update([
            'calibration_mask_json' => json_encode([[0.1, 0.3], [0.9, 0.3], [0.9, 1], [0.1, 1]]),
            'calibration_line_json' => json_encode(['x1' => 0.1, 'y1' => 0.6, 'x2' => 0.9, 'y2' => 0.6, 'in_side' => -1]),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(DeviceFiles::directory());
        File::delete(CameraFiles::statusPath());

        parent::tearDown();
    }

    public function test_deleting_a_camera_removes_its_settings_and_keeps_the_records_as_removed(): void
    {
        $this->crossing('k-before', 'no_pass');
        $crossing = VehicleCrossing::query()->sole();
        $visitor = VisitorRecord::query()->sole();
        $this->assertSame([$this->device(self::CAMERA_MAC)->id, 'Gate 1 Camera · TP-Link VIGI-C240'], [$crossing->camera_device_id, $crossing->camera_device_label]);
        $this->assertSame($crossing->camera_device_label, $visitor->camera_device_label);
        File::ensureDirectoryExists(dirname(CameraFiles::framePath('gate-1')));
        File::put(CameraFiles::framePath('gate-1'), 'jpg');
        File::put(CameraFiles::framePath('gate-2'), 'jpg');

        $this->actingAs($this->admin)->getJson(route('settings.devices.delete-summary', $this->device(self::CAMERA_MAC)))->assertOk()
            ->assertJsonPath('kind', 'camera')->assertJsonPath('gates.0.name', 'Gate 1')->assertJsonPath('records_kept', 2)
            ->assertJsonFragment(["The gate's detection zone, trigger line and IN direction"]);
        $this->actingAs($this->admin)->deleteJson(route('settings.devices.destroy', $this->device(self::CAMERA_MAC)))->assertUnprocessable();
        $this->actingAs($this->admin)->deleteJson(route('settings.devices.destroy', $this->device(self::CAMERA_MAC)), ['confirm' => true])->assertOk()
            ->assertJsonPath('message', 'TP-Link VIGI-C240 Camera deleted from Gate 1. Its records are kept.');

        // The hardware's data is gone.
        $this->assertNull(NetworkDevice::query()->where('mac', self::CAMERA_MAC)->first());
        $this->assertFalse(DeviceAssignment::query()->where('role', 'camera')->exists());
        $camera = Camera::query()->forRole('gate-1')->firstOrFail();
        $this->assertSame(['none', null, null, null, null], [$camera->source_type, $camera->source_username, $camera->source_password, $camera->calibration_mask_json, $camera->calibration_line_json]);
        $this->assertFileDoesNotExist(CameraFiles::framePath('gate-1'));
        $this->assertFileExists(CameraFiles::framePath('gate-2'), 'Another gate is not touched.');
        app(SettingsService::class)->exportCameraRuntimeConfig();
        $this->assertSame('none', json_decode(File::get(app(SettingsService::class)->cameraRuntimeConfigPath()), true)['cameras']['gate-1']['source_type']);
        $this->assertSame([], app(Go2rtcService::class)->streams());

        // The records stay, with the device's name.
        $this->assertSame([1, 1], [VehicleCrossing::query()->count(), VisitorRecord::query()->count()]);
        $this->assertSame(['Gate 1 Camera · TP-Link VIGI-C240 (removed)', null], [$crossing->fresh()->camera_device_label, $crossing->fresh()->camera_device_id]);
        $this->assertSame('Gate 1 Camera · TP-Link VIGI-C240 (removed)', $visitor->fresh()->camera_device_label);

        // The reader of the same gate keeps working; the slot says "+ Add camera"; the deletion is logged.
        $this->assertTrue(DeviceAssignment::query()->where('station', 'gate-1')->where('role', 'reader')->exists());
        $card = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()->getContent();
        $this->assertStringContainsString('data-add-device="camera" data-gate="gate-1"', $card);
        $removal = DB::table('device_removals')->sole();
        $this->assertSame(['camera', $this->admin->id, self::CAMERA_MAC, 2], [$removal->device_kind, (int) $removal->user_id, $removal->mac, (int) $removal->records_kept]);
    }

    public function test_an_in_out_event_keeps_its_camera_name_after_the_camera_is_deleted(): void
    {
        $vehicle = Vehicle::query()->create(['plate_number' => 'DEL 1001', 'vehicle_owner_name' => 'Owner', 'category' => 'faculty_staff', 'vehicle_type' => 'Car']);
        RfidTag::query()->create(['uid' => 'DEL-TAG-1', 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        // RFID only (no camera running): one IN, made with both devices of the gate.
        app(RfidIngestService::class)->ingest(['tag_uid' => 'DEL-TAG-1', 'scan_location' => 'gate-1']);
        $event = VehicleEvent::query()->sole();
        $this->assertSame('Gate 1 Camera · TP-Link VIGI-C240', $event->camera_label);

        $this->actingAs($this->admin)->deleteJson(route('settings.devices.destroy', $this->device(self::CAMERA_MAC)), ['confirm' => true])->assertOk();

        $this->assertSame('Gate 1 Camera · TP-Link VIGI-C240 (removed)', $event->fresh()->camera_label);
        $this->actingAs($this->admin)->get(route('vehicle-events.show', $event))->assertOk()->assertSee('Gate 1 Camera · TP-Link VIGI-C240 (removed)');
        $this->assertSame(1, VehicleEvent::query()->count());
    }

    public function test_deleting_a_reader_stops_its_link_and_keeps_its_tag_reads(): void
    {
        $vehicle = Vehicle::query()->create(['plate_number' => 'DEL 2002', 'vehicle_owner_name' => 'Owner', 'category' => 'faculty_staff', 'vehicle_type' => 'Car']);
        RfidTag::query()->create(['uid' => 'DEL-TAG-2', 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        app(RfidIngestService::class)->ingest(['tag_uid' => 'DEL-TAG-2', 'scan_location' => 'gate-1']);
        $scan = RfidScanLog::query()->sole();
        $this->assertSame($this->device(self::READER_MAC)->id, $scan->reader_device_id);
        Cache::put('rfid-read-buffer.gate-1', [['epc' => 'DEL-TAG-2']], now()->addMinute());
        $this->assertNotNull(json_decode(File::get(DeviceFiles::runtimeConfigPath()), true)['stations']['gate-1']['reader']);

        $this->actingAs($this->admin)->deleteJson(route('settings.devices.destroy', $this->device(self::READER_MAC)), ['confirm' => true])->assertOk();

        // The device service's reader link has no target any more (it stops connecting by itself).
        $this->assertNull(json_decode(File::get(DeviceFiles::runtimeConfigPath()), true)['stations']['gate-1']['reader']);
        $this->assertNull(Cache::get('rfid-read-buffer.gate-1'));
        $this->assertSame(1, VehicleEvent::query()->count(), 'The IN stays.');
        $this->assertSame([null, 'Gate 1 UHF Reader (removed)'], [$scan->fresh()->reader_device_id, $scan->fresh()->reader_name]);
        $this->assertTrue(DeviceAssignment::query()->where('station', 'gate-1')->where('role', 'camera')->exists(), 'The camera stays.');
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertSee('data-add-device="reader" data-gate="gate-1"', false);
    }

    public function test_a_device_still_plugged_in_comes_back_unassigned_and_can_be_hidden(): void
    {
        $this->actingAs($this->admin)->deleteJson(route('settings.devices.destroy', $this->device(self::CAMERA_MAC)), ['confirm' => true])->assertOk();

        // The next scan still sees it: listed again, not assigned.
        app(DeviceRegistryService::class)->ingestScan($this->scan());
        $listed = collect(app(DeviceRegistryService::class)->panelPayload()['devices'])->firstWhere('mac', self::CAMERA_MAC);
        $this->assertSame([], $listed['assigned']);

        // Hide: out of the list, also after the next scan; "Show again" brings it back.
        $this->actingAs($this->admin)->postJson(route('settings.devices.hide', $this->device(self::CAMERA_MAC)))->assertOk();
        app(DeviceRegistryService::class)->ingestScan($this->scan());
        $payload = app(DeviceRegistryService::class)->panelPayload();
        $this->assertNull(collect($payload['devices'])->firstWhere('mac', self::CAMERA_MAC));
        $this->assertSame(self::CAMERA_MAC, $payload['hidden_devices'][0]['mac']);
        $this->assertSame('TP-Link VIGI-C240 Camera', $payload['removals'][0]['device']);

        $this->actingAs($this->admin)->postJson(route('settings.devices.unhide', $this->device(self::CAMERA_MAC)))->assertOk();
        $this->assertNotNull(collect(app(DeviceRegistryService::class)->panelPayload()['devices'])->firstWhere('mac', self::CAMERA_MAC));

        // A device used by a gate cannot be hidden.
        $this->actingAs($this->admin)->postJson(route('settings.devices.hide', $this->device(self::READER_MAC)))->assertStatus(422);
    }

    public function test_only_an_admin_can_delete_and_the_menus_offer_it(): void
    {
        $guard = User::query()->create(['name' => 'Gate Guard', 'email' => 'guard@philcst.local', 'password' => 'password', 'role' => 'guard']);
        $this->actingAs($guard)->deleteJson(route('settings.devices.destroy', $this->device(self::CAMERA_MAC)), ['confirm' => true])->assertForbidden();
        $this->assertNotNull(NetworkDevice::query()->where('mac', self::CAMERA_MAC)->first());

        $card = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()->getContent();
        $this->assertSame(2, substr_count($card, 'data-device-delete'."\n"), 'Camera and reader menus.');
        $this->assertStringContainsString('>Remove…</button>', $card, '"Remove" (unassign) stays next to "Delete device".');
        $this->assertStringContainsString('js/device-delete.js', $card);
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'devices']))->assertOk()
            ->assertSee('Show hidden devices')->assertSee('Deleted devices')->assertSee('js/device-delete.js');
    }

    protected function crossing(string $key, string $rfidStatus): void
    {
        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->postJson(route('api.integration.crossings'), [
            'external_event_key' => $key, 'camera_role' => 'gate-1', 'direction' => 'IN', 'event_time' => now()->toIso8601String(),
            'track_id' => 1, 'confidence' => 0.9, 'detection_metadata' => ['rfid_status' => $rfidStatus],
        ])->assertCreated();
    }

    protected function device(string $mac): NetworkDevice
    {
        return NetworkDevice::query()->where('mac', $mac)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    protected function scan(): array
    {
        return ['scan' => ['complete' => true], 'devices' => [
            [
                'key' => self::CAMERA_MAC, 'mac' => self::CAMERA_MAC, 'ip' => '198.51.100.20', 'reachable' => true, 'online' => true,
                'kind' => 'camera', 'confidence' => 'confirmed', 'brand' => 'TP-Link VIGI', 'model' => 'VIGI-C240', 'name' => 'VIGI C240',
                'camera' => ['rtsp_port' => 554, 'vendor_profile' => 'TP-Link VIGI', 'rtsp_paths' => ['main' => '/stream1', 'sub' => '/stream2']],
            ],
            [
                'key' => self::READER_MAC, 'mac' => self::READER_MAC, 'ip' => '198.51.100.30', 'reachable' => true, 'online' => true,
                'kind' => 'rfid_reader', 'confidence' => 'confirmed', 'name' => 'UHF RFID reader',
                'reader' => ['transport' => 'tcp', 'port' => 49152, 'protocol' => 'cc', 'work_mode' => 'active', 'confirmed' => true],
            ],
        ]];
    }
}
