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
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('class="page-header"', false)
            ->assertSee('All Events')
            ->assertSee('Vehicle Owner Name')
            ->assertSee('data-event-log-print', false)
            ->assertSee('Print Reports')
            ->assertSee('Print All Records')
            ->assertSee('Print Today')
            ->assertSee('Print This Week')
            ->assertSee('Print This Year')
            ->assertSee('event-log-report-data', false)
            ->assertSee('CSV')
            ->assertSee('event-log-list-view', false)
            ->assertSee('Color')
            ->assertDontSee('Daily and date-range reports');
    }

    public function test_event_log_record_csv_export_contains_one_compact_record(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $event = VehicleEvent::query()->create([
            'event_type' => 'ENTRY',
            'event_status' => VehicleEvent::STATUS_COMPLETED,
            'event_origin' => 'manual',
            'plate_text' => 'ONE-1001',
            'vehicle_type' => 'Car',
            'detected_vehicle_type' => 'Car',
            'vehicle_color' => 'White',
            'event_time' => now(),
            'match_status' => 'open',
            'resulting_state' => 'INSIDE',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('vehicle-events.export.csv', [
                'record_type' => 'vehicle_event',
                'record_id' => $event->id,
            ]))
            ->assertOk();

        $csv = $response->streamedContent();

        // Phase 4: CSV now includes Log Type and Source (e.g. "Guest Pass #G-03").
        // Phase 7 (visitor model): Category and Gate columns.
        $this->assertStringContainsString('Type,"Log Type",Source,Plate,Owner,Vehicle,Color,Category,Gate,State,Time,Status,"RFID Tag"', $csv);
        $this->assertMatchesRegularExpression('/ENTRY,[^,]+,[^,]*,ONE-1001/', $csv);
        $this->assertStringNotContainsString('Record Type,ID', $csv);
    }

    public function test_legacy_reports_route_redirects_to_event_logs(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($admin)
            ->get('/reports')
            ->assertRedirect('/logs');
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
            'location' => 'gate-1',
            'observation_source' => 'cctv',
            'status' => 'pending_review',
            'observed_at' => now(),
            'snapshot_path' => 'guest_snapshots/event-log-guest.jpg',
        ]);

        $this->actingAs($admin)
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('Unregistered')
            ->assertSee('GST-LOG-01')
            ->assertSee('White')
            ->assertSee('/storage/guest_snapshots/event-log-guest.jpg', false)
            ->assertSee('Unregistered Visitor #'.$observation->id)
            ->assertDontSee('Guest Observation');
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
                ->get(route('logs.index', ['period' => 'month']))
                ->assertOk()
                ->assertSee('This Month')
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
            'location' => 'gate-1',
            'observation_source' => 'cctv',
            'status' => 'pending_review',
            'observed_at' => now(),
            'snapshot_path' => 'guest_snapshots/realtime-guest.jpg',
        ]);

        // Phase 6: the log feeds need a signed-in admin.
        $this->actingAs(User::query()->where('email', 'admin@philcst.local')->firstOrFail());

        $this->getJson(route('api.recent-guest-logs'))
            ->assertOk()
            ->assertJsonPath('logs.0.plate_number', 'GST-RT-01')
            ->assertJsonPath('logs.0.vehicle_color', 'Blue');

        $this->getJson(route('api.recent-event-logs'))
            ->assertOk()
            ->assertJsonPath('logs.0.plate_number', 'GST-RT-01')
            ->assertJsonPath('logs.0.event_type', 'UNREGISTERED');

        $this->getJson(route('api.recent-station-logs'))
            ->assertOk()
            ->assertJsonPath('logs.0.plate_number', 'GST-RT-01')
            ->assertJsonPath('logs.0.event_type', 'UNREGISTERED');
    }

    public function test_old_guest_category_tag_read_is_flagged_and_makes_no_guest_record(): void
    {
        // Phase 8 (visitor model): the old "Guest" category no longer creates
        // a guest record; the read is flagged so the Registry entry gets fixed.
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $vehicle = Vehicle::query()->create([
            'plate_number' => 'GST-EVT-01',
            'vehicle_owner_name' => 'Guest Event',
            'category' => 'guest',
            'vehicle_type' => 'Car',
        ]);
        $tag = RfidTag::query()->create(['uid' => 'RFID-GUEST-EVT-01', 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        $this->actingAs($admin)
            ->postJson(route('rfid-scans.store'), ['tag_uid' => $tag->uid, 'scan_location' => 'gate-2'])
            ->assertCreated()
            ->assertJsonPath('scan.verification_status', 'guest');

        $this->assertSame(0, GuestVehicleObservation::query()->count());
        $this->actingAs($admin)->get(route('logs.index', ['tab' => 'alerts']))
            ->assertOk()
            ->assertSee('set Faculty &amp; Staff or Registered Visitor in the Registry', false);
    }
}
