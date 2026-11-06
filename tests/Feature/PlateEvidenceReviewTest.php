<?php

namespace Tests\Feature;

use App\Models\ReviewAction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VisitorRecord;
use App\Services\PlateEvidenceService;
use App\Services\VisitorRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class PlateEvidenceReviewTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        $this->travelTo(now()->startOfDay()->addHours(12));
        Storage::fake('public');
    }

    private function vehicle(string $plate): Vehicle
    {
        return Vehicle::create(['plate_number' => $plate, 'category' => 'faculty_staff', 'vehicle_type' => 'Car', 'status' => 'active', 'created_at' => now()->subDay()]);
    }

    private function observation(string $plate = '395ISX', float $confidence = .95, string $status = 'read'): VisitorRecord
    {
        $key = 'evidence-'.++$this->sequence;
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.crossings'), [
            'external_event_key' => $key, 'camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(),
            'direction' => 'UNKNOWN', 'detection_metadata' => ['rfid_status' => 'no_pass'],
        ])->assertCreated();
        $this->postJson(route('api.integration.visitor-plates'), ['external_event_key' => $key,
            'camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(), 'plate_status' => $status,
            'plate_number' => $status === 'read' ? $plate : null, 'best_guess' => $plate, 'plate_confidence' => $confidence,
            'ocr_details' => ['raw_reads' => [['text' => $plate, 'confidence' => $confidence]], 'vote' => ['reason' => 'Fixture character vote.']],
            'plate_image' => UploadedFile::fake()->image('identifier.jpg'),
        ])->assertOk();

        return VisitorRecord::where('external_event_key', $key)->sole();
    }

    public function test_clear_text_and_an_exact_registry_match_do_not_establish_identifier_type(): void
    {
        $this->vehicle('395ISX');
        $record = $this->observation();
        $this->assertSame('review', $record->registration_status);
        $this->assertStringContainsString('identifier type', strtolower($record->registration_reason));
        $this->assertNull($record->plate_number);
        $this->assertNull($record->plate_key);
        $this->assertNull($record->plate_profile_id);
        $this->assertNull($record->vehicle_id);
        $this->assertSame('395ISX', $record->ocr_plate_number);
        $this->assertSame('395ISX', data_get($record->ocr_details_json, 'raw_reads.0.text'));
        $this->assertNotNull($record->plate_image_url);
        $this->assertDatabaseCount('plate_profiles', 0);
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public static function unrelatedActions(): array
    {
        return [['no_plate', []], ['dismiss', ['reason' => 'not_vehicle']], ['set_direction', ['direction' => 'IN']]];
    }

    #[DataProvider('unrelatedActions')]
    public function test_stale_invalid_plate_blocks_save_but_not_an_unrelated_valid_action(string $action, array $inputs): void
    {
        $record = $this->observation();
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => '395/ISX'])
            ->assertUnprocessable()->assertJsonValidationErrors('plate');
        $this->patchJson(route('visitors.records.review', $record), ['action' => $action, 'plate' => '395/ISX'] + $inputs)->assertOk();
        $this->assertDatabaseCount('review_actions', 1);
        $this->assertSame($action, ReviewAction::sole()->action);
    }

    public static function actualPlates(): array
    {
        return array_map(fn ($plate) => [$plate], ['ABC 1234', 'ABC 123', '123ABC', 'A123BC', 'AB-00123', '395ISX', 'ISX395', 'AO0123', 'A00123', 'AI0123', 'A10123']);
    }

    #[DataProvider('actualPlates')]
    public function test_staff_confirms_a_literal_actual_plate_and_retries_keep_one_occurrence(string $plate): void
    {
        $vehicle = $this->vehicle($plate);
        $record = $this->observation($plate);
        $identity = $record->only(['id', 'external_event_key', 'seen_at', 'gate', 'direction', 'plate_image_path', 'snapshot_path']);
        $url = route('visitors.records.review', $record);
        $this->patchJson($url, ['action' => 'correct_plate', 'plate' => strtolower($plate)])->assertOk()->assertJsonPath('registration_status', 'registered');
        $event = $record->fresh()->movementEvent;
        $this->assertNotNull($event);
        $eventId = $event->id;
        $this->patchJson($url, ['action' => 'correct_plate', 'plate' => strtolower($plate)])->assertOk();
        app(VisitorRecordService::class)->applyPlateReading(['external_event_key' => $record->external_event_key,
            'camera_role' => 'gate-1', 'event_time' => $record->seen_at->toIso8601String(), 'plate_status' => 'read',
            'plate_number' => 'WRONG-1234', 'plate_confidence' => .99, 'ocr_details' => ['raw_reads' => [['text' => 'WRONG-1234']]]]);
        $this->assertEquals($identity, $record->fresh()->only(array_keys($identity)));
        $this->assertSame(\App\Support\PlateNumber::key($plate), $record->fresh()->plate_key);
        $this->assertSame($eventId, $record->fresh()->movementEvent->id);
        $this->assertSame($vehicle->id, $record->fresh()->vehicle_id);
        $this->assertSame('staff', data_get($record->fresh()->ocr_details_json, PlateEvidenceService::KEY.'.source'));
        $this->assertSame('actual_plate', PlateEvidenceService::kind($record->fresh()));
        $this->assertSame($plate, data_get($record->fresh()->ocr_details_json, PlateEvidenceService::KEY.'.original.plate_number'));
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('review_actions', 1);
        $this->assertSame(0, $vehicle->fresh()->entries_today_count);
        $this->assertSame(0, $vehicle->fresh()->exits_today_count);
        $this->getJson(route('logs.index'))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('logs.0.log_type', 'registered');
    }

    public static function temporaryKinds(): array
    {
        return [['temporary_plate'], ['conduction_sticker'], ['mv_file']];
    }

    #[DataProvider('temporaryKinds')]
    public function test_same_text_can_be_temporary_evidence_without_matching_registry_or_creating_a_profile(string $kind): void
    {
        $this->vehicle('395ISX');
        $record = $this->observation();
        $image = $record->plate_image_path;
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'temporary_identifier',
            'plate' => 'STALE/INVALID', 'identifier_type' => $kind, 'identifier_text' => '395ISX'])->assertOk()->assertJsonPath('registration_status', 'guest');
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'temporary_identifier',
            'identifier_type' => $kind, 'identifier_text' => '395ISX'])->assertOk();
        app(VisitorRecordService::class)->applyPlateReading(['external_event_key' => $record->external_event_key,
            'camera_role' => 'gate-1', 'event_time' => $record->seen_at->toIso8601String(), 'plate_status' => 'read',
            'plate_number' => '395ISX', 'plate_confidence' => .99, 'ocr_details' => [PlateEvidenceService::KEY => ['kind' => 'actual_plate', 'source' => 'staff']]]);
        $record = $record->fresh();
        $this->assertSame($kind, PlateEvidenceService::kind($record));
        $this->assertNull($record->plate_number);
        $this->assertNull($record->plate_key);
        $this->assertNull($record->vehicle_id);
        $this->assertNull($record->plate_profile_id);
        $this->assertSame($image, $record->plate_image_path);
        $this->assertFalse($record->plateNeedsReview());
        $this->assertDatabaseCount('plate_profiles', 0);
        $this->assertDatabaseCount('review_actions', 1);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->getJson(route('logs.index'))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('logs.0.log_type', 'unregistered_visitor')
            ->assertJsonPath('logs.0.status_label', 'Evidence only')->assertJsonPath('logs.0.identifier_text', '395ISX');
        $this->get(route('visitors.index'))->assertSee(PlateEvidenceService::TEMPORARY[$kind])->assertSee('Evidence only: 395ISX');
    }

    public function test_uncertain_text_can_be_confirmed_as_a_guest_actual_plate_without_a_match(): void
    {
        $record = $this->observation('000123');
        $this->assertSame('review', $record->registration_status);
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'confirm'])->assertOk()->assertJsonPath('registration_status', 'guest');
        $this->assertSame('000123', $record->fresh()->plate_number);
        $this->assertSame('actual_plate', PlateEvidenceService::kind($record->fresh()));
        $this->assertSame('000123', $record->fresh()->plateProfile->plate_key);
    }

    public function test_collisions_inactive_and_ineligible_registrations_cannot_be_promoted_by_staff_confirmation(): void
    {
        $this->vehicle('395ISX');
        $this->vehicle('395-ISX');
        $record = $this->observation();
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => '395ISX'])
            ->assertOk()->assertJsonPath('registration_status', 'review');
        $this->assertStringContainsString('Multiple registrations', $record->fresh()->registration_reason);
        $inactive = $this->vehicle('A123BC');
        $inactive->forceFill(['status' => 'inactive'])->save();
        $record = $this->observation('A123BC');
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'confirm'])->assertOk()->assertJsonPath('registration_status', 'review');
        $ineligible = $this->vehicle('123ABC');
        $ineligible->update(['category' => 'unregistered_visitor']);
        $record = $this->observation('123ABC');
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'confirm'])->assertOk()->assertJsonPath('registration_status', 'guest');
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public function test_action_specific_validation_still_rejects_malformed_arguments_and_unauthed_requests(): void
    {
        $record = $this->observation();
        foreach ([['set_direction', ['direction' => 'SIDEWAYS'], 'direction'], ['dismiss', ['reason' => 'anything'], 'reason'],
            ['pick_match', ['profile_id' => []], 'profile_id'], ['temporary_identifier', ['identifier_type' => 'actual_plate', 'identifier_text' => '395ISX'], 'identifier_type']] as [$action, $input, $field]) {
            $this->patchJson(route('visitors.records.review', $record), ['action' => $action, 'plate' => 'STALE/INVALID'] + $input)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        auth()->forgetGuards();
        $this->app['session']->flush();
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'no_plate'])->assertUnauthorized();
        $this->assertDatabaseCount('review_actions', 0);
    }

    public function test_late_rfid_conflict_with_a_staff_confirmed_guest_preserves_evidence_and_one_event(): void
    {
        $registry = app(\App\Services\VehicleRegistryService::class);
        $vehicle = $this->vehicle('RFID001');
        $vehicle = $registry->replaceTag($vehicle, ['rfid_uid' => 'PHASE3-FIXTURE', 'auto_register_tag' => true]);
        $record = $this->observation('395ISX');
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'set_direction', 'direction' => 'IN'])->assertOk();
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'confirm'])->assertOk()->assertJsonPath('registration_status', 'guest');
        $before = $record->fresh()->only(['id', 'seen_at', 'gate', 'direction', 'plate_image_path']);
        $scan = \App\Models\RfidScanLog::create(['vehicle_id' => $vehicle->id, 'tag_uid' => $vehicle->rfid_tag_uid,
            'scan_location' => 'gate-1', 'scan_time' => $record->seen_at, 'verification_status' => 'verified']);
        app(\App\Services\RfidCameraFusionService::class)->applyMovement($scan, $record->crossing);
        $record = $record->fresh();
        $eventId = $record->movementEvent->id;
        $this->assertSame('review', $record->registration_status);
        $this->assertSame('395ISX', $record->plate_number);
        $this->assertSame('pending_details', $record->movementEvent->event_status);
        $this->assertSame(0, $vehicle->fresh()->entries_today_count);
        $this->assertTrue($scan->fresh()->is_anomaly);
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => 'RFID001'])->assertOk();
        $this->assertSame($eventId, $record->fresh()->movementEvent->id);
        $this->assertEquals($before, $record->fresh()->only(array_keys($before)));
        $this->assertSame($vehicle->id, $record->fresh()->movementEvent->vehicle_id);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('rfid_scan_logs', 1);
    }

    public function test_temporary_confirmation_reuses_existing_event_and_media_and_can_be_corrected_to_actual(): void
    {
        $vehicle = $this->vehicle('395ISX');
        $record = $this->observation();
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'set_direction', 'direction' => 'IN'])->assertOk();
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'confirm'])->assertOk();
        $record = $record->fresh();
        $event = $record->movementEvent;
        $identity = $event->only(['id', 'event_time', 'camera_id', 'event_type', 'plate_image_path', 'vehicle_image_path']);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $data = ['action' => 'temporary_identifier', 'identifier_type' => 'conduction_sticker', 'identifier_text' => '395ISX'];
        $this->patchJson(route('visitors.records.review', $record), $data)->assertOk();
        $this->patchJson(route('visitors.records.review', $record), $data)->assertOk();
        $this->assertNull($record->fresh()->plate_number);
        $this->assertNull($event->fresh()->plate_number);
        $this->assertSame(0, $vehicle->fresh()->entries_today_count);
        $this->assertEquals($identity, $event->fresh()->only(array_keys($identity)));
        $this->assertDatabaseCount('review_actions', 3); // Direction, actual plate, temporary identifier.
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => '395ISX'])->assertOk();
        $this->assertEquals($identity, $event->fresh()->only(array_keys($identity)));
        $this->assertSame('actual_plate', PlateEvidenceService::kind($record->fresh()));
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('vehicle_events', 1);
    }

    public static function unreliableReads(): array
    {
        return [['ABC/1234', .99, 'read'], ['123', .2, 'unreadable'], ['ABC1234', .2, 'read'], ['ABC1234', .8, 'unreadable']];
    }

    #[DataProvider('unreliableReads')]
    public function test_unreliable_results_retain_raw_text_images_and_a_review_reason(string $text, float $confidence, string $status): void
    {
        $this->vehicle('ABC1234');
        $record = $this->observation($text, $confidence, $status);
        $this->assertSame('review', $record->registration_status);
        $this->assertNotEmpty($record->registration_reason);
        $this->assertSame($text, data_get($record->ocr_details_json, 'raw_reads.0.text'));
        $this->assertNotNull($record->plate_image_url);
        $this->assertNull($record->plate_number);
        $this->assertDatabaseCount('plate_profiles', 0);
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public function test_detector_metadata_cannot_claim_a_staff_type_decision_or_create_an_actual_plate_profile(): void
    {
        $this->vehicle('395ISX');
        $record = $this->observation();
        $payload = ['external_event_key' => $record->external_event_key, 'camera_role' => 'gate-1',
            'event_time' => $record->seen_at->toIso8601String(), 'plate_status' => 'read', 'plate_number' => '395ISX', 'plate_confidence' => .99,
            'ocr_details' => [PlateEvidenceService::KEY => ['kind' => 'actual_plate', 'source' => 'staff'],
                'identifier_evidence' => ['kind' => 'actual_plate', 'source' => 'registry_match'], 'raw_reads' => 'malformed diagnostics']];
        $this->postJson(route('api.integration.visitor-plates'), $payload)->assertOk()->assertJsonPath('visitor_record.registration_status', 'review');
        $this->assertSame('uncertain', PlateEvidenceService::kind($record->fresh()));
        $this->assertSame('detector', data_get($record->fresh()->ocr_details_json, PlateEvidenceService::KEY.'.source'));
        $this->assertDatabaseCount('plate_profiles', 0);
        $this->get(route('visitors.index', ['tab' => 'review']))->assertOk();
    }

    public function test_pre_phase_three_staff_decision_survives_late_ocr_without_becoming_uncertain(): void
    {
        $record = $this->observation();
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'confirm'])->assertOk();
        $record = $record->fresh();
        $record->forceFill(['ocr_details_json' => \Illuminate\Support\Arr::except($record->ocr_details_json, [PlateEvidenceService::KEY])])->save();
        $this->assertSame('actual_plate', PlateEvidenceService::kind($record));
        $record = app(VisitorRecordService::class)->applyPlateReading(['external_event_key' => $record->external_event_key,
            'camera_role' => 'gate-1', 'event_time' => $record->seen_at->toIso8601String(), 'plate_status' => 'read',
            'plate_number' => 'ISX395', 'plate_confidence' => .99]);
        $this->assertSame('395ISX', $record->plate_number);
        $this->assertSame('guest', $record->registration_status);
        $this->assertSame('actual_plate', PlateEvidenceService::kind($record));
        $this->assertSame('staff', data_get($record->ocr_details_json, PlateEvidenceService::KEY.'.source'));
        $this->assertDatabaseCount('review_actions', 1);
    }
}
