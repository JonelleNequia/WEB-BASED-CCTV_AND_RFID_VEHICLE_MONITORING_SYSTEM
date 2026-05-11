<?php

namespace Tests\Feature;

use App\Models\ActiveSession;
use App\Models\EventReceiveLog;
use App\Models\GuestVehicleObservation;
use App\Models\RfidTag;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\RfidService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DetectedEventIngestionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure the Python detector stores one unregistered crossing as a guest observation.
     */
    public function test_detector_event_ingestion_creates_a_guest_observation_record(): void
    {
        $this->seed(DatabaseSeeder::class);

        $payload = [
            'external_event_key' => 'test-crossing-entrance-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'vehicle_color' => 'Red',
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
            ->assertJsonPath('event_status', 'pending_review')
            ->assertJsonPath('event_type', 'GUEST')
            ->assertJsonPath('overlay.verification', 'guest');

        $observation = GuestVehicleObservation::query()
            ->where('external_event_key', 'test-crossing-entrance-001')
            ->firstOrFail();

        $this->assertSame('pending_review', $observation->status);
        $this->assertSame('entrance', $observation->location);
        $this->assertSame('Car', $observation->vehicle_type);
        $this->assertSame('Red', $observation->vehicle_color);
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
            ->assertJsonPath('message', 'Duplicate guest observation ignored.');

        $this->assertSame(1, GuestVehicleObservation::query()->where('external_event_key', 'test-crossing-exit-duplicate')->count());
    }

    /**
     * Ensure a camera crossing near a verified RFID scan returns overlay data for Python drawing.
     */
    public function test_detector_event_ingestion_returns_registered_overlay_when_rfid_scan_matches(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tag = $this->createAssignedVehicleWithTag('RFID-DETECT-1001', 'DET-1001');
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

        $this->assertDatabaseMissing('guest_vehicle_observations', [
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
            'vehicle_color' => 'White',
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

        $this->assertDatabaseMissing('guest_vehicle_observations', [
            'external_event_key' => 'test-crossing-no-rfid-probe',
        ]);
    }

    public function test_detector_can_poll_rfid_match_endpoint_during_detection_window(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tag = $this->createAssignedVehicleWithTag('RFID-DETECT-2002', 'DET-2002');
        $scanTime = now();

        app(RfidService::class)->ingest([
            'tag_uid' => $tag->uid,
            'scan_location' => 'entrance',
            'scan_time' => $scanTime->toIso8601String(),
        ]);

        $this->withHeaders([
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ])->getJson(route('api.integration.rfid-match', [
            'camera_role' => 'entrance',
            'event_time' => $scanTime->copy()->subSecond()->toIso8601String(),
            'window_seconds' => 4,
        ]))
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('overlay.verification', 'registered')
            ->assertJsonPath('overlay.vehicle.plate_number', $tag->vehicle->plate_number);
    }

    public function test_detector_can_poll_rfid_match_across_stations_during_detection_window(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tag = $this->createAssignedVehicleWithTag('RFID-DETECT-CROSS-2002', 'CRS-2002');
        $scanTime = now();

        app(RfidService::class)->ingest([
            'tag_uid' => $tag->uid,
            'scan_location' => 'entrance',
            'scan_time' => $scanTime->toIso8601String(),
        ]);

        $this->withHeaders([
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ])->getJson(route('api.integration.rfid-match', [
            'camera_role' => 'exit',
            'event_time' => $scanTime->copy()->subSecond()->toIso8601String(),
            'window_seconds' => 4,
        ]))
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('overlay.verification', 'registered')
            ->assertJsonPath('overlay.vehicle.plate_number', $tag->vehicle->plate_number);
    }

    public function test_local_detector_can_poll_rfid_match_without_key_and_with_detector_timezone(): void
    {
        $this->seed(DatabaseSeeder::class);

        SystemSetting::query()->updateOrCreate(
            ['setting_key' => 'python_api_key'],
            ['setting_value' => '']
        );

        $tag = $this->createAssignedVehicleWithTag('RFID-DETECT-3003', 'DET-3003');
        $scanTime = now();

        app(RfidService::class)->ingest([
            'tag_uid' => $tag->uid,
            'scan_location' => 'entrance',
            'scan_time' => $scanTime->toIso8601String(),
        ], 'station_reader');

        $detectorEventTime = $scanTime
            ->copy()
            ->subSecond()
            ->setTimezone('Asia/Manila')
            ->toIso8601String();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Source-Name' => 'phpunit-local-detector'])
            ->getJson(route('api.integration.rfid-match', [
                'camera_role' => 'entrance',
                'event_time' => $detectorEventTime,
                'window_seconds' => 4,
            ]))
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('overlay.verification', 'registered')
            ->assertJsonPath('overlay.vehicle.plate_number', $tag->vehicle->plate_number);
    }

    public function test_rfid_match_endpoint_allows_realtime_detector_polling_burst(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tag = $this->createAssignedVehicleWithTag('RFID-DETECT-4004', 'DET-4004');
        $scanTime = now();

        app(RfidService::class)->ingest([
            'tag_uid' => $tag->uid,
            'scan_location' => 'entrance',
            'scan_time' => $scanTime->toIso8601String(),
        ], 'station_reader');

        $headers = [
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ];

        for ($attempt = 0; $attempt < 75; $attempt++) {
            $this->withHeaders($headers)
                ->getJson(route('api.integration.rfid-match', [
                    'camera_role' => 'entrance',
                    'event_time' => $scanTime->copy()->subSecond()->toIso8601String(),
                    'window_seconds' => 4,
                ]))
                ->assertOk()
                ->assertJsonPath('matched', true);
        }
    }

    public function test_detector_guest_observation_endpoint_creates_pending_review_record(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->withHeaders([
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ])->postJson(route('api.integration.guest-observations'), [
            'external_event_key' => 'guest-window-timeout-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'vehicle_color' => 'White',
            'event_time' => now()->toIso8601String(),
            'vehicle_image_path' => 'detected-vehicle-images/entrance/guest-window-timeout-001.jpg',
            'detection_metadata' => [
                'track_id' => 44,
                'rfid_window_seconds' => 4,
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'pending_review')
            ->assertJsonPath('overlay.verification', 'guest');

        $observation = GuestVehicleObservation::query()
            ->where('external_event_key', 'guest-window-timeout-001')
            ->firstOrFail();

        $this->assertSame('pending_review', $observation->status);
        $this->assertSame('entrance', $observation->location);
        $this->assertSame('cctv', $observation->observation_source);
        $this->assertSame('White', $observation->vehicle_color);
    }

    public function test_detector_guest_observation_accepts_multipart_snapshot_upload(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $this->withHeaders([
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ])->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-timeout-upload-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => now()->toIso8601String(),
            'plate_number' => 'abc1234',
            'vehicle_color' => 'black',
            'snapshot' => UploadedFile::fake()->image('guest-upload.jpg', 640, 480),
            'detection_metadata' => json_encode([
                'track_id' => 55,
                'rfid_window_seconds' => 4,
            ]),
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'pending_review')
            ->assertJsonPath('overlay.verification', 'guest');

        $observation = GuestVehicleObservation::query()
            ->where('external_event_key', 'guest-window-timeout-upload-001')
            ->firstOrFail();

        $this->assertStringStartsWith('guest_snapshots/', $observation->snapshot_path);
        $this->assertSame('ABC1234', $observation->plate_number);
        $this->assertSame('ABC1234', $observation->plate_text);
        $this->assertSame('Black', $observation->vehicle_color);
        $this->assertSame(55, $observation->detection_metadata_json['track_id']);
        Storage::disk('public')->assertExists($observation->snapshot_path);

        $event = VehicleEvent::query()
            ->where('external_event_key', 'guest-window-timeout-upload-001')
            ->firstOrFail();

        $this->assertSame('ENTRY', $event->event_type);
        $this->assertSame('guest_cctv', $event->event_origin);
        $this->assertSame('guest', $event->vehicle_category);
        $this->assertSame('ABC1234', $event->plate_number);
        $this->assertSame('open', $event->match_status);
        $this->assertSame('INSIDE', $event->resulting_state);
        $this->assertSame($observation->snapshot_path, $event->vehicle_image_path);

        $this->assertDatabaseHas('active_sessions', [
            'entry_event_id' => $event->id,
            'plate_number' => 'ABC1234',
            'status' => 'open',
        ]);
    }

    public function test_detector_guest_exit_closes_the_open_guest_session(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $headers = [
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ];
        $eventTime = now();
        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $baselineInside = (int) $this->actingAs($admin)
            ->getJson(route('dashboard.live-state'))
            ->json('metrics.vehicles_inside');

        $this->withHeaders($headers)->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-persistent-entry-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => $eventTime->toIso8601String(),
            'plate_number' => 'PGS-777',
            'vehicle_color' => 'white',
            'snapshot' => UploadedFile::fake()->image('guest-persistent-entry.jpg', 640, 480),
            'detection_metadata' => json_encode(['track_id' => 155]),
        ])->assertCreated();

        $entryEvent = VehicleEvent::query()
            ->where('external_event_key', 'guest-persistent-entry-001')
            ->firstOrFail();

        $this->assertSame('open', ActiveSession::query()->where('entry_event_id', $entryEvent->id)->value('status'));
        $this->actingAs($admin)
            ->getJson(route('dashboard.live-state'))
            ->assertJsonPath('metrics.vehicles_inside', $baselineInside + 1);

        $this->travel(4)->minutes();

        $this->withHeaders($headers)->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-persistent-exit-001',
            'camera_role' => 'exit',
            'detected_vehicle_type' => 'Car',
            'event_time' => $eventTime->copy()->addMinutes(10)->toIso8601String(),
            'plate_number' => 'PGS 777',
            'vehicle_color' => 'white',
            'snapshot' => UploadedFile::fake()->image('guest-persistent-exit.jpg', 640, 480),
            'detection_metadata' => json_encode(['track_id' => 255]),
        ])->assertCreated();

        $exitEvent = VehicleEvent::query()
            ->where('external_event_key', 'guest-persistent-exit-001')
            ->firstOrFail();

        $this->assertSame('EXIT', $exitEvent->event_type);
        $this->assertSame('closed', $exitEvent->match_status);
        $this->assertSame('OUTSIDE', $exitEvent->resulting_state);
        $this->assertSame($entryEvent->id, $exitEvent->matched_entry_id);
        $this->assertSame('closed', ActiveSession::query()->where('entry_event_id', $entryEvent->id)->value('status'));
        $this->assertNotNull(ActiveSession::query()->where('entry_event_id', $entryEvent->id)->value('time_out'));
        $this->actingAs($admin)
            ->getJson(route('dashboard.live-state'))
            ->assertJsonPath('metrics.vehicles_inside', $baselineInside);

        $this->travelBack();
    }

    public function test_detector_guest_observation_is_suppressed_when_recent_rfid_scan_is_registered(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $tag = $this->createAssignedVehicleWithTag('RFID-GUEST-SUPPRESS-1001', 'REG-1001');
        $scanTime = now();

        app(RfidService::class)->ingest([
            'tag_uid' => $tag->uid,
            'scan_location' => 'entrance',
            'scan_time' => $scanTime->toIso8601String(),
        ], 'station_reader');

        $this->withHeaders([
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ])->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-registered-suppressed-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => $scanTime->copy()->addSecond()->toIso8601String(),
            'snapshot' => UploadedFile::fake()->image('guest-suppressed.jpg', 640, 480),
            'detection_metadata' => json_encode([
                'track_id' => 88,
                'analysis_status' => 'pending',
            ]),
        ])
            ->assertOk()
            ->assertJsonPath('suppressed', true)
            ->assertJsonPath('overlay.verification', 'registered')
            ->assertJsonPath('overlay.vehicle.plate_number', 'REG-1001');

        $this->assertDatabaseMissing('guest_vehicle_observations', [
            'external_event_key' => 'guest-window-registered-suppressed-001',
        ]);

        $this->assertTrue(EventReceiveLog::query()
            ->where('status', 'guest_observation_suppressed_registered')
            ->exists());
    }

    public function test_detector_guest_observation_is_suppressed_when_recent_rfid_scan_is_registered_across_stations(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $tag = $this->createAssignedVehicleWithTag('RFID-GUEST-SUPPRESS-CROSS-1001', 'CRS-1001');
        $scanTime = now();

        app(RfidService::class)->ingest([
            'tag_uid' => $tag->uid,
            'scan_location' => 'entrance',
            'scan_time' => $scanTime->toIso8601String(),
        ], 'station_reader');

        $this->withHeaders([
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ])->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-registered-suppressed-cross-001',
            'camera_role' => 'exit',
            'detected_vehicle_type' => 'Car',
            'event_time' => $scanTime->copy()->addSecond()->toIso8601String(),
            'snapshot' => UploadedFile::fake()->image('guest-suppressed-cross.jpg', 640, 480),
            'detection_metadata' => json_encode([
                'track_id' => 89,
                'analysis_status' => 'pending',
            ]),
        ])
            ->assertOk()
            ->assertJsonPath('suppressed', true)
            ->assertJsonPath('overlay.verification', 'registered')
            ->assertJsonPath('overlay.vehicle.plate_number', 'CRS-1001');

        $this->assertDatabaseMissing('guest_vehicle_observations', [
            'external_event_key' => 'guest-window-registered-suppressed-cross-001',
        ]);
    }

    public function test_detector_guest_observation_merges_recent_duplicate_track_ids_across_stations(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $headers = [
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ];
        $eventTime = now();

        $this->withHeaders($headers)->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-duplicate-track-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => $eventTime->toIso8601String(),
            'plate_number' => 'gst 123',
            'vehicle_color' => 'white',
            'snapshot' => UploadedFile::fake()->image('guest-duplicate-a.jpg', 640, 480),
            'detection_metadata' => json_encode([
                'track_id' => 91,
                'bbox_xyxy' => [120, 140, 560, 420],
            ]),
        ])->assertCreated();

        $this->withHeaders($headers)->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-duplicate-track-002',
            'camera_role' => 'exit',
            'detected_vehicle_type' => 'Car',
            'event_time' => $eventTime->copy()->addSeconds(2)->toIso8601String(),
            'snapshot' => UploadedFile::fake()->image('guest-duplicate-b.jpg', 640, 480),
            'detection_metadata' => json_encode([
                'track_id' => 92,
                'bbox_xyxy' => [130, 150, 570, 430],
            ]),
        ])
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('message', 'Recent duplicate guest observation merged.');

        $this->withHeaders($headers)->postJson(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-duplicate-track-002',
            'camera_role' => 'exit',
            'detected_vehicle_type' => 'Car',
            'event_time' => $eventTime->copy()->addSeconds(3)->toIso8601String(),
            'plate_number' => 'gst 128',
            'vehicle_color' => 'gray',
            'detection_metadata' => [
                'track_id' => 92,
                'analysis_status' => 'complete',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('message', 'Duplicate guest observation merged.');

        $this->assertSame(1, GuestVehicleObservation::query()
            ->whereIn('external_event_key', [
                'guest-window-duplicate-track-001',
                'guest-window-duplicate-track-002',
            ])
            ->count());

        $observation = GuestVehicleObservation::query()
            ->where('external_event_key', 'guest-window-duplicate-track-001')
            ->firstOrFail();

        $this->assertSame('GST 123', $observation->plate_number);
        $this->assertSame('White', $observation->vehicle_color);
        $this->assertSame('complete', $observation->detection_metadata_json['analysis_status']);
    }

    public function test_detector_guest_observation_merges_same_plate_after_track_changes_and_keeps_snapshot_for_logs(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $headers = [
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ];
        $eventTime = now();

        $firstResponse = $this->withHeaders($headers)->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-plate-long-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => $eventTime->copy()->subSeconds(90)->toIso8601String(),
            'plate_number' => 'aal',
            'vehicle_color' => 'silver',
            'snapshot' => UploadedFile::fake()->image('guest-same-plate-a.jpg', 640, 480),
            'detection_metadata' => json_encode([
                'track_id' => 101,
            ]),
        ])
            ->assertCreated()
            ->assertJsonPath('duplicate', false);

        $observationId = $firstResponse->json('guest_observation_id');

        $this->withHeaders($headers)->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-plate-long-002',
            'camera_role' => 'exit',
            'detected_vehicle_type' => 'Car',
            'event_time' => $eventTime->toIso8601String(),
            'plate_number' => 'A A L',
            'vehicle_color' => 'silver',
            'snapshot' => UploadedFile::fake()->image('guest-same-plate-b.jpg', 640, 480),
            'detection_metadata' => json_encode([
                'track_id' => 202,
            ]),
        ])
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('guest_observation_id', $observationId)
            ->assertJsonPath('message', 'Recent duplicate guest observation merged.');

        $this->assertSame(1, GuestVehicleObservation::query()
            ->whereIn('external_event_key', [
                'guest-window-plate-long-001',
                'guest-window-plate-long-002',
            ])
            ->count());

        $observation = GuestVehicleObservation::query()->findOrFail($observationId);

        $this->assertSame('AAL', $observation->plate_number);
        $this->assertStringStartsWith('guest_snapshots/', $observation->snapshot_path);
        Storage::disk('public')->assertExists($observation->snapshot_path);

        $event = VehicleEvent::query()
            ->whereIn('external_event_key', [
                'guest-window-plate-long-001',
                'guest-window-plate-long-002',
            ])
            ->firstOrFail();

        $this->assertSame(1, VehicleEvent::query()
            ->whereIn('external_event_key', [
                'guest-window-plate-long-001',
                'guest-window-plate-long-002',
            ])
            ->count());
        $this->assertSame('ENTRY', $event->event_type);
        $this->assertSame('guest_cctv', $event->event_origin);
        $this->assertSame($observation->snapshot_path, $event->vehicle_image_path);

        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('guest-observations.index'))
            ->assertOk()
            ->assertSee($observation->snapshot_url, false);

        $this->actingAs($admin)
            ->get(route('vehicle-events.index'))
            ->assertOk()
            ->assertSee($observation->snapshot_url, false);

        $this->assertTrue(EventReceiveLog::query()
            ->where('status', 'guest_observation_recent_duplicate_merged')
            ->where('notes', 'like', '%'.$observationId.'%')
            ->exists());
    }

    public function test_detector_guest_observation_merges_unplated_same_source_cross_station_by_receive_time(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $headers = [
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ];
        $staleDetectorTime = now()->subMinutes(2);

        $firstResponse = $this->withHeaders($headers)->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-stale-same-source-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => $staleDetectorTime->toIso8601String(),
            'snapshot' => UploadedFile::fake()->image('guest-stale-source-a.jpg', 640, 480),
            'detection_metadata' => json_encode([
                'track_id' => 301,
                'bbox_xyxy' => [100, 120, 520, 420],
            ]),
        ])
            ->assertCreated()
            ->assertJsonPath('duplicate', false);

        $observationId = $firstResponse->json('guest_observation_id');

        $this->withHeaders($headers)->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-stale-same-source-002',
            'camera_role' => 'exit',
            'detected_vehicle_type' => 'Car',
            'event_time' => $staleDetectorTime->toIso8601String(),
            'snapshot' => UploadedFile::fake()->image('guest-stale-source-b.jpg', 640, 480),
            'detection_metadata' => json_encode([
                'track_id' => 302,
                'bbox_xyxy' => [108, 128, 528, 428],
            ]),
        ])
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('guest_observation_id', $observationId);

        $this->assertSame(1, GuestVehicleObservation::query()
            ->whereIn('external_event_key', [
                'guest-window-stale-same-source-001',
                'guest-window-stale-same-source-002',
            ])
            ->count());

        $this->assertSame(1, VehicleEvent::query()
            ->whereIn('external_event_key', [
                'guest-window-stale-same-source-001',
                'guest-window-stale-same-source-002',
            ])
            ->count());

        $event = VehicleEvent::query()
            ->where('external_event_key', 'guest-window-stale-same-source-001')
            ->firstOrFail();

        $this->assertSame('ENTRY', $event->event_type);
        $this->assertSame('guest_cctv', $event->event_origin);
    }

    public function test_detector_guest_observation_does_not_merge_unplated_same_source_when_bbox_is_different(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $headers = [
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ];
        $eventTime = now();

        $this->withHeaders($headers)->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-source-different-box-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => $eventTime->toIso8601String(),
            'snapshot' => UploadedFile::fake()->image('guest-source-box-a.jpg', 640, 480),
            'detection_metadata' => json_encode([
                'track_id' => 401,
                'bbox_xyxy' => [20, 30, 240, 180],
            ]),
        ])->assertCreated();

        $this->withHeaders($headers)->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-source-different-box-002',
            'camera_role' => 'exit',
            'detected_vehicle_type' => 'Car',
            'event_time' => $eventTime->copy()->addSeconds(2)->toIso8601String(),
            'snapshot' => UploadedFile::fake()->image('guest-source-box-b.jpg', 640, 480),
            'detection_metadata' => json_encode([
                'track_id' => 402,
                'bbox_xyxy' => [420, 320, 620, 520],
            ]),
        ])
            ->assertCreated()
            ->assertJsonPath('duplicate', false);

        $this->assertSame(2, GuestVehicleObservation::query()
            ->whereIn('external_event_key', [
                'guest-window-source-different-box-001',
                'guest-window-source-different-box-002',
            ])
            ->count());

        $this->assertSame(2, VehicleEvent::query()
            ->whereIn('external_event_key', [
                'guest-window-source-different-box-001',
                'guest-window-source-different-box-002',
            ])
            ->count());
    }

    public function test_detector_guest_observation_duplicate_updates_late_ocr_details(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $headers = [
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ];

        $this->withHeaders($headers)->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-two-step-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => now()->toIso8601String(),
            'snapshot' => UploadedFile::fake()->image('guest-two-step.jpg', 640, 480),
            'detection_metadata' => json_encode([
                'track_id' => 77,
                'analysis_status' => 'pending',
            ]),
        ])->assertCreated();

        $this->withHeaders($headers)->postJson(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-two-step-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => now()->toIso8601String(),
            'plate_number' => 'abc 123',
            'vehicle_color' => 'white',
            'detection_metadata' => [
                'track_id' => 77,
                'analysis_status' => 'complete',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('overlay.verification', 'guest');

        $observation = GuestVehicleObservation::query()
            ->where('external_event_key', 'guest-window-two-step-001')
            ->firstOrFail();

        $this->assertSame('ABC 123', $observation->plate_number);
        $this->assertSame('White', $observation->vehicle_color);
        $this->assertSame('complete', $observation->detection_metadata_json['analysis_status']);
        $this->assertSame(1, GuestVehicleObservation::query()->where('external_event_key', 'guest-window-two-step-001')->count());

        $event = VehicleEvent::query()
            ->where('external_event_key', 'guest-window-two-step-001')
            ->firstOrFail();

        $this->assertSame('ABC 123', $event->plate_text);
        $this->assertSame('ABC 123', $event->plate_number);
        $this->assertSame('White', $event->vehicle_color);
        $this->assertSame($observation->snapshot_path, $event->vehicle_image_path);
        $this->assertDatabaseHas('active_sessions', [
            'entry_event_id' => $event->id,
            'plate_text' => 'ABC 123',
            'plate_number' => 'ABC 123',
            'vehicle_color' => 'White',
            'status' => 'open',
        ]);

        $this->assertTrue(EventReceiveLog::query()
            ->where('status', 'guest_observation_duplicate_updated')
            ->where('notes', 'like', '%'.$observation->id.'%')
            ->exists());
    }

    public function test_detector_guest_observation_same_event_complete_analysis_replaces_previous_ai_details(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $headers = [
            'X-Api-Key' => 'PHILCST-DEMO-KEY',
            'X-Source-Name' => 'phpunit-detector',
        ];

        $this->withHeaders($headers)->post(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-two-step-replace-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => now()->toIso8601String(),
            'plate_number' => 'TMP 000',
            'vehicle_color' => 'gray',
            'snapshot' => UploadedFile::fake()->image('guest-two-step-replace.jpg', 640, 480),
            'detection_metadata' => json_encode([
                'track_id' => 78,
                'analysis_status' => 'pending',
            ]),
        ])->assertCreated();

        $this->withHeaders($headers)->postJson(route('api.guest-observation'), [
            'external_event_key' => 'guest-window-two-step-replace-001',
            'camera_role' => 'entrance',
            'detected_vehicle_type' => 'Car',
            'event_time' => now()->toIso8601String(),
            'plate_number' => 'abc 123',
            'vehicle_color' => 'white',
            'detection_metadata' => [
                'track_id' => 78,
                'analysis_status' => 'complete',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $observation = GuestVehicleObservation::query()
            ->where('external_event_key', 'guest-window-two-step-replace-001')
            ->firstOrFail();

        $this->assertSame('ABC 123', $observation->plate_number);
        $this->assertSame('White', $observation->vehicle_color);

        $event = VehicleEvent::query()
            ->where('external_event_key', 'guest-window-two-step-replace-001')
            ->firstOrFail();

        $this->assertSame('ABC 123', $event->plate_number);
        $this->assertSame('White', $event->vehicle_color);
    }

    protected function createAssignedVehicleWithTag(string $tagUid, string $plateNumber): RfidTag
    {
        $vehicle = Vehicle::query()->create([
            'plate_number' => $plateNumber,
            'vehicle_owner_name' => 'Detector Test Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);
        $vehicle->forceFill([
            'current_state' => Vehicle::STATE_OUTSIDE,
        ])->save();

        $tag = RfidTag::query()->create([
            'uid' => $tagUid,
            'status' => RfidTag::STATUS_ASSIGNED,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now(),
        ]);

        $vehicle->forceFill([
            'rfid_tag_id' => $tag->id,
            'rfid_tag_uid' => $tag->uid,
        ])->save();

        return $tag->fresh('vehicle');
    }
}
