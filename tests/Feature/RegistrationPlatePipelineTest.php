<?php

namespace Tests\Feature;

use App\Models\PlateCorrection;
use App\Models\ReviewAction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\MovementCountService;
use App\Services\VehicleOccupancyService;
use App\Services\VehicleRegistryService;
use App\Services\VisitorRecordService;
use App\Support\PlateNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class RegistrationPlatePipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->travelTo(now()->startOfDay()->addHours(12));
        Storage::fake('public');
        $this->admin = User::query()->where('role', 'admin')->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function register(string $plate, string $staff = 'Phase Two Registrar'): Vehicle
    {
        return app(VehicleRegistryService::class)->register([
            'plate_number' => $plate, 'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => $staff, 'vehicle_type' => 'Motorcycle',
            'rfid_uid' => 'PHASE-TWO-'.(++$this->sequence), 'display_number' => (string) $this->sequence, 'auto_register_tag' => true,
        ])->fresh();
    }

    private function crossing(): VisitorRecord
    {
        $this->travel(2)->minutes();
        $key = 'phase-two-crossing-'.(++$this->sequence);
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.crossings'), [
            'external_event_key' => $key, 'camera_role' => 'gate-1', 'direction' => 'IN',
            'event_time' => now()->toIso8601String(), 'detected_vehicle_type' => 'Motorcycle',
            'detection_metadata' => ['rfid_status' => 'no_pass', 'direction_confidence' => 'high'],
        ])->assertCreated();

        return VisitorRecord::query()->where('external_event_key', $key)->sole();
    }

    private function read(VisitorRecord $record, ?string $plate, ?float $confidence = 0.95, string $status = 'read'): VisitorRecord
    {
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.visitor-plates'), [
            'external_event_key' => $record->external_event_key, 'camera_role' => $record->gate,
            'event_time' => $record->seen_at->toIso8601String(), 'plate_status' => $status,
            'plate_number' => $plate, 'plate_confidence' => $confidence, 'best_guess' => $plate,
            'ocr_details' => ['fixture' => 'simulated OCR evidence'],
        ])->assertOk();

        return $record->fresh();
    }

    public static function formats(): array
    {
        return [
            ['  abc   0001 ', 'ABC 0001', 'ABC0001', 'ABC 0001'],
            ['ab-00001', 'AB-00001', 'AB00001', 'AB 00001'],
            ['0012 abc', '0012 ABC', '0012ABC', '0012 ABC'],
            ['001 ab', '001 AB', '001AB', '001 AB'], // No invented reverse layout.
            ['a1-b02', 'A1-B02', 'A1B02', 'A1-B02'],
            ['000123', '000123', '000123', '000123'],
            ['temp-0007', 'TEMP-0007', 'TEMP0007', 'TEMP-0007'],
            ['ab  0012', 'AB 0012', 'AB0012', 'AB 0012'],
        ];
    }

    #[DataProvider('formats')]
    public function test_normalization_contract_preserves_supported_identifiers_and_zeros(string $input, string $normalized, string $key, string $display): void
    {
        $this->assertSame($normalized, PlateNumber::normalize($input));
        $this->assertSame($key, PlateNumber::key($input));
        $this->assertSame($display, PlateNumber::display($input));
        $vehicle = $this->register($input);
        $this->assertSame($normalized, $vehicle->plate_number);
        $record = $this->read($this->crossing(), $input);
        $this->assertSame(['review', null, null, null], [$record->registration_status, $record->vehicle_id, $record->plate_number, $record->plate_key]);
        $this->assertSame($display, $record->ocr_plate_number);
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => strtolower($input)])->assertOk();
        $this->assertSame([$display, $key, $vehicle->id], [$record->fresh()->plate_number, $record->fresh()->plate_key, $record->fresh()->vehicle_id]);
    }

    public static function invalidPlates(): array
    {
        return [['ABC/0001'], ['ABC🚗0001'], ['ABC_0001'], ["ABC\t0001"], ["ABC\n0001"], ['---'], ['   '], ['ＡＢＣ0001']];
    }

    #[DataProvider('invalidPlates')]
    public function test_invalid_identifiers_cannot_be_laundered_into_registered_keys(string $input): void
    {
        $this->register('ABC-0001');
        $this->assertNull(PlateNumber::key($input));
        $this->postJson(route('vehicle-registry.store'), ['vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Another Staff', 'plate_number' => $input,
            'vehicle_type' => 'Car', 'rfid_uid' => 'INVALID-NEW-TAG', 'auto_register_tag' => true])->assertUnprocessable()->assertJsonValidationErrors('plate_number');
        $record = $this->read($this->crossing(), $input);
        $this->assertSame('review', $record->registration_status);
        $this->assertNull($record->vehicle_id);
        $this->assertTrue($record->plateNeedsReview());
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => $input])
            ->assertUnprocessable()->assertJsonValidationErrors('plate');
        $this->assertSame('review', $record->fresh()->registration_status);
        $this->assertDatabaseCount('review_actions', 0);
        $this->assertDatabaseCount('vehicles', 1);
    }

    public function test_readable_unmatched_and_unreliable_results_follow_the_decision_table(): void
    {
        $this->register('ABC-0001');
        foreach ([['XYZ-9876', 0.95, 'read', 'review'], ['ABC-0001', 0.2, 'read', 'review'],
            ['ABC-0001', null, 'read', 'review'], ['ABC-0001', 0.95, 'unreadable', 'review'], [null, 0.0, 'unreadable', 'review']] as [$plate, $confidence, $status, $expected]) {
            $record = $this->read($this->crossing(), $plate, $confidence, $status);
            $this->assertSame($expected, $record->registration_status);
            $this->assertNull($record->vehicle_id);
        }
        $this->assertSame(5, VisitorRecord::query()->needsReview()->count());
    }

    public function test_manual_correction_updates_one_pass_audit_profiles_and_outputs_without_replaying_state(): void
    {
        $vehicle = $this->register('AB-00001');
        $state = $vehicle->getRawOriginal();
        $record = $this->read($this->crossing(), 'A8-00001', 0.2, 'unreadable');
        Storage::disk('public')->put('fixture/vehicle.jpg', 'existing snapshot');
        Storage::disk('public')->put('fixture/plate.jpg', 'existing crop');
        $record->forceFill(['snapshot_path' => 'fixture/vehicle.jpg', 'plate_image_path' => 'fixture/plate.jpg'])->save();
        $identity = $record->only(['id', 'external_event_key', 'vehicle_crossing_id', 'gate', 'seen_at', 'direction', 'snapshot_path', 'plate_image_path']);
        $beforeCount = app(MovementCountService::class)->counts('today')['in'];
        foreach (range(1, 3) as $retry) {
            $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => '  ab   00001  '])
                ->assertOk()->assertJsonPath('needs.plate', false);
        }
        $fresh = $record->fresh();
        $this->assertEquals($identity, $fresh->only(array_keys($identity)));
        $this->assertSame(['registered', $vehicle->id, 'A8-00001', 'AB 00001'], [$fresh->registration_status, $fresh->vehicle_id, $fresh->ocr_plate_number, $fresh->plate_number]);
        $this->assertDatabaseCount('visitor_records', 1);
        $this->assertDatabaseCount('vehicle_crossings', 1);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('review_actions', 1);
        $this->assertSame(1, PlateCorrection::query()->sole()->times);
        $this->assertSame($this->admin->id, ReviewAction::query()->sole()->user_id);
        $this->assertSame($beforeCount, app(MovementCountService::class)->counts('today')['in']);
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['categories']['faculty_staff']['in']);
        $identityFields = array_flip(['id', 'rfid_tag_id', 'rfid_tag_uid', 'plate_number', 'vehicle_owner_name', 'registered_by', 'category', 'vehicle_type', 'status']);
        $this->assertSame(array_intersect_key($state, $identityFields), array_intersect_key($vehicle->fresh()->getRawOriginal(), $identityFields));
        $this->assertSame(1, app(VehicleOccupancyService::class)->counts()['total']);
        $this->getJson(route('logs.index', ['log_type' => 'registered']))->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.source_label', 'Staff-confirmed plate')->assertJsonPath('summary.guests', 0);
        $this->getJson(route('logs.index', ['log_type' => 'unregistered_visitor']))->assertJsonPath('total', 0);
        $csv = $this->get(route('vehicle-events.export.csv', ['log_type' => 'registered', 'all' => 1]))->streamedContent();
        $this->assertStringContainsString('AB 00001', $csv);
        $this->assertStringContainsString('Registered', $csv);
        $this->get(route('logs.print', ['log_type' => 'registered', 'autoprint' => 0]))->assertOk()->assertSee('AB 00001');
        $this->get(route('visitors.index'))->assertOk()->assertViewHas('records', fn ($records) => $records->isEmpty());
        $this->assertSame('Phase Two Registrar', app(VehicleRegistryService::class)->rememberedStaffName());
        $this->assertSame('Phase Two Registrar', $vehicle->fresh()->registered_by);
        // Late OCR delivery cannot replace a staff-confirmed plate or original OCR evidence.
        $late = $this->read($fresh, 'ZZZ-9999');
        $this->assertSame(['AB 00001', 'A8-00001', 'registered'], [$late->plate_number, $late->ocr_plate_number, $late->registration_status]);
    }

    public function test_manual_unmatched_plate_is_guest_and_confirm_uses_the_same_resolution(): void
    {
        $this->register('ABC-0001');
        $record = $this->read($this->crossing(), 'XYZ-9876', 0.2);
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'confirm'])->assertOk()->assertJsonPath('needs.plate', false);
        $this->assertSame('guest', $record->fresh()->registration_status);
        $this->assertNull($record->fresh()->vehicle_id);
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => 'abc0001'])->assertOk();
        $this->assertSame('registered', $record->fresh()->registration_status);
        $this->patchJson(route('visitors.records.plate', $record), ['plate_number' => 'temp-0007'])->assertRedirect();
        $this->assertSame('guest', $record->fresh()->registration_status);
        $this->assertDatabaseCount('visitor_records', 1);
        $this->assertDatabaseCount('review_actions', 3);
    }

    public function test_historical_normalization_collision_stays_in_review_and_new_collisions_are_rejected(): void
    {
        $first = $this->register('ABC-0001');
        $legacy = Vehicle::query()->create(['plate_number' => 'abc 0001', 'category' => 'faculty_staff', 'vehicle_type' => 'Car']);
        $record = $this->read($this->crossing(), 'ABC0001');
        $this->assertSame('review', $record->registration_status);
        $this->assertNull($record->vehicle_id);
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => 'ABC 0001'])->assertOk()->assertJsonPath('needs.plate', true);
        $this->assertNull($record->fresh()->vehicle_id);
        $this->postJson(route('vehicle-registry.store'), ['plate_number' => 'ABC0001', 'vehicle_type' => 'Car',
            'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Other Staff', 'rfid_uid' => 'COLLIDING-TAG', 'display_number' => '123', 'auto_register_tag' => true])->assertUnprocessable()->assertJsonValidationErrors('plate_number');
        $this->assertSame([$first->plate_number, $legacy->plate_number], Vehicle::query()->orderBy('id')->pluck('plate_number')->all());
        $this->assertSame('Phase Two Registrar', app(VehicleRegistryService::class)->rememberedStaffName());
    }

    public function test_inactive_vehicle_and_learned_or_fuzzy_profiles_do_not_establish_registration(): void
    {
        $vehicle = $this->register('ABC-0001');
        $vehicle->forceFill(['status' => 'inactive'])->save();
        $record = $this->read($this->crossing(), 'ABC-0001');
        $this->assertSame('review', $record->registration_status);
        $record = \Tests\fixtures\ConfirmedActualPlate::for($record, 'ABC-0001');
        $this->assertStringContainsString('inactive', $record->registration_reason);
        $this->assertNull($record->vehicle_id);
        $vehicle->forceFill(['status' => 'active'])->save();
        app(VisitorRecordService::class)->profileFor('ABC-0001', now());
        app(\App\Services\PlateMatchService::class)->learn('A8C-0001', 'ABC-0001', $this->admin);
        $other = $this->read($this->crossing(), 'A8C-0001');
        $this->assertSame('review', $other->registration_status);
        $this->assertNull($other->vehicle_id);
        $this->patchJson(route('visitors.records.review', $other), ['action' => 'correct_plate', 'plate' => 'A8C-0001'])->assertOk();
        $this->assertSame('guest', $other->fresh()->registration_status);
    }

    public function test_later_registration_does_not_relink_or_reclassify_prior_passes(): void
    {
        $record = \Tests\fixtures\ConfirmedActualPlate::for($this->read($this->crossing(), 'LATE-0007'), 'LATE-0007');
        $before = $record->fresh()->getRawOriginal();
        $this->travel(1)->minutes();
        $vehicle = $this->register('LATE-0007');
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        $this->assertSame('guest', $record->fresh()->registration_status);
        // Even an explicit historical plate correction uses occurrence time.
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => 'LATE-0007'])->assertOk();
        $this->assertSame('guest', $record->fresh()->registration_status);
        $this->assertNull($record->fresh()->vehicle_id);
        $future = \Tests\fixtures\ConfirmedActualPlate::for($this->read($this->crossing(), 'LATE-0007'), 'LATE-0007');
        $this->assertSame($vehicle->id, $future->vehicle_id);
        $this->assertSame('registered', $future->registration_status);
    }

    public function test_rfid_plate_conflict_retains_verified_identity_and_counts_the_crossing_once(): void
    {
        $vehicle = $this->register('RFID-0001');
        $this->travel(1)->minutes();
        $scan = \App\Models\RfidScanLog::query()->create([
            'vehicle_id' => $vehicle->id, 'vehicle_rfid_tag_id' => $vehicle->rfid_tag_id,
            'tag_uid' => $vehicle->rfid_tag_uid, 'verification_status' => 'verified',
            'scan_time' => now(), 'scan_location' => 'gate-1', 'fusion_status' => 'recorded',
        ]);
        $crossing = VehicleCrossing::query()->create([
            'external_event_key' => 'rfid-conflict', 'rfid_scan_log_id' => $scan->id,
            'gate' => 'gate-1', 'direction' => 'IN', 'crossed_at' => now(), 'snapshot_path' => 'kept/rfid.jpg',
        ]);
        $event = VehicleEvent::query()->create([
            'external_event_key' => $crossing->external_event_key, 'rfid_scan_log_id' => $scan->id,
            'vehicle_id' => $vehicle->id, 'vehicle_category' => 'faculty_staff', 'event_type' => 'ENTRY',
            'event_status' => 'completed', 'event_origin' => 'rfid', 'event_time' => now(), 'plate_text' => $vehicle->plate_number,
        ]);
        $scan->forceFill(['vehicle_crossing_id' => $crossing->id, 'correlated_vehicle_event_id' => $event->id])->save();
        $eventBefore = $event->fresh()->getRawOriginal();
        $scanBefore = $scan->fresh()->getRawOriginal();
        $this->travel(1)->hours();
        $record = app(VisitorRecordService::class)->applyPlateReading([
            'external_event_key' => $crossing->external_event_key, 'camera_role' => 'gate-1',
            'event_time' => $crossing->crossed_at->toIso8601String(), 'plate_status' => 'read', 'plate_confidence' => 0.95,
            'plate_number' => 'OTHER-0007',
        ]);
        $this->assertSame([$crossing->id, $crossing->crossed_at->timestamp, 'kept/rfid.jpg'], [$record->vehicle_crossing_id, $record->seen_at->timestamp, $record->snapshot_path]);
        $this->assertSame('review', $record->registration_status);
        $this->assertTrue($record->plateNeedsReview());
        $record = \Tests\fixtures\ConfirmedActualPlate::for($record, 'OTHER-0007');
        $this->assertStringContainsString('RFID', $record->registration_reason);
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['in']);
        $this->getJson(route('logs.index'))->assertJsonPath('total', 1);
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => 'OTHER-0007'])->assertOk()->assertJsonPath('needs.plate', true);
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => 'rfid-0001'])->assertOk();
        $this->assertSame('dismissed', $record->fresh()->status);
        $this->assertSame(\Illuminate\Support\Arr::except(array_replace($eventBefore, ['visitor_record_id' => $record->id]), ['updated_at']),
            \Illuminate\Support\Arr::except($event->fresh()->getRawOriginal(), ['updated_at']));
        $this->assertSame($scanBefore, $scan->fresh()->getRawOriginal());
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['in']);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('vehicle_crossings', 1);
    }

    public function test_audit_failure_rolls_back_the_whole_manual_correction(): void
    {
        $record = $this->read($this->crossing(), null, 0.0, 'unreadable');
        $before = $record->fresh()->getRawOriginal();
        \Illuminate\Support\Facades\DB::statement("CREATE TRIGGER fail_plate_audit BEFORE INSERT ON review_actions BEGIN SELECT RAISE(ABORT, 'Isolated fixture audit failure'); END");
        try {
            app(VisitorRecordService::class)->correctPlate($record, 'XYZ-9876', $this->admin);
            $this->fail('The fixture must reject the audit write.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame($before, $record->fresh()->getRawOriginal());
            $this->assertDatabaseCount('plate_profiles', 0);
            $this->assertDatabaseCount('review_actions', 0);
        }
    }

    public function test_normalized_searches_and_realtime_feeds_show_the_same_corrected_classification(): void
    {
        $vehicle = $this->register('AB-00001');
        $record = \Tests\fixtures\ConfirmedActualPlate::for($this->read($this->crossing(), 'AB 00001'), 'AB 00001');
        foreach (['ab00001', 'ab-00001', '  ab   00001 '] as $query) {
            $this->getJson(route('logs.index', ['plate_text' => $query]))->assertOk()->assertJsonPath('total', 1);
            $this->get(route('visitors.index', ['q' => $query]))->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 0);
            $this->get(route('registry.index', ['q' => $query]))->assertOk()->assertViewHas('vehicles', fn ($vehicles) => $vehicles->count() === 1);
        }
        $this->getJson(route('logs.index', ['plate_text' => 'AB/00001']))->assertJsonPath('total', 0);
        $this->get(route('visitors.index', ['q' => '🚗']))->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 0);
        $this->getJson(route('api.recent-station-logs'))->assertOk()->assertJsonPath('logs.0.registered', true)
            ->assertJsonPath('logs.0.category_label', 'Faculty & Staff');
        $this->getJson(route('api.recent-event-logs'))->assertOk()->assertJsonPath('logs.0.log_type', 'registered');
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => 'XYZ-9876'])->assertOk();
        $this->get(route('visitors.index', ['q' => 'xyz9876']))->assertOk()
            ->assertViewHas('records', fn ($records) => $records->pluck('id')->all() === [$record->id]);
        $this->getJson(route('api.recent-station-logs'))->assertJsonPath('logs.0.registered', false)
            ->assertJsonPath('logs.0.category_label', 'Unregistered Guest');
        $this->getJson(route('logs.index', ['category' => 'faculty_staff']))->assertJsonPath('total', 0);
        $this->assertSame('Phase Two Registrar', $vehicle->fresh()->registered_by);
    }

    public function test_correction_before_ocr_arrives_keeps_the_later_original_evidence_without_reclassification(): void
    {
        $vehicle = $this->register('AB-00001');
        $record = $this->crossing();
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => 'AB 00001'])->assertOk();
        $this->assertNull($record->fresh()->ocr_plate_number);
        $late = $this->read($record, 'A8-00001', 0.2);
        $this->assertSame(['AB 00001', 'A8-00001', $vehicle->id, 'registered'], [$late->plate_number, $late->ocr_plate_number, $late->vehicle_id, $late->registration_status]);
        $this->assertSame(['fixture' => 'simulated OCR evidence'], \Illuminate\Support\Arr::except($late->ocr_details_json, [\App\Services\VehicleTypeEvidenceService::KEY, \App\Services\PlateEvidenceService::KEY]));
        $this->assertDatabaseCount('review_actions', 1);
        $this->assertDatabaseCount('visitor_records', 1);
    }

    public function test_overlong_ocr_identifier_is_retained_for_review_without_overflowing_the_profile_key(): void
    {
        $plate = str_repeat('A', 21);
        $record = $this->read($this->crossing(), $plate);
        $this->assertSame('review', $record->registration_status);
        $this->assertSame($plate, $record->ocr_plate_number);
        $this->assertNull($record->plate_key);
        $this->assertNull($record->plate_profile_id);
    }
}
