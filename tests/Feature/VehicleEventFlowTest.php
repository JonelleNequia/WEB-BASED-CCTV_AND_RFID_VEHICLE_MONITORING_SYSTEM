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

    public function test_manual_exit_matching_ignores_open_guest_sessions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $guestEntry = VehicleEvent::query()->create([
            'event_type' => 'ENTRY',
            'event_status' => VehicleEvent::STATUS_COMPLETED,
            'event_origin' => 'guest_cctv',
            'plate_text' => 'GST-9001',
            'plate_number' => 'GST-9001',
            'vehicle_type' => 'Car',
            'vehicle_color' => 'White',
            'vehicle_category' => 'guest',
            'roi_name' => 'Entrance Guest Detector',
            'event_time' => now()->subMinutes(20),
            'match_status' => 'open',
            'resulting_state' => 'INSIDE',
            'details_completed_at' => now()->subMinutes(20),
        ]);
        ActiveSession::query()->create([
            'entry_event_id' => $guestEntry->id,
            'plate_text' => 'GST-9001',
            'plate_number' => 'GST-9001',
            'vehicle_type' => 'Car',
            'vehicle_color' => 'White',
            'entry_time' => $guestEntry->event_time,
            'status' => 'open',
        ]);

        $exitCamera = Camera::query()->where('camera_name', 'PHILCST Exit Camera')->firstOrFail();

        $this->actingAs($user)->post(route('vehicle-events.store'), [
            'event_type' => 'EXIT',
            'plate_text' => 'GST-9001',
            'plate_confidence' => 99,
            'vehicle_type' => 'Car',
            'vehicle_color' => 'White',
            'camera_id' => $exitCamera->id,
            'roi_name' => 'Main Exit Lane',
            'event_time' => now()->toDateTimeString(),
        ])->assertRedirect();

        $exitEvent = VehicleEvent::query()
            ->where('event_origin', 'manual')
            ->where('event_type', 'EXIT')
            ->where('plate_text', 'GST-9001')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('unmatched', $exitEvent->match_status);
        $this->assertNull($exitEvent->matched_entry_id);
        $this->assertSame('open', ActiveSession::query()->where('entry_event_id', $guestEntry->id)->value('status'));
    }
}
