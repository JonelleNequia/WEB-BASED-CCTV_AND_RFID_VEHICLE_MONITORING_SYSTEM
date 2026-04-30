<?php

namespace Tests\Feature;

use App\Models\User;
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
            ->assertDontSee('Vehicle Registry');

        $this->actingAs($admin)
            ->get(route('stations.exit'))
            ->assertOk()
            ->assertSee('Camera 2')
            ->assertSee('EXIT Logs')
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
}
