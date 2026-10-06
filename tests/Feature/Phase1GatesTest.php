<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\DeviceAssignment;
use App\Models\Gate;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\RfidIngestService;
use App\Support\CameraFiles;
use App\Support\DeviceFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Phase 1 (visitor model): gates instead of Entrance/Exit stations.
 */
class Phase1GatesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        File::deleteDirectory(DeviceFiles::directory());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(DeviceFiles::directory());

        parent::tearDown();
    }

    public function test_migration_turns_entrance_and_exit_into_gate_1_and_gate_2(): void
    {
        $migration = require database_path('migrations/2026_10_01_000002_create_gates.php');
        $migration->down(); // back to the data as it was before gates

        DB::table('rfid_scan_logs')->insert(['tag_uid' => 'OLD-1', 'scan_location' => 'entrance', 'scan_direction' => 'entry',
            'scan_time' => now(), 'verification_status' => 'guest', 'source_mode' => 'station_reader', 'reader_name' => 'x',
            'created_at' => now(), 'updated_at' => now()]);
        $deviceId = DB::table('network_devices')->insertGetId(['device_key' => 'AA:BB:CC:00:00:01', 'kind' => 'rfid_reader']);
        DB::table('device_assignments')->insert(['station' => 'exit', 'role' => 'reader', 'network_device_id' => $deviceId,
            'created_at' => now(), 'updated_at' => now()]);
        foreach ([
            'entrance_portal_label' => 'PHILCST Entrance Portal', // old default -> "Gate 1"
            'exit_portal_label' => 'Back Gate',                    // custom name kept
            'entrance_reader_type' => 'uhf_ethernet',
            'exit_reader_type' => 'nfc',
            'entrance_reader_manual' => '1',
            'entrance_reader_ip' => '192.168.1.2',
            'entrance_reader_port' => '24',
        ] as $key => $value) {
            DB::table('system_settings')->updateOrInsert(['setting_key' => $key], ['setting_value' => $value]);
        }

        $migration->up();

        $gates = Gate::query()->orderBy('sort_order')->get();
        $this->assertSame(['gate-1', 'gate-2'], $gates->pluck('code')->all());
        $this->assertSame(['Gate 1', 'Back Gate'], $gates->pluck('name')->all());
        $this->assertSame(['uhf_ethernet', true, '192.168.1.2', 24], [$gates[0]->reader_type, $gates[0]->reader_manual, $gates[0]->reader_ip, $gates[0]->reader_port]);
        $this->assertSame('gate-1', RfidScanLog::query()->where('tag_uid', 'OLD-1')->value('scan_location'));
        $this->assertSame('gate-2', DeviceAssignment::query()->value('station'));
        $this->assertSame(['gate-1', 'gate-2'], Camera::query()->orderBy('id')->pluck('camera_role')->all());
        $this->assertFalse(DB::table('system_settings')->where('setting_key', 'like', 'entrance\_%')->exists());
    }

    public function test_both_gates_record_in_and_out_and_old_names_still_work(): void
    {
        $vehicle = $this->registeredVehicle('GTE 1001', 'GTE-TAG-1');
        $ingest = app(RfidIngestService::class);

        // IN at Gate 2 (no gate is "the exit" any more), OUT at Gate 2 later.
        $in = $ingest->ingest(['tag_uid' => 'GTE-TAG-1', 'scan_location' => 'gate-2']);
        $this->assertSame(['ENTRY', 'gate-2'], [$in->scanLog->resolved_event_type, $in->scanLog->scan_location]);
        $this->travel(2)->minutes();
        $out = $ingest->ingest(['tag_uid' => 'GTE-TAG-1', 'scan_location' => 'gate-2']);
        $this->assertSame('EXIT', $out->scanLog->resolved_event_type);
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);

        // Old clients (UHF listener before Phase 1, old links) send "entrance".
        $this->travel(2)->minutes();
        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])
            ->postJson(route('api.integration.rfid-scans'), ['tag_uid' => 'GTE-TAG-1', 'scan_location' => 'entrance'])
            ->assertCreated();
        $this->assertSame('gate-1', RfidScanLog::query()->latest('id')->value('scan_location'));
        $this->actingAs($this->admin)->get('/station/entrance')->assertRedirect(route('gates.kiosk', 'gate-1'));
        $this->actingAs($this->admin)->get(route('gates.kiosk', 'nope'))->assertNotFound();

        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])
            ->postJson(route('api.integration.rfid-scans'), ['tag_uid' => 'GTE-TAG-1', 'scan_location' => 'gate-9'])
            ->assertUnprocessable();
    }

    public function test_add_rename_and_deactivate_gates(): void
    {
        $this->actingAs($this->admin)->post(route('settings.gates.store'), ['name' => 'Service Gate'])
            ->assertRedirect(route('settings.index', ['tab' => 'gates']));

        $gate = Gate::query()->where('code', 'gate-3')->firstOrFail();
        $this->assertSame('Service Gate', $gate->name);
        $this->assertTrue(Camera::query()->where('camera_role', 'gate-3')->exists());
        $this->actingAs($this->admin)->get(route('gates.kiosk', 'gate-3'))->assertOk()->assertSee('Service Gate');
        $this->actingAs($this->admin)->get(route('gates.index'))->assertOk()->assertSee('Open Service Gate Kiosk');

        // Both runtime files list the new gate (detector camera, reader slot).
        $camera = json_decode(File::get(CameraFiles::path('camera_runtime_config.json')), true);
        $this->assertSame(['gate-1', 'gate-2', 'gate-3'], array_column($camera['gates'], 'code'));
        $this->assertArrayHasKey('gate-3', $camera['cameras']);
        $devices = json_decode(File::get(DeviceFiles::runtimeConfigPath()), true);
        $this->assertArrayHasKey('gate-3', $devices['stations']);

        // Rename Gate 1; deactivate Gate 3 (hidden from the Gate Monitor).
        $this->actingAs($this->admin)->put(route('settings.update'), [
            'section' => 'stations',
            'gates' => [
                'gate-1' => ['name' => 'Main Gate', 'reader_type' => 'uhf_ethernet', 'is_active' => '1'],
                'gate-3' => ['name' => 'Service Gate', 'is_active' => '0'],
            ],
            'rfid_cooldown_seconds' => 60,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Main Gate', Gate::query()->where('code', 'gate-1')->value('name'));
        $this->assertSame('Main Gate UHF Reader', Gate::query()->where('code', 'gate-1')->value('reader_name'));
        $this->actingAs($this->admin)->get(route('gates.index'))->assertOk()
            ->assertSee('Open Main Gate Kiosk')
            ->assertDontSee('Open Service Gate Kiosk');

        // At least one gate stays active.
        $this->actingAs($this->admin)->put(route('settings.update'), [
            'section' => 'stations',
            'gates' => [
                'gate-1' => ['name' => 'Main Gate', 'is_active' => '0'],
                'gate-2' => ['name' => 'Gate 2', 'is_active' => '0'],
            ],
        ])->assertSessionHasErrors('gates');
    }

    public function test_logs_and_kiosk_show_the_gate_name(): void
    {
        Gate::query()->where('code', 'gate-1')->update(['name' => 'Main Gate']);
        $this->registeredVehicle('LBL 2002', 'LBL-TAG-2');
        app(RfidIngestService::class)->ingest(['tag_uid' => 'LBL-TAG-2', 'scan_location' => 'gate-1']);

        $this->actingAs($this->admin)->get(route('logs.index', ['tab' => 'scans']))
            ->assertOk()->assertSee('Main Gate')->assertSee('<th>Gate</th>', false);
        // B1: gate cards on Gates, gate names on General.
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))
            ->assertOk()->assertSee('Main Gate')->assertSee('+ Add gate');
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'general']))
            ->assertOk()->assertSee('Gate names')->assertSee('gates[gate-1][name]', false);
        $this->actingAs($this->admin)->get(route('gates.kiosk', 'gate-1'))
            ->assertOk()->assertSee('Main Gate')->assertSee('IN and OUT');
    }

    protected function registeredVehicle(string $plate, string $uid): Vehicle
    {
        $vehicle = Vehicle::query()->create([
            'plate_number' => $plate, 'vehicle_owner_name' => 'Gate Owner', 'category' => 'faculty_staff', 'vehicle_type' => 'Car',
        ]);
        $tag = RfidTag::query()->create(['uid' => $uid, 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        return $vehicle;
    }
}
