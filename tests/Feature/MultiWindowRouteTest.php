<?php

namespace Tests\Feature;

use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiWindowRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_is_available_at_admin_route(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Campus parking monitoring dashboard')
            ->assertSee('Entrance Station')
            ->assertSee('Exit Station');
    }

    public function test_station_kiosk_windows_render_dedicated_camera_and_log_views(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('stations.entrance'))
            ->assertOk()
            ->assertSee('Camera 1')
            ->assertSee('ENTRY Logs')
            ->assertSee('RFID Ready')
            ->assertSee('data-rfid-input', false)
            ->assertDontSee('Vehicle Registry');

        $this->actingAs($admin)
            ->get(route('stations.exit'))
            ->assertOk()
            ->assertSee('Camera 2')
            ->assertSee('EXIT Logs')
            ->assertSee('RFID Ready')
            ->assertSee('data-rfid-input', false)
            ->assertDontSee('RFID Inventory');
    }

    public function test_station_state_endpoint_returns_event_type_scoped_logs(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($admin)
            ->getJson(route('stations.state', 'entrance'))
            ->assertOk()
            ->assertJsonPath('location', 'entrance')
            ->assertJsonPath('event_type', 'ENTRY');

        $this->actingAs($admin)
            ->getJson(route('stations.state', 'exit'))
            ->assertOk()
            ->assertJsonPath('location', 'exit')
            ->assertJsonPath('event_type', 'EXIT');
    }

    public function test_station_rfid_scan_endpoint_records_registered_reader_scan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $tag = RfidTag::query()->with('vehicle')->where('uid', 'RFID-DEF-1002')->firstOrFail();

        $this->actingAs($admin)
            ->postJson(route('stations.rfid-scan', 'entrance'), [
                'tag_uid' => $tag->uid,
            ])
            ->assertCreated()
            ->assertJsonPath('scan.verification_status', 'verified')
            ->assertJsonPath('scan.scan_location', 'entrance')
            ->assertJsonPath('action_taken', 'ENTRY')
            ->assertJsonPath('new_state', Vehicle::STATE_INSIDE)
            ->assertJsonPath('vehicle.plate_number', $tag->vehicle->plate_number);

        $this->assertDatabaseHas('rfid_scan_logs', [
            'tag_uid' => $tag->uid,
            'scan_location' => 'entrance',
            'verification_status' => 'verified',
            'source_mode' => 'station_reader',
        ]);

        $this->assertDatabaseHas('vehicle_events', [
            'vehicle_id' => $tag->vehicle_id,
            'event_type' => 'ENTRY',
            'resulting_state' => Vehicle::STATE_INSIDE,
        ]);
    }
}
