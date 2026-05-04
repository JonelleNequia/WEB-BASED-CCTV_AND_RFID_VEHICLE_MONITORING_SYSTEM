<?php

namespace Tests\Feature;

use App\Models\GuestVehicleObservation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestObservationReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_verify_and_correct_guest_observation_details(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $observation = GuestVehicleObservation::query()->create([
            'plate_text' => null,
            'plate_number' => null,
            'vehicle_type' => 'Car',
            'location' => 'entrance',
            'observation_source' => 'cctv',
            'status' => 'pending_review',
            'observed_at' => now(),
            'snapshot_path' => 'guest_snapshots/review-test.jpg',
        ]);

        $this->actingAs($admin)
            ->patch(route('guest-observations.update', $observation), [
                'plate_number' => ' abc 1234 ',
                'vehicle_type' => 'SUV',
                'vehicle_color' => 'White',
                'location' => 'entrance',
                'observed_at' => now()->format('Y-m-d H:i:s'),
                'status' => 'reviewed',
                'notes' => 'Verified by guard.',
            ])
            ->assertRedirect();

        $observation->refresh();

        $this->assertSame('ABC 1234', $observation->plate_number);
        $this->assertSame('ABC 1234', $observation->plate_text);
        $this->assertSame('SUV', $observation->vehicle_type);
        $this->assertSame('reviewed', $observation->status);
    }
}

