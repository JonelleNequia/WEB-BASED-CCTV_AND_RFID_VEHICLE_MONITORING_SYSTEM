<?php

namespace Tests\Feature;

use App\Models\GuestVehicleObservation;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventLogReportIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_logs_render_integrated_report_actions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('vehicle-events.index'))
            ->assertOk()
            ->assertSee('Vehicle operations logs')
            ->assertSee('Vehicle Owner Name')
            ->assertSee('Report Actions')
            ->assertSee('Export CSV')
            ->assertSee('event-log-list-view', false)
            ->assertSee('Color')
            ->assertDontSee('Daily and date-range reports');
    }

    public function test_legacy_reports_route_redirects_to_event_logs(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($admin)
            ->get('/reports')
            ->assertRedirect('/vehicle-events');
    }

    public function test_event_logs_include_guest_observation_records(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        Storage::disk('public')->put('guest_snapshots/event-log-guest.jpg', 'guest-snapshot');

        $observation = GuestVehicleObservation::query()->create([
            'plate_number' => 'GST-LOG-01',
            'vehicle_type' => 'Car',
            'vehicle_color' => 'White',
            'location' => 'entrance',
            'observation_source' => 'cctv',
            'status' => 'pending_review',
            'observed_at' => now(),
            'snapshot_path' => 'guest_snapshots/event-log-guest.jpg',
        ]);

        $this->actingAs($admin)
            ->get(route('vehicle-events.index'))
            ->assertOk()
            ->assertSee('GUEST')
            ->assertSee('GST-LOG-01')
            ->assertSee('White')
            ->assertSee('/storage/guest_snapshots/event-log-guest.jpg', false)
            ->assertSee('Guest Observation #'.$observation->id);
    }

    public function test_event_logs_can_filter_records_by_current_month(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $now = Carbon::parse('2026-05-10 10:00:00', 'Asia/Manila');
        $this->travelTo($now);

        try {
            VehicleEvent::query()->create([
                'event_type' => 'ENTRY',
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'event_origin' => 'manual',
                'plate_text' => 'MON-2026',
                'vehicle_type' => 'Car',
                'detected_vehicle_type' => 'Car',
                'event_time' => Carbon::parse('2026-05-08 09:00:00', 'Asia/Manila'),
                'match_status' => 'open',
            ]);

            VehicleEvent::query()->create([
                'event_type' => 'ENTRY',
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'event_origin' => 'manual',
                'plate_text' => 'APR-2026',
                'vehicle_type' => 'Car',
                'detected_vehicle_type' => 'Car',
                'event_time' => Carbon::parse('2026-04-30 23:59:00', 'Asia/Manila'),
                'match_status' => 'open',
            ]);

            $this->actingAs($admin)
                ->get(route('vehicle-events.index', ['period' => 'month']))
                ->assertOk()
                ->assertSee('This Month Logs')
                ->assertSee('MON-2026')
                ->assertDontSee('APR-2026');
        } finally {
            $this->travelBack();
        }
    }

    public function test_realtime_log_endpoints_return_latest_guest_and_event_rows(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        Storage::disk('public')->put('guest_snapshots/realtime-guest.jpg', 'guest-snapshot');

        GuestVehicleObservation::query()->create([
            'plate_number' => 'GST-RT-01',
            'vehicle_type' => 'Van',
            'vehicle_color' => 'Blue',
            'location' => 'entrance',
            'observation_source' => 'cctv',
            'status' => 'pending_review',
            'observed_at' => now(),
            'snapshot_path' => 'guest_snapshots/realtime-guest.jpg',
        ]);

        $this->getJson(route('api.recent-guest-logs'))
            ->assertOk()
            ->assertJsonPath('logs.0.plate_number', 'GST-RT-01')
            ->assertJsonPath('logs.0.vehicle_color', 'Blue');

        $this->getJson(route('api.recent-event-logs'))
            ->assertOk()
            ->assertJsonPath('logs.0.plate_number', 'GST-RT-01')
            ->assertJsonPath('logs.0.event_type', 'GUEST');

        $this->getJson(route('api.recent-station-logs'))
            ->assertOk()
            ->assertJsonPath('logs.0.plate_number', 'GST-RT-01')
            ->assertJsonPath('logs.0.event_type', 'GUEST');
    }

    public function test_event_logs_include_guest_rfid_scan_as_guest_observation(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $sourcePath = public_path('camera/exit_latest_frame.jpg');
        File::ensureDirectoryExists(dirname($sourcePath));
        File::put($sourcePath, 'guest-event-log-frame');

        try {
            $vehicle = Vehicle::query()->create([
                'plate_number' => 'GST-EVT-01',
                'vehicle_owner_name' => 'Guest Event',
                'category' => 'guest',
                'vehicle_type' => 'Car',
            ]);
            $tag = RfidTag::query()->create([
                'uid' => 'RFID-GUEST-EVT-01',
                'status' => RfidTag::STATUS_ASSIGNED,
                'vehicle_id' => $vehicle->id,
                'assigned_at' => now(),
            ]);
            $vehicle->forceFill([
                'rfid_tag_id' => $tag->id,
                'rfid_tag_uid' => $tag->uid,
            ])->save();

            $this->actingAs($admin)
                ->postJson(route('rfid-scans.store'), [
                    'tag_uid' => $tag->uid,
                    'scan_location' => 'exit',
                    'reader_name' => 'Exit RFID Reader',
                ])
                ->assertCreated()
                ->assertJsonPath('scan.verification_status', 'guest');

            $observation = GuestVehicleObservation::query()
                ->where('plate_number', 'GST-EVT-01')
                ->firstOrFail();

            Storage::disk('public')->assertExists($observation->snapshot_path);

            $this->actingAs($admin)
                ->get(route('vehicle-events.index'))
                ->assertOk()
                ->assertSee('GUEST')
                ->assertSee('GST-EVT-01')
                ->assertSee('/storage/'.$observation->snapshot_path, false)
                ->assertSee('Guest Observation #'.$observation->id);
        } finally {
            File::delete($sourcePath);
        }
    }
}
