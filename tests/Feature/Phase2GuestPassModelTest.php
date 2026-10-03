<?php

namespace Tests\Feature;

use App\Models\GuestVisit;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\VehicleRegistryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 2: guest pass data model, registry rules, and stale session cleanup.
 * Phase 0 (visitor model): guest passes were removed; the tables stay for
 * history, so only the schema, registry and cleanup tests remain.
 */
class Phase2GuestPassModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_has_guest_pass_columns_and_tables(): void
    {
        $this->assertTrue(Schema::hasColumns('vehicle_rfid_tags', ['tag_type', 'display_number']));
        $this->assertTrue(Schema::hasColumns('guest_visits', [
            'rfid_tag_id', 'plate', 'driver_name', 'vehicle_type', 'color', 'purpose', 'destination',
            'id_presented', 'issued_by', 'entry_at', 'entry_snapshot', 'exit_at', 'exit_snapshot',
            'valid_until', 'status', 'notes',
        ]));
        $this->assertTrue(Schema::hasColumn('vehicle_events', 'guest_visit_id'));
    }

    public function test_registry_rejects_guest_category(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $tag = app(VehicleRegistryService::class)->registerRfidTag(['uid' => 'VT-UID-7', 'tag_number' => 7]);

        $this->actingAs($admin)
            ->from(route('registry.index'))
            ->post(route('vehicle-registry.store'), [
                'rfid_tag_id' => $tag->id,
                'plate_number' => 'GST 777',
                'vehicle_type' => 'Car',
                'category' => 'others',
                'category_other' => 'Guest',
            ])
            ->assertSessionHasErrors('category');

        $this->assertFalse(Vehicle::query()->where('plate_number', 'GST 777')->exists());
    }
}
