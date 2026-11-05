<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\MovementCountService;
use App\Services\VehicleRegistryService;
use App\Services\VisitorRecordService;
use App\Support\PlateNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class PlateLiteralReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        $this->travelTo(now()->startOfDay()->addHours(12));
        Storage::fake('public');
    }

    private function register(string $plate): Vehicle
    {
        return app(VehicleRegistryService::class)->register(['plate_number' => $plate, 'vehicle_type' => 'Car', 'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Fixture Registrar'])->fresh();
    }

    private function occurrence(string $key = 'literal-incident'): array
    {
        $this->travel(2)->minutes();
        $camera = Camera::query()->forRole('gate-1')->firstOrFail();
        $crossing = VehicleCrossing::create(['external_event_key' => $key, 'gate' => 'gate-1', 'camera_id' => $camera->id,
            'direction' => 'IN', 'crossed_at' => now(), 'vehicle_type' => 'Motorcycle', 'snapshot_path' => 'retained/vehicle.jpg',
            'detection_metadata_json' => ['rfid_status' => 'no_pass']]);
        $record = app(VisitorRecordService::class)->createFromCrossing($crossing);
        $record->forceFill(['ocr_plate_number' => '3951SX', 'ocr_details_json' => ['raw_read' => '3951SX'],
            'plate_image_path' => 'retained/plate.jpg'])->save();
        $event = VehicleEvent::create(['external_event_key' => $key, 'event_origin' => 'guest_cctv', 'event_status' => 'completed',
            'event_type' => 'ENTRY', 'camera_id' => $camera->id, 'event_time' => $crossing->crossed_at,
            'vehicle_image_path' => 'retained/vehicle.jpg', 'plate_image_path' => 'retained/plate.jpg', 'vehicle_category' => 'guest']);

        return [$record->fresh(), $event->fresh(), $crossing];
    }

    private function correct(VisitorRecord $record, string $plate = '395ISX')
    {
        return $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => $plate]);
    }

    public function test_literal_order_survives_normalization_and_does_not_match_the_reversed_registration(): void
    {
        $this->assertSame('395ISX', PlateNumber::normalize('395isx'));
        $this->assertSame('395ISX', PlateNumber::display('395ISX'));
        $this->assertTrue(PlateNumber::isPhilippineFormat('395ISX'));
        $this->assertNotSame(PlateNumber::key('395ISX'), PlateNumber::key('ISX395'));
        $this->register('ISX-395');
        [$record, $event] = $this->occurrence();
        $this->correct($record)->assertOk()->assertJsonPath('plate_number', '395ISX')->assertJsonPath('registration_status', 'guest');
        $this->assertNull($record->fresh()->vehicle_id);
        $this->assertSame($record->id, $event->fresh()->visitor_record_id);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['in']);
    }

    public function test_compatible_key_only_event_is_reused_for_a_registered_literal_plate(): void
    {
        $vehicle = $this->register('ISX395');
        [$record, $event] = $this->occurrence();
        $this->correct($record, 'ISX395')->assertOk()->assertJsonPath('registration_status', 'registered');
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertSame($record->id, $event->fresh()->visitor_record_id);
        $this->assertSame($vehicle->id, $event->fresh()->vehicle_id);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('review_actions', 1);
    }

    public function test_repeat_and_late_ocr_preserve_one_event_audit_visit_and_original_evidence(): void
    {
        $vehicle = $this->register('395ISX');
        [$record, $event, $crossing] = $this->occurrence();
        $identity = $event->only(['id', 'external_event_key', 'event_time', 'camera_id', 'event_type', 'vehicle_image_path', 'plate_image_path']);
        $this->correct($record)->assertOk()->assertJsonPath('registration_status', 'registered');
        $this->correct($record, '395isx')->assertOk();
        app(VisitorRecordService::class)->applyPlateReading(['external_event_key' => $record->external_event_key,
            'camera_role' => 'gate-1', 'event_time' => $record->seen_at->toIso8601String(), 'plate_status' => 'read',
            'plate_number' => 'ISX395', 'plate_confidence' => 0.99, 'ocr_details' => ['late' => 'different OCR']]);
        $this->assertEquals($identity, $event->fresh()->only(array_keys($identity)));
        $this->assertSame('395ISX', $record->fresh()->plate_number);
        $this->assertSame('3951SX', $record->fresh()->ocr_plate_number);
        $this->assertSame('3951SX', $record->fresh()->ocr_details_json['raw_read']);
        $this->assertSame(1, $record->fresh()->plateProfile->visit_count);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('review_actions', 1);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['in']);
        $this->assertNull($event->fresh()->rfid_scan_log_id);
    }

    public function test_existing_canonical_plate_event_is_reused_when_its_visitor_link_is_missing(): void
    {
        $vehicle = $this->register('395ISX');
        [$record, $event] = $this->occurrence();
        $this->correct($record)->assertOk();
        $event->fresh()->forceFill(['visitor_record_id' => null])->save();
        $this->correct($record)->assertOk();
        $this->assertSame($record->id, $event->fresh()->visitor_record_id);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('review_actions', 1);
    }

    public function test_key_owned_by_incompatible_time_or_another_record_is_a_safe_conflict_with_full_rollback(): void
    {
        $this->register('395ISX');
        [$record, $event] = $this->occurrence();
        $event->forceFill(['event_time' => $record->seen_at->copy()->subDay()])->save();
        $before = $record->fresh()->getRawOriginal();
        $existing = $event->fresh()->getRawOriginal();
        $response = $this->correct($record)->assertStatus(409)->assertJsonPath('error_code', 'occurrence_conflict');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        $this->assertSame($existing, $event->fresh()->getRawOriginal());
        $this->assertDatabaseCount('review_actions', 0);
        $this->assertDatabaseCount('plate_profiles', 0);
        $this->assertDatabaseCount('vehicle_occupancy_baselines', 0);
        $other = VisitorRecord::create(['external_event_key' => 'other-record', 'gate' => 'gate-1', 'seen_at' => now()]);
        $event->forceFill(['event_time' => $record->seen_at, 'visitor_record_id' => $other->id])->save();
        $this->correct($record)->assertStatus(409);
        $this->assertSame($other->id, $event->fresh()->visitor_record_id);
        $this->assertSame($before, $record->fresh()->getRawOriginal());
    }

    public function test_incompatible_camera_direction_or_origin_is_not_blindly_adopted(): void
    {
        [$record, $event] = $this->occurrence();
        foreach ([['camera_id' => Camera::query()->forRole('gate-2')->value('id')], ['event_type' => 'EXIT'], ['event_origin' => 'manual']] as $change) {
            $original = $event->fresh()->only(array_keys($change));
            $event->forceFill($change)->save();
            $this->correct($record)->assertStatus(409);
            $this->assertNull($record->fresh()->plate_number);
            $event->forceFill($original)->save();
        }
        $this->assertDatabaseCount('review_actions', 0);
    }

    public function test_unexpected_failure_rolls_back_plate_profile_audit_and_state_and_never_exposes_diagnostics(): void
    {
        $vehicle = $this->register('395ISX');
        [$record, $event] = $this->occurrence();
        $before = [$record->getRawOriginal(), $event->getRawOriginal(), $vehicle->getRawOriginal()];
        $this->partialMock(\App\Services\MovementReconciliationService::class, function ($mock): void {
            $mock->shouldReceive('syncPlate')->andThrow(new \Illuminate\Database\QueryException('sqlite', 'insert into vehicle_events', [],
                new \PDOException('SQLSTATE[23000] UNIQUE constraint failed /private/fixture/database.sqlite')));
        });
        config(['app.debug' => true]);
        $response = $this->correct($record)->assertStatus(500)->assertJsonPath('ok', false)->assertJsonPath('error_code', 'correction_failed');
        foreach (['SQLSTATE', 'database.sqlite', '/private', 'insert into', 'trace'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertSame($before, [$record->fresh()->getRawOriginal(), $event->fresh()->getRawOriginal(), $vehicle->fresh()->getRawOriginal()]);
        $this->assertDatabaseCount('review_actions', 0);
        $this->assertDatabaseCount('plate_profiles', 0);
        $this->assertDatabaseCount('plate_corrections', 0);
        $this->assertDatabaseCount('vehicle_occupancy_baselines', 0);
        $record->forceFill(['plate_status' => 'unreadable', 'registration_status' => 'review'])->save();
        $this->from(route('visitors.index', ['tab' => 'review']))->patch(route('visitors.records.review', $record), [
            'action' => 'correct_plate', 'plate' => '395ISX', '_review_record_id' => $record->id,
        ])->assertRedirect()->assertSessionHasInput('plate', '395ISX')->assertSessionHasErrors('plate');
        $this->get(route('visitors.index', ['tab' => 'review']))->assertOk()->assertSee('value="395ISX"', false)->assertDontSee('SQLSTATE');
    }

    public function test_existing_rfid_event_is_linked_without_replacing_its_identity_or_evidence(): void
    {
        $vehicle = $this->register('395ISX');
        app(VehicleRegistryService::class)->replaceTag($vehicle, ['rfid_uid' => 'LITERAL-TAG', 'auto_register_tag' => true]);
        [$record, $event, $crossing] = $this->occurrence();
        app(\App\Services\RfidTagMatcher::class)->addRead('gate-1', 'LITERAL-TAG', $crossing->crossed_at);
        app(\App\Services\RfidIngestService::class)->forCrossing($crossing);
        $event = $event->fresh();
        $this->assertNotNull($event->rfid_scan_log_id);
        $scan = $event->rfidScanLog->fresh();
        $event->forceFill(['visitor_record_id' => null])->save();
        $provenance = $event->fresh()->only(['id', 'rfid_scan_log_id', 'event_time', 'camera_id', 'event_type', 'vehicle_image_path']);
        $this->correct($record)->assertOk()->assertJsonPath('registration_status', 'registered');
        $this->assertEquals($provenance, $event->fresh()->only(array_keys($provenance)));
        $this->assertSame($scan->getRawOriginal(), $scan->fresh()->getRawOriginal());
        $this->assertSame($record->id, $event->fresh()->visitor_record_id);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('rfid_scan_logs', 1);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->correct($record, 'ISX395')->assertOk()->assertJsonPath('registration_status', 'review');
        $this->assertSame($vehicle->id, $event->fresh()->vehicle_id);
        $this->assertSame($scan->id, $event->fresh()->rfid_scan_log_id);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
    }

    public function test_learned_suggestions_never_replace_literal_ocr_or_a_staff_confirmed_plate(): void
    {
        $this->register('ISX395');
        app(VisitorRecordService::class)->profileFor('ISX395');
        app(\App\Services\PlateMatchService::class)->learn('395ISX', 'ISX395', auth()->user());
        [$record] = $this->occurrence();
        app(VisitorRecordService::class)->applyPlateReading(['external_event_key' => $record->external_event_key,
            'camera_role' => 'gate-1', 'event_time' => $record->seen_at->toIso8601String(),
            'plate_status' => 'read', 'plate_number' => '395ISX', 'plate_confidence' => 0.99]);
        $this->assertSame('395ISX', $record->fresh()->plate_number);
        $this->assertSame('review', $record->fresh()->registration_status);
        $this->assertNotNull($record->fresh()->suggested_profile_id);
        $this->correct($record)->assertOk()->assertJsonPath('registration_status', 'guest');
        $this->assertSame('395ISX', $record->fresh()->plate_key);
        $this->assertSame('395ISX', $record->fresh()->plateProfile->plate_key);
    }

    public function test_separator_case_search_profile_and_registration_paths_keep_distinct_literal_identities(): void
    {
        foreach ([[" 395\u{00A0}isx ", '395 ISX', '395ISX'], ['395-isx', '395-ISX', '395ISX'], ['isx395', 'ISX395', 'ISX395']] as [$input, $normalized, $key]) {
            $this->assertSame($normalized, PlateNumber::normalize($input));
            $this->assertSame($key, PlateNumber::key($input));
        }
        $this->post(route('vehicle-registry.store'), ['vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Literal Registrar', 'plate_number' => '395isx', 'vehicle_type' => 'Motorcycle'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->register('ISX395');
        $this->assertDatabaseHas('vehicles', ['plate_number' => '395ISX']);
        $this->get(route('registry.index', ['q' => '395ISX']))->assertOk()->assertSee('Actions for 395ISX')->assertDontSee('Actions for ISX395');
        $this->get(route('registry.index', ['q' => 'ISX395']))->assertOk()->assertSee('Actions for ISX395')->assertDontSee('Actions for 395ISX');
        $literal = app(VisitorRecordService::class)->profileFor('395ISX');
        $reverse = app(VisitorRecordService::class)->profileFor('ISX395');
        $this->assertNotSame($literal->id, $reverse->id);
        $this->assertSame('395ISX', $literal->plate_key);
    }

    public function test_late_legacy_camera_payload_cannot_overwrite_confirmed_canonical_plate_or_relationships(): void
    {
        $vehicle = $this->register('395ISX');
        [$record, $event] = $this->occurrence();
        $this->correct($record)->assertOk();
        $before = $event->fresh()->getRawOriginal();
        $this->withHeader('X-Api-Key', 'test-detector-key')->post(route('api.guest-observation'), [
            'external_event_key' => $record->external_event_key, 'camera_role' => 'gate-1', 'event_time' => $record->seen_at->toIso8601String(),
            'plate_number' => 'ISX395', 'detected_vehicle_type' => 'Car',
            'snapshot' => \Illuminate\Http\UploadedFile::fake()->image('late-camera.jpg'),
            'detection_metadata' => json_encode(['direction' => 'IN']),
        ])->assertCreated();
        $this->assertSame($before, $event->fresh()->getRawOriginal());
        $this->assertSame('395ISX', $record->fresh()->plate_number);
        $this->assertSame($vehicle->id, $event->fresh()->vehicle_id);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('review_actions', 1);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['in']);
    }

    public function test_adopting_a_guest_event_does_not_suppress_a_real_rfid_state_conflict(): void
    {
        $vehicle = $this->register('395ISX');
        $vehicle->forceFill(['current_state' => 'INSIDE'])->save();
        app(VehicleRegistryService::class)->replaceTag($vehicle, ['rfid_uid' => 'STATE-CONFLICT-TAG', 'auto_register_tag' => true]);
        [$record, $event, $crossing] = $this->occurrence();
        app(\App\Services\RfidTagMatcher::class)->addRead('gate-1', 'STATE-CONFLICT-TAG', $crossing->crossed_at);
        app(\App\Services\RfidIngestService::class)->forCrossing($crossing);
        $scan = $event->fresh()->rfidScanLog;
        $this->assertNotNull($scan);
        $this->assertTrue($scan->is_anomaly);
        $this->assertStringContainsString('already inside', $scan->anomaly_reason);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertSame($record->id, $event->fresh()->visitor_record_id);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
    }
}
