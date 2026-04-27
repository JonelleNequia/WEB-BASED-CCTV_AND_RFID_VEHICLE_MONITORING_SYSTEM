<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->actingAs($user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('Frequent Entry Ranking')
            ->assertSee('Total Entries')
            ->assertSee('ABC-1234');
    }
}
