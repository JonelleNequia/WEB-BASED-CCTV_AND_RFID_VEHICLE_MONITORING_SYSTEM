<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringCameraStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure the legacy monitoring route points operators to the dedicated station windows.
     */
    public function test_monitoring_route_redirects_to_entrance_station(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->get(route('monitoring.index'))
            ->assertRedirect(route('gates.index'));
    }
}
