<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\Gate;
use App\Models\PlateProfile;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\VehicleRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class ExistingFieldValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->admin = User::query()->where('role', 'admin')->firstOrFail();
    }

    private function registration(array $extra = []): array
    {
        $tag = RfidTag::query()->create(['tag_number' => RfidTag::query()->count() + 123, 'uid' => '0000'.(RfidTag::query()->count() + 123), 'status' => RfidTag::STATUS_AVAILABLE]);

        return array_replace(['display_number' => $tag->number_label, 'rfid_tag_id' => $tag->id, 'plate_number' => 'TST-0001',
            'registered_by' => 'Test Registrar',
            'vehicle_owner_name' => 'Ma. José Dela Cruz', 'category' => 'faculty_staff', 'vehicle_type' => 'Car'], $extra);
    }

    public static function invalidNames(): array
    {
        return ['digits' => ['123'], 'mixed digits' => ['Owner 123'], 'emoji' => ['Owner 🚗'],
            'punctuation' => ['---...'], 'modifier apostrophe only' => ['ʼ'], 'spaces' => ['   '], 'unicode spaces' => ["\u{00A0}\u{2003}"],
            'new company punctuation' => ['Company & Sons'], 'array' => [['not a string']]];
    }

    #[DataProvider('invalidNames')]
    public function test_new_registry_and_review_names_reject_invalid_values(mixed $name): void
    {
        $this->actingAs($this->admin)->postJson(route('vehicle-registry.store'), $this->registration(['vehicle_owner_name' => $name]))
            ->assertUnprocessable()->assertJsonValidationErrors('vehicle_owner_name');
        $this->assertDatabaseCount('vehicles', 0);
        $record = $this->visitor();
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'set_name', 'name' => $name])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertNull($record->plateProfile->fresh()->owner_name);
    }

    public static function validNames(): array
    {
        return [['Ma. José Dela Cruz'], ['O’Connor'], ["D'Angelo"], ['Jean-Luc'], ['李'], ["Jose\u{0301}"]];
    }

    #[DataProvider('validNames')]
    public function test_names_support_unicode_initials_single_words_and_apostrophes(string $name): void
    {
        $this->actingAs($this->admin)->postJson(route('vehicle-registry.store'), $this->registration(['vehicle_owner_name' => $name]))
            ->assertCreated();
        $this->assertSame($name, Vehicle::query()->sole()->vehicle_owner_name);
        $this->patchJson(route('visitors.records.review', $this->visitor()), ['action' => 'set_name', 'name' => $name])->assertOk();
    }

    public function test_new_registration_requires_owner_while_review_names_can_still_be_cleared(): void
    {
        $data = $this->registration();
        unset($data['vehicle_owner_name']);
        $this->actingAs($this->admin)->postJson(route('vehicle-registry.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('vehicle_owner_name');
        $this->assertDatabaseCount('vehicles', 0);
        $record = $this->visitor('Known Owner');
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'set_name', 'name' => ''])->assertOk();
        $this->assertNull($record->plateProfile->fresh()->owner_name);
        $this->postJson(route('vehicle-registry.store'), $this->registration(['plate_number' => 'TST-0002', 'owner_name' => '   ', 'vehicle_owner_name' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('vehicle_owner_name');
    }

    public function test_unchanged_legacy_organizations_survive_edits_but_new_invalid_names_do_not(): void
    {
        $data = $this->registration(['vehicle_owner_name' => '3M & Sons, Inc.']);
        $vehicle = Vehicle::query()->create($data); // Existing legacy fixture, not a new registration.
        $this->actingAs($this->admin)->putJson(route('vehicle-registry.update', $vehicle), $data + ['vehicle_type_other' => null])->assertOk();
        $this->assertSame('3M & Sons, Inc.', $vehicle->fresh()->vehicle_owner_name);
        $this->putJson(route('vehicle-registry.update', $vehicle), array_replace($data, ['vehicle_owner_name' => '4M & Sons, Inc.']))
            ->assertUnprocessable()->assertJsonValidationErrors('vehicle_owner_name');
        $record = $this->visitor('3M & Sons, Inc.');
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'set_name', 'name' => '3M & Sons, Inc.'])->assertOk();
        $this->assertSame('3M & Sons, Inc.', $record->plateProfile->fresh()->owner_name);
    }

    public function test_shared_owner_names_are_allowed_and_uid_leading_zeros_are_preserved(): void
    {
        $this->actingAs($this->admin)->postJson(route('vehicle-registry.store'), $this->registration())->assertCreated();
        $this->postJson(route('vehicle-registry.store'), $this->registration(['plate_number' => 'TST-0002']))->assertCreated();
        $this->assertDatabaseCount('vehicles', 2);
        $this->assertSame(1, Vehicle::query()->distinct()->count('vehicle_owner_name'));
        $this->assertStringStartsWith('0000', Vehicle::query()->first()->rfid_tag_uid);
    }

    public function test_new_name_lengths_are_bounded_without_forcing_legacy_name_rewrites(): void
    {
        $this->actingAs($this->admin)->postJson(route('vehicle-registry.store'), $this->registration(['vehicle_owner_name' => str_repeat('A', 101)]))
            ->assertUnprocessable()->assertJsonValidationErrors('vehicle_owner_name');
        $data = $this->registration(['vehicle_owner_name' => 'Legacy '.str_repeat('A', 110)]);
        $vehicle = Vehicle::query()->create($data); // Existing legacy fixture, not a new registration.
        $this->putJson(route('vehicle-registry.update', $vehicle), $data)->assertOk();
        $this->assertSame($data['vehicle_owner_name'], $vehicle->fresh()->vehicle_owner_name);
        $record = $this->visitor();
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'set_name', 'name' => str_repeat('A', 121)])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_legacy_aliases_are_validated_and_valid_input_is_preserved_on_failure(): void
    {
        $data = $this->registration(['vehicle_owner_name' => null, 'owner_name' => 'Owner 12']);
        $this->actingAs($this->admin)->from(route('registry.index'))->post(route('vehicle-registry.store'), $data)
            ->assertRedirect(route('registry.index'))->assertSessionHasErrors('vehicle_owner_name')
            ->assertSessionHasInput('plate_number', 'TST-0001')->assertSessionHasInput('vehicle_type', 'Car');
    }

    public function test_an_unused_blank_legacy_alias_does_not_override_a_valid_canonical_name(): void
    {
        $this->actingAs($this->admin)->postJson(route('vehicle-registry.store'), $this->registration(['owner_name' => '   ']))
            ->assertCreated();
        $this->assertSame('Ma. José Dela Cruz', Vehicle::query()->sole()->vehicle_owner_name);
    }

    public static function malformedRegistrations(): array
    {
        return [['plate_number', ['bad']], ['rfid_tag_uid', ['bad']], ['category', []], ['vehicle_type_other', ['bad']]];
    }

    #[DataProvider('malformedRegistrations')]
    public function test_malformed_registry_inputs_return_field_errors(string $field, array $value): void
    {
        $data = $this->registration([$field => $value]);
        if ($field === 'vehicle_type_other') {
            $data['vehicle_type'] = 'Others';
        }
        $this->actingAs($this->admin)->postJson(route('vehicle-registry.store'), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('vehicles', 0);
    }

    private function visitor(?string $owner = null): VisitorRecord
    {
        $profile = PlateProfile::query()->create(['plate_key' => 'VIS1234', 'plate_number' => 'VIS 1234', 'owner_name' => $owner]);

        return VisitorRecord::query()->create(['external_event_key' => 'validation-visitor', 'gate' => 'gate-1', 'direction' => 'IN',
            'seen_at' => now(), 'status' => 'active', 'plate_status' => 'read', 'plate_number' => 'VIS 1234', 'plate_key' => 'VIS1234',
            'plate_profile_id' => $profile->id, 'vehicle_type' => 'Car']);
    }

    public function test_optional_manual_confidence_is_safe_and_zero_is_preserved(): void
    {
        $base = ['event_type' => 'ENTRY', 'plate_text' => 'CNF-001', 'vehicle_type' => 'Car', 'vehicle_color' => 'Black',
            'camera_id' => Camera::query()->forRole('gate-1')->value('id'), 'roi_name' => 'Zone', 'event_time' => now()->toDateTimeString()];
        $this->actingAs($this->admin)->post(route('vehicle-events.store'), $base)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull(VehicleEvent::query()->first()->plate_confidence);
        $this->post(route('vehicle-events.store'), array_replace($base, ['plate_text' => 'CNF-002', 'plate_confidence' => 0]))->assertSessionHasNoErrors();
        $this->assertEquals(0, VehicleEvent::query()->where('plate_text', 'CNF-002')->value('plate_confidence'));
        $this->postJson(route('vehicle-events.store'), $base + ['plate_confidence' => 101])->assertUnprocessable()->assertJsonValidationErrors('plate_confidence');
    }

    public function test_pending_completion_accepts_missing_confidence_and_preserves_zero(): void
    {
        $this->actingAs($this->admin);
        foreach ([null, 0] as $index => $confidence) {
            $event = app(\App\Services\EventService::class)->createDetectedEvent([
                'external_event_key' => 'validation-pending-'.$index, 'camera_role' => 'gate-1',
                'detected_vehicle_type' => 'Car', 'event_time' => now(), 'vehicle_image_path' => 'fixture/pending.jpg',
            ]);
            $data = ['plate_text' => 'PND-000'.$index, 'vehicle_type' => 'Car', 'vehicle_color' => 'Black'];
            if ($confidence !== null) {
                $data['plate_confidence'] = $confidence;
            }
            $this->put(route('vehicle-events.complete', $event), $data)->assertRedirect()->assertSessionHasNoErrors();
            if ($confidence === null) {
                $this->assertNull($event->fresh()->plate_confidence);
            } else {
                $this->assertEquals(0, $event->fresh()->plate_confidence);
            }
        }
    }

    public function test_meaningless_and_oversized_normalized_plates_are_rejected_without_requiring_a_ph_layout(): void
    {
        $this->actingAs($this->admin)->postJson(route('vehicle-registry.store'), $this->registration(['plate_number' => '---']))
            ->assertUnprocessable()->assertJsonValidationErrors('plate_number');
        $payload = ['gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now()->subSecond()->toDateTimeString()];
        $this->postJson(route('visitors.records.store'), $payload + ['plate_number' => str_repeat('A', 21)])
            ->assertUnprocessable()->assertJsonValidationErrors('plate_number');
        $this->postJson(route('visitors.records.store'), $payload + ['plate_number' => '000123-TEMP'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('000123-TEMP', VisitorRecord::query()->sole()->plate_number);
    }

    public function test_unchanged_legacy_guest_plates_do_not_block_notes_or_verification(): void
    {
        $plate = str_repeat('A', 21);
        $observation = \App\Models\GuestVehicleObservation::query()->create([
            'plate_number' => $plate, 'plate_text' => $plate, 'vehicle_type' => 'Car', 'location' => 'gate-1',
            'observation_source' => 'manual', 'observed_at' => now(), 'status' => 'pending_review',
        ]);
        $data = ['plate_number' => $plate, 'vehicle_type' => 'Car', 'location' => 'gate-1',
            'observed_at' => now()->toDateTimeString(), 'status' => 'reviewed', 'notes' => 'Updated fixture note'];
        $this->actingAs($this->admin)->patchJson(route('guest-observations.update', $observation), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->patchJson(route('guest-observations.verify', $observation), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($plate, $observation->fresh()->plate_number);
    }

    public function test_camera_and_gate_must_agree_even_when_each_exists(): void
    {
        $payload = ['external_event_key' => 'validation-crossing', 'camera_role' => 'gate-1', 'direction' => 'IN',
            'camera_id' => Camera::query()->forRole('gate-2')->value('id'), 'event_time' => now()->toIso8601String()];
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.crossings'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('camera_id');
        $this->assertDatabaseCount('vehicle_crossings', 0);
        $this->postJson(route('api.integration.events'), $payload + ['detected_vehicle_type' => 'Car'])
            ->assertUnprocessable()->assertJsonValidationErrors('camera_id');
    }

    public function test_existing_camera_id_only_ingestion_resolves_the_gate_safely(): void
    {
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.events'), [
            'external_event_key' => 'validation-id-only', 'camera_id' => Camera::query()->forRole('gate-1')->value('id'),
            'event_time' => now()->toIso8601String(), 'detected_vehicle_type' => 'Car', 'unregistered_capture' => true,
            'vehicle_image_path' => 'fixture/evidence.jpg',
        ])->assertCreated();
        $this->assertDatabaseHas('guest_vehicle_observations', ['external_event_key' => 'validation-id-only', 'location' => 'gate-1']);
    }

    public function test_malformed_integration_json_direction_and_plate_lengths_are_rejected(): void
    {
        $payload = ['external_event_key' => 'validation-crossing', 'camera_role' => 'gate-1', 'direction' => 'IN', 'event_time' => now()->toIso8601String()];
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.crossings'), $payload + ['detection_metadata' => '{bad'])
            ->assertUnprocessable()->assertJsonValidationErrors('detection_metadata');
        $this->postJson(route('api.integration.crossings'), array_replace($payload, ['direction' => ['bad']]))
            ->assertUnprocessable()->assertJsonValidationErrors('direction');
        $this->postJson(route('api.integration.visitor-plates'), $payload + ['plate_status' => 'read', 'plate_number' => str_repeat('A', 31)])
            ->assertUnprocessable()->assertJsonValidationErrors('plate_number');
        $this->postJson(route('api.integration.visitor-plates'), $payload + ['plate_status' => 'unreadable', 'ocr_details' => '{bad'])
            ->assertUnprocessable()->assertJsonValidationErrors('ocr_details');
    }

    public function test_source_lengths_are_enforced_but_an_unchanged_legacy_source_can_be_kept(): void
    {
        $camera = Camera::query()->forRole('gate-1')->firstOrFail();
        $long = 'rtsp://192.0.2.1/'.str_repeat('a', 270);
        $data = ['section' => 'manual', 'camera_configs' => ['gate-1' => ['camera_name' => 'Validation Camera', 'source_type' => 'rtsp', 'source_value' => $long]]];
        $this->actingAs($this->admin)->putJson(route('settings.update'), $data)->assertUnprocessable()->assertJsonValidationErrors('camera_configs.gate-1.source_value');
        $camera->forceFill(['source_type' => 'rtsp', 'source_value' => $long])->save();
        $this->putJson(route('settings.update'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($long, $camera->fresh()->source_value);
    }

    public function test_an_unchanged_legacy_source_format_does_not_block_a_name_edit(): void
    {
        $camera = Camera::query()->forRole('gate-1')->firstOrFail();
        $camera->forceFill(['source_type' => 'rtsp', 'source_value' => 'legacy-source-value'])->save();
        $this->actingAs($this->admin)->putJson(route('settings.update'), ['section' => 'manual', 'camera_configs' => ['gate-1' => [
            'camera_name' => 'Renamed Camera', 'source_type' => 'rtsp', 'source_value' => 'legacy-source-value',
        ]]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('legacy-source-value', $camera->fresh()->source_value);
    }

    public function test_malformed_nested_settings_and_roi_return_validation_errors(): void
    {
        $this->actingAs($this->admin)->putJson(route('settings.update'), ['section' => 'gates', 'gates' => ['gate-1' => 'bad']])
            ->assertUnprocessable()->assertJsonValidationErrors('gates.gate-1');
        $this->putJson(route('settings.update'), ['section' => 'manual', 'camera_configs' => ['gate-1' => ['camera_name' => 'Camera', 'source_type' => ['bad'], 'source_value' => ['bad']]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['camera_configs.gate-1.source_type', 'camera_configs.gate-1.source_value']);
        $this->putJson(route('calibration.update'), ['camera_id' => Camera::query()->first()->id, 'calibration_mask' => ['bad', 'bad', 'bad']])
            ->assertUnprocessable()->assertJsonValidationErrors('calibration_mask.0');
    }

    public function test_filter_validation_retains_good_criteria_and_rejects_bad_exports(): void
    {
        $this->actingAs($this->admin)->getJson(route('logs.index', ['tab' => 'events', 'date_from' => '2026-10-20', 'date_to' => '2026-10-19']))
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson(route('logs.index', ['plate_text' => ['bad'], 'date_from' => ['bad'], 'date_to' => '2026-10-19']))
            ->assertUnprocessable()->assertJsonValidationErrors(['plate_text', 'date_from']);
        $this->get(route('registry.index', ['q' => '0001', 'category' => ['bad'], 'status' => 'active']))
            ->assertRedirect(route('registry.index', ['q' => '0001', 'status' => 'active']))->assertSessionHasErrors('category');
        $this->get(route('vehicle-events.export.csv', ['date_from' => 'invalid']))->assertUnprocessable()->assertSee('report was not generated');
        $this->get(route('logs.print', ['date_from' => 'invalid']))->assertUnprocessable();
        $this->get(route('logs.index', ['period' => 'TODAY', 'event_type' => 'entry']))->assertOk();
    }

    public function test_archived_gates_remain_valid_for_history_filters_but_not_new_records(): void
    {
        $gate = Gate::query()->first();
        $gate->forceFill(['archived_at' => now()])->save();
        $this->actingAs($this->admin)->get(route('logs.index', ['gate' => $gate->code]))->assertOk();
        $this->postJson(route('visitors.records.store'), ['gate' => $gate->code, 'direction' => 'IN', 'seen_at' => now()->subSecond()->toDateTimeString()])
            ->assertUnprocessable()->assertJsonValidationErrors('gate');
    }

    public function test_upload_and_permission_checks_still_apply(): void
    {
        Storage::fake('public');
        $data = ['gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now()->subSecond()->toDateTimeString()];
        $this->actingAs($this->admin)->postJson(route('visitors.records.store'), $data + ['snapshot' => UploadedFile::fake()->create('bad.txt', 1, 'text/plain')])
            ->assertUnprocessable()->assertJsonValidationErrors('snapshot');
        $this->postJson(route('visitors.records.store'), $data + ['snapshot' => UploadedFile::fake()->create('big.jpg', 10241, 'image/jpeg')])
            ->assertUnprocessable()->assertJsonValidationErrors('snapshot');
        $guard = User::factory()->create(['role' => 'guard']);
        $this->actingAs($guard)->postJson(route('vehicle-registry.store'), $this->registration())->assertForbidden();
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_guest_and_unauthorized_integration_requests_are_rejected_before_mutations(): void
    {
        $this->postJson(route('vehicle-registry.store'), $this->registration())->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->postJson(route('api.integration.visitor-plates'), [
            'external_event_key' => 'denied-validation', 'camera_role' => 'gate-1', 'plate_status' => 'read',
            'plate_number' => 'BAD-0001', 'event_time' => now()->toIso8601String(),
        ])->assertUnauthorized();
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('visitor_records', 0);
    }
}
