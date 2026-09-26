<?php

namespace Tests\Feature;

use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\GuestPassService;
use App\Services\RfidIngestService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI Phase 3: Registry › Vehicles (one-flow Add Vehicle, Replace Tag,
 * Deactivate, side panel), RFID Tags (bulk register, lost/disable) and
 * Guest Passes (lost/disable).
 */
class UiPhase3RegistryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    public function test_tag_lookup_explains_what_will_happen(): void
    {
        $this->tag('LOOK-AVAIL', 901);
        $this->vehicleWithTag('LKP 101', 'LOOK-ASSIGNED', 902);
        $this->tag('LOOK-PASS', 903, RfidTag::TYPE_GUEST_PASS);

        $this->lookup('look-new')->assertJsonPath('state', 'new')->assertJsonPath('ok', true);
        $this->lookup('LOOK-AVAIL')->assertJsonPath('state', 'available')->assertJsonPath('ok', true);
        $this->lookup('LOOK-ASSIGNED')->assertJsonPath('state', 'assigned')->assertJsonPath('ok', false)
            ->assertJsonFragment(['message' => 'Tag #902 is already assigned to LKP 101. Use Replace Tag on that vehicle first.']);
        $this->lookup('LOOK-PASS')->assertJsonPath('state', 'guest_pass')->assertJsonPath('ok', false);
    }

    public function test_add_vehicle_with_a_new_scanned_tag_creates_and_assigns_it(): void
    {
        $next = ((int) RfidTag::query()->max('tag_number')) + 1;

        $this->actingAs($this->admin)
            ->from(route('registry.index'))
            ->post(route('vehicle-registry.store'), $this->vehiclePayload('NEW 2001', ['rfid_uid' => 'brand-new-uid']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('registry.index'))
            ->assertSessionHas('status');

        $tag = RfidTag::query()->where('uid', 'BRAND-NEW-UID')->firstOrFail();
        $vehicle = Vehicle::query()->where('plate_number', 'NEW 2001')->firstOrFail();

        $this->assertSame($next, $tag->tag_number);
        $this->assertSame(RfidTag::STATUS_ASSIGNED, $tag->status);
        $this->assertSame($vehicle->id, $tag->vehicle_id);
        $this->assertSame($tag->id, $vehicle->rfid_tag_id);
    }

    public function test_add_vehicle_assigns_an_existing_available_tag_and_rejects_passes_and_assigned_tags(): void
    {
        $this->tag('EXIST-AVAIL', 911);
        $this->tag('EXIST-PASS', 912, RfidTag::TYPE_GUEST_PASS);
        $this->vehicleWithTag('OWN 101', 'EXIST-TAKEN', 913);

        $this->actingAs($this->admin)
            ->post(route('vehicle-registry.store'), $this->vehiclePayload('ADD 3001', ['rfid_uid' => 'EXIST-AVAIL']))
            ->assertSessionHasNoErrors();
        $this->assertSame(RfidTag::STATUS_ASSIGNED, RfidTag::query()->where('uid', 'EXIST-AVAIL')->value('status'));

        $this->actingAs($this->admin)
            ->post(route('vehicle-registry.store'), $this->vehiclePayload('ADD 3002', ['rfid_uid' => 'EXIST-PASS']))
            ->assertSessionHasErrors('rfid_uid');

        $this->actingAs($this->admin)
            ->post(route('vehicle-registry.store'), $this->vehiclePayload('ADD 3003', ['rfid_uid' => 'EXIST-TAKEN']))
            ->assertSessionHasErrors(['rfid_tag_uid' => 'This RFID tag is already assigned to another vehicle. Use Replace Tag on that vehicle first.']);

        $this->assertDatabaseMissing('vehicles', ['plate_number' => 'ADD 3002']);
        $this->assertDatabaseMissing('vehicles', ['plate_number' => 'ADD 3003']);
    }

    public function test_replace_tag_marks_the_old_tag_and_assigns_the_new_one(): void
    {
        $vehicle = $this->vehicleWithTag('RPL 101', 'OLD-STICKER', 921);

        $this->actingAs($this->admin)
            ->from(route('registry.index'))
            ->post(route('registry.vehicles.replace-tag', $vehicle), [
                'rfid_uid' => 'NEW-STICKER',
                'old_tag_status' => 'lost',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('registry.index'));

        $vehicle->refresh();
        $this->assertSame('NEW-STICKER', $vehicle->rfid_tag_uid);
        $this->assertSame(RfidTag::STATUS_LOST, RfidTag::query()->where('uid', 'OLD-STICKER')->value('status'));
        $this->assertSame(RfidTag::STATUS_ASSIGNED, RfidTag::query()->where('uid', 'NEW-STICKER')->value('status'));

        // The old (lost) sticker is now flagged at the gate; the new one works.
        $old = app(RfidIngestService::class)->ingest(['tag_uid' => 'OLD-STICKER', 'scan_location' => 'entrance']);
        $this->assertSame('inactive_tag', $old->scanLog->verification_status);

        $new = app(RfidIngestService::class)->ingest(['tag_uid' => 'NEW-STICKER', 'scan_location' => 'entrance']);
        $this->assertSame('verified', $new->scanLog->verification_status);
    }

    public function test_deactivate_and_activate_a_vehicle(): void
    {
        $vehicle = $this->vehicleWithTag('DEA 101', 'DEA-TAG', 931);

        $this->actingAs($this->admin)
            ->post(route('registry.vehicles.status', $vehicle), ['status' => 'inactive'])
            ->assertSessionHasNoErrors();
        $this->assertSame('inactive', $vehicle->fresh()->status);

        $scan = app(RfidIngestService::class)->ingest(['tag_uid' => 'DEA-TAG', 'scan_location' => 'entrance']);
        $this->assertSame('inactive_vehicle', $scan->scanLog->verification_status);

        $this->actingAs($this->admin)
            ->post(route('registry.vehicles.status', $vehicle), ['status' => 'active'])
            ->assertSessionHasNoErrors();
        $this->assertSame('active', $vehicle->fresh()->status);
    }

    public function test_side_panel_shows_details_and_last_ten_movements(): void
    {
        $vehicle = $this->vehicleWithTag('PNL 101', 'PNL-TAG', 941);

        foreach (range(1, 12) as $i) {
            $this->travel(2)->minutes();
            app(RfidIngestService::class)->ingest(['tag_uid' => 'PNL-TAG', 'scan_location' => $i % 2 ? 'entrance' : 'exit']);
        }

        $this->actingAs($this->admin)
            ->getJson(route('registry.vehicles.show', $vehicle))
            ->assertOk()
            ->assertJsonPath('plate_number', 'PNL 101')
            ->assertJsonPath('tag.uid', 'PNL-TAG')
            ->assertJsonCount(10, 'movements')
            ->assertJsonPath('movements.0.event_type', 'EXIT');
    }

    public function test_vehicle_search_and_filters(): void
    {
        $this->vehicleWithTag('SRC 101', 'SRC-TAG-A', 951);
        $inactive = $this->vehicleWithTag('SRC 202', 'SRC-TAG-B', 952);
        $inactive->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($this->admin)
            ->get(route('registry.index', ['q' => 'SRC-TAG-A']))
            ->assertSee('SRC 101')
            ->assertDontSee('SRC 202');

        $this->actingAs($this->admin)
            ->get(route('registry.index', ['status' => 'inactive']))
            ->assertSee('SRC 202')
            ->assertDontSee('SRC 101');
    }

    public function test_bulk_register_numbers_tags_automatically(): void
    {
        $next = ((int) RfidTag::query()->max('tag_number')) + 1;

        foreach (['BULK-1', 'BULK-2'] as $uid) {
            $this->actingAs($this->admin)
                ->postJson(route('rfid-inventory.store'), ['uid' => $uid, 'tag_type' => 'guest_pass', 'auto_number' => '1'])
                ->assertCreated();
        }

        $this->assertSame($next, RfidTag::query()->where('uid', 'BULK-1')->value('tag_number'));
        $this->assertSame($next + 1, RfidTag::query()->where('uid', 'BULK-2')->value('tag_number'));
        $this->assertNotSame(
            RfidTag::query()->where('uid', 'BULK-1')->value('display_number'),
            RfidTag::query()->where('uid', 'BULK-2')->value('display_number')
        );

        $this->actingAs($this->admin)
            ->postJson(route('rfid-inventory.store'), ['uid' => 'BULK-1', 'tag_type' => 'guest_pass', 'auto_number' => '1'])
            ->assertUnprocessable();
    }

    public function test_mark_tags_lost_disabled_and_enable_again(): void
    {
        $pass = $this->tag('PASS-ACT', 961, RfidTag::TYPE_GUEST_PASS);

        $this->actingAs($this->admin)->post(route('registry.tags.status', $pass), ['status' => 'disabled'])->assertSessionHasNoErrors();
        $this->assertSame(RfidTag::STATUS_DISABLED, $pass->fresh()->status);

        $this->actingAs($this->admin)->post(route('registry.tags.status', $pass), ['status' => 'available'])->assertSessionHasNoErrors();
        $this->assertSame(RfidTag::STATUS_AVAILABLE, $pass->fresh()->status);

        // A pass with a guest must be handled from the visit.
        app(GuestPassService::class)->issue($pass, ['plate' => 'GST 1', 'id_presented' => 'UMID']);
        $this->actingAs($this->admin)->post(route('registry.tags.status', $pass), ['status' => 'lost'])->assertSessionHasErrors('status');
        $this->assertSame(RfidTag::STATUS_ISSUED, $pass->fresh()->status);

        $vehicle = $this->vehicleWithTag('LST 101', 'LST-TAG', 962);
        $tag = $vehicle->rfidTag;
        $this->actingAs($this->admin)->post(route('registry.tags.status', $tag), ['status' => 'lost'])->assertSessionHasNoErrors();
        $this->assertSame(RfidTag::STATUS_LOST, $tag->fresh()->status);

        // Found again: it is still this vehicle's tag, so it goes back to assigned.
        $this->actingAs($this->admin)->post(route('registry.tags.status', $tag), ['status' => 'available'])->assertSessionHasNoErrors();
        $this->assertSame(RfidTag::STATUS_ASSIGNED, $tag->fresh()->status);
    }

    public function test_registry_pages_render_new_actions(): void
    {
        $this->vehicleWithTag('UI 101', 'UI-TAG', 971);
        $this->tag('UI-PASS', 972, RfidTag::TYPE_GUEST_PASS);

        $this->actingAs($this->admin)->get(route('registry.index'))
            ->assertOk()
            ->assertSee('data-vehicle-row', false)
            ->assertSee('Replace Tag')
            ->assertSee('Deactivate')
            ->assertSee('data-tag-scan', false)
            ->assertSee('Or choose an available tag');

        $this->actingAs($this->admin)->get(route('registry.index', ['tab' => 'tags']))
            ->assertOk()
            ->assertSee('Register Tags')
            ->assertSee('data-bulk-scan', false)
            ->assertSee('Mark lost');

        $this->actingAs($this->admin)->get(route('registry.index', ['tab' => 'passes']))
            ->assertOk()
            ->assertSee('UI-PASS')
            ->assertSee('Disable');
    }

    protected function lookup(string $uid)
    {
        return $this->actingAs($this->admin)->postJson(route('registry.tags.lookup'), ['uid' => $uid])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function vehiclePayload(string $plate, array $overrides = []): array
    {
        return array_merge([
            '_form' => 'add',
            'auto_register_tag' => '1',
            'plate_number' => $plate,
            'vehicle_owner_name' => 'Owner '.$plate,
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ], $overrides);
    }

    protected function tag(string $uid, int $number, string $type = RfidTag::TYPE_VEHICLE): RfidTag
    {
        return RfidTag::query()->create([
            'uid' => $uid,
            'tag_number' => $number,
            'tag_type' => $type,
            'status' => RfidTag::STATUS_AVAILABLE,
            'display_number' => $type === RfidTag::TYPE_GUEST_PASS ? RfidTag::nextGuestPassNumber() : null,
        ]);
    }

    protected function vehicleWithTag(string $plate, string $uid, int $number): Vehicle
    {
        $tag = $this->tag($uid, $number);
        $vehicle = Vehicle::query()->create([
            'plate_number' => $plate,
            'vehicle_owner_name' => 'Owner '.$plate,
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);

        $tag->forceFill(['vehicle_id' => $vehicle->id, 'status' => RfidTag::STATUS_ASSIGNED, 'assigned_at' => now()])->save();
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        return $vehicle->fresh(['rfidTag']);
    }
}
