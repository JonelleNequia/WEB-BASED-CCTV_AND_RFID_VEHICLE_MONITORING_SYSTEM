<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleRfidTag;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleRegistryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure the vehicle registry page loads with seeded RFID-ready records.
     */
    public function test_vehicle_registry_page_renders_seeded_records(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->get(route('vehicle-registry.index'))
            ->assertOk()
            ->assertSee('Vehicle Registry')
            ->assertSee('ABC-1234')
            ->assertSee('RFID-ABC-1001');
    }

    /**
     * Ensure the registry form can save one vehicle with its RFID tag.
     */
    public function test_vehicle_registry_form_creates_vehicle_and_tag(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->post(route('vehicle-registry.store'), [
                'plate_number' => ' tst-8899 ',
                'owner_name' => 'Test Owner',
                'vehicle_type' => 'Car',
                'vehicle_color' => 'Black',
                'status' => 'active',
                'tag_uid' => ' demo-tag-8899 ',
                'tag_label' => 'Test Tag',
                'tag_status' => 'active',
                'notes' => 'Created from feature test.',
            ])
            ->assertRedirect();

        $vehicle = Vehicle::query()->where('plate_number', 'TST-8899')->first();

        $this->assertNotNull($vehicle);
        $this->assertSame('Test Owner', $vehicle->owner_name);
        $this->assertTrue(VehicleRfidTag::query()->where('tag_uid', 'DEMO-TAG-8899')->exists());
    }
}
