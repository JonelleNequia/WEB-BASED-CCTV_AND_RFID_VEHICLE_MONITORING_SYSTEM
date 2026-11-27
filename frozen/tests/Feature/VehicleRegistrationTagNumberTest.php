<?php

namespace Tests\Feature;

use App\Models\RfidTag;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class VehicleRegistrationTagNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        SystemSetting::updateOrCreate(['setting_key' => VehicleRegistryService::STAFF_NAME_SETTING], ['setting_value' => 'Earlier Staff']);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['_form' => 'add', 'auto_register_tag' => true, 'plate_number' => '395ISX',
            'vehicle_owner_name' => 'Ma. José Dela Cruz', 'registered_by' => 'New Staff',
            'vehicle_type' => 'Car', 'category' => 'faculty_staff'], $extra);
    }

    public function test_untagged_registration_remains_valid_and_has_no_placeholder_or_movement(): void
    {
        $this->postJson(route('vehicle-registry.store'), $this->payload(['display_number' => '']))->assertCreated();
        $vehicle = Vehicle::sole();
        $this->assertNull($vehicle->rfid_tag_id);
        $this->assertNull($vehicle->rfid_tag_uid);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public static function invalidNumbers(): array
    {
        return array_map(fn ($value) => [$value], [null, '', '   ', '0', '000000', '-1', '1e3', '12.5', '12 3',
            '123/4', 'ABC123', '１２３', '123😀', '1000000', str_repeat('0', 20).'1', ['123']]);
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_or_missing_number_is_rejected_by_request_and_service_without_writes(mixed $number): void
    {
        $data = $this->payload(['rfid_uid' => 'NEW-UID', 'display_number' => $number]);
        $this->postJson(route('vehicle-registry.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('display_number');
        try {
            app(VehicleRegistryService::class)->register($data);
            $this->fail('A direct registration bypassed tag-number validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('display_number', $exception->errors());
        }
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertSame('Earlier Staff', app(VehicleRegistryService::class)->rememberedStaffName());
    }

    public function test_manual_printed_number_and_uid_save_separately_and_padding_is_preserved(): void
    {
        foreach (['000123', '999999', str_repeat('0', 19).'1'] as $index => $number) {
            $this->postJson(route('vehicle-registry.store'), $this->payload([
                'plate_number' => 'TAG-'.($index + 1), 'rfid_scan_uid' => ' real-uid-'.$index, 'display_number' => $number,
            ]))->assertCreated();
            $tag = RfidTag::where('uid', 'REAL-UID-'.$index)->sole();
            $this->assertSame($number, $tag->display_number);
            $this->assertSame((int) $number, $tag->tag_number);
            $this->assertSame('assigned', $tag->status);
            $this->assertSame($tag->vehicle_id, Vehicle::where('rfid_tag_id', $tag->id)->sole()->id);
        }
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
    }

    public function test_existing_number_must_match_and_inventory_is_not_relabelled(): void
    {
        $tag = app(VehicleRegistryService::class)->registerRfidTag(['uid' => 'EXISTING-UID', 'display_number' => '000123']);
        foreach ([null, '123', '000124'] as $number) {
            $this->postJson(route('vehicle-registry.store'), $this->payload(['rfid_tag_id' => $tag->id, 'display_number' => $number]))
                ->assertUnprocessable()->assertJsonValidationErrors('display_number');
            $this->assertSame('000123', $tag->fresh()->display_number);
            $this->assertSame('available', $tag->fresh()->status);
        }
        $this->postJson(route('vehicle-registry.store'), $this->payload(['rfid_tag_id' => $tag->id, 'display_number' => '000123']))->assertCreated();
        $this->assertDatabaseCount('vehicle_rfid_tags', 1);
        $this->assertSame($tag->id, Vehicle::sole()->rfid_tag_id);
    }

    public function test_duplicate_number_and_number_without_uid_fail_without_orphan_inventory(): void
    {
        app(VehicleRegistryService::class)->registerRfidTag(['uid' => 'EXISTING-UID', 'display_number' => '000123']);
        foreach ([['rfid_uid' => 'NEW-UID', 'display_number' => '123'], ['display_number' => '124']] as $extra) {
            $this->postJson(route('vehicle-registry.store'), $this->payload($extra))->assertUnprocessable()->assertJsonValidationErrors('display_number');
        }
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('vehicle_rfid_tags', 1);
        $this->assertSame('Earlier Staff', app(VehicleRegistryService::class)->rememberedStaffName());
    }

    public function test_unchanged_legacy_label_can_be_confirmed_but_cannot_be_copied_to_a_new_uid(): void
    {
        $tag = RfidTag::create(['uid' => 'LEGACY-UID', 'tag_number' => 123, 'display_number' => 'G-01', 'status' => 'available']);
        $this->postJson(route('vehicle-registry.store'), $this->payload(['rfid_tag_id' => $tag->id, 'display_number' => 'G-01']))->assertCreated();
        $this->assertSame('G-01', $tag->fresh()->display_number);
        $this->postJson(route('vehicle-registry.store'), $this->payload([
            'plate_number' => 'TAG-0002', 'rfid_uid' => 'NEW-UID', 'display_number' => 'G-01',
        ]))->assertUnprocessable()->assertJsonValidationErrors('display_number');
        $this->assertDatabaseCount('vehicle_rfid_tags', 1);
    }

    public function test_malformed_tag_selection_is_validation_failure_instead_of_a_lookup_error(): void
    {
        $this->postJson(route('vehicle-registry.store'), $this->payload(['rfid_tag_id' => ['1'], 'display_number' => '123']))
            ->assertUnprocessable()->assertJsonValidationErrors('rfid_tag_id');
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_uid_alias_cannot_bypass_required_number_and_selected_uid_must_agree(): void
    {
        foreach (['rfid_uid', 'rfid_scan_uid', 'rfid_tag_uid', 'tag_uid'] as $field) {
            $this->postJson(route('vehicle-registry.store'), $this->payload([$field => 'NEW-UID']))
                ->assertUnprocessable()->assertJsonValidationErrors('display_number');
        }
        $tag = app(VehicleRegistryService::class)->registerRfidTag(['uid' => 'EXISTING-UID', 'display_number' => '123']);
        $this->postJson(route('vehicle-registry.store'), $this->payload([
            'rfid_tag_id' => $tag->id, 'rfid_uid' => 'DIFFERENT-UID', 'display_number' => '123',
        ]))->assertUnprocessable()->assertJsonValidationErrors('rfid_uid');
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertSame('available', $tag->fresh()->status);
    }

    public function test_failed_form_keeps_tag_number_and_failed_save_rolls_back_new_inventory_and_staff_default(): void
    {
        $data = $this->payload(['rfid_uid' => 'NEW-UID', 'display_number' => '123/4']);
        $this->from(route('registry.index'))->post(route('vehicle-registry.store'), $data)
            ->assertRedirect(route('registry.index'))->assertSessionHasErrors('display_number')
            ->assertSessionHasInput('display_number', '123/4')->assertSessionHasInput('rfid_uid', 'NEW-UID');
        $this->get(route('registry.index'))->assertOk()->assertSee('value="123/4"', false)->assertSee('add_tag_number_error');
        DB::unprepared("CREATE TRIGGER reject_tag_vehicle BEFORE INSERT ON vehicles BEGIN SELECT RAISE(ABORT, 'fixture save failure'); END");
        $this->from(route('registry.index'))->post(route('vehicle-registry.store'), array_replace($data, ['display_number' => '000123']))
            ->assertSessionHasErrors('vehicle')->assertSessionHasInput('display_number', '000123');
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertSame('Earlier Staff', app(VehicleRegistryService::class)->rememberedStaffName());
    }

    public function test_form_entry_points_share_the_number_field_without_affecting_edit_or_permissions(): void
    {
        foreach ([[], ['register_tag' => 'UNKNOWN-UID']] as $query) {
            $this->get(route('registry.index', $query))->assertOk()->assertSee('1 · RFID')
                ->assertDontSee('RFID (optional)')->assertSee('data-registration-tag-number', false)->assertSee('No RFID assigned');
        }
        $this->actingAs(User::factory()->create(['role' => 'guard']))
            ->postJson(route('vehicle-registry.store'), $this->payload(['rfid_uid' => 'DENIED', 'display_number' => '123']))->assertForbidden();
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
    }
}
