<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\Gate;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\CameraStreams;
use App\Services\GateManagementService;
use App\Support\CameraFiles;
use App\Support\DeviceFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GateManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_general_redirects_with_query_preserved_and_gates_has_names_and_activation(): void
    {
        $this->actingAs($this->admin)->get('/settings?tab=general&gate=gate-2')
            ->assertRedirect(route('settings.index', ['tab' => 'gates', 'gate' => 'gate-2']));
        $this->get('/settings?tab=gates')->assertOk()->assertDontSee('tab=general', false)
            ->assertSee('gates[gate-1][name]', false)->assertSee('gates[gate-2][is_active]', false)
            ->assertSee('At least two gates are required.')->assertDontSee('Remove gate…');
        $this->put(route('settings.update'), ['section' => 'gates', 'gates' => [
            'gate-1' => ['name' => 'Main Gate', 'is_active' => '1'],
            'gate-2' => ['name' => 'Back Gate', 'is_active' => '0'],
        ]])->assertSessionHasNoErrors()->assertSessionHas('status', 'Gate settings saved.');
        $this->assertDatabaseHas('gates', ['code' => 'gate-2', 'name' => 'Back Gate', 'is_active' => false]);
        $this->assertSame(['gate-1'], Gate::codes());
        $this->delete(route('settings.gates.destroy', 'gate-2'), ['confirm' => '1'])->assertSessionHasErrors('gate');
        $this->assertSame(2, Gate::query()->retained()->count());
    }

    public function test_confirmation_is_required_and_three_can_become_two_without_reusing_ids(): void
    {
        $gate = app(GateManagementService::class)->create('Service Gate');
        $this->actingAs($this->admin)->get('/settings?tab=gates')->assertOk()
            ->assertSee('Remove Service Gate?')->assertSee('assignments will be released');
        $this->delete(route('settings.gates.destroy', $gate->code))->assertSessionHasErrors('confirm');
        $this->delete(route('settings.gates.destroy', $gate->code), ['confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertNotNull($gate->fresh()->archived_at);
        $this->assertSame(2, Gate::query()->retained()->count());
        $this->delete(route('settings.gates.destroy', 'gate-1'), ['confirm' => '1'])->assertSessionHasErrors('gate');
        $new = app(GateManagementService::class)->create(null);
        $this->assertSame('gate-4', $new->code);
        $this->assertGreaterThan($gate->id, $new->id);
        $this->assertSame($gate->id, $gate->fresh()->id);
    }

    public function test_guest_and_guard_cannot_mutate_gates(): void
    {
        $gate = app(GateManagementService::class)->create('Protected');
        $actions = [
            ['POST', route('settings.gates.store'), ['name' => 'No']],
            ['PUT', route('settings.update'), ['section' => 'gates', 'gates' => ['gate-1' => ['name' => 'No', 'is_active' => '0']]]],
            ['DELETE', route('settings.gates.destroy', $gate->code), ['confirm' => '1']],
        ];
        foreach ($actions as [$method, $url, $data]) {
            $this->json($method, $url, $data)->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create(['role' => 'guard']));
        foreach ($actions as [$method, $url, $data]) {
            $this->json($method, $url, $data)->assertForbidden();
        }
        $this->assertSame(3, Gate::query()->retained()->count());
        $this->assertDatabaseHas('gates', ['code' => 'gate-1', 'name' => 'Gate 1', 'is_active' => true]);
    }

    public function test_runtime_refresh_failure_explains_that_archival_has_already_completed(): void
    {
        $gate = app(GateManagementService::class)->create('Service Gate');
        $this->partialMock(GateManagementService::class, function ($mock): void {
            $mock->shouldReceive('refreshRuntime')->once()->andThrow(new \RuntimeException('Test export failure'));
        });
        $this->actingAs($this->admin)->delete(route('settings.gates.destroy', $gate->code), ['confirm' => '1'])
            ->assertSessionHasErrors(['gate' => 'Service Gate was archived and its device assignments released, but runtime settings could not be refreshed. Save gate settings on Gates to retry the refresh.']);
        $this->assertNotNull($gate->fresh()->archived_at);
        $this->assertSame(2, Gate::query()->retained()->count());
    }

    public function test_archive_preserves_history_calibration_and_devices_but_releases_operational_state(): void
    {
        Storage::fake('public');
        $gate = app(GateManagementService::class)->create('Historical Gate');
        $camera = Camera::query()->create(['camera_role' => $gate->code, 'camera_name' => 'History Camera',
            'source_type' => 'rtsp', 'source_value' => 'rtsp://camera.test/live',
            'calibration_mask_json' => [['x' => 0, 'y' => 0], ['x' => 1, 'y' => 0], ['x' => 1, 'y' => 1]]]);
        $device = NetworkDevice::query()->create(['device_key' => 'gate-history-camera', 'kind' => 'camera']);
        $reader = NetworkDevice::query()->create(['device_key' => 'gate-history-reader', 'kind' => 'rfid_reader']);
        DeviceAssignment::query()->create(['station' => $gate->code, 'role' => 'camera', 'network_device_id' => $device->id]);
        DeviceAssignment::query()->create(['station' => 'gate-1', 'role' => 'camera', 'network_device_id' => $device->id]);
        DeviceAssignment::query()->create(['station' => $gate->code, 'role' => 'reader', 'network_device_id' => $reader->id]);
        Storage::disk('public')->put('crossing_snapshots/history.jpg', 'evidence');
        $crossing = VehicleCrossing::query()->create(['gate' => $gate->code, 'camera_id' => $camera->id,
            'direction' => 'IN', 'crossed_at' => now(), 'external_event_key' => 'history-crossing', 'snapshot_path' => 'crossing_snapshots/history.jpg']);
        $event = VehicleEvent::query()->create(['camera_id' => $camera->id, 'event_type' => 'ENTRY', 'event_time' => now(), 'event_status' => 'completed', 'event_origin' => 'manual']);
        $visitor = VisitorRecord::query()->create(['gate' => $gate->code, 'camera_id' => $camera->id, 'external_event_key' => 'history-visitor',
            'vehicle_crossing_id' => $crossing->id, 'direction' => 'IN', 'seen_at' => now(), 'status' => 'active', 'plate_status' => 'unreadable']);
        DB::table('rfid_scan_logs')->insert(['tag_uid' => 'HISTORY-TAG', 'scan_location' => $gate->code, 'scan_direction' => 'entry',
            'scan_time' => now(), 'verification_status' => 'unknown_tag', 'source_mode' => 'station_reader']);
        File::ensureDirectoryExists(DeviceFiles::directory());
        File::put(DeviceFiles::runtimeConfigPath(), json_encode(['reader_network_request' => ['station' => $gate->code, 'action' => 'apply']]));

        $this->actingAs($this->admin)->delete(route('settings.gates.destroy', $gate->code), ['confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertSame($gate->id, $event->fresh()->camera->gate->id);
        $this->assertSame($camera->id, $crossing->fresh()->camera->id);
        $this->assertSame($crossing->id, $visitor->fresh()->crossing->id);
        $this->assertSame($camera->calibration_mask_json, $camera->fresh()->calibration_mask_json);
        Storage::disk('public')->assertExists('crossing_snapshots/history.jpg');
        $this->assertDatabaseHas('rfid_scan_logs', ['scan_location' => $gate->code]);
        $this->assertDatabaseHas('network_devices', ['id' => $reader->id]);
        $this->assertDatabaseHas('network_devices', ['id' => $device->id]);
        $this->assertDatabaseMissing('device_assignments', ['station' => $gate->code]);
        $this->assertDatabaseHas('device_assignments', ['station' => 'gate-1', 'network_device_id' => $device->id]);
        $this->assertNull(Gate::resolveCode($gate->code));
        $this->assertSame($gate->code, Gate::resolveCode($gate->code, historical: true));
        $this->assertSame('Historical Gate', Gate::labelFor($gate->code));
        $this->assertArrayNotHasKey($gate->code, Gate::options());
        $this->assertSame(CameraStreams::SOURCE_NONE, app(CameraStreams::class)->forGate($gate->code)['source']);
        $this->assertArrayNotHasKey($gate->code, json_decode(File::get(CameraFiles::path('camera_runtime_config.json')), true)['cameras']);
        $this->assertArrayNotHasKey($gate->code, json_decode(File::get(DeviceFiles::runtimeConfigPath()), true)['stations']);
        $this->assertNull(json_decode(File::get(DeviceFiles::runtimeConfigPath()), true)['reader_network_request']);
        $this->assertArrayNotHasKey($gate->code, app(\App\Services\Go2rtcService::class)->streams());
        $this->get(route('gates.kiosk', $gate->code))->assertNotFound();
        $this->get('/settings?tab=gates')->assertOk()->assertDontSee('data-gate-card="'.$gate->code.'"', false);
        $this->put(route('settings.update'), ['section' => 'gates', 'gates' => [$gate->code => ['name' => 'Revive', 'is_active' => '1']]])->assertSessionHasErrors('gates');
        $this->postJson(route('visitors.records.store'), ['gate' => $gate->code, 'direction' => 'IN', 'seen_at' => now()->subMinute()->toIso8601String()])->assertUnprocessable();
        $this->postJson(route('settings.devices.assign', $reader), ['station' => $gate->code, 'role' => 'reader'])->assertUnprocessable();
        $this->putJson(route('calibration.update'), ['camera_id' => $camera->id])->assertUnprocessable();
        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->postJson(route('api.integration.events'), [
            'camera_id' => $camera->id, 'direction' => 'IN', 'image_path' => 'unused.jpg',
        ])->assertUnprocessable();
        $this->get(route('logs.index', ['tab' => 'events', 'gate' => $gate->code]))->assertOk()->assertSee('Historical Gate');
        $this->get(route('logs.index', ['tab' => 'scans', 'scan_location' => $gate->code]))->assertOk()->assertSee('HISTORY-TAG');
        $this->get(route('logs.print', ['gate' => $gate->code]))->assertOk()->assertSee('Historical Gate');
    }

    public function test_initialization_adds_only_missing_inactive_unconfigured_gates_and_is_idempotent(): void
    {
        // Isolated test database only: simulate an older one-gate installation.
        Gate::query()->where('code', 'gate-2')->delete();
        $existing = Gate::query()->sole();
        $existing->update(['name' => 'Existing name', 'reader_ip' => '192.168.1.80', 'reader_manual' => true]);
        $before = $existing->fresh()->getAttributes();
        app(GateManagementService::class)->ensureMinimum();
        app(GateManagementService::class)->ensureMinimum();
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertSame(2, Gate::query()->retained()->count());
        $added = Gate::query()->whereKeyNot($existing->id)->sole();
        $this->assertFalse($added->is_active);
        $this->assertNull($added->reader_ip);
        $this->assertFalse($added->reader_manual);
        $this->assertNull($added->camera);
        Gate::query()->delete();
        app(GateManagementService::class)->ensureMinimum();
        $this->assertSame(2, Gate::query()->retained()->count());
        $this->assertSame(0, Gate::query()->active()->count());
    }

    public function test_upgrade_migration_repairs_missing_gate_without_changing_accounts_or_existing_configuration(): void
    {
        $migration = require database_path('migrations/2026_10_30_000001_archive_gates_and_ensure_minimum.php');
        $migration->down();
        DB::table('gates')->where('code', 'gate-2')->delete();
        DB::table('gates')->where('code', 'gate-1')->update(['name' => 'Keep my name', 'reader_ip' => '192.168.1.45']);
        $orphan = Camera::query()->create(['camera_role' => 'gate-2', 'camera_name' => 'Keep historical camera',
            'source_type' => 'rtsp', 'source_value' => 'rtsp://camera.test/history']);
        $before = (array) DB::table('gates')->sole();
        $accounts = DB::table('users')->get()->toJson();
        $migration->up();
        app(GateManagementService::class)->ensureMinimum();
        $after = (array) DB::table('gates')->where('code', 'gate-1')->sole();
        unset($after['archived_at']);
        $this->assertSame($before, $after);
        $this->assertSame($accounts, DB::table('users')->get()->toJson());
        $this->assertSame(2, Gate::query()->retained()->count());
        $added = Gate::query()->where('code', '!=', 'gate-1')->sole();
        $this->assertFalse($added->is_active);
        $this->assertNull($added->reader_ip);
        $this->assertSame('gate-3', $added->code);
        $this->assertNull($added->camera);
        $this->assertSame('rtsp://camera.test/history', $orphan->fresh()->source_value);
    }
}
