<?php

namespace Tests\Feature;

use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\DetectorRuntimeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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
            ->assertSee('Campus vehicle monitoring dashboard')
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
            ->assertSee('Shared Station Logs')
            ->assertSee('RFID Ready')
            ->assertSee('data-rfid-input', false)
            ->assertDontSee('Vehicle Registry')
            ->assertDontSee('<canvas', false)
            ->assertDontSee('Save ROI')
            ->assertDontSee('browser-camera-common.js')
            ->assertDontSee('data-browser-frame', false);

        $this->actingAs($admin)
            ->get(route('stations.exit'))
            ->assertOk()
            ->assertSee('Camera 2')
            ->assertSee('Shared Station Logs')
            ->assertSee('RFID Ready')
            ->assertSee('data-rfid-input', false)
            ->assertDontSee('RFID Inventory')
            ->assertDontSee('<canvas', false)
            ->assertDontSee('Save ROI')
            ->assertDontSee('browser-camera-common.js')
            ->assertDontSee('data-browser-frame', false);
    }

    public function test_station_state_endpoint_returns_station_identity_and_shared_logs(): void
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
            $mock->shouldReceive('markStationViewerActive')
                ->once()
                ->with('entrance');
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
            $mock->shouldReceive('markStationViewerActive')
                ->once()
                ->with('entrance');
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
                'verification_label' => 'GUEST',
                'resulting_state' => 'Guest',
            ]);
    }

    public function test_station_rfid_scan_endpoint_records_registered_reader_scan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $vehicle = Vehicle::query()->create([
            'plate_number' => 'STA-1002',
            'vehicle_owner_name' => 'Station Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);
        $vehicle->forceFill([
            'current_state' => Vehicle::STATE_OUTSIDE,
        ])->save();
        $tag = RfidTag::query()->create([
            'uid' => 'RFID-STATION-1002',
            'status' => RfidTag::STATUS_ASSIGNED,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now(),
        ]);
        $vehicle->forceFill([
            'rfid_tag_id' => $tag->id,
            'rfid_tag_uid' => $tag->uid,
        ])->save();
        $tag->load('vehicle');

        $this->actingAs($admin)
            ->postJson(route('stations.rfid-scan', 'entrance'), [
                'tag_uid' => $tag->uid,
            ])
            ->assertCreated()
            ->assertJsonPath('scan.verification_status', 'verified')
            ->assertJsonPath('scan.verification_label', 'Registered')
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

        $this->mock(DetectorRuntimeService::class, function ($mock): void {
            $mock->shouldReceive('markStationViewerActive')
                ->once()
                ->with('exit');
            $mock->shouldReceive('ensureRunning')
                ->once()
                ->andReturn([
                    'service_running' => true,
                    'service_message' => 'Detector service is already running.',
                    'updated_at' => now()->toIso8601String(),
                    'cameras' => [
                        'exit' => [
                            'camera_role' => 'exit',
                            'camera_running' => true,
                            'detection_ready' => true,
                            'stream_url' => 'http://127.0.0.1:8765/stream/exit',
                        ],
                    ],
                ]);
        });

        $this->actingAs($admin)
            ->getJson(route('stations.state', 'exit'))
            ->assertOk()
            ->assertJsonPath('logs.0.event_type', 'ENTRY')
            ->assertJsonPath('logs.0.plate_number', 'STA-1002')
            ->assertJsonPath('logs.0.scan_location', 'entrance');
    }

    public function test_station_rfid_scan_links_vehicle_by_legacy_rfid_tag_uid(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $vehicle = Vehicle::query()->create([
            'rfid_tag_uid' => '1261556674',
            'plate_number' => 'LEG-1261',
            'vehicle_owner_name' => 'Legacy RFID Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);
        $vehicle->forceFill([
            'current_state' => Vehicle::STATE_OUTSIDE,
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('stations.rfid-scan', 'entrance'), [
                'tag_uid' => '1261556674',
            ])
            ->assertCreated()
            ->assertJsonPath('scan.verification_status', 'verified')
            ->assertJsonPath('vehicle.id', $vehicle->id)
            ->assertJsonPath('vehicle.plate_number', 'LEG-1261')
            ->assertJsonPath('vehicle.rfid_tag_uid', '1261556674')
            ->assertJsonPath('action_taken', 'ENTRY');

        $tag = RfidTag::query()->where('uid', '1261556674')->firstOrFail();

        $this->assertSame($vehicle->id, $tag->vehicle_id);
        $this->assertSame(RfidTag::STATUS_ASSIGNED, $tag->status);
        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'rfid_tag_id' => $tag->id,
            'rfid_tag_uid' => '1261556674',
            'current_state' => Vehicle::STATE_INSIDE,
        ]);
    }

    public function test_station_rfid_scan_for_guest_category_creates_guest_observation_for_sidebar(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $sourcePath = public_path('camera/entrance_latest_frame.jpg');
        File::ensureDirectoryExists(dirname($sourcePath));
        File::put($sourcePath, 'guest-category-frame');

        try {
            $vehicle = Vehicle::query()->create([
                'plate_number' => 'GST-1005',
                'vehicle_owner_name' => 'Guest Visitor',
                'category' => 'guest',
                'vehicle_type' => 'Van',
            ]);
            $tag = RfidTag::query()->create([
                'uid' => 'RFID-GUEST-1005',
                'status' => RfidTag::STATUS_ASSIGNED,
                'vehicle_id' => $vehicle->id,
                'assigned_at' => now(),
            ]);
            $vehicle->forceFill([
                'rfid_tag_id' => $tag->id,
                'rfid_tag_uid' => $tag->uid,
            ])->save();

            $this->actingAs($admin)
                ->postJson(route('stations.rfid-scan', 'entrance'), [
                    'tag_uid' => $tag->uid,
                ])
                ->assertCreated()
                ->assertJsonPath('scan.verification_status', 'guest')
                ->assertJsonPath('scan.verification_label', 'Guest')
                ->assertJsonPath('scan.scan_location', 'entrance');

            $scanLog = RfidScanLog::query()
                ->with('guestVehicleObservation')
                ->where('tag_uid', $tag->uid)
                ->firstOrFail();
            $observation = $scanLog->guestVehicleObservation;

            $this->assertNotNull($observation);
            $this->assertSame('GST-1005', $observation->plate_number);
            $this->assertSame('entrance', $observation->location);
            $this->assertNotNull($observation->snapshot_path);
            Storage::disk('public')->assertExists($observation->snapshot_path);

            $this->mock(DetectorRuntimeService::class, function ($mock): void {
                $mock->shouldReceive('markStationViewerActive')
                    ->once()
                    ->with('entrance');
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
                    'plate_number' => 'GST-1005',
                    'verification_label' => 'GUEST',
                ]);
        } finally {
            File::delete($sourcePath);
        }
    }

    public function test_station_rfid_scan_ignores_immediate_duplicate_reads(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        [$vehicle, $tag] = $this->createStationVehicleWithTag('DUP-1003', 'RFID-DUP-1003');

        $this->actingAs($admin)
            ->postJson(route('stations.rfid-scan', 'entrance'), [
                'tag_uid' => $tag->uid,
            ])
            ->assertCreated()
            ->assertJsonPath('duplicate_ignored', false)
            ->assertJsonPath('action_taken', 'ENTRY');

        $this->actingAs($admin)
            ->postJson(route('stations.rfid-scan', 'entrance'), [
                'tag_uid' => $tag->uid,
            ])
            ->assertOk()
            ->assertJsonPath('duplicate_ignored', true)
            ->assertJsonPath('vehicle.id', $vehicle->id)
            ->assertJsonPath('vehicle.entries_today_count', 1)
            ->assertJsonPath('vehicle.exits_today_count', 0);

        $this->assertSame(1, RfidScanLog::query()->where('tag_uid', $tag->uid)->count());
        $this->assertSame(1, VehicleEvent::query()->where('vehicle_id', $vehicle->id)->count());
        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'current_state' => Vehicle::STATE_INSIDE,
            'entries_today_count' => 1,
            'exits_today_count' => 0,
        ]);
    }

    public function test_station_state_returns_latest_station_activity_first_with_daily_counts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        [$vehicle, $tag] = $this->createStationVehicleWithTag('CNT-1004', 'RFID-CNT-1004');

        $this->actingAs($admin)
            ->postJson(route('stations.rfid-scan', 'entrance'), ['tag_uid' => $tag->uid])
            ->assertCreated();

        $this->travel(10)->seconds();

        $this->actingAs($admin)
            ->postJson(route('stations.rfid-scan', 'exit'), ['tag_uid' => $tag->uid])
            ->assertCreated();

        $this->travel(10)->seconds();

        $this->actingAs($admin)
            ->postJson(route('stations.rfid-scan', 'entrance'), ['tag_uid' => $tag->uid])
            ->assertCreated();

        $this->mock(DetectorRuntimeService::class, function ($mock): void {
            $mock->shouldReceive('markStationViewerActive')
                ->once()
                ->with('entrance');
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

        $response = $this->actingAs($admin)
            ->getJson(route('stations.state', 'entrance'))
            ->assertOk()
            ->assertJsonPath('logs.0.plate_number', 'CNT-1004')
            ->assertJsonPath('logs.0.event_type', 'ENTRY')
            ->assertJsonPath('logs.0.entries_today_count', 2)
            ->assertJsonPath('logs.0.exits_today_count', 1);

        $vehicleRows = collect($response->json('logs'))
            ->where('plate_number', 'CNT-1004')
            ->values();

        $this->assertCount(3, $vehicleRows);
        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'current_state' => Vehicle::STATE_INSIDE,
            'entries_today_count' => 2,
            'exits_today_count' => 1,
        ]);
    }

    /**
     * @return array{Vehicle, RfidTag}
     */
    protected function createStationVehicleWithTag(string $plateNumber, string $tagUid): array
    {
        $vehicle = Vehicle::query()->create([
            'plate_number' => $plateNumber,
            'vehicle_owner_name' => 'Station Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);
        $vehicle->forceFill([
            'current_state' => Vehicle::STATE_OUTSIDE,
        ])->save();
        $tag = RfidTag::query()->create([
            'uid' => $tagUid,
            'status' => RfidTag::STATUS_ASSIGNED,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now(),
        ]);
        $vehicle->forceFill([
            'rfid_tag_id' => $tag->id,
            'rfid_tag_uid' => $tag->uid,
        ])->save();

        return [$vehicle->fresh(), $tag->fresh()];
    }
}
