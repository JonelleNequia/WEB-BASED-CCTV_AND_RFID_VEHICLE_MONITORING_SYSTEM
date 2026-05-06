<?php

namespace Tests\Feature;

use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectRfidRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_rfid_inventory_page_is_removed(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->get('/rfid-inventory')
            ->assertNotFound();
    }

    public function test_vehicle_registration_assigns_pre_registered_inventory_tag(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->post(route('vehicle-registry.rfid-tags.store'), [
                'tag_number' => 1,
                'uid' => ' direct-3003 ',
            ])
            ->assertRedirect();

        $tag = RfidTag::query()->where('uid', 'DIRECT-3003')->firstOrFail();

        $this->assertSame(1, $tag->tag_number);

        $this->actingAs($user)
            ->post(route('vehicle-registry.store'), [
                'rfid_tag_id' => $tag->id,
                'plate_number' => ' dir-3003 ',
                'vehicle_owner_name' => 'Direct Owner',
                'category' => 'faculty_staff',
                'vehicle_type' => 'Car',
            ])
            ->assertRedirect();

        $vehicle = Vehicle::query()->where('plate_number', 'DIR-3003')->firstOrFail();

        $this->assertSame('DIRECT-3003', $vehicle->rfid_tag_uid);
        $this->assertDatabaseHas('vehicle_rfid_tags', [
            'uid' => 'DIRECT-3003',
            'status' => RfidTag::STATUS_ASSIGNED,
            'vehicle_id' => $vehicle->id,
        ]);
    }

    public function test_vehicle_registration_rejects_unregistered_direct_uid(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->from(route('vehicle-registry.index'))
            ->post(route('vehicle-registry.store'), [
                'rfid_uid' => ' direct-4004 ',
                'plate_number' => ' dir-4004 ',
                'vehicle_owner_name' => 'Direct Owner',
                'category' => 'faculty_staff',
                'vehicle_type' => 'Car',
            ])
            ->assertRedirect(route('vehicle-registry.index'))
            ->assertSessionHasErrors('rfid_uid');

        $this->assertDatabaseMissing('vehicles', [
            'plate_number' => 'DIR-4004',
        ]);
        $this->assertDatabaseMissing('vehicle_rfid_tags', [
            'uid' => 'DIRECT-4004',
        ]);
    }

    public function test_registry_lists_vehicle_assignment_tags_by_tag_number(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        RfidTag::query()->create([
            'tag_number' => 10,
            'uid' => 'RFID-TEN',
            'status' => RfidTag::STATUS_AVAILABLE,
        ]);
        RfidTag::query()->create([
            'tag_number' => 2,
            'uid' => 'RFID-TWO',
            'status' => RfidTag::STATUS_AVAILABLE,
        ]);

        $response = $this->actingAs($user)
            ->get(route('vehicle-registry.index'))
            ->assertOk()
            ->assertSee('RFID Tag No.')
            ->assertSee('RFID #2 - RFID-TWO')
            ->assertSee('RFID #10 - RFID-TEN');

        $this->assertLessThan(
            strpos($response->getContent(), 'RFID #10 - RFID-TEN'),
            strpos($response->getContent(), 'RFID #2 - RFID-TWO')
        );
    }
}
