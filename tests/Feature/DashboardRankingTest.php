<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardRankingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure the dashboard shows registered vehicles ranked by entry count.
     */
    public function test_dashboard_displays_frequent_entry_ranking(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $vehicle = Vehicle::query()->create([
            'plate_number' => 'RANK-1001',
            'vehicle_owner_name' => 'Ranking Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);
        VehicleEvent::query()->create([
            'event_type' => 'ENTRY',
            'event_status' => VehicleEvent::STATUS_COMPLETED,
            'event_origin' => 'manual',
            'plate_text' => $vehicle->plate_number,
            'vehicle_id' => $vehicle->id,
            'vehicle_type' => $vehicle->vehicle_type,
            'detected_vehicle_type' => $vehicle->vehicle_type,
            'event_time' => now(),
            'match_status' => 'open',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.index'))
            ->assertOk()
            // Phase 6: the Visitor Ranking is separate; UI Phase 4: one card, two tabs.
            ->assertSee('Most Entries')
            ->assertSee('Registered Vehicles')
            ->assertSee('Total Entries')
            ->assertSee('RANK-1001');
    }

    public function test_dashboard_live_state_counts_registered_and_guest_traffic_today(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        // A Wednesday mid-month: "yesterday" is in the same week, month and year.
        $this->travelTo(\Carbon\Carbon::parse('2026-10-14 12:00:00', 'Asia/Manila'));
        $vehicle = Vehicle::query()->create([
            'plate_number' => 'TOT-1001',
            'vehicle_owner_name' => 'Traffic Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);

        foreach (['ENTRY', 'EXIT'] as $eventType) {
            VehicleEvent::query()->create([
                'event_type' => $eventType,
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'event_origin' => 'manual',
                'plate_text' => $vehicle->plate_number,
                'vehicle_id' => $vehicle->id,
                'vehicle_type' => $vehicle->vehicle_type,
                'detected_vehicle_type' => $vehicle->vehicle_type,
                'event_time' => now(),
                'match_status' => 'open',
            ]);
        }

        // Phase 1 (gates): a camera capture counts by its crossing direction,
        // not by the gate (both gates record IN and OUT).
        foreach (['IN' => 'gate-1', 'OUT' => 'gate-1'] as $direction => $location) {
            GuestVehicleObservation::query()->create([
                'plate_number' => 'GST-'.$direction,
                'vehicle_type' => 'Car',
                'vehicle_color' => 'White',
                'location' => $location,
                'detection_metadata_json' => ['direction' => $direction],
                'observation_source' => 'cctv',
                'status' => 'pending_review',
                'observed_at' => now(),
            ]);
        }

        GuestVehicleObservation::query()->create([
            'plate_number' => 'GST-CREATED-TODAY',
            'vehicle_type' => 'Car',
            'vehicle_color' => 'Blue',
            'location' => 'gate-1',
            'observation_source' => 'cctv',
            'status' => 'pending_review',
            'observed_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        RfidScanLog::query()->create([
            'tag_uid' => 'UNLINKED-GUEST-ENTRY',
            'scan_location' => 'gate-1',
            'scan_direction' => 'entry',
            'resolved_event_type' => 'ENTRY',
            'reader_name' => 'Entrance RFID Reader',
            'scan_time' => now(),
            'verification_status' => 'guest',
            'source_mode' => 'station_reader',
        ]);

        $this->actingAs($user)
            ->getJson(route('dashboard.live-state'))
            ->assertOk()
            // 1 registered + 1 capture IN; the unknown-tag read is not a movement.
            // Phase 7 (visitor model): a movement counts on the day it happened
            // (observed yesterday, saved today = yesterday), not the day it was saved.
            ->assertJsonPath('metrics.total_vehicles_entered_today', 2)
            ->assertJsonPath('metrics.total_vehicles_exited_today', 2)
            ->assertJsonPath('metrics.guest_observations_today', 3)
            ->assertJsonPath('traffic_summary.today.entries', 2)
            ->assertJsonPath('traffic_summary.week.entries', 3)
            ->assertJsonPath('traffic_summary.month.entries', 3)
            ->assertJsonPath('traffic_summary.year.entries', 3);
    }

    public function test_dashboard_recent_events_do_not_duplicate_mirrored_guest_observations(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $eventTime = now();

        GuestVehicleObservation::query()->create([
            'plate_number' => 'DPF-233',
            'plate_text' => 'DPF-233',
            'vehicle_type' => 'Car',
            'vehicle_color' => 'White',
            'location' => 'gate-1',
            'observation_source' => 'cctv',
            'status' => 'pending_review',
            'observed_at' => $eventTime,
            'external_event_key' => 'dashboard-mirrored-guest-001',
        ]);

        VehicleEvent::query()->create([
            'event_type' => 'ENTRY',
            'event_status' => VehicleEvent::STATUS_COMPLETED,
            'event_origin' => 'guest_cctv',
            'plate_text' => 'DPF-233',
            'plate_number' => 'DPF-233',
            'vehicle_type' => 'Car',
            'detected_vehicle_type' => 'Car',
            'vehicle_color' => 'White',
            'vehicle_category' => 'guest',
            'event_time' => $eventTime,
            'external_event_key' => 'dashboard-mirrored-guest-001',
            'match_status' => 'open',
            'resulting_state' => 'INSIDE',
        ]);

        VehicleEvent::query()->create([
            'event_type' => 'EXIT',
            'event_status' => VehicleEvent::STATUS_COMPLETED,
            'event_origin' => 'manual',
            'plate_text' => 'EXIT-1001',
            'plate_number' => 'EXIT-1001',
            'vehicle_type' => 'Car',
            'detected_vehicle_type' => 'Car',
            'event_time' => $eventTime->copy()->subMinute(),
            'match_status' => 'closed',
            'resulting_state' => 'OUTSIDE',
        ]);

        $latestEvents = collect($this->actingAs($user)
            ->getJson(route('dashboard.live-state'))
            ->assertOk()
            ->json('latest_events'));

        // UI Phase 2 (wording): IN / OUT and "Unregistered" instead of ENTRY / EXIT and GUEST.
        $this->assertTrue($latestEvents->contains(fn (array $event): bool => $event['title'] === 'IN • DPF-233'
            && $event['badge_label'] === 'Unregistered'));
        $this->assertTrue($latestEvents->contains(fn (array $event): bool => $event['title'] === 'OUT • EXIT-1001'
            && $event['badge_label'] === 'Exit'));
        // The mirrored copy is not listed a second time.
        $this->assertSame(1, $latestEvents->filter(fn (array $event): bool => str_contains($event['title'], 'DPF-233'))->count());
    }

    public function test_dashboard_today_counts_reset_on_philippine_midnight(): void
    {
        $user = User::factory()->create();
        $now = Carbon::parse('2026-05-08 00:30:00', 'Asia/Manila');
        $currentBusinessTime = Carbon::parse('2026-05-08 00:10:00', 'Asia/Manila');
        $previousBusinessTime = Carbon::parse('2026-05-07 23:50:00', 'Asia/Manila');
        $vehicle = Vehicle::query()->create([
            'plate_number' => 'PHD-0001',
            'vehicle_owner_name' => 'PH Window Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);

        $this->travelTo($now);

        try {
            $currentEntry = VehicleEvent::query()->create([
                'event_type' => 'ENTRY',
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'event_origin' => 'rfid_simulated',
                'plate_text' => $vehicle->plate_number,
                'vehicle_id' => $vehicle->id,
                'vehicle_type' => $vehicle->vehicle_type,
                'detected_vehicle_type' => $vehicle->vehicle_type,
                'event_time' => $currentBusinessTime,
                'match_status' => 'open',
            ]);
            $currentEntry->forceFill([
                'created_at' => $currentBusinessTime->copy()->setTimezone('UTC'),
                'updated_at' => $currentBusinessTime->copy()->setTimezone('UTC'),
            ])->saveQuietly();

            $previousEntry = VehicleEvent::query()->create([
                'event_type' => 'ENTRY',
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'event_origin' => 'rfid_simulated',
                'plate_text' => 'PH-OLD',
                'vehicle_type' => 'Car',
                'detected_vehicle_type' => 'Car',
                'event_time' => $previousBusinessTime,
                'match_status' => 'open',
            ]);
            $previousEntry->forceFill([
                'created_at' => $previousBusinessTime->copy()->setTimezone('UTC'),
                'updated_at' => $previousBusinessTime->copy()->setTimezone('UTC'),
            ])->saveQuietly();

            $currentGuest = GuestVehicleObservation::query()->create([
                'plate_number' => 'GST-PH',
                'vehicle_type' => 'Car',
                'vehicle_color' => 'White',
                'location' => 'gate-1',
                'observation_source' => 'cctv',
                'status' => 'pending_review',
                'observed_at' => $currentBusinessTime,
            ]);
            $currentGuest->forceFill([
                'created_at' => $currentBusinessTime->copy()->setTimezone('UTC'),
                'updated_at' => $currentBusinessTime->copy()->setTimezone('UTC'),
            ])->saveQuietly();

            $previousGuest = GuestVehicleObservation::query()->create([
                'plate_number' => 'GST-OLD',
                'vehicle_type' => 'Car',
                'vehicle_color' => 'White',
                'location' => 'gate-1',
                'observation_source' => 'cctv',
                'status' => 'pending_review',
                'observed_at' => $previousBusinessTime,
            ]);
            $previousGuest->forceFill([
                'created_at' => $previousBusinessTime->copy()->setTimezone('UTC'),
                'updated_at' => $previousBusinessTime->copy()->setTimezone('UTC'),
            ])->saveQuietly();

            $currentScan = RfidScanLog::query()->create([
                'tag_uid' => 'PH-SCAN-CURRENT',
                'scan_location' => 'gate-1',
                'scan_direction' => 'entry',
                'resolved_event_type' => 'ENTRY',
                'reader_name' => 'Entrance RFID Reader',
                'scan_time' => $currentBusinessTime,
                'verification_status' => 'verified',
                'source_mode' => 'station_reader',
            ]);
            $currentScan->forceFill([
                'created_at' => $currentBusinessTime->copy()->setTimezone('UTC'),
                'updated_at' => $currentBusinessTime->copy()->setTimezone('UTC'),
            ])->saveQuietly();

            $previousScan = RfidScanLog::query()->create([
                'tag_uid' => 'PH-SCAN-OLD',
                'scan_location' => 'gate-1',
                'scan_direction' => 'entry',
                'resolved_event_type' => 'ENTRY',
                'reader_name' => 'Entrance RFID Reader',
                'scan_time' => $previousBusinessTime,
                'verification_status' => 'verified',
                'source_mode' => 'station_reader',
            ]);
            $previousScan->forceFill([
                'created_at' => $previousBusinessTime->copy()->setTimezone('UTC'),
                'updated_at' => $previousBusinessTime->copy()->setTimezone('UTC'),
            ])->saveQuietly();

            $this->actingAs($user)
                ->getJson(route('dashboard.live-state'))
                ->assertOk()
                ->assertJsonPath('metrics.total_vehicles_entered_today', 2)
                ->assertJsonPath('metrics.registered_scans_today', 1)
                ->assertJsonPath('metrics.guest_observations_today', 1);
        } finally {
            $this->travelBack();
        }
    }
}
