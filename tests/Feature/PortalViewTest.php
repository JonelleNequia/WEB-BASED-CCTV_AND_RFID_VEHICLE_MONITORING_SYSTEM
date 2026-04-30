<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalViewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure the old portal URL stays compatible with the new station window.
     */
    public function test_entrance_portal_route_redirects_to_station_window(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->get(route('portals.show', 'entrance'))
            ->assertRedirect(route('stations.entrance'));
    }
}
