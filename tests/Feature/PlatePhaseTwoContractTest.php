<?php

namespace Tests\Feature;

use App\Http\Controllers\GuestObservationController;
use App\Models\ActiveSession;
use App\Models\Camera;
use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Rules\PlateIdentifier;
use App\Services\VehicleRegistryService;
use App\Support\PlateNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class PlatePhaseTwoContractTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::query()->where('role', 'admin')->firstOrFail());
        $this->travelTo(now()->startOfDay()->addHours(12));
    }

    private function register(string $plate): Vehicle
    {
        $id = $this->postJson(route('vehicle-registry.store'), ['plate_number' => $plate, 'registered_by' => 'Fixture Registrar',
            'vehicle_owner_name' => 'Fixture Owner', 'category' => 'faculty_staff', 'vehicle_type' => 'Car'])->assertCreated()->json('vehicle_id');

        return Vehicle::findOrFail($id);
    }

    private function read(string $plate, string $status = 'read'): VisitorRecord
    {
        $this->travel(2)->minutes();
        $key = 'phase-two-contract-'.(++$this->sequence);
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.crossings'), [
            'external_event_key' => $key, 'camera_role' => 'gate-1', 'direction' => 'IN', 'event_time' => now()->toIso8601String(),
            'detection_metadata' => ['rfid_status' => 'no_pass'],
        ])->assertCreated();
        $this->postJson(route('api.integration.visitor-plates'), ['external_event_key' => $key, 'camera_role' => 'gate-1',
            'event_time' => now()->toIso8601String(), 'plate_status' => $status, 'plate_number' => $status === 'read' ? $plate : null,
            'best_guess' => $plate, 'plate_confidence' => .95, 'ocr_details' => ['raw_reads' => [['text' => $plate]]]])->assertOk();

        return VisitorRecord::query()->where('external_event_key', $key)->sole();
    }

    public function test_php_uses_the_shared_cross_language_normalization_corpus(): void
    {
        foreach (json_decode(file_get_contents(base_path('tests/fixtures/plate-contract.json')), true) as $case) {
            $this->assertSame($case['normalized'], PlateNumber::normalize($case['input']));
            $this->assertSame($case['key'], PlateNumber::key($case['input']));
            $this->assertSame($case['display'], PlateNumber::display($case['input']));
            $valid = Validator::make(['plate' => $case['input']], ['plate' => ['required', new PlateIdentifier(20)]]);
            $this->assertSame($case['valid'], $valid->passes(), $case['input']);
        }
    }

    public static function formats(): array
    {
        return [['ABC 1234'], ['ABC 123'], ['123ABC'], ['A123BC'], ['AB-00123'], ['395ISX'],
            ['A0123456789012345678'], ['A0 12 34 56 78 90 12 34 56 78']];
    }

    #[DataProvider('formats')]
    public function test_supported_formats_cross_registration_guest_review_and_event_storage(string $plate): void
    {
        $vehicle = $this->register(strtolower($plate));
        $record = $this->read(str_replace(' ', '-', strtolower($plate)));
        $this->assertSame('review', $record->registration_status);
        $record = \Tests\fixtures\ConfirmedActualPlate::for($record, $plate);
        $this->assertSame($vehicle->id, $record->vehicle_id);
        $this->assertSame('registered', $record->registration_status);
        $event = $record->movementEvent;
        $this->assertNotNull($event);
        $this->assertSame(PlateNumber::key($plate), $event->plate_number);
        $this->assertLessThanOrEqual(20, strlen($event->plate_number));
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => strtolower($plate)])->assertOk();
        $this->assertSame($event->id, $record->fresh()->movementEvent->id);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertSame(PlateNumber::normalize($plate), $vehicle->fresh()->plate_number);
    }

    public function test_literal_order_and_both_lookalike_pairs_establish_distinct_exact_identities(): void
    {
        foreach (['395ISX', 'ISX395', 'AO0123', 'A00123', 'AI0123', 'A10123'] as $plate) {
            $vehicle = $this->register($plate);
            $this->assertSame($vehicle->id, app(VehicleRegistryService::class)->resolveVehicleByPlate(strtolower($plate))->id);
        }
        $this->assertNull(app(VehicleRegistryService::class)->resolveVehicleByPlate('395'));
        $this->assertNull(app(VehicleRegistryService::class)->resolveVehicleByPlate('395/ISX'));
        $this->assertDatabaseCount('vehicles', 6);
    }

    public function test_ineligible_legacy_collision_stays_ambiguous_without_rewriting_either_vehicle(): void
    {
        $vehicle = $this->register('A123BC');
        Vehicle::query()->create(['plate_number' => 'A-123 BC', 'category' => 'unregistered_visitor', 'vehicle_type' => 'Car']);
        $before = Vehicle::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->assertNull(app(VehicleRegistryService::class)->resolveVehicleByPlate('a 123-bc'));
        $record = $this->read('A123BC');
        $record = \Tests\fixtures\ConfirmedActualPlate::for($record, 'A123BC');
        $this->assertSame('review', $record->registration_status);
        $this->assertNull($record->vehicle_id);
        $this->assertStringContainsString('Multiple registrations', $record->registration_reason);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertSame($before, Vehicle::query()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_invalid_and_uncertain_raw_evidence_cannot_become_registered(): void
    {
        $this->register('ABC1234');
        foreach (['ABC/1234', 'ABC_1234', 'ABC\t1234'] as $plate) {
            $record = $this->read($plate);
            $this->assertSame('review', $record->registration_status);
            $this->assertNull($record->vehicle_id);
            $this->assertSame($plate, data_get($record->ocr_details_json, 'raw_reads.0.text'));
        }
        $record = $this->read('ABC1234', 'unreadable');
        $this->assertSame('review', $record->registration_status);
        $this->assertNull($record->vehicle_id);
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public function test_rfid_only_activity_search_uses_separator_equivalence_and_combined_filters(): void
    {
        $vehicle = $this->register('AB-00123');
        RfidScanLog::query()->create(['vehicle_id' => $vehicle->id, 'tag_uid' => 'SEARCH-UID', 'scan_time' => now(), 'scan_location' => 'gate-1', 'verification_status' => 'scan_only']);
        $query = ['plate_text' => 'ab 001', 'event_type' => 'RFID', 'gate' => 'gate-1', 'date_from' => now()->toDateString()];
        $this->getJson(route('logs.index', $query))->assertOk()->assertJsonPath('total', 1);
        $this->getJson(route('logs.index', array_replace($query, ['gate' => 'gate-2'])))->assertJsonPath('total', 0);
        $this->getJson(route('logs.index', array_replace($query, ['plate_text' => '001ab'])))->assertJsonPath('total', 0);
        $this->get(route('registry.index', ['q' => 'ab 001', 'status' => 'active', 'state' => 'outside']))->assertOk()->assertViewHas('vehicles', fn ($rows) => $rows->count() === 1);
        $this->assertNull(app(VehicleRegistryService::class)->resolveVehicleByPlate('ab 001'));
    }

    public function test_invalid_legacy_guest_text_is_not_stripped_into_a_duplicate_plate(): void
    {
        $bad = GuestVehicleObservation::query()->create(['plate_number' => 'ABC/1234', 'observation_source' => 'cctv', 'location' => 'gate-1', 'observed_at' => now()]);
        $controller = new class extends GuestObservationController
        {
            public function duplicate(string $plate): ?GuestVehicleObservation
            {
                return $this->latestPlateDetectorDuplicate(GuestVehicleObservation::query(), $plate);
            }
        };
        $this->assertNull($controller->duplicate('abc-1234'));
        $this->assertSame('ABC/1234', $bad->fresh()->plate_number);
    }

    public function test_alert_plate_search_normalizes_separators_and_keeps_the_type_filter(): void
    {
        $vehicle = $this->register('AB-00123');
        RfidScanLog::query()->create(['vehicle_id' => $vehicle->id, 'tag_uid' => 'ALERT-UID', 'scan_time' => now(), 'scan_location' => 'gate-1',
            'verification_status' => 'plate_mismatch', 'is_anomaly' => true]);
        $filters = ['tab' => 'alerts', 'q' => 'ab 001', 'type' => 'anomaly'];
        $this->get(route('logs.index', $filters))->assertOk()->assertViewHas('alerts', fn ($rows) => $rows->total() === 1);
        $this->get(route('logs.index', array_replace($filters, ['type' => 'unknown_tag'])))->assertViewHas('alerts', fn ($rows) => $rows->total() === 0);
        $this->get(route('logs.index', array_replace($filters, ['q' => '001ab'])))->assertViewHas('alerts', fn ($rows) => $rows->total() === 0);
        $this->assertNull(app(VehicleRegistryService::class)->resolveVehicleByPlate('ab 001'));
    }

    public function test_long_new_identifiers_and_control_suffix_edits_are_rejected_without_repairing_legacy(): void
    {
        $vehicle = $this->register('ABC1234');
        $data = ['plate_number' => 'A012345678901234567890', 'registered_by' => 'Fixture Registrar', 'vehicle_owner_name' => 'Fixture Owner', 'vehicle_type' => 'Car'];
        $this->postJson(route('vehicle-registry.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('plate_number');
        $this->putJson(route('vehicle-registry.update', $vehicle), array_replace($data, ['plate_number' => "ABC1234\n"]))->assertUnprocessable()->assertJsonValidationErrors('plate_number');
        $this->assertSame('ABC1234', $vehicle->fresh()->plate_number);
        $this->assertDatabaseCount('vehicles', 1);
    }

    public function test_legacy_detector_exit_uses_exact_keys_without_rewriting_the_old_session_plate(): void
    {
        $event = VehicleEvent::query()->create(['event_type' => 'ENTRY', 'event_status' => 'completed', 'plate_text' => 'AB 00123', 'event_time' => now()->subMinute()]);
        $session = ActiveSession::query()->create(['entry_event_id' => $event->id, 'plate_text' => 'AB 00123', 'plate_number' => 'AB 00123', 'entry_time' => $event->event_time, 'status' => 'open']);
        $payload = ['camera_id' => Camera::query()->forRole('gate-1')->value('id'), 'direction' => 'OUT', 'image_path' => 'fixture/legacy.jpg'];
        foreach (['AB001', 'AB/00123', 'AB0O123'] as $plate) {
            $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.events'), $payload + ['plate_number' => $plate])->assertCreated();
            $this->assertSame('open', $session->fresh()->status);
        }
        $this->postJson(route('api.integration.events'), $payload + ['plate_number' => 'ab-00123'])->assertOk()->assertJsonPath('session_id', $session->id);
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame('AB 00123', $session->fresh()->plate_number);
        $this->assertSame($event->id, VehicleEvent::query()->where('event_type', 'EXIT')->sole()->matched_entry_id);
    }
}
