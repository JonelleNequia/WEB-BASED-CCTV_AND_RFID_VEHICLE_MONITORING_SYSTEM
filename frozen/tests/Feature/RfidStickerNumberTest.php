<?php

namespace Tests\Feature;

use App\Models\RfidTag;
use App\Models\User;
use App\Models\VehicleEvent;
use App\Services\RfidIngestService;
use App\Services\VehicleRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class RfidStickerNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
    }

    public function test_manual_number_keeps_leading_zeros_separate_from_uid_and_displays_in_inventory_and_reports(): void
    {
        $this->postJson(route('rfid-inventory.store'), ['display_number' => '000123', 'uid' => ' 0000epc789 '])
            ->assertCreated()->assertJsonPath('tag_number', 123)->assertJsonPath('display_number', '000123')
            ->assertJsonPath('number_label', '000123')->assertJsonPath('label', 'Tag #000123');
        $tag = RfidTag::sole();
        $this->assertSame(['000123', 123, '0000EPC789'], [$tag->display_number, $tag->tag_number, $tag->uid]);
        $this->assertNull($tag->vehicle_id);
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->get(route('registry.index', ['tab' => 'tags']))->assertOk()->assertSee('#000123')->assertSee('0000EPC789')->assertSee('Add RFID Tag');
        $print = $this->get(route('registry.print', ['tab' => 'tags']))->assertOk();
        $print->assertSee('000123')->assertSee('0000EPC789');
        $csv = $this->get(route('registry.export.csv', ['tab' => 'tags']))->assertOk()->streamedContent();
        $this->assertStringContainsString('000123', $csv);
        $this->assertStringContainsString('0000EPC789', $csv);
    }

    public static function invalidNumbers(): array
    {
        return array_map(fn ($number) => [$number], ['', '   ', '0', '000000', '-1', '+12', '12.5', '1e3', '1 23', 'ABC123', '123/4',
            '１２３', '123😀', '1000000', '000000000000000000001', ['123'], null, true, 12.5]);
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_numbers_are_rejected_by_both_routes_and_the_shared_service(mixed $number): void
    {
        foreach (['rfid-inventory.store', 'vehicle-registry.rfid-tags.store'] as $route) {
            $this->postJson(route($route), ['display_number' => $number, 'uid' => 'INVALID-FIXTURE'])
                ->assertUnprocessable()->assertJsonValidationErrors('display_number');
        }
        try {
            app(VehicleRegistryService::class)->registerRfidTag(['display_number' => $number, 'uid' => 'INVALID-FIXTURE']);
            $this->fail('The shared registration service accepted an invalid sticker number.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('display_number', $exception->errors());
        }
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
    }

    public function test_numeric_uniqueness_includes_other_padding_and_legacy_inventory_numbers(): void
    {
        $registry = app(VehicleRegistryService::class);
        $tag = $registry->registerRfidTag(['display_number' => '000123', 'uid' => 'FIRST-UID']);
        foreach (['000123', '123', '00123'] as $number) {
            $this->postJson(route('rfid-inventory.store'), ['display_number' => $number, 'uid' => 'OTHER-UID'])
                ->assertUnprocessable()->assertJsonValidationErrors('display_number');
        }
        $legacy = $registry->registerRfidTag(['tag_number' => 124, 'uid' => 'LEGACY-UID']);
        $this->postJson(route('rfid-inventory.store'), ['display_number' => '000124', 'uid' => 'OTHER-UID'])
            ->assertUnprocessable()->assertJsonValidationErrors('display_number');
        $this->assertSame([123, '000123', 'FIRST-UID'], [$tag->fresh()->tag_number, $tag->fresh()->display_number, $tag->fresh()->uid]);
        $this->assertNull($legacy->fresh()->display_number);
        $this->assertDatabaseCount('vehicle_rfid_tags', 2);
    }

    public function test_existing_display_labels_are_preserved_and_cannot_be_reused_with_different_padding(): void
    {
        $legacy = RfidTag::create(['uid' => 'OLD-UID', 'tag_number' => 900, 'display_number' => '000321']);
        $this->postJson(route('rfid-inventory.store'), ['display_number' => '321', 'uid' => 'NEW-UID'])
            ->assertUnprocessable()->assertJsonValidationErrors('display_number');
        $this->assertSame(['000321', 900], [$legacy->fresh()->display_number, $legacy->fresh()->tag_number]);
        $this->assertDatabaseCount('vehicle_rfid_tags', 1);
    }

    public function test_html_failure_keeps_the_fields_and_reopens_the_manual_drawer(): void
    {
        $url = route('registry.index', ['tab' => 'tags']);
        $this->from($url)->post(route('rfid-inventory.store'), ['_form' => 'add-tag', 'display_number' => '12/3', 'uid' => 'KEEP-ME'])
            ->assertRedirect($url)->assertSessionHasErrors('display_number')->assertSessionHasInput('display_number', '12/3')->assertSessionHasInput('uid', 'KEEP-ME');
        $this->get($url)->assertOk()->assertSee('id="add-tag-drawer"', false)->assertSee('value="12/3"', false)
            ->assertSee('value="KEEP-ME"', false)->assertSee('aria-invalid="true"', false);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
    }

    public function test_assignment_scanning_replacement_and_lost_permanence_use_uid_not_the_sticker_number(): void
    {
        $registry = app(VehicleRegistryService::class);
        $tag = $registry->registerRfidTag(['display_number' => '000123', 'uid' => '0000EPC789']);
        $vehicle = $registry->register(['plate_number' => '395ISX', 'vehicle_type' => 'Car', 'vehicle_owner_name' => 'Fixture Owner',
            'registered_by' => 'Fixture Staff', 'rfid_tag_id' => $tag->id, 'display_number' => '000123']);
        $this->assertSame($tag->id, $vehicle->rfid_tag_id);
        $this->assertSame('0000EPC789', $vehicle->rfid_tag_uid);
        $this->assertNull(RfidTag::findUid('000123'));
        $this->assertSame($tag->id, RfidTag::findUid('0000EPC789')->id);
        $this->get(route('registry.index'))->assertSee('#000123');
        $this->get(route('registry.index', ['q' => '000123']))->assertSee('395ISX')->assertSee('#000123');
        $this->getJson(route('registry.vehicles.show', $vehicle))->assertOk()->assertJsonPath('tag.number_label', '000123');
        $this->get(route('registry.print', ['tab' => 'vehicles']))->assertOk()->assertSee('000123');
        $this->postJson(route('registry.tags.lookup'), ['uid' => '0000EPC789'])->assertOk()->assertJsonPath('tag.number_label', '000123');
        $this->travel(2)->minutes();
        $read = app(RfidIngestService::class)->ingest(['tag_uid' => '0000EPC789', 'scan_location' => 'gate-1']);
        $this->assertSame($tag->id, $read->scanLog->vehicle_rfid_tag_id);
        $this->assertSame('0000EPC789', $read->scanLog->tag_uid);
        $this->assertSame($vehicle->id, $read->scanLog->vehicle_id);
        $replacement = $registry->registerRfidTag(['display_number' => '000124', 'uid' => 'REPLACEMENT-UID']);
        $this->post(route('registry.vehicles.replace-tag', $vehicle), ['rfid_tag_id' => $replacement->id, 'old_tag_status' => 'lost'])->assertRedirect();
        $this->assertSame($replacement->id, $vehicle->fresh()->rfid_tag_id);
        $this->assertTrue(RfidTag::isUnavailableUid('0000EPC789'));
        $this->assertFalse(RfidTag::isUnavailableUid('000123'));
        $this->assertSame('000123', $tag->fresh()->display_number);
        $this->postJson(route('registry.tags.status', $tag), ['status' => 'available'])->assertUnprocessable();
        $before = VehicleEvent::count();
        $this->travel(2)->minutes();
        $ignored = app(RfidIngestService::class)->ingest(['tag_uid' => '0000EPC789', 'scan_location' => 'gate-1']);
        $this->assertFalse($ignored->isSaved());
        $this->assertSame($before, VehicleEvent::count());
        $this->postJson(route('rfid-inventory.store'), ['display_number' => '000125', 'uid' => '0000EPC789'])->assertUnprocessable()->assertJsonValidationErrors('uid');
    }

    public function test_automatic_and_legacy_creation_keep_their_existing_contract_and_admin_permissions(): void
    {
        $this->postJson(route('rfid-inventory.store'), ['tag_number' => 41, 'uid' => 'LEGACY-UID'])->assertCreated()->assertJsonPath('tag_number', 41);
        $this->postJson(route('rfid-inventory.store'), ['auto_number' => true, 'uid' => 'BULK-UID'])->assertCreated()->assertJsonPath('tag_number', 42);
        $this->assertNull(RfidTag::where('uid', 'BULK-UID')->sole()->display_number);
        $this->actingAs(User::factory()->create(['role' => 'guard']));
        foreach (['rfid-inventory.store', 'vehicle-registry.rfid-tags.store'] as $route) {
            $this->postJson(route($route), ['display_number' => '000123', 'uid' => 'DENIED'])->assertForbidden();
        }
        auth()->forgetGuards();
        $this->app['session']->flush();
        $this->postJson(route('rfid-inventory.store'), ['display_number' => '000123', 'uid' => 'DENIED'])->assertUnauthorized();
        $this->assertDatabaseCount('vehicle_rfid_tags', 2);
    }

    public function test_boundary_values_keep_literal_text_and_automatic_numbering_respects_reserved_legacy_labels(): void
    {
        $registry = app(VehicleRegistryService::class);
        $tag = $registry->registerRfidTag(['display_number' => str_repeat('0', 19).'1', 'uid' => 'LOWER-BOUND']);
        $this->assertSame(str_repeat('0', 19).'1', $tag->display_number);
        RfidTag::create(['uid' => 'LEGACY-LABEL', 'tag_number' => 2, 'display_number' => '0003']);
        $this->postJson(route('rfid-inventory.store'), ['auto_number' => 1, 'uid' => 'BULK-NEXT'])->assertCreated()
            ->assertJsonPath('tag_number', 4)->assertJsonPath('next_tag_number', 5);
        $this->postJson(route('rfid-inventory.store'), ['display_number' => '999999', 'uid' => 'UPPER-BOUND'])->assertCreated()->assertJsonPath('tag_number', 999999);
        $this->postJson(route('rfid-inventory.store'), ['auto_number' => 1, 'uid' => 'NO-OVERFLOW'])->assertUnprocessable()->assertJsonValidationErrors('tag_number');
        $this->assertDatabaseMissing('vehicle_rfid_tags', ['uid' => 'NO-OVERFLOW']);
        $this->get(route('registry.index', ['tab' => 'tags']))->assertOk()
            ->assertSee('Add RFID Tag')->assertDontSee('Register Tags');
    }

    public function test_mismatched_numeric_and_printed_values_cannot_bypass_the_shared_service(): void
    {
        $this->postJson(route('rfid-inventory.store'), ['display_number' => '000123', 'tag_number' => 124, 'uid' => 'MISMATCH'])
            ->assertUnprocessable()->assertJsonValidationErrors('display_number');
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
    }

    public function test_failed_save_preserves_form_values_and_rolls_back_inventory_changes(): void
    {
        RfidTag::creating(function (RfidTag $tag): void {
            if ($tag->uid === 'SAVE-FAILURE-FIXTURE') {
                throw new \RuntimeException('Synthetic private save failure.');
            }
        });
        $url = route('registry.index', ['tab' => 'tags']);
        $this->from($url)->post(route('rfid-inventory.store'), ['_form' => 'add-tag', 'display_number' => '000123', 'uid' => 'SAVE-FAILURE-FIXTURE'])
            ->assertRedirect($url)->assertSessionHasErrors('uid')->assertSessionHasInput('display_number', '000123')->assertSessionHasInput('uid', 'SAVE-FAILURE-FIXTURE');
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertDatabaseCount('vehicles', 0);
    }
}
