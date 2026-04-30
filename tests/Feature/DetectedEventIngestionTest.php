<?php

namespace Tests\Feature;

use App\Models\VehicleEvent;
use App\Models\RfidTag;
use App\Services\RfidService;
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

    /**
     * Ensure a camera crossing near a verified RFID scan returns overlay data for Python drawing.
     */
    public function test_detector_event_ingestion_returns_registered_overlay_when_rfid_scan_matches(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tag = RfidTag::query()->assigned()->with('vehicle')->firstOrFail();
        $scanTime = now();

        app(RfidService::class)->ingest([
            'tag_uid' => $tag->uid,
            'scan_location' => 'entrance',
            'scan_time' => $scanTime->toIso8601String(),
        ]);

        $payload = [
            'external_event_key' => 'test-crossing-rfid-overlay',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => $scanTime->copy()->addSecond()->toIso8601String(),
            'roi_name' => 'Entrance Trigger Line',
        ];

        $this->withHeaders([
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ])->postJson(route('api.integration.events'), $payload)
            ->assertOk()
            ->assertJsonPath('requires_capture', false)
            ->assertJsonPath('overlay.verification', 'registered')
            ->assertJsonPath('overlay.vehicle.id', $tag->vehicle->id)
            ->assertJsonPath('overlay.vehicle.plate_number', $tag->vehicle->plate_number);

        $this->assertDatabaseMissing('vehicle_events', [
            'external_event_key' => 'test-crossing-rfid-overlay',
        ]);
    }

    /**
     * Ensure detector probes without RFID do not store evidence until Python captures once.
     */
    public function test_detector_event_probe_without_rfid_requests_capture_without_creating_event(): void
    {
        $this->seed(DatabaseSeeder::class);

        $payload = [
            'external_event_key' => 'test-crossing-no-rfid-probe',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => now()->toIso8601String(),
            'roi_name' => 'Entrance Trigger Line',
        ];

        $this->withHeaders([
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ])->postJson(route('api.integration.events'), $payload)
            ->assertAccepted()
            ->assertJsonPath('requires_capture', true)
            ->assertJsonPath('overlay.verification', 'guest');

        $this->assertDatabaseMissing('vehicle_events', [
            'external_event_key' => 'test-crossing-no-rfid-probe',
        ]);
    }
}
