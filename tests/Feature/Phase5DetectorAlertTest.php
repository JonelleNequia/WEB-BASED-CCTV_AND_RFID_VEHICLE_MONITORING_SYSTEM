<?php

namespace Tests\Feature;

use App\Models\ActiveSession;
use App\Models\Camera;
use App\Models\GuestVehicleObservation;
use App\Models\GuestVisit;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\GuestPassService;
use App\Services\MatchingService;
use App\Services\RfidIngestService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 5: detector "Vehicle with no pass" alerts, 10-second RFID lookback,
 * and no guest exit matching by vehicle type + color.
 */
class Phase5DetectorAlertTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Carbon $start;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $this->start = now()->startOfSecond();
        $this->travelTo($this->start);
    }

    public function test_rfid_match_accepts_a_read_from_up_to_ten_seconds_before_the_crossing(): void
    {
        $this->registeredVehicle('LBK 101', 'UHF-LOOKBACK-1');
        $this->scan('UHF-LOOKBACK-1', 'entrance');

        $this->travelTo($this->start->copy()->addSeconds(13));

        $this->pollMatch('entrance', $this->start->copy()->addSeconds(8))
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('status', 'registered')
            ->assertJsonPath('overlay.label', 'REGISTERED - LBK 101');

        $this->pollMatch('entrance', $this->start->copy()->addSeconds(11))
            ->assertOk()
            ->assertJsonPath('matched', false)
            ->assertJsonPath('status', 'no_pass')
            ->assertJsonPath('overlay.label', 'NO PASS');
    }

    public function test_rfid_match_counts_a_guest_pass_read(): void
    {
        app(GuestPassService::class)->issue($this->guestPass('GP-DET-1'), ['plate' => 'GPD 101', 'id_presented' => 'UMID']);
        $this->scan('GP-DET-1', 'exit');

        $this->pollMatch('exit', $this->start->copy()->addSecond())
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('status', 'guest_pass')
            ->assertJsonPath('guest_pass.label', 'Guest Pass #G-01')
            ->assertJsonPath('overlay.verification', 'guest_pass')
            ->assertJsonPath('overlay.label', 'GUEST PASS - G-01');
    }

    public function test_one_rfid_read_confirms_only_one_vehicle(): void
    {
        $this->registeredVehicle('ONE 101', 'UHF-ONE-1');
        $this->scan('UHF-ONE-1', 'entrance');
        $this->travelTo($this->start->copy()->addSeconds(8));

        $this->pollMatch('entrance', $this->start->copy()->addSecond(), 'entrance-track-1')
            ->assertJsonPath('matched', true);

        // Same car, YOLO gave it a new track id a moment later.
        $this->pollMatch('entrance', $this->start->copy()->addSeconds(2), 'entrance-track-2')
            ->assertJsonPath('matched', true);

        // A second car without a tag, right behind the first one.
        $this->pollMatch('entrance', $this->start->copy()->addSeconds(7), 'entrance-track-3')
            ->assertJsonPath('matched', false);
    }

    public function test_no_rfid_read_creates_a_no_pass_alert_without_a_guest_session(): void
    {
        $insideBefore = $this->liveMetrics()['vehicles_inside'];

        $this->postNoPass('det-no-pass-1', 'entrance', $this->start, 'NOP 101')
            ->assertCreated()
            ->assertJsonPath('overlay.verification', 'no_pass')
            ->assertJsonPath('overlay.label', 'NO PASS');

        $observation = GuestVehicleObservation::query()->where('external_event_key', 'det-no-pass-1')->firstOrFail();
        $event = VehicleEvent::query()->where('external_event_key', 'det-no-pass-1')->firstOrFail();

        $this->assertStringContainsString('Vehicle with no pass', $observation->notes);
        $this->assertSame(VehicleEvent::MATCH_NO_PASS_ALERT, $event->match_status);
        $this->assertSame('No-pass Alert', $event->display_status_label);
        $this->assertNull($event->resulting_state);
        $this->assertSame(0, ActiveSession::query()->count());
        $this->assertSame(0, GuestVisit::query()->count());

        $metrics = $this->liveMetrics();
        $this->assertSame($insideBefore, $metrics['vehicles_inside']);
        $this->assertSame(1, $metrics['no_pass_alerts_today']);

        $this->actingAs($this->admin)
            ->getJson(route('api.recent-station-logs'))
            ->assertJsonPath('logs.0.verification_label', 'NO PASS')
            ->assertJsonPath('logs.0.plate_number', 'NOP 101')
            ->assertJsonPath('logs.0.no_pass_alert', true)
            ->assertJsonPath('logs.0.alert_location', 'entrance');
    }

    public function test_no_pass_alert_is_suppressed_by_a_read_before_the_crossing(): void
    {
        $this->registeredVehicle('SUP 101', 'UHF-SUP-1');
        $this->scan('UHF-SUP-1', 'entrance');

        $this->travelTo($this->start->copy()->addSeconds(13));

        $this->postNoPass('det-suppressed-1', 'entrance', $this->start->copy()->addSeconds(9))
            ->assertOk()
            ->assertJsonPath('suppressed', true)
            ->assertJsonPath('overlay.verification', 'registered');

        $this->assertSame(0, GuestVehicleObservation::query()->count());
    }

    public function test_guest_pass_exit_read_suppresses_the_no_pass_alert(): void
    {
        app(GuestPassService::class)->issue($this->guestPass('GP-SUP-1'), ['plate' => 'GPS 101', 'id_presented' => 'UMID']);
        $this->scan('GP-SUP-1', 'exit');

        $this->postNoPass('det-suppressed-guest-1', 'exit', $this->start->copy()->addSeconds(3))
            ->assertOk()
            ->assertJsonPath('suppressed', true)
            ->assertJsonPath('overlay.verification', 'guest_pass');

        $this->assertSame(0, GuestVehicleObservation::query()->count());
    }

    public function test_issuing_a_guest_pass_resolves_the_no_pass_alert(): void
    {
        $this->postNoPass('det-resolve-1', 'entrance', $this->start, 'RES 101')->assertCreated();
        $observation = GuestVehicleObservation::query()->where('external_event_key', 'det-resolve-1')->firstOrFail();
        $this->guestPass('GP-RES-1');

        $scan = $this->actingAs($this->admin)
            ->postJson(route('stations.rfid-scan', 'entrance'), ['tag_uid' => 'GP-RES-1'])
            ->assertCreated()
            ->assertJsonPath('issue.prefill.observation_id', $observation->id)
            ->assertJsonPath('issue.prefill.plate', 'RES 101');

        $this->actingAs($this->admin)
            ->postJson($scan->json('issue.url'), [
                'plate' => 'RES 101',
                'id_presented' => 'UMID',
                'rfid_scan_log_id' => $scan->json('issue.rfid_scan_log_id'),
                'guest_observation_id' => $observation->id,
            ])
            ->assertCreated();

        $this->assertSame(GuestVehicleObservation::STATUS_RESOLVED, $observation->fresh()->status);
        $this->assertStringContainsString('Guest Pass #G-01 issued', $observation->fresh()->notes);
        $this->assertSame(
            VehicleEvent::MATCH_NO_PASS_RESOLVED,
            VehicleEvent::query()->where('external_event_key', 'det-resolve-1')->value('match_status')
        );
        $this->assertSame(0, $this->liveMetrics()['no_pass_alerts_today']);

        // Late OCR update from the detector keeps the alert resolved.
        $this->postNoPass('det-resolve-1', 'entrance', $this->start, 'RES 101')->assertOk();
        $this->assertSame(
            VehicleEvent::MATCH_NO_PASS_RESOLVED,
            VehicleEvent::query()->where('external_event_key', 'det-resolve-1')->value('match_status')
        );
    }

    public function test_guest_pass_issued_after_the_tap_still_counts_for_the_crossing(): void
    {
        $this->guestPass('GP-LATE-1');

        $scan = $this->actingAs($this->admin)
            ->postJson(route('stations.rfid-scan', 'entrance'), ['tag_uid' => 'GP-LATE-1'])
            ->assertCreated();

        // The guard takes a minute to fill in the Issue form.
        $this->travelTo($this->start->copy()->addSeconds(60));

        $this->actingAs($this->admin)
            ->postJson($scan->json('issue.url'), [
                'id_presented' => 'UMID',
                'rfid_scan_log_id' => $scan->json('issue.rfid_scan_log_id'),
            ])
            ->assertCreated();

        $this->travelTo($this->start->copy()->addSeconds(66));

        $this->pollMatch('entrance', $this->start->copy()->addSeconds(65))
            ->assertJsonPath('matched', true)
            ->assertJsonPath('status', 'guest_pass');
    }

    public function test_guest_exit_is_not_matched_by_vehicle_type_and_color(): void
    {
        $cameraId = Camera::query()->forRole('entrance')->value('id');
        $entry = VehicleEvent::query()->create([
            'event_type' => 'ENTRY',
            'event_status' => VehicleEvent::STATUS_COMPLETED,
            'event_origin' => 'guest_manual',
            'vehicle_category' => 'guest',
            'vehicle_type' => 'Car',
            'vehicle_color' => 'White',
            'camera_id' => $cameraId,
            'roi_name' => 'Gate',
            'event_time' => now()->subHour(),
            'match_status' => 'open',
        ]);
        ActiveSession::query()->create([
            'entry_event_id' => $entry->id,
            'vehicle_type' => 'Car',
            'vehicle_color' => 'White',
            'entry_time' => $entry->event_time,
            'status' => 'open',
        ]);
        $exit = VehicleEvent::query()->create([
            'event_type' => 'EXIT',
            'event_status' => VehicleEvent::STATUS_COMPLETED,
            'event_origin' => 'guest_manual',
            'vehicle_category' => 'guest',
            'vehicle_type' => 'Car',
            'vehicle_color' => 'White',
            'camera_id' => $cameraId,
            'roi_name' => 'Gate',
            'event_time' => now(),
        ]);

        $match = app(MatchingService::class)->matchExitEvent($exit);

        // Only time gap (10) + route (5); type and color no longer add points.
        $this->assertSame(15, $match['match_score']);
        $this->assertSame('unmatched', $match['match_status']);
    }

    protected function pollMatch(string $role, Carbon $eventTime, ?string $eventKey = null): TestResponse
    {
        return $this->withHeaders($this->detectorHeaders())
            ->getJson(route('api.integration.rfid-match', array_filter([
                'camera_role' => $role,
                'event_time' => $eventTime->toIso8601String(),
                'window_seconds' => 4,
                'lookback_seconds' => 10,
                'event_key' => $eventKey,
            ])));
    }

    protected function postNoPass(string $key, string $role, Carbon $eventTime, ?string $plate = null): TestResponse
    {
        return $this->withHeaders($this->detectorHeaders())
            ->post(route('api.guest-observation'), array_filter([
                'external_event_key' => $key,
                'camera_role' => $role,
                'detected_vehicle_type' => 'Car',
                'event_time' => $eventTime->toIso8601String(),
                'plate_number' => $plate,
                'snapshot' => UploadedFile::fake()->image($key.'.jpg', 640, 480),
                'detection_metadata' => json_encode(['track_id' => crc32($key) % 1000]),
            ]));
    }

    /**
     * @return array<string, string>
     */
    protected function detectorHeaders(): array
    {
        return [
            'X-Api-Key' => 'test-detector-key',
            'X-Source-Name' => 'phpunit-detector',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function liveMetrics(): array
    {
        return $this->actingAs($this->admin)->getJson(route('dashboard.live-state'))->json('metrics');
    }

    protected function scan(string $uid, string $location): void
    {
        app(RfidIngestService::class)->ingest([
            'tag_uid' => $uid,
            'scan_location' => $location,
        ], 'station_reader');
    }

    protected function guestPass(string $uid): RfidTag
    {
        return RfidTag::query()->create([
            'uid' => $uid,
            'tag_type' => RfidTag::TYPE_GUEST_PASS,
            'status' => RfidTag::STATUS_AVAILABLE,
        ]);
    }

    protected function registeredVehicle(string $plate, string $uid): Vehicle
    {
        $vehicle = Vehicle::query()->create([
            'plate_number' => $plate,
            'vehicle_owner_name' => 'Phase Five Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);

        $tag = RfidTag::query()->create([
            'uid' => $uid,
            'status' => RfidTag::STATUS_ASSIGNED,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now(),
        ]);

        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        return $vehicle;
    }
}
