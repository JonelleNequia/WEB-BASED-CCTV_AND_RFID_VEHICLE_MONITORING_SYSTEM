<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\PlateEvidenceService;
use App\Services\VisitorRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class AutomaticPlateClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->travelTo(now()->startOfDay()->addHours(12));
        Storage::fake('public'); Storage::fake('occurrences');
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        $this->withHeader('X-Api-Key', 'test-detector-key');
    }

    private function vehicle(string $plate = '395ISX'): Vehicle
    {
        return Vehicle::create(['plate_number' => $plate, 'category' => 'faculty_staff',
            'vehicle_type' => 'Car', 'status' => 'active', 'created_at' => now()->subDay()]);
    }

    private function payload(string $plate = '395ISX', string $key = 'automatic-in'): array
    {
        return ['external_event_key' => $key, 'camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(),
            'plate_status' => 'read', 'plate_number' => $plate, 'best_guess' => $plate, 'plate_confidence' => .95,
            'ocr_details' => ['vote' => ['status' => 'read', 'plate' => $plate, 'confidence' => .95],
                'identifier_evidence' => ['kind' => 'plate_region', 'source' => 'ocr_plate_region'],
                'raw_reads' => [['text' => $plate, 'confidence' => .95]]]];
    }

    private function occurrence(array $payload, string $direction = 'IN'): VisitorRecord
    {
        $this->post(route('api.integration.crossings'), ['external_event_key' => $payload['external_event_key'],
            'camera_role' => 'gate-1', 'event_time' => $payload['event_time'], 'direction' => $direction,
            'detection_metadata' => ['rfid_status' => 'no_pass'], 'snapshot' => UploadedFile::fake()->image('vehicle.jpg')])->assertCreated();
        $this->post(route('api.integration.visitor-plates'), array_replace($payload,
            ['ocr_details' => json_encode($payload['ocr_details']), 'plate_image' => UploadedFile::fake()->image('plate.jpg')]))->assertOk();

        return VisitorRecord::where('external_event_key', $payload['external_event_key'])->sole();
    }

    public function test_clear_untagged_registered_plate_automatically_records_in_out_and_retry_once(): void
    {
        $vehicle = $this->vehicle(); $payload = $this->payload(); $record = $this->occurrence($payload);
        $this->assertSame('registered', $record->registration_status);
        $this->assertFalse($record->plateNeedsReview());
        $this->assertSame('395ISX', $record->plate_number);
        $this->assertNull($record->reviewed_at);
        $this->assertSame('detector', data_get($record->ocr_details_json, PlateEvidenceService::KEY.'.source'));
        $this->assertSame('actual_plate', PlateEvidenceService::kind($record));
        $this->assertSame($vehicle->id, $record->vehicle_id);
        $identity = $record->only(['id', 'seen_at', 'gate', 'direction', 'snapshot_path', 'plate_image_path']);
        $eventId = $record->movementEvent->id;
        for ($i = 0; $i < 3; $i++) app(VisitorRecordService::class)->applyPlateReading($payload);
        $this->assertEquals($identity, $record->fresh()->only(array_keys($identity)));
        $this->assertSame($eventId, $record->fresh()->movementEvent->id);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
        $this->assertDatabaseCount('vehicle_events', 1); $this->assertDatabaseCount('review_actions', 0);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0); $this->assertDatabaseCount('rfid_scan_logs', 0);
        $this->getJson(route('logs.index'))->assertOk()->assertJsonPath('logs.0.log_type', 'registered');
        $this->get(route('visitors.index'))->assertOk()->assertDontSee('395ISX');
        $this->travel(2)->minutes(); $out = $this->occurrence($this->payload('395ISX', 'automatic-out'), 'OUT');
        $this->assertSame('registered', $out->registration_status);
        $this->assertSame(1, $vehicle->fresh()->exits_today_count);
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
        $this->assertDatabaseCount('vehicle_events', 2);
    }

    public function test_unmatched_clear_plate_is_guest_and_later_registration_does_not_rewrite_it(): void
    {
        $payload = $this->payload(); $record = $this->occurrence($payload);
        $this->assertSame('guest', $record->registration_status); $this->assertFalse($record->plateNeedsReview());
        $this->travel(2)->minutes(); $this->vehicle()->forceFill(['created_at' => now()])->save();
        app(VisitorRecordService::class)->applyPlateReading($payload);
        $this->assertSame('guest', $record->fresh()->registration_status);
        $this->assertNull($record->fresh()->vehicle_id);
        $this->get(route('visitors.index'))->assertOk()->assertSee('395ISX');
        $this->getJson(route('logs.index'))->assertOk()->assertJsonPath('logs.0.log_type', 'unregistered_visitor');
    }

    public static function literalPlates(): array
    {
        return array_map(fn ($plate) => [$plate], ['ABC 1234', 'ABC 123', '123ABC', 'A123BC', 'AB-00123',
            '395ISX', 'ISX395', 'AO0123', 'A00123', 'AI0123', 'A10123']);
    }

    #[DataProvider('literalPlates')]
    public function test_automatic_matching_is_literal_and_exact(string $plate): void
    {
        $vehicle = $this->vehicle($plate);
        $record = $this->occurrence($this->payload(strtolower($plate)));
        $this->assertSame('registered', $record->registration_status);
        $this->assertSame($vehicle->id, $record->vehicle_id);
        $this->assertSame(\App\Support\PlateNumber::key($plate), $record->plate_key);
    }

    public static function blockedEvidence(): array
    {
        return [['low'], ['empty'], ['invalid'], ['vote_uncertain'], ['vote_conflict'], ['temporary_plate'],
            ['conduction_sticker'], ['mv_file'], ['uncertain'], ['bad_confidence'], ['temporary_label']];
    }

    #[DataProvider('blockedEvidence')]
    public function test_bad_or_explicitly_uncertain_evidence_still_requires_review(string $case): void
    {
        $vehicle = $this->vehicle(); $p = $this->payload();
        if ($case === 'low') $p['plate_confidence'] = .2;
        elseif ($case === 'bad_confidence') $p['plate_confidence'] = null;
        elseif ($case === 'empty') { $p['plate_number'] = null; $p['best_guess'] = null; $p['ocr_details']['vote'] = []; }
        elseif ($case === 'invalid') { $p['plate_number'] = '395/ISX'; $p['best_guess'] = '395/ISX'; }
        elseif ($case === 'vote_uncertain') $p['ocr_details']['vote']['status'] = 'unreadable';
        elseif ($case === 'vote_conflict') $p['ocr_details']['vote']['plate'] = 'ISX395';
        elseif ($case === 'temporary_label') $p['ocr_details']['raw_reads'][0]['text'] = 'CONDUCTION STICKER 395ISX';
        else $p['ocr_details']['identifier_evidence']['kind'] = $case;
        $record = $this->occurrence($p);
        $this->assertSame('review', $record->registration_status);
        $this->assertNull($record->plate_number); $this->assertNull($record->vehicle_id);
        $this->assertNotEmpty($record->registration_reason);
        $this->assertSame(0, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('plate_profiles', 0);
    }

    public function test_collision_inactive_and_ineligible_category_keep_the_existing_policy(): void
    {
        $vehicle = $this->vehicle(); $vehicle->forceFill(['status' => 'inactive'])->save();
        $record = $this->occurrence($this->payload());
        $this->assertSame('review', $record->registration_status);
        $this->assertStringContainsString('inactive', $record->registration_reason);
        $this->travel(2)->minutes(); $vehicle->forceFill(['status' => 'active'])->save(); $this->vehicle('395-ISX');
        $record = $this->occurrence($this->payload('395ISX', 'collision'));
        $this->assertSame('review', $record->registration_status);
        $this->assertStringContainsString('Multiple registrations', $record->registration_reason);
        $this->travel(2)->minutes(); $this->vehicle('ABC1234')->update(['category' => 'unregistered_visitor']);
        $record = $this->occurrence($this->payload('ABC1234', 'ineligible'));
        $this->assertSame('guest', $record->registration_status);
    }

    public static function rfidRestrictions(): array
    {
        return [['verified', true], ['inactive_tag', false], ['inactive_vehicle', false]];
    }

    #[DataProvider('rfidRestrictions')]
    public function test_automatic_plate_does_not_override_linked_rfid_conflict_or_restrictions(string $verification, bool $differentVehicle): void
    {
        $vehicle = $this->vehicle(); $rfidVehicle = $differentVehicle ? $this->vehicle('OTHER001') : $vehicle;
        $p = $this->payload(); $pending = $p; $pending['plate_status'] = 'unreadable';
        $record = $this->occurrence($pending);
        $scan = \App\Models\RfidScanLog::create(['vehicle_id' => $rfidVehicle->id, 'tag_uid' => 'AUTOMATIC-FIXTURE',
            'scan_location' => 'gate-1', 'scan_time' => $record->seen_at, 'verification_status' => $verification]);
        $scan->forceFill(['vehicle_crossing_id' => $record->vehicle_crossing_id])->save();
        $record->crossing->forceFill(['rfid_scan_log_id' => $scan->id])->save();
        $record = app(VisitorRecordService::class)->applyPlateReading($p);
        $this->assertSame('review', $record->registration_status);
        $this->assertStringContainsString('RFID', $record->registration_reason);
        $this->assertSame($rfidVehicle->id, $scan->fresh()->vehicle_id);
        $this->assertSame(0, $vehicle->fresh()->entries_today_count);
    }

    public function test_separate_same_plate_entries_remain_separate(): void
    {
        $vehicle = $this->vehicle(); $first = $this->occurrence($this->payload());
        $this->travel(30)->seconds(); $second = $this->occurrence($this->payload('395ISX', 'second-in'));
        $this->assertSame(VisitorRecord::STATUS_ACTIVE, $second->status);
        $this->assertNotSame($first->movementEvent->id, $second->movementEvent->id);
        $this->assertSame(2, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('vehicle_events', 2);
    }

    public function test_reported_rfid_match_without_an_authoritative_link_cannot_add_a_plate_movement(): void
    {
        $vehicle = $this->vehicle(); $p = $this->payload();
        $pending = $p; $pending['plate_status'] = 'unreadable';
        $record = $this->occurrence($pending);
        $record->crossing->forceFill(['detection_metadata_json' => ['rfid_status' => 'matched']])->save();
        $record = app(VisitorRecordService::class)->applyPlateReading($p);
        $this->assertSame('review', $record->registration_status);
        $this->assertStringContainsString('no authoritative scan link', $record->registration_reason);
        $this->assertSame(0, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public function test_real_repeat_entry_anomaly_survives_automatic_ocr_retry(): void
    {
        $vehicle = $this->vehicle(); $this->occurrence($this->payload());
        $this->travel(30)->seconds(); $p = $this->payload('395ISX', 'unknown');
        $second = $this->occurrence($p, 'UNKNOWN');
        // The existing direction-correction policy flags repeat IN. Preserve
        // that real reason when the same occurrence's OCR is retried.
        $this->patch(route('vehicle-events.direction', $second->movementEvent), ['direction' => 'IN'])->assertRedirect();
        $reason = $second->movementEvent->fresh()->anomaly_reason;
        $this->assertNotEmpty($reason);
        app(VisitorRecordService::class)->applyPlateReading($p);
        $this->assertSame($reason, $second->movementEvent->fresh()->anomaly_reason);
        $this->assertSame(2, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('vehicle_events', 2);
    }
}
