<?php

namespace Tests\Feature;

use App\Models\ActiveSession;
use App\Models\User;
use App\Models\VehicleEvent;
use App\Services\EventService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingDetailsCompletionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure completing a pending detected ENTRY creates or keeps the active session.
     */
    public function test_completing_a_pending_entry_opens_an_active_session(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $vehicleEvent = VehicleEvent::query()
            ->where('external_event_key', 'seed-detected-entrance-001')
            ->firstOrFail();

        $this->actingAs($user)
            ->put(route('vehicle-events.complete', $vehicleEvent), [
                'plate_text' => ' auto-1001 ',
                'plate_confidence' => 88.5,
                'vehicle_type' => 'Car',
                'vehicle_color' => 'Black',
            ])
            ->assertRedirect(route('vehicle-events.show', $vehicleEvent));

        $vehicleEvent->refresh();

        $this->assertSame('completed', $vehicleEvent->event_status);
        $this->assertSame('AUTO-1001', $vehicleEvent->plate_text);
        $this->assertSame('open', $vehicleEvent->match_status);
        $this->assertSame('open', ActiveSession::query()->where('entry_event_id', $vehicleEvent->id)->value('status'));
    }

    /**
     * Ensure completing a pending detected EXIT triggers the existing weighted matcher.
     */
    public function test_completing_a_pending_exit_runs_automatic_matching(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        /** @var EventService $eventService */
        $eventService = app(EventService::class);

        $vehicleEvent = $eventService->createDetectedEvent([
            'camera_role' => 'exit',
            'detected_vehicle_type' => 'Van',
            'event_time' => now()->toIso8601String(),
            'vehicle_image_path' => 'detected-vehicle-images/exit/pending-exit-test.jpg',
            'external_event_key' => 'pending-exit-match-001',
            'roi_name' => 'Exit Trigger Line',
            'detection_metadata_json' => [
                'track_id' => 18,
                'confidence' => 0.89,
                'detector_class' => 'car',
            ],
        ]);

        $this->actingAs($user)
            ->put(route('vehicle-events.complete', $vehicleEvent), [
                'plate_text' => 'GHI-9012',
                'plate_confidence' => 90,
                'vehicle_type' => 'Van',
                'vehicle_color' => 'Silver',
            ])
            ->assertRedirect(route('vehicle-events.show', $vehicleEvent));

        $vehicleEvent->refresh();

        $this->assertSame('completed', $vehicleEvent->event_status);
        $this->assertSame('matched', $vehicleEvent->match_status);
        $this->assertNotNull($vehicleEvent->matched_entry_id);
        $this->assertSame('closed', $vehicleEvent->matchedEntry?->activeSession?->status);
    }
}
