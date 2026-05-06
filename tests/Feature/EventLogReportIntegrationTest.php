<?php

namespace Tests\Feature;

use App\Models\GuestVehicleObservation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
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
            ->assertSee('Guest Observation #'.$observation->id);
    }
}
