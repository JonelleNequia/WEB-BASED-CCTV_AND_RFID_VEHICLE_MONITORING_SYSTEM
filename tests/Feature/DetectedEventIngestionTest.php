<?php

namespace Tests\Feature;

use App\Models\VehicleEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetectedEventIngestionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure the Python detector can ingest one crossing as a pending details event.
     */
    public function test_detector_event_ingestion_creates_a_pending_details_record(): void
    {
        $this->seed(DatabaseSeeder::class);

        $payload = [
            'external_event_key' => 'test-crossing-entrance-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => now()->toIso8601String(),
            'vehicle_image_path' => 'detected-vehicle-images/entrance/test-crossing-entrance-001.jpg',
            'roi_name' => 'Entrance Trigger Line',
            'detection_metadata' => [
                'track_id' => 12,
                'confidence' => 0.93,
                'detector_class' => 'car',
                'line_side_before' => -1,
                'line_side_after' => 1,
            ],
        ];

        $this->withHeaders([
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ])->postJson(route('api.integration.events'), $payload)
            ->assertCreated()
            ->assertJsonPath('event_status', 'pending_details')
            ->assertJsonPath('event_type', 'ENTRY');

        $event = VehicleEvent::query()
            ->where('external_event_key', 'test-crossing-entrance-001')
            ->firstOrFail();

        $this->assertSame('pending_details', $event->event_status);
        $this->assertSame('pending_details', $event->match_status);
        $this->assertSame('ENTRY', $event->event_type);
        $this->assertSame('Car', $event->detected_vehicle_type);
    }

    /**
     * Ensure repeated detector payloads do not create duplicate events.
     */
    public function test_detector_event_ingestion_is_idempotent_by_external_event_key(): void
    {
        $this->seed(DatabaseSeeder::class);

        $payload = [
            'external_event_key' => 'test-crossing-exit-duplicate',
            'camera_role' => 'exit',
            'detected_vehicle_type' => 'Truck',
            'event_time' => now()->toIso8601String(),
            'vehicle_image_path' => 'detected-vehicle-images/exit/test-crossing-exit-duplicate.jpg',
            'roi_name' => 'Exit Trigger Line',
        ];

        $headers = [
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ];

        $this->withHeaders($headers)
            ->postJson(route('api.integration.events'), $payload)
            ->assertCreated();

        $this->withHeaders($headers)
            ->postJson(route('api.integration.events'), $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Duplicate crossing ignored. The original incomplete record event is still available.');

        $this->assertSame(1, VehicleEvent::query()->where('external_event_key', 'test-crossing-exit-duplicate')->count());
    }
}
