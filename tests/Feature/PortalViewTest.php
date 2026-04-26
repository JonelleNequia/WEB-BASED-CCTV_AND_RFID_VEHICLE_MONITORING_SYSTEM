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
     * Ensure the lightweight entrance portal page is available for the local multi-monitor setup.
     */
    public function test_entrance_portal_page_renders(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->get(route('portals.show', 'entrance'))
            ->assertOk()
            ->assertSee('PHILCST Entrance Portal')
            ->assertSee('Not connected');
    }
}
