<?php

namespace Tests\Feature;

use App\Models\PlateProfile;
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

class RegistryStatusOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::query()->where('role', 'admin')->firstOrFail());
        SystemSetting::query()->updateOrCreate(['setting_key' => VehicleRegistryService::STAFF_NAME_SETTING], ['setting_value' => 'Earlier Registrar']);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['plate_number' => 'OWN-1001', 'vehicle_owner_name' => 'Ma. José Dela Cruz',
            'registered_by' => 'New Registrar', 'vehicle_type' => 'Car', 'category' => 'faculty_staff'], $overrides);
    }

    public static function invalidOwners(): array
    {
        return [[null], [''], ['   '], ["\u{00a0}\u{2003}"], ['123'], ['Owner 1'], ['...---'], ['🚗'],
            ['Owner & Co'], ['Owner @ School'], ['ʼ'], ["\u{0301}"], [str_repeat('é', 101)], [['Owner']], [42],
            ["\tAnne"], ["Anne\nMarie"], ["\0Anne"], ["Anne\x7f"], ["Anne\u{200b}"]];
    }

    #[DataProvider('invalidOwners')]
    public function test_request_and_service_reject_invalid_new_owners_before_any_writes(mixed $owner): void
    {
        $data = $this->payload(['vehicle_owner_name' => $owner, 'auto_register_tag' => true, 'rfid_uid' => 'NEW-REJECTED-UID']);
        $this->postJson(route('vehicle-registry.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('vehicle_owner_name');
        try {
            app(VehicleRegistryService::class)->register($data);
            $this->fail('Direct service registration must validate the owner.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vehicle_owner_name', $exception->errors());
        }
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertSame('Earlier Registrar', app(VehicleRegistryService::class)->rememberedStaffName());
    }

    public static function validOwners(): array
    {
        return [['Ma. José Dela Cruz'], ['O’Connor'], ['Anne-Marie'], ['李'], ["Jose\u{0301}"], [str_repeat('é', 100)]];
    }

    #[DataProvider('validOwners')]
    public function test_valid_owner_can_register_without_rfid_or_movement(string $owner): void
    {
        $this->postJson(route('vehicle-registry.store'), $this->payload(['vehicle_owner_name' => $owner, 'auto_register_tag' => true]))->assertCreated();
        $vehicle = Vehicle::query()->sole();
        $this->assertSame($owner, $vehicle->vehicle_owner_name);
        $this->assertSame('New Registrar', $vehicle->registered_by);
        $this->assertNull($vehicle->rfid_tag_id);
        $this->assertNull($vehicle->rfid_tag_uid);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertDatabaseCount('vehicle_crossings', 0);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
    }

    public function test_missing_owner_cannot_fall_back_to_staff_and_failed_form_keeps_values(): void
    {
        $data = $this->payload();
        unset($data['vehicle_owner_name']);
        $this->from(route('registry.index'))->post(route('vehicle-registry.store'), $data + ['_form' => 'add'])
            ->assertSessionHasErrors('vehicle_owner_name')->assertSessionHasInput('plate_number', 'OWN-1001')
            ->assertSessionHasInput('registered_by', 'New Registrar');
        $this->assertSame('Earlier Registrar', app(VehicleRegistryService::class)->rememberedStaffName());
        $tag = RfidTag::query()->create(['uid' => 'AVAILABLE-OWNER-TAG', 'status' => 'available']);
        $this->postJson(route('vehicle-registry.store'), $data + ['rfid_tag_id' => $tag->id])->assertUnprocessable();
        $this->assertSame('available', $tag->fresh()->status);
        $this->assertNull($tag->fresh()->vehicle_id);
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_direct_service_normalizes_owner_and_validates_the_legacy_alias(): void
    {
        $data = $this->payload(['vehicle_owner_name' => " Ma.\u{00a0} José   Dela Cruz "]);
        $vehicle = app(VehicleRegistryService::class)->register($data);
        $this->assertSame('Ma. José Dela Cruz', $vehicle->vehicle_owner_name);
        unset($data['vehicle_owner_name']);
        $data['plate_number'] = 'OWN-1002';
        $this->postJson(route('vehicle-registry.store'), $data + ['owner_name' => 'O’Connor'])->assertCreated();
        $this->assertSame('O’Connor', Vehicle::query()->where('plate_number', 'OWN-1002')->value('vehicle_owner_name'));
        $this->postJson(route('vehicle-registry.store'), $data + ['owner_name' => "\0Anne"])
            ->assertUnprocessable()->assertJsonValidationErrors('vehicle_owner_name');
    }

    public function test_save_failure_rolls_back_new_inventory_and_staff_default(): void
    {
        DB::unprepared("CREATE TRIGGER reject_owner_fixture BEFORE INSERT ON vehicles BEGIN SELECT RAISE(ABORT, 'isolated failure'); END");
        $this->from(route('registry.index'))->post(route('vehicle-registry.store'), $this->payload([
            '_form' => 'add', 'auto_register_tag' => true, 'rfid_uid' => 'ROLLBACK-OWNER-TAG', 'display_number' => '123',
        ]))->assertSessionHasErrors('vehicle')->assertSessionHasInput('vehicle_owner_name', 'Ma. José Dela Cruz');
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertSame('Earlier Registrar', app(VehicleRegistryService::class)->rememberedStaffName());
    }

    public function test_all_registration_entry_points_share_required_owner_and_optional_tag(): void
    {
        $profile = PlateProfile::query()->create(['plate_number' => 'GST-1001', 'plate_key' => 'GST1001']);
        foreach ([[], ['register_plate' => $profile->id], ['register_tag' => 'UNKNOWN-OWNER-TAG']] as $query) {
            $this->get(route('registry.index', ['tab' => 'vehicles'] + $query))->assertOk()
                ->assertSee('required data-registration-owner', false)->assertSee('No RFID assigned')
                ->assertDontSee('No RFID assigned (optional)')->assertSee('add_owner_error');
        }
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
    }

    public function test_legacy_blank_and_unchanged_organization_owners_remain_editable(): void
    {
        foreach ([null, '', '3M & Sons, Inc.', 'Legacy '.str_repeat('A', 110)] as $index => $owner) {
            $vehicle = Vehicle::query()->create(['plate_number' => 'LEG-'.($index + 1), 'vehicle_owner_name' => $owner, 'vehicle_type' => 'Car', 'category' => 'faculty_staff']);
            $this->get(route('vehicle-registry.edit', $vehicle))->assertOk();
            $this->putJson(route('vehicle-registry.update', $vehicle), $this->payload([
                'plate_number' => $vehicle->plate_number, 'vehicle_owner_name' => $owner, 'vehicle_type' => 'Bus',
            ]))->assertOk();
            $this->assertSame($owner === '' ? null : $owner, $vehicle->fresh()->vehicle_owner_name);
            $this->assertNull($vehicle->fresh()->registered_by);
        }
        $this->assertSame('Earlier Registrar', app(VehicleRegistryService::class)->rememberedStaffName());
    }

    public function test_status_changes_keep_campus_state_timestamps_tag_and_all_movements(): void
    {
        $vehicle = app(VehicleRegistryService::class)->register($this->payload(['auto_register_tag' => true, 'rfid_uid' => 'STABLE-STATUS-TAG', 'display_number' => '124']))->fresh();
        $vehicle->forceFill(['current_state' => 'INSIDE', 'last_seen_at' => now()->subHour()])->save();
        $before = $vehicle->fresh()->getAttributes();
        $tag = $vehicle->rfidTag->getAttributes();
        foreach (['inactive', 'active'] as $status) {
            $this->from(route('registry.index'))->post(route('registry.vehicles.status', $vehicle), ['status' => $status])->assertSessionHas('status');
            $after = $vehicle->fresh()->getAttributes();
            unset($after['status'], $after['updated_at']);
            $expected = $before;
            unset($expected['status'], $expected['updated_at']);
            $this->assertSame($expected, $after);
            $this->assertSame($tag, $vehicle->rfidTag->fresh()->getAttributes());
            foreach (['vehicle_events', 'vehicle_crossings', 'visitor_records', 'rfid_scan_logs'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        }
    }

    public function test_registration_and_campus_filters_keep_a_passive_status_light_before_plate(): void
    {
        foreach (['active', 'inactive'] as $status) {
            foreach (['INSIDE', 'OUTSIDE'] as $state) {
                Vehicle::query()->create(['plate_number' => strtoupper(substr($status, 0, 3)).'-'.$state, 'vehicle_type' => 'Car', 'category' => 'faculty_staff'])
                    ->forceFill(['status' => $status, 'current_state' => $state])->save();
            }
        }
        foreach (['active', 'inactive'] as $status) {
            foreach (['inside', 'outside'] as $state) {
                $response = $this->get(route('registry.index', ['status' => $status, 'state' => $state]))->assertOk()
                    ->assertSee('Registration Status')->assertSee('Campus State')->assertSee('registration-status-dot', false)
                    ->assertDontSee('<th>Registration Status</th>', false)->assertDontSee('registration-status-button', false);
                $this->assertCount(1, $response->viewData('vehicles'));
                $vehicle = $response->viewData('vehicles')->sole();
                $this->assertSame($status, $vehicle->status);
                $this->assertSame(strtoupper($state), $vehicle->current_state);
                $document = new \DOMDocument;
                @$document->loadHTML($response->getContent());
                $xpath = new \DOMXPath($document);
                $cells = $xpath->query('//tr[@data-vehicle-row]/td');
                $this->assertCount(9, $cells);
                $this->assertSame(ucfirst($status), trim($cells[0]->textContent));
                $this->assertStringContainsString('registration-status-dot is-'.$status, $document->saveHTML($cells[0]));
                $this->assertSame($vehicle->plate_number, trim($cells[1]->textContent));
                $this->assertSame(0, $xpath->query('//tr[@data-vehicle-row]/td[1]//button | //tr[@data-vehicle-row]/td[1]//form | //tr[@data-vehicle-row]/td[1]//a')->length);
                $response->assertSee($status === 'active' ? 'Deactivate vehicle…' : 'Activate vehicle…');
            }
        }
    }

    public function test_empty_filtered_registry_has_a_standalone_empty_state_without_stale_columns(): void
    {
        $this->get(route('registry.index', ['q' => 'NO-MATCH', 'status' => 'inactive', 'state' => 'inside']))->assertOk()
            ->assertSee('No vehicles match these filters.')
            ->assertSee('data-auto-empty="true"', false)
            ->assertDontSee('registration-status-dot', false)
            ->assertDontSee('data-vehicle-row', false)
            ->assertDontSee('<td colspan=', false);
    }

    public function test_guards_and_guests_cannot_create_or_change_vehicle_status(): void
    {
        $vehicle = Vehicle::query()->create(['plate_number' => 'AUTH-1001', 'vehicle_type' => 'Car', 'category' => 'faculty_staff']);
        $this->actingAs(User::factory()->create(['role' => 'guard']));
        $this->postJson(route('vehicle-registry.store'), $this->payload())->assertForbidden();
        $this->postJson(route('registry.vehicles.status', $vehicle), ['status' => 'inactive'])->assertForbidden();
        auth()->logout();
        $this->postJson(route('vehicle-registry.store'), $this->payload())->assertUnauthorized();
        $this->postJson(route('registry.vehicles.status', $vehicle), ['status' => 'inactive'])->assertUnauthorized();
        $this->assertSame('active', $vehicle->fresh()->status);
        $this->assertDatabaseCount('vehicles', 1);
    }
}
