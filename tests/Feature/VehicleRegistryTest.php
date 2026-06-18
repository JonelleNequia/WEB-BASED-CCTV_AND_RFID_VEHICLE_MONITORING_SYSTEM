<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\RfidTag;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleRegistryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure the vehicle registry page loads without demo vehicle records.
     */
    public function test_vehicle_registry_page_renders_clean_registry(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->get(route('vehicle-registry.index'))
            ->assertOk()
            ->assertSee('Vehicle Registry')
            ->assertSee('RFID Tag Inventory')
            ->assertSee('No RFID tags registered yet.')
            ->assertSee('No registered vehicles yet.')
            ->assertDontSee('RFID-ABC-1001');

        $this->assertDatabaseCount('vehicles', 0);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
    }

    /**
     * Ensure the registry form can save one vehicle with its RFID tag.
     */
    public function test_vehicle_registry_form_creates_vehicle_and_tag(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $tag = RfidTag::query()->create([
            'uid' => 'DEMO-TAG-8899',
            'status' => RfidTag::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)
            ->post(route('vehicle-registry.store'), [
                'rfid_tag_id' => $tag->id,
                'plate_number' => ' tst-8899 ',
                'owner_name' => 'Test Owner',
                'vehicle_type' => 'Car',
                'vehicle_color' => 'Black',
                'status' => 'active',
                'notes' => 'Created from feature test.',
            ])
            ->assertRedirect();

        $vehicle = Vehicle::query()->where('plate_number', 'TST-8899')->first();

        $this->assertNotNull($vehicle);
        $this->assertSame('Test Owner', $vehicle->owner_name);
        $this->assertDatabaseHas('vehicle_rfid_tags', [
            'uid' => 'DEMO-TAG-8899',
            'status' => RfidTag::STATUS_ASSIGNED,
            'vehicle_id' => $vehicle->id,
        ]);
    }

    public function test_vehicle_registry_saves_custom_category_and_vehicle_type(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $tag = RfidTag::query()->create([
            'uid' => 'RFID-CUSTOM-3001',
            'status' => RfidTag::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)
            ->post(route('vehicle-registry.store'), [
                'rfid_tag_id' => $tag->id,
                'plate_number' => 'CUS-3001',
                'vehicle_owner_name' => 'Custom Owner',
                'category' => 'others',
                'category_other' => 'Alumni',
                'vehicle_type' => 'Others',
                'vehicle_type_other' => 'Tricycle',
            ])
            ->assertRedirect();

        $vehicle = Vehicle::query()->where('plate_number', 'CUS-3001')->firstOrFail();

        $this->assertSame('Alumni', $vehicle->category);
        $this->assertSame('Tricycle', $vehicle->vehicle_type);
        $this->assertTrue($vehicle->isRfidRecurring());
    }

    public function test_vehicle_registry_rejects_same_owner_and_plate_with_different_uid(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $assignedTag = RfidTag::query()->create([
            'uid' => 'RFID-DUP-1001-A',
            'status' => RfidTag::STATUS_ASSIGNED,
            'assigned_at' => now(),
        ]);
        $vehicle = Vehicle::query()->create([
            'rfid_tag_id' => $assignedTag->id,
            'rfid_tag_uid' => $assignedTag->uid,
            'plate_number' => 'DUP-1001',
            'vehicle_owner_name' => 'Duplicate Owner',
            'category' => 'student',
            'vehicle_type' => 'Car',
        ]);
        $assignedTag->forceFill(['vehicle_id' => $vehicle->id])->save();
        $newTag = RfidTag::query()->create([
            'uid' => 'RFID-DUP-1001-B',
            'status' => RfidTag::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)
            ->from(route('vehicle-registry.index'))
            ->post(route('vehicle-registry.store'), [
                'rfid_tag_id' => $newTag->id,
                'plate_number' => 'dup 1001',
                'vehicle_owner_name' => ' duplicate   owner ',
                'category' => 'student',
                'vehicle_type' => 'Car',
            ])
            ->assertRedirect(route('vehicle-registry.index'))
            ->assertSessionHasErrors('plate_number');

        $this->assertDatabaseMissing('vehicles', [
            'rfid_tag_uid' => 'RFID-DUP-1001-B',
        ]);
        $this->assertSame(RfidTag::STATUS_AVAILABLE, $newTag->fresh()->status);
    }

    public function test_vehicle_registry_allows_same_owner_with_different_plate_and_uid(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        Vehicle::query()->create([
            'plate_number' => 'OWN-1001',
            'vehicle_owner_name' => 'Shared Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);
        $tag = RfidTag::query()->create([
            'uid' => 'RFID-OWN-2002',
            'status' => RfidTag::STATUS_AVAILABLE,
        ]);

        $this->actingAs($user)
            ->post(route('vehicle-registry.store'), [
                'rfid_tag_id' => $tag->id,
                'plate_number' => 'OWN-2002',
                'vehicle_owner_name' => 'Shared Owner',
                'category' => 'faculty_staff',
                'vehicle_type' => 'Truck',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('vehicles', [
            'plate_number' => 'OWN-2002',
            'vehicle_owner_name' => 'Shared Owner',
            'rfid_tag_uid' => 'RFID-OWN-2002',
        ]);
    }

    public function test_vehicle_registry_edit_updates_existing_vehicle(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $vehicle = Vehicle::query()->create([
            'plate_number' => 'OLD-1001',
            'vehicle_owner_name' => 'Old Owner',
            'category' => 'student',
            'vehicle_type' => 'Car',
        ]);
        $tag = RfidTag::query()->create([
            'uid' => 'RFID-EDIT-1001',
            'status' => RfidTag::STATUS_ASSIGNED,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now(),
        ]);
        $vehicle->forceFill([
            'rfid_tag_id' => $tag->id,
            'rfid_tag_uid' => $tag->uid,
        ])->save();

        $this->actingAs($user)
            ->put(route('vehicle-registry.update', ['vehicle' => $vehicle->id]), [
                'rfid_tag_id' => $tag->id,
                'plate_number' => 'NEW-1001',
                'vehicle_owner_name' => 'Updated Owner',
                'category' => 'faculty_staff',
                'vehicle_type' => 'Truck',
            ])
            ->assertRedirect(route('vehicle-registry.index'));

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'plate_number' => 'NEW-1001',
            'vehicle_owner_name' => 'Updated Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Truck',
            'rfid_tag_id' => $tag->id,
        ]);
    }
}
