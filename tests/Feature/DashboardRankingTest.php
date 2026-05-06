<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\GuestVehicleObservation;
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
            ->assertSee('Frequent Entry Ranking')
            ->assertSee('Total Entries')
            ->assertSee('RANK-1001');
    }

    public function test_dashboard_live_state_counts_registered_and_guest_traffic_today(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
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

        foreach (['entrance', 'exit'] as $location) {
            GuestVehicleObservation::query()->create([
                'plate_number' => 'GST-'.$location,
                'vehicle_type' => 'Car',
                'vehicle_color' => 'White',
                'location' => $location,
                'observation_source' => 'cctv',
                'status' => 'pending_review',
                'observed_at' => now(),
            ]);
        }

        GuestVehicleObservation::query()->create([
            'plate_number' => 'GST-CREATED-TODAY',
            'vehicle_type' => 'Car',
            'vehicle_color' => 'Blue',
            'location' => 'entrance',
            'observation_source' => 'cctv',
            'status' => 'pending_review',
            'observed_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson(route('dashboard.live-state'))
            ->assertOk()
            ->assertJsonPath('metrics.total_vehicles_entered_today', 3)
            ->assertJsonPath('metrics.total_vehicles_exited_today', 2)
            ->assertJsonPath('metrics.guest_observations_today', 3);
    }
}
