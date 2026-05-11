<?php

namespace Tests\Feature;

use App\Models\ActiveSession;
use App\Models\GuestVehicleObservation;
use App\Models\User;
use App\Models\VehicleEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            ->patch(route('guest-observations.verify', $observation), [
                'plate_number' => ' abc 1234 ',
                'vehicle_type' => 'SUV',
                'vehicle_color' => 'White',
                'location' => 'entrance',
                'observed_at' => now()->format('Y-m-d H:i:s'),
                'notes' => 'Verified by guard.',
            ])
            ->assertRedirect();

        $observation->refresh();

        $this->assertSame('ABC 1234', $observation->plate_number);
        $this->assertSame('ABC 1234', $observation->plate_text);
        $this->assertSame('SUV', $observation->vehicle_type);
        $this->assertSame('White', $observation->vehicle_color);
        $this->assertSame('verified', $observation->status);
    }

    public function test_verifying_detector_guest_observation_syncs_event_and_session_details(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->withHeaders([
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ])->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-review-sync-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => now()->toIso8601String(),
            'snapshot' => UploadedFile::fake()->image('guest-review-sync.jpg', 640, 480),
            'detection_metadata' => json_encode(['track_id' => 909]),
        ])->assertCreated();

        $observation = GuestVehicleObservation::query()
            ->where('external_event_key', 'guest-review-sync-001')
            ->firstOrFail();
        $event = VehicleEvent::query()
            ->where('external_event_key', 'guest-review-sync-001')
            ->firstOrFail();

        $this->assertNull(ActiveSession::query()->where('entry_event_id', $event->id)->value('plate_text'));

        $this->actingAs($admin)
            ->patch(route('guest-observations.verify', $observation), [
                'plate_number' => ' sync 123 ',
                'vehicle_type' => 'SUV',
                'vehicle_color' => 'Black',
                'location' => 'entrance',
                'observed_at' => now()->format('Y-m-d H:i:s'),
                'notes' => 'Corrected after review.',
            ])
            ->assertRedirect();

        $event->refresh();

        $this->assertSame('SYNC 123', $event->plate_text);
        $this->assertSame('SYNC 123', $event->plate_number);
        $this->assertSame('Black', $event->vehicle_color);
        $this->assertDatabaseHas('active_sessions', [
            'entry_event_id' => $event->id,
            'plate_text' => 'SYNC 123',
            'plate_number' => 'SYNC 123',
            'vehicle_color' => 'Black',
            'status' => 'open',
        ]);
    }
}
