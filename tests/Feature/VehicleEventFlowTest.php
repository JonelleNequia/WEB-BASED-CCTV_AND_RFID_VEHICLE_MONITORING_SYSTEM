<?php

namespace Tests\Feature;

use App\Models\ActiveSession;
use App\Models\Camera;
use App\Models\User;
use App\Models\VehicleEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleEventFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure a manual EXIT can automatically match an open ENTRY session.
     */
    public function test_exit_events_are_automatically_matched_to_open_entries(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $camera = Camera::query()->where('camera_name', 'PHILCST Entrance Camera')->firstOrFail();

        $this->actingAs($user)->post(route('vehicle-events.store'), [
            'event_type' => 'ENTRY',
            'plate_text' => 'TST-1001',
            'plate_confidence' => 99,
            'vehicle_type' => 'Car',
            'vehicle_color' => 'Black',
            'camera_id' => $camera->id,
            'roi_name' => 'Main Entrance Lane',
            'event_time' => now()->subHour()->toDateTimeString(),
        ])->assertRedirect();

        $entryEvent = VehicleEvent::query()
            ->where('event_type', 'ENTRY')
            ->where('plate_text', 'TST-1001')
            ->firstOrFail();

        $exitCamera = Camera::query()->where('camera_name', 'PHILCST Exit Camera')->firstOrFail();

        $this->actingAs($user)->post(route('vehicle-events.store'), [
            'event_type' => 'EXIT',
            'plate_text' => 'TST-1001',
            'plate_confidence' => 99,
            'vehicle_type' => 'Car',
            'vehicle_color' => 'Black',
            'camera_id' => $exitCamera->id,
            'roi_name' => 'Main Exit Lane',
            'event_time' => now()->toDateTimeString(),
        ])->assertRedirect();

        $exitEvent = VehicleEvent::query()
            ->where('event_type', 'EXIT')
            ->where('plate_text', 'TST-1001')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('matched', $exitEvent->match_status);
        $this->assertSame($entryEvent->id, $exitEvent->matched_entry_id);
        $this->assertSame('closed', ActiveSession::query()->where('entry_event_id', $entryEvent->id)->value('status'));
    }
}
