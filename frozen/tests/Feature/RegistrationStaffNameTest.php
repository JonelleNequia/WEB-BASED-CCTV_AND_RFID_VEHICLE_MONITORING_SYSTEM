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

class RegistrationStaffNameTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->admin = User::query()->where('role', 'admin')->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function payload(array $extra = []): array
    {
        $index = ++$this->sequence;

        return array_replace([
            '_form' => 'add', 'auto_register_tag' => true, 'rfid_uid' => 'STAFF-TEST-'.$index, 'display_number' => (string) $index,
            'plate_number' => 'STF-'.$index, 'vehicle_type' => 'Car', 'category' => 'faculty_staff',
            'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Ma. José Dela Cruz',
        ], $extra);
    }

    private function defaultName(): string
    {
        return app(VehicleRegistryService::class)->rememberedStaffName();
    }

    public function test_initial_default_is_blank_in_all_existing_new_registration_entry_points(): void
    {
        foreach ([[], ['register_tag' => 'UNKNOWN-TAG']] as $query) {
            $this->get(route('registry.index', $query))->assertOk()
                ->assertViewHas('registrationStaffName', '')
                ->assertSee('id="add_registered_by"', false)
                ->assertSee('name="registered_by" type="text" required', false);
        }
        $this->assertSame('', $this->defaultName());
        $this->assertDatabaseMissing('system_settings', ['setting_key' => VehicleRegistryService::STAFF_NAME_SETTING]);
    }

    public static function invalidNames(): array
    {
        return [
            'null' => [null], 'empty' => [''], 'spaces' => ['   '], 'unicode spaces' => ["\u{00A0}\u{2003}"],
            'digits' => ['123'], 'mixed digits' => ['Staff 1'], 'unicode digits' => ['Staff ١'],
            'emoji' => ['Staff 🚗'], 'symbol' => ['Staff & Co'], 'punctuation' => ['.-’'],
            'modifier apostrophe only' => ['ʼ'], 'combining only' => ["\u{0301}"],
            'tab' => ["\tAnne"], 'trailing newline' => ["Anne\n"], 'embedded newline' => ["Anne\nMarie"],
            'null byte' => ["\0Anne"], 'delete control' => ["Anne\x7f"], 'invisible control' => ["Anne\u{200B}"],
            'too long' => [str_repeat('é', 101)], 'array' => [['Anne']], 'number' => [42],
        ];
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names_are_rejected_by_http_and_service(mixed $name): void
    {
        $data = $this->payload(['registered_by' => $name]);
        $this->postJson(route('vehicle-registry.store'), $data)->assertUnprocessable()
            ->assertJsonValidationErrors('registered_by');
        try {
            app(VehicleRegistryService::class)->register($data);
            $this->fail('Direct service registration must validate staff attribution.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('registered_by', $exception->errors());
        }
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertSame('', $this->defaultName());
    }

    public function test_missing_staff_name_cannot_fall_back_to_a_remembered_name(): void
    {
        $data = $this->payload();
        $this->postJson(route('vehicle-registry.store'), $data)->assertCreated();
        $missing = $this->payload();
        unset($missing['registered_by']);
        $this->postJson(route('vehicle-registry.store'), $missing)->assertUnprocessable()->assertJsonValidationErrors('registered_by');
        $this->assertDatabaseCount('vehicles', 1);
        $this->assertSame($data['registered_by'], $this->defaultName());
    }

    public function test_the_same_staff_member_can_register_multiple_vehicles_with_a_separate_owner_name(): void
    {
        foreach (range(1, 2) as $index) {
            $this->postJson(route('vehicle-registry.store'), $this->payload(['registered_by' => 'Anne-Marie']))->assertCreated();
        }
        $this->assertDatabaseCount('vehicles', 2);
        $this->assertSame(['Anne-Marie', 'Anne-Marie'], Vehicle::query()->pluck('registered_by')->all());
        $this->assertSame(['Fixture Owner', 'Fixture Owner'], Vehicle::query()->pluck('vehicle_owner_name')->all());
    }

    public static function validNames(): array
    {
        return [['Ma. José Dela Cruz'], ['O’Connor'], ['O‘Connor'], ['Anne-Marie'], ['李'], ["Jose\u{0301}"],
            ["D'Angelo"], ['Anne‐Marie'], ['Anne‑Marie'], [str_repeat('é', 100)]];
    }

    #[DataProvider('validNames')]
    public function test_valid_names_persist_on_registration_and_as_default(string $name): void
    {
        $data = $this->payload(['registered_by' => $name]);
        $response = $this->postJson(route('vehicle-registry.store'), $data)->assertCreated();
        $this->assertDatabaseHas('vehicles', ['id' => $response->json('vehicle_id'), 'registered_by' => $name]);
        $this->assertSame($name, $this->defaultName());
        $this->get(route('registry.vehicles.show', $response->json('vehicle_id')))->assertOk()->assertJsonPath('registered_by', $name);
    }

    public function test_normalization_and_shared_default_do_not_rewrite_previous_records_or_accounts(): void
    {
        $first = $this->postJson(route('vehicle-registry.store'), $this->payload(['registered_by' => "  Ma.\u{00A0} José   Dela Cruz  "]))->assertCreated()->json('vehicle_id');
        $this->assertSame('Ma. José Dela Cruz', $this->defaultName());
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $second = $this->postJson(route('vehicle-registry.store'), $this->payload(['registered_by' => 'O’Connor']))->assertCreated()->json('vehicle_id');
        $this->assertSame('O’Connor', $this->defaultName());
        $this->get(route('registry.index'))->assertOk()->assertViewHas('registrationStaffName', 'O’Connor');
        $this->assertSame('Ma. José Dela Cruz', Vehicle::findOrFail($first)->registered_by);
        $this->assertSame('O’Connor', Vehicle::findOrFail($second)->registered_by);
        $this->assertSame($this->admin->name, $this->admin->fresh()->name);
        $this->assertDatabaseHas('system_settings', ['setting_key' => VehicleRegistryService::STAFF_NAME_SETTING, 'setting_value' => 'O’Connor']);
        // A new application service instance reads durable server-side storage.
        $this->assertSame('O’Connor', (new VehicleRegistryService)->rememberedStaffName());
    }

    public function test_validation_failure_preserves_entered_value_and_does_not_change_default(): void
    {
        $this->postJson(route('vehicle-registry.store'), $this->payload())->assertCreated();
        $this->from(route('registry.index'))->post(route('vehicle-registry.store'), $this->payload([
            'registered_by' => 'Anne-Marie', 'plate_number' => '---',
        ]))->assertRedirect(route('registry.index'))->assertSessionHasErrors('plate_number')
            ->assertSessionHasInput('registered_by', 'Anne-Marie');
        $this->get(route('registry.index'))->assertOk()->assertSee('value="Anne-Marie"', false);
        $this->assertSame('Ma. José Dela Cruz', $this->defaultName());
        $this->assertDatabaseCount('vehicles', 1);
    }

    public function test_failed_setting_write_rolls_back_vehicle_and_new_tag_and_keeps_previous_default(): void
    {
        $this->postJson(route('vehicle-registry.store'), $this->payload())->assertCreated();
        DB::statement("CREATE TRIGGER fail_staff_default BEFORE UPDATE ON system_settings WHEN NEW.setting_key = 'registration_staff_name' BEGIN SELECT RAISE(ABORT, 'Isolated fixture write failure'); END");
        $this->postJson(route('vehicle-registry.store'), $this->payload(['registered_by' => 'Anne-Marie']))->assertStatus(500);
        $this->assertSame('Ma. José Dela Cruz', $this->defaultName());
        $this->assertDatabaseCount('vehicles', 1);
        $this->assertDatabaseCount('vehicle_rfid_tags', 1);
        $this->assertDatabaseMissing('vehicles', ['plate_number' => 'STF-2']);
    }

    public function test_existing_records_without_staff_remain_readable_and_editable_and_edits_do_not_change_default(): void
    {
        $this->postJson(route('vehicle-registry.store'), $this->payload())->assertCreated();
        $legacy = Vehicle::query()->create(['plate_number' => 'LEG-0001', 'vehicle_type' => 'Car', 'category' => 'faculty_staff']);
        $tag = RfidTag::query()->create(['uid' => 'LEGACY-TAG', 'status' => 'available']);
        $this->assertNull($legacy->fresh()->registered_by);
        $this->get(route('registry.vehicles.show', $legacy))->assertOk()->assertJsonPath('registered_by', null);
        $this->get(route('vehicle-registry.edit', $legacy))->assertOk()->assertSee('Registered by: Not recorded');
        $this->putJson(route('vehicle-registry.update', $legacy), [
            'rfid_tag_id' => $tag->id, 'plate_number' => $legacy->plate_number,
            'vehicle_owner_name' => 'Legacy Owner', 'vehicle_type' => 'Bus', 'category' => 'faculty_staff',
        ])->assertOk();
        $this->assertNull($legacy->fresh()->registered_by);
        $this->assertSame('Bus', $legacy->fresh()->vehicle_type);
        $this->assertSame('Ma. José Dela Cruz', $this->defaultName());
        $current = Vehicle::query()->where('plate_number', 'STF-1')->firstOrFail();
        $this->putJson(route('vehicle-registry.update', $current), [
            'rfid_tag_id' => $current->rfid_tag_id, 'plate_number' => $current->plate_number,
            'vehicle_type' => 'Bus', 'category' => 'faculty_staff', 'registered_by' => 'Another Staff',
        ])->assertOk();
        $this->assertSame('Ma. José Dela Cruz', $current->fresh()->registered_by);
        $this->assertSame('Ma. José Dela Cruz', $this->defaultName());
    }

    public function test_additive_upgrade_preserves_existing_records_relationships_and_configuration(): void
    {
        $migration = require database_path('migrations/2026_11_02_000001_add_registered_by_to_vehicles.php');
        // Reconstruct the pre-upgrade schema in this isolated test database.
        $migration->down();
        $vehicle = Vehicle::query()->create([
            'plate_number' => 'OLD-9001', 'vehicle_owner_name' => 'Existing Organization & Co',
            'vehicle_type' => 'Car', 'category' => 'faculty_staff',
        ]);
        $tag = RfidTag::query()->create(['uid' => 'UPGRADE-KEPT', 'vehicle_id' => $vehicle->id, 'status' => 'assigned']);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();
        $before = $vehicle->fresh()->getRawOriginal();
        SystemSetting::query()->create(['setting_key' => 'existing_fixture_configuration', 'setting_value' => 'kept']);
        $migration->up();
        $after = $vehicle->fresh()->getRawOriginal();
        $this->assertNull($after['registered_by']);
        unset($after['registered_by']);
        $this->assertSame($before, $after);
        $this->assertSame($vehicle->id, $tag->fresh()->vehicle_id);
        $this->assertDatabaseHas('system_settings', ['setting_key' => 'existing_fixture_configuration', 'setting_value' => 'kept']);
        $this->assertSame('', $this->defaultName());
    }
}
