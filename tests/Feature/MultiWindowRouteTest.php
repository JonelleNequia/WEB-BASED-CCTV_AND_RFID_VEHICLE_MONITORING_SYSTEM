<?php

namespace Tests\Feature;

use App\Models\GuestVehicleObservation;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DetectorRuntimeService;
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

    public function test_station_state_endpoint_attempts_detector_restart_when_polled(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->mock(DetectorRuntimeService::class, function ($mock): void {
            $mock->shouldReceive('ensureRunning')
                ->once()
                ->andReturn([
                    'service_running' => false,
                    'service_message' => 'Detector status is stale. A restart will be attempted while monitoring stays online.',
                    'updated_at' => null,
                    'cameras' => [
                        'entrance' => [
                            'camera_role' => 'entrance',
                            'camera_running' => false,
                            'detection_ready' => false,
                            'stream_url' => 'http://127.0.0.1:8765/stream/entrance',
                        ],
                    ],
                ]);
        });

        $this->actingAs($admin)
            ->getJson(route('stations.state', 'entrance'))
            ->assertOk()
            ->assertJsonPath('runtime.service_running', false)
            ->assertJsonPath('camera.camera_running', false);
    }

    public function test_station_state_endpoint_includes_guest_observations_for_sidebar(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        GuestVehicleObservation::query()->create([
            'plate_text' => null,
            'vehicle_type' => 'Car',
            'vehicle_color' => null,
            'location' => 'entrance',
            'observation_source' => 'cctv',
            'status' => 'pending_review',
            'observed_at' => now(),
            'external_event_key' => 'guest-station-sidebar-001',
            'snapshot_path' => 'guest_snapshots/guest-station-sidebar-001.jpg',
        ]);

        $this->mock(DetectorRuntimeService::class, function ($mock): void {
            $mock->shouldReceive('ensureRunning')
                ->once()
                ->andReturn([
                    'service_running' => true,
                    'service_message' => 'Detector service is already running.',
                    'updated_at' => now()->toIso8601String(),
                    'cameras' => [
                        'entrance' => [
                            'camera_role' => 'entrance',
                            'camera_running' => true,
                            'detection_ready' => true,
                            'stream_url' => 'http://127.0.0.1:8765/stream/entrance',
                        ],
                    ],
                ]);
        });

        $this->actingAs($admin)
            ->getJson(route('stations.state', 'entrance'))
            ->assertOk()
            ->assertJsonFragment([
                'event_type' => 'GUEST',
                'verification_label' => 'Unregistered / Guest',
                'resulting_state' => 'Pending Review',
            ]);
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
