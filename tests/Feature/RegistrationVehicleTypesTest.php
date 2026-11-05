<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Models\VehicleTypeCorrection;
use App\Models\VisitorRecord;
use App\Services\MovementCountService;
use App\Services\VehicleRegistryService;
use App\Services\VehicleTypeEvidenceService;
use App\Services\VisitorRecordService;
use App\Support\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class RegistrationVehicleTypesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs($this->admin = User::query()->where('role', 'admin')->firstOrFail());
        $this->travelTo(now()->startOfDay()->addHours(12));
        Storage::fake('public');
    }

    private function register(string $type = 'E-bike'): Vehicle
    {
        return app(VehicleRegistryService::class)->register(['vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Original Registrar',
            'plate_number' => 'TYP-'.str_pad((string) ++$this->sequence, 4, '0', STR_PAD_LEFT), 'vehicle_type' => $type])->fresh();
    }

    private function occurrence(?string $plate = null, ?string $raw = 'Motorcycle'): VisitorRecord
    {
        $at = now()->addMinutes(++$this->sequence);
        $crossing = VehicleCrossing::query()->create(['external_event_key' => 'type-pass-'.$this->sequence,
            'gate' => 'gate-1', 'direction' => 'IN', 'vehicle_type' => $raw, 'crossed_at' => $at,
            'snapshot_path' => 'kept/vehicle.jpg', 'detection_metadata_json' => ['rfid_status' => 'no_pass']]);
        app(VisitorRecordService::class)->createFromCrossing($crossing);

        return app(VisitorRecordService::class)->applyPlateReading([
            'external_event_key' => $crossing->external_event_key, 'camera_role' => 'gate-1',
            'event_time' => $at->toIso8601String(), 'plate_status' => $plate ? 'read' : 'unreadable',
            'plate_number' => $plate, 'plate_confidence' => 0.95, 'detected_vehicle_type' => $raw,
            'ocr_details' => ['fixture' => 'raw OCR'],
        ]);
    }

    public function test_every_supported_choice_can_register_and_requested_choices_are_on_existing_forms(): void
    {
        foreach (VehicleType::TYPES as $type) {
            $vehicle = $this->register($type);
            $this->assertSame($type, $vehicle->vehicle_type);
        }
        foreach ([route('registry.index'), route('visitors.index'), route('vehicle-events.create'), route('vehicle-registry.edit', $vehicle)] as $url) {
            $response = $this->get($url)->assertOk();
            foreach (['E-bike', 'E-trike', 'E-car', 'Car', 'Truck', 'Tricycle', 'Motorcycle', 'Bus', 'Van'] as $type) {
                $response->assertSee('value="'.$type.'"', false);
            }
        }
    }

    public function test_aliases_remain_distinct_and_unsupported_submissions_are_rejected(): void
    {
        foreach (['ebike' => 'E-bike', 'E_TRIKE' => 'E-trike', 'electric car' => 'E-car', 'motorbike' => 'Motorcycle',
            'auto rickshaw' => 'Tricycle', 'pickup truck' => 'Pickup', 'truck / bus' => 'Truck/Bus'] as $alias => $type) {
            $this->assertSame($type, $this->register($alias)->vehicle_type);
        }
        foreach (['Hovercraft', 'Spaceship', '---', 'Unknown'] as $invalid) {
            $this->postJson(route('vehicle-registry.store'), ['vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Registrar', 'plate_number' => 'BAD-1234',
                'vehicle_type' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('vehicle_type');
        }
        $this->assertSame('Original Registrar', app(VehicleRegistryService::class)->rememberedStaffName());
    }

    public function test_unchanged_legacy_type_is_usable_in_edit_and_does_not_relabel_history(): void
    {
        $vehicle = $this->register('Car');
        $vehicle->forceFill(['vehicle_type' => 'School service 1999'])->save();
        $record = $this->occurrence($vehicle->plate_number, 'Car');
        $event = $record->movementEvent;
        $before = $event->getRawOriginal();
        $this->putJson(route('vehicle-registry.update', $vehicle), ['plate_number' => $vehicle->plate_number,
            'vehicle_type' => 'School service 1999'])->assertOk();
        $this->assertSame('School service 1999', $vehicle->fresh()->vehicle_type);
        $this->putJson(route('vehicle-registry.update', $vehicle), ['plate_number' => $vehicle->plate_number,
            'vehicle_type' => 'Others', 'vehicle_type_other' => 'School service 1999'])->assertOk();
        $this->assertSame('School service 1999', $vehicle->fresh()->vehicle_type);
        $this->putJson(route('vehicle-registry.update', $vehicle), ['plate_number' => $vehicle->plate_number,
            'vehicle_type' => 'E-car'])->assertOk();
        $this->assertSame($before, $event->fresh()->getRawOriginal());
        $this->assertSame('School service 1999', $record->fresh()->vehicle_type);
    }

    public function test_specific_registry_type_is_snapshotted_and_late_generic_ocr_does_not_relabel_it(): void
    {
        $vehicle = $this->register('E-bike');
        $record = $this->occurrence($vehicle->plate_number);
        $event = $record->movementEvent;
        $this->assertSame('E-bike', $record->vehicle_type);
        $this->assertSame('E-bike', $event->display_vehicle_type);
        $this->assertSame('Motorcycle', $event->detected_vehicle_type);
        $this->assertSame('Registry record', $event->vehicle_type_source_label);
        $state = $vehicle->fresh()->getRawOriginal();
        $vehicle->forceFill(['vehicle_type' => 'E-trike'])->save();
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.visitor-plates'), [
            'external_event_key' => $record->external_event_key, 'camera_role' => 'gate-1', 'event_time' => $record->seen_at->toIso8601String(),
            'plate_status' => 'read', 'plate_number' => $vehicle->plate_number, 'plate_confidence' => 0.95, 'detected_vehicle_type' => 'Car',
        ])->assertOk();
        $this->assertSame('E-bike', $event->fresh()->display_vehicle_type);
        $this->assertSame('Car', $event->fresh()->detected_vehicle_type);
        $this->assertSame($state['entries_today_count'], $vehicle->fresh()->entries_today_count);
        $this->assertSame($state['current_state'], $vehicle->fresh()->current_state);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
    }

    public function test_staff_type_correction_is_occurrence_only_audited_and_survives_late_evidence(): void
    {
        $vehicle = $this->register('E-bike');
        $record = $this->occurrence($vehicle->plate_number);
        $event = $record->movementEvent;
        $before = $event->getRawOriginal();
        $vehicleBefore = $vehicle->fresh()->getRawOriginal();
        $counts = app(MovementCountService::class)->counts('today');
        $this->patch(route('visitors.records.type', $record), ['vehicle_type' => 'e_trike'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('E-trike', $record->fresh()->vehicle_type);
        $this->assertSame('E-trike', $event->fresh()->display_vehicle_type);
        $this->assertSame('Staff confirmed', $event->fresh()->vehicle_type_source_label);
        $this->assertSame($vehicleBefore, $vehicle->fresh()->getRawOriginal());
        $this->assertSame('Motorcycle', VehicleTypeCorrection::query()->sole()->detected_type);
        $this->assertDatabaseHas('review_actions', ['action' => 'correct_type', 'from_value' => 'E-bike', 'to_value' => 'E-trike', 'user_id' => $this->admin->id]);
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.crossings'), [
            'external_event_key' => $record->external_event_key, 'camera_role' => 'gate-1', 'event_time' => $record->seen_at->toIso8601String(),
            'direction' => $record->direction, 'detected_vehicle_type' => 'Car',
        ])->assertOk();
        $this->assertSame('E-trike', $event->fresh()->display_vehicle_type);
        $this->assertSame('Car', $event->fresh()->detected_vehicle_type);
        $this->assertSame($counts, app(MovementCountService::class)->counts('today'));
        foreach (['id', 'vehicle_id', 'event_time', 'direction', 'rfid_scan_log_id', 'visitor_record_id', 'vehicle_image_path', 'plate_image_path'] as $field) {
            $this->assertSame($before[$field], $event->fresh()->getRawOriginal($field), $field);
        }
        $this->patch(route('visitors.records.type', $record), ['vehicle_type' => 'E-trike'])->assertRedirect();
        $this->assertDatabaseCount('vehicle_type_corrections', 1);
        $this->getJson(route('logs.index'))->assertOk()->assertJsonPath('logs.0.vehicle_type', 'E-trike');
        $this->get(route('vehicle-events.show', $event))->assertOk()->assertSee('Staff confirmed')->assertSee('E-trike');
        $this->assertStringContainsString('E-trike', $this->get(route('vehicle-events.export.csv', ['all' => 1]))->assertOk()->streamedContent());
        $this->get(route('logs.print', ['autoprint' => 0]))->assertOk()->assertSee('E-trike');
    }

    public function test_unknown_or_missing_automatic_types_do_not_default_to_car_or_claim_electric_subtypes(): void
    {
        foreach ([null, 'spaceship', 'E-bike'] as $raw) {
            $record = $this->occurrence(null, $raw);
            $this->assertNull($record->vehicle_type);
            $this->assertSame('Unknown', $record->display_vehicle_type);
            $this->assertSame($raw, data_get($record->ocr_details_json, VehicleTypeEvidenceService::KEY.'.raw'));
        }
        $this->assertSame('Bus', $this->occurrence(null, 'bus')->vehicle_type);
        $this->assertSame('Truck', $this->occurrence(null, 'truck')->vehicle_type);
    }

    public function test_optional_manual_type_uses_shared_choices_and_rejects_unknown_values(): void
    {
        foreach ([null, 'e bike', 'Tricycle', 'E-car'] as $type) {
            $record = app(VisitorRecordService::class)->createManual(['gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now(), 'vehicle_type' => $type], $this->admin);
            $this->assertSame(VehicleType::canonical($type), $record->fresh()->vehicle_type);
            $this->assertNull(data_get($record->fresh()->ocr_details_json, VehicleTypeEvidenceService::KEY.'.raw'));
            $this->assertSame('Manual entry', $record->fresh()->vehicle_type_source_label);
        }
        $this->post(route('visitors.records.store'), ['gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now()->toDateTimeString(),
            'vehicle_type' => 'Spaceship'])->assertSessionHasErrors('vehicle_type');
    }

    public function test_staff_type_survives_late_real_rfid_then_generic_ocr_without_a_second_movement(): void
    {
        $vehicle = $this->register();
        $registry = app(VehicleRegistryService::class);
        $tag = $registry->registerRfidTag(['uid' => 'TYPE-LATE-RFID', 'auto_number' => true]);
        $vehicle = $registry->replaceTag($vehicle, ['rfid_tag_id' => $tag->id]);
        $record = $this->occurrence($vehicle->plate_number);
        $eventId = $record->movementEvent->id;
        app(VisitorRecordService::class)->correctType($record, 'E-trike', $this->admin);
        app(\App\Services\RfidTagMatcher::class)->addRead('gate-1', $tag->uid, $record->seen_at);
        app(\App\Services\RfidIngestService::class)->forCrossing($record->crossing);
        app(VisitorRecordService::class)->applyPlateReading([
            'external_event_key' => $record->external_event_key, 'camera_role' => 'gate-1', 'event_time' => $record->seen_at->toIso8601String(),
            'plate_status' => 'read', 'plate_number' => $vehicle->plate_number, 'plate_confidence' => 0.95, 'detected_vehicle_type' => 'Truck',
            'ocr_details' => [VehicleTypeEvidenceService::KEY => ['source' => 'detector', 'registry' => 'Car']],
        ]);
        $event = VehicleEvent::findOrFail($eventId);
        $this->assertSame('E-trike', $event->display_vehicle_type);
        $this->assertSame('Staff confirmed', $event->vehicle_type_source_label);
        $this->assertSame('Truck', $event->detected_vehicle_type);
        $this->assertSame('E-bike', $vehicle->fresh()->vehicle_type);
        $this->assertSame('INSIDE', $vehicle->fresh()->current_state);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertNotNull($event->rfid_scan_log_id);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('rfid_scan_logs', 1);
        $this->assertDatabaseCount('vehicle_type_corrections', 1);
        $this->assertSame('Original Registrar', $registry->rememberedStaffName());
    }

    public function test_rfid_first_type_snapshot_survives_registry_edit_before_late_ocr(): void
    {
        $vehicle = $this->register('E-car');
        $registry = app(VehicleRegistryService::class);
        $tag = $registry->registerRfidTag(['uid' => 'TYPE-RFID-FIRST', 'auto_number' => true]);
        $vehicle = $registry->replaceTag($vehicle, ['rfid_tag_id' => $tag->id]);
        $crossing = VehicleCrossing::create(['external_event_key' => 'type-rfid-first', 'gate' => 'gate-1',
            'crossed_at' => now(), 'direction' => 'IN', 'vehicle_type' => 'Car']);
        app(\App\Services\RfidTagMatcher::class)->addRead('gate-1', $tag->uid, now());
        app(\App\Services\RfidIngestService::class)->forCrossing($crossing);
        $event = VehicleEvent::query()->sole();
        $this->assertSame('E-car', $event->display_vehicle_type);
        $scan = \App\Models\RfidScanLog::query()->sole();
        $this->assertSame('E-car', $scan->display_vehicle_type);
        $vehicle->forceFill(['vehicle_type' => 'Truck'])->save();
        $record = app(VisitorRecordService::class)->applyPlateReading([
            'external_event_key' => $crossing->external_event_key, 'camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(),
            'plate_status' => 'read', 'plate_number' => $vehicle->plate_number, 'plate_confidence' => 0.95, 'detected_vehicle_type' => 'Car',
        ]);
        $this->assertSame('E-car', $record->vehicle_type);
        $this->assertSame('E-car', $event->fresh()->display_vehicle_type);
        $this->assertSame('E-car', $scan->fresh()->display_vehicle_type);
        $this->assertSame('Registry record', $event->fresh()->vehicle_type_source_label);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('vehicle_events', 1);
    }

    public function test_registration_without_a_recorded_type_uses_truthful_detector_fallback(): void
    {
        $vehicle = $this->register();
        $vehicle->forceFill(['vehicle_type' => null])->save();
        $record = $this->occurrence($vehicle->plate_number, 'Bus');
        $this->assertSame('Bus', $record->movementEvent->display_vehicle_type);
        $this->assertSame('Detector', $record->movementEvent->vehicle_type_source_label);
        $this->assertNull($vehicle->fresh()->vehicle_type);
    }

    public static function legacyCaptureRoutes(): array
    {
        return [['api.guest-observation'], ['api.integration.guest-observations'], ['api.integration.events']];
    }

    #[DataProvider('legacyCaptureRoutes')]
    public function test_legacy_capture_does_not_present_an_unsupported_electric_type_as_visual_recognition(string $route): void
    {
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route($route), [
            'external_event_key' => 'type-legacy-unsupported', 'camera_role' => 'gate-1',
            'detected_vehicle_type' => 'E-bike', 'event_time' => now()->toIso8601String(),
            'vehicle_image_path' => 'fixture/type.jpg',
            'detection_metadata' => [VehicleTypeEvidenceService::KEY => ['source' => 'staff']],
        ])->assertCreated();
        $observation = \App\Models\GuestVehicleObservation::query()->sole();
        $this->assertNull($observation->vehicle_type);
        $this->assertSame('Unknown', $observation->display_vehicle_type);
        $this->assertSame('Detector', $observation->vehicle_type_source_label);
        $this->assertSame('E-bike', data_get($observation->detection_metadata_json, VehicleTypeEvidenceService::KEY.'.raw'));
        foreach (VehicleEvent::all() as $event) {
            $this->assertSame('Unknown', $event->display_vehicle_type);
        }
    }
}
