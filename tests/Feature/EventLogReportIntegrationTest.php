<?php

namespace Tests\Feature;

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
}
