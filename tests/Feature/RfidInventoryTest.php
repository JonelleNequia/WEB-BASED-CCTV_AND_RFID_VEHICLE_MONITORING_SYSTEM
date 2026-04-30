<?php

namespace Tests\Feature;

use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RfidInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_rfid_inventory_page_renders_available_tags(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        RfidTag::query()->create([
            'uid' => 'POOL-1001',
            'status' => RfidTag::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)
            ->get(route('rfid-inventory.index'))
            ->assertOk()
            ->assertSee('RFID Inventory')
            ->assertSee('POOL-1001');
    }

    public function test_scanned_uid_is_added_to_available_inventory(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('rfid-inventory.store'), [
                'uid' => ' pool-2002 ',
            ])
            ->assertCreated()
            ->assertJsonPath('tag.uid', 'POOL-2002')
            ->assertJsonPath('tag.status', RfidTag::STATUS_AVAILABLE);

        $this->assertDatabaseHas('vehicle_rfid_tags', [
            'uid' => 'POOL-2002',
            'status' => RfidTag::STATUS_AVAILABLE,
            'vehicle_id' => null,
        ]);
    }

    public function test_assigned_uid_scanned_in_inventory_reports_assigned_vehicle(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $tag = RfidTag::query()->assigned()->with('vehicle')->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('rfid-inventory.store'), [
                'uid' => $tag->uid,
            ])
            ->assertConflict()
            ->assertJsonPath('tag.uid', $tag->uid)
            ->assertJsonPath('tag.status', RfidTag::STATUS_ASSIGNED)
            ->assertJsonPath('tag.vehicle_plate', $tag->vehicle->plate_number);
    }

    public function test_vehicle_registration_assigns_available_inventory_tag(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $tag = RfidTag::query()->create([
            'uid' => 'POOL-3003',
            'status' => RfidTag::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)
            ->post(route('vehicle-registry.store'), [
                'rfid_tag_id' => $tag->id,
                'plate_number' => ' inv-3003 ',
                'vehicle_owner_name' => 'Inventory Owner',
                'category' => 'faculty_staff',
                'vehicle_type' => 'Car',
            ])
            ->assertRedirect();

        $vehicle = Vehicle::query()->where('plate_number', 'INV-3003')->firstOrFail();

        $this->assertSame($tag->id, $vehicle->rfid_tag_id);
        $this->assertSame('POOL-3003', $vehicle->rfid_tag_uid);

        $this->assertDatabaseHas('vehicle_rfid_tags', [
            'id' => $tag->id,
            'uid' => 'POOL-3003',
            'status' => RfidTag::STATUS_ASSIGNED,
            'vehicle_id' => $vehicle->id,
        ]);
    }
}
