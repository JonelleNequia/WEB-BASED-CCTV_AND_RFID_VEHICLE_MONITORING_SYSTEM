<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\Gate;
use App\Models\User;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\EventService;
use App\Services\VehicleRegistryService;
use App\Services\VisitorRecordService;
use App\Support\PlateNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class ManualEntryCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        $this->travelTo(\Carbon\Carbon::parse('2026-11-07 12:00:00', 'Asia/Manila'));
        Storage::fake('public');
    }

    private function guest(array $fields = []): array
    {
        return $fields + ['gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => '2026-11-07T10:30'];
    }

    private function quick(array $fields = []): array
    {
        return $fields + ['event_type' => 'ENTRY', 'plate_text' => '395isx', 'vehicle_type' => 'Car',
            'camera_id' => Camera::forRole('gate-1')->value('id'), 'event_time' => '2026-11-03T08:17'];
    }

    public function test_manual_guest_allows_blank_plate_and_type_and_preserves_existing_review_and_staff_behavior(): void
    {
        $this->post(route('visitors.records.store'), $this->guest(['plate_number' => '', 'vehicle_type' => '']))
            ->assertRedirect(route('visitors.index'))->assertSessionHasNoErrors();
        $record = VisitorRecord::sole();
        $this->assertNull($record->plate_number);
        $this->assertNull($record->vehicle_type);
        $this->assertSame('Unknown', $record->display_vehicle_type);
        $this->assertSame(auth()->id(), $record->corrected_by);
        $this->assertSame('manual', $record->source);
        $this->assertSame('review', $record->classificationCategory());
        $this->assertDatabaseCount('plate_profiles', 0);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->getJson(route('logs.index'))->assertJsonPath('total', 1)->assertJsonPath('logs.0.record_type', 'visitor_record');
    }

    public static function validPlates(): array
    {
        return array_map(fn ($plate) => [$plate], ['abc 1234', 'abc 123', '123abc', 'a123bc', 'ab-00123', '395isx', 'isx395', 'AO0123', 'A00123', 'AI0123', 'A10123', "abc\u{00a0}1234"]);
    }

    #[DataProvider('validPlates')]
    public function test_manual_guest_uses_the_shared_plate_contract(string $plate): void
    {
        $this->postJson(route('visitors.records.store'), $this->guest(['plate_number' => $plate]))->assertRedirect()->assertSessionHasNoErrors();
        $record = VisitorRecord::sole();
        $this->assertSame(PlateNumber::display($plate), $record->plate_number);
        $this->assertSame(PlateNumber::key($plate), $record->plate_key);
        $this->assertSame('guest', $record->registration_status);
    }

    public static function invalidPlates(): array
    {
        return array_map(fn ($plate) => [$plate], ['395/ISX', 'ABC_1234', '123🚗', '---', "ABC\t123", "ABC\n123", 'ＡＢＣ123', str_repeat('A', 21)]);
    }

    #[DataProvider('invalidPlates')]
    public function test_invalid_manual_guest_plate_is_rejected_without_profiles_images_or_movements(string $plate): void
    {
        $this->postJson(route('visitors.records.store'), $this->guest(['plate_number' => $plate]))->assertUnprocessable()->assertJsonValidationErrors('plate_number');
        try {
            app(VisitorRecordService::class)->createManual($this->guest(['plate_number' => $plate]), auth()->user(), UploadedFile::fake()->image('not-stored.jpg'));
            $this->fail('Invalid plate was accepted by the service.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plate_number', $exception->errors());
        }
        $this->assertDatabaseCount('visitor_records', 0);
        $this->assertDatabaseCount('plate_profiles', 0);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_manual_registered_plate_without_rfid_stays_out_of_guests_and_has_accurate_feedback(): void
    {
        $registry = app(VehicleRegistryService::class);
        $this->travel(-1)->days();
        $vehicle = $registry->register(['plate_number' => '395ISX', 'vehicle_type' => 'Car', 'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Fixture Staff']);
        $this->travel(1)->days();
        $this->post(route('visitors.records.store'), $this->guest(['plate_number' => '395isx']))->assertRedirect()
            ->assertSessionHas('status', fn ($message) => str_starts_with($message, 'Registered vehicle 395ISX'));
        $this->assertNull($vehicle->fresh()->rfid_tag_id);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->get(route('visitors.index'))->assertViewHas('records', fn ($records) => $records->total() === 0);
        $this->getJson(route('logs.index'))->assertJsonPath('total', 1)->assertJsonPath('logs.0.log_type', 'registered');
        $this->assertSame('Fixture Staff', $registry->rememberedStaffName());
    }

    public function test_quick_manual_log_form_has_only_the_remaining_inputs_and_clear_date_time_label(): void
    {
        $this->get(route('vehicle-events.create'))->assertOk()->assertSee('Date and Time')
            ->assertDontSee('name="plate_confidence"', false)->assertDontSee('name="roi_name"', false)
            ->assertDontSee('Plate Confidence')->assertDontSee('Station / ROI')->assertSee('name="camera_id"', false);
    }

    public function test_quick_manual_log_saves_the_chosen_occurrence_time_without_confidence_or_roi(): void
    {
        $this->post(route('vehicle-events.store'), $this->quick())->assertRedirect()->assertSessionHasNoErrors();
        $event = VehicleEvent::sole();
        $this->assertSame('395ISX', $event->plate_text);
        $this->assertSame('2026-11-03 08:17:00', $event->event_time->toDateTimeString());
        $this->assertNull($event->plate_confidence);
        $this->assertNull($event->roi_name);
        $this->assertSame('manual', $event->event_origin);
        $this->assertSame('manual', data_get($event->detection_metadata_json, \App\Services\VehicleTypeEvidenceService::KEY.'.source'));
        $this->assertNull($event->detected_vehicle_type);
        $this->getJson(route('logs.index', ['date_from' => '2026-11-03', 'date_to' => '2026-11-03', 'time_from' => '08:17', 'time_to' => '08:17']))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('logs.0.event_time_export', '2026-11-03 08:17:00')->assertJsonPath('logs.0.station_label', Gate::labelFor('gate-1'));
    }

    public function test_direct_manual_event_service_accepts_no_roi_without_inventing_calibration_evidence(): void
    {
        $event = app(EventService::class)->create($this->quick());
        $this->assertNull($event->roi_name);
        $this->assertNull($event->plate_confidence);
        $this->assertSame('395ISX', $event->plate_text);
    }

    public function test_removed_fields_remain_optional_and_validated_for_legacy_callers(): void
    {
        $this->postJson(route('vehicle-events.store'), $this->quick(['roi_name' => 'Existing historical zone', 'plate_confidence' => 95]))->assertRedirect();
        $this->assertSame('Existing historical zone', VehicleEvent::sole()->roi_name);
        $this->assertEquals(95, VehicleEvent::sole()->plate_confidence);
        $this->postJson(route('vehicle-events.store'), $this->quick(['roi_name' => str_repeat('x', 101)]))->assertUnprocessable()->assertJsonValidationErrors('roi_name');
        $this->postJson(route('vehicle-events.store'), $this->quick(['plate_confidence' => 101]))->assertUnprocessable()->assertJsonValidationErrors('plate_confidence');
        $this->assertDatabaseCount('vehicle_events', 1);
    }

    public function test_remaining_required_inputs_operational_camera_and_permissions_are_enforced(): void
    {
        foreach (['plate_text' => '', 'vehicle_type' => '', 'event_time' => 'not-a-date', 'camera_id' => 99999] as $field => $value) {
            $this->postJson(route('vehicle-events.store'), $this->quick([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        Gate::where('code', 'gate-1')->update(['is_active' => false]);
        $this->postJson(route('vehicle-events.store'), $this->quick())->assertUnprocessable()->assertJsonValidationErrors('camera_id');
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->actingAs(User::factory()->create(['role' => 'guard']));
        $this->getJson(route('vehicle-events.create'))->assertForbidden();
        $this->postJson(route('vehicle-events.store'), $this->quick())->assertForbidden();
        $this->post(route('visitors.records.store'), $this->guest(['gate' => 'gate-2']))->assertRedirect()->assertSessionHasNoErrors();
        auth()->forgetGuards();
        $this->app['session']->flush();
        $this->postJson(route('visitors.records.store'), $this->guest())->assertUnauthorized();
    }

    public function test_html_invalid_guest_plate_keeps_values_and_reopens_with_an_inline_error(): void
    {
        $before = app(VehicleRegistryService::class)->rememberedStaffName();
        $url = route('visitors.index');
        $this->from($url)->post(route('visitors.records.store'), $this->guest(['plate_number' => '395/ISX', 'vehicle_type' => '']))
            ->assertRedirect($url)->assertSessionHasErrors('plate_number')->assertSessionHasInput('plate_number', '395/ISX');
        $this->get($url)->assertOk()->assertSee('value="395/ISX"', false)->assertSee('id="manual_plate-plate-error"', false)
            ->assertSee('aria-invalid="true"', false)->assertSee('data-drawer-autoopen', false);
        $this->assertSame($before, app(VehicleRegistryService::class)->rememberedStaffName());
        $this->assertDatabaseCount('visitor_records', 0);
    }
}
