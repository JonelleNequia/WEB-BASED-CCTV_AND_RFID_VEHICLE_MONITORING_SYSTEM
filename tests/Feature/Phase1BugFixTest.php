<?php

namespace Tests\Feature;

use App\Models\ActiveSession;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\DetectorRuntimeService;
use App\Services\RfidService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 1: timezone, snapshot URLs, station heartbeat, and inside count.
 */
class Phase1BugFixTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_app_runs_on_philippine_time(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->assertSame('Asia/Manila', now()->getTimezone()->getName());
    }

    public function test_utc_scan_time_is_stored_in_philippine_time_and_counted_on_the_local_day(): void
    {
        // 23:30 UTC on Sep 25 is 07:30 on Sep 26 in Manila.
        Carbon::setTestNow(Carbon::parse('2026-09-26 07:35:00', 'Asia/Manila'));
        $vehicle = $this->registeredVehicle('ABC 1234', 'TZ-TAG-1');

        $scan = app(RfidService::class)->ingest([
            'tag_uid' => 'TZ-TAG-1',
            'scan_location' => 'entrance',
            'scan_time' => '2026-09-25T23:30:00Z',
        ], 'station_reader');

        $this->assertSame(
            '2026-09-26 07:30:00',
            DB::table('rfid_scan_logs')->where('id', $scan->id)->value('scan_time')
        );
        $this->assertSame('2026-09-26', $vehicle->fresh()->daily_count_date->toDateString());
        $this->assertSame(1, app(RfidService::class)->stats()['entries_today']);
        $this->assertSame(1, app(RfidService::class)->stats()['scans_today']);
    }

    public function test_detector_times_with_offset_keep_their_local_wall_clock(): void
    {
        $event = VehicleEvent::query()->create([
            'event_type' => 'ENTRY',
            'event_status' => VehicleEvent::STATUS_COMPLETED,
            'event_time' => Carbon::parse('2026-09-26T08:58:00+08:00'),
        ]);

        $this->assertSame('2026-09-26 08:58:00', DB::table('vehicle_events')->where('id', $event->id)->value('event_time'));
        $this->assertSame('08:58 AM', $event->fresh()->event_time->format('h:i A'));
    }

    public function test_snapshot_urls_do_not_depend_on_app_url_port(): void
    {
        config(['app.url' => 'http://127.0.0.1:8001']);

        $this->assertSame(
            '/storage/guest_snapshots/example.jpg',
            Storage::disk('public')->url('guest_snapshots/example.jpg')
        );
    }

    public function test_station_heartbeat_is_written_as_complete_json_without_temp_files(): void
    {
        $service = app(DetectorRuntimeService::class);
        $path = $service->stationActivityPath();
        $original = File::exists($path) ? File::get($path) : null;

        try {
            $service->markStationViewerActive('entrance');
            $service->markStationViewerActive('exit');

            $payload = json_decode((string) File::get($path), true);

            $this->assertIsArray($payload);
            $this->assertArrayHasKey('entrance', $payload['locations']);
            $this->assertArrayHasKey('exit', $payload['locations']);
            $this->assertSame([], File::glob($path.'.*.tmp'));
            $this->assertTrue($service->stationActivity()['active']);
        } finally {
            $original === null ? File::delete($path) : File::put($path, $original);
        }
    }

    public function test_all_pages_show_the_same_vehicles_inside_count(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->registeredVehicle('IN 0001', 'IN-TAG-1', Vehicle::STATE_INSIDE);
        $this->registeredVehicle('IN 0002', 'IN-TAG-2', Vehicle::STATE_INSIDE);
        $this->registeredVehicle('OUT 0003', 'OUT-TAG-3', Vehicle::STATE_OUTSIDE);
        $this->openGuestSession();

        $stats = app(RfidService::class)->stats();
        $this->assertSame(2, $stats['registered_inside']);
        $this->assertSame(1, $stats['guests_inside']);
        $this->assertSame(3, $stats['vehicles_inside']);

        foreach (['dashboard.index', 'vehicle-registry.index', 'rfid-scans.index'] as $route) {
            $this->actingAs($admin)
                ->get(route($route))
                ->assertOk()
                ->assertSee('2 registered · 1 guests');
        }

        $this->actingAs($admin)
            ->getJson(route('dashboard.live-state'))
            ->assertOk()
            ->assertJsonPath('metrics.vehicles_inside', 3);
    }

    protected function registeredVehicle(string $plate, string $uid, string $state = Vehicle::STATE_OUTSIDE): Vehicle
    {
        $vehicle = Vehicle::query()->create([
            'plate_number' => $plate,
            'vehicle_owner_name' => 'Phase One Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);

        $tag = RfidTag::query()->create([
            'uid' => $uid,
            'status' => RfidTag::STATUS_ASSIGNED,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now(),
        ]);

        $vehicle->forceFill([
            'current_state' => $state,
            'rfid_tag_id' => $tag->id,
            'rfid_tag_uid' => $tag->uid,
        ])->save();

        return $vehicle;
    }

    protected function openGuestSession(): void
    {
        $event = VehicleEvent::query()->create([
            'event_type' => 'ENTRY',
            'event_status' => VehicleEvent::STATUS_COMPLETED,
            'event_origin' => 'guest_cctv',
            'vehicle_category' => 'guest',
            'event_time' => now(),
        ]);

        ActiveSession::query()->create([
            'entry_event_id' => $event->id,
            'entry_time' => now(),
            'status' => 'open',
        ]);
    }
}
