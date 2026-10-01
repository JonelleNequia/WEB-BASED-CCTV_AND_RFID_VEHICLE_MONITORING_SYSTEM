<?php

namespace Tests\Feature;

use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\RfidIngestService;
use App\Support\VehicleCategory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4 (visitor model): Faculty & Staff, Registered Visitor, Unregistered Visitor.
 */
class Phase4VisitorCategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    public function test_migration_moves_parent_student_guard_and_custom_categories_to_registered_visitor(): void
    {
        $migration = require database_path('migrations/2026_10_01_000005_visitor_categories.php');
        $migration->down();

        $old = [];
        foreach (['parent', 'student', 'guard', 'Alumni', 'faculty_staff'] as $index => $category) {
            $old[$category] = Vehicle::query()->create([
                'plate_number' => "CAT {$index}00", 'vehicle_owner_name' => 'Owner', 'category' => $category, 'vehicle_type' => 'Car',
            ])->id;
        }
        $eventId = $this->eventFor(Vehicle::query()->find($old['student']));
        DB::table('vehicle_events')->where('id', $eventId)->update(['vehicle_category' => 'student']);

        $migration->up();

        foreach (['parent', 'student', 'guard', 'Alumni'] as $category) {
            $this->assertSame('registered_visitor', Vehicle::query()->find($old[$category])->category, $category);
        }
        $this->assertSame('faculty_staff', Vehicle::query()->find($old['faculty_staff'])->category);
        $this->assertSame('registered_visitor', DB::table('vehicle_events')->where('id', $eventId)->value('vehicle_category'));
        // Nothing lost: the old category is kept, and down() puts it back.
        $this->assertSame('Alumni', DB::table('legacy_vehicle_categories')->where('vehicle_id', $old['Alumni'])->value('category'));

        $migration->down();
        $this->assertSame('guard', Vehicle::query()->find($old['guard'])->category);
        $migration->up();
    }

    public function test_registry_offers_only_faculty_staff_and_registered_visitor(): void
    {
        $this->actingAs($this->admin)->get(route('registry.index', ['tab' => 'vehicles']))
            ->assertOk()
            ->assertSee('<option value="faculty_staff"', false)
            ->assertSee('<option value="registered_visitor"', false)
            ->assertDontSee('<option value="unregistered_visitor"', false)
            ->assertDontSee('<option value="student"', false)
            ->assertDontSee('<option value="others"', false)
            ->assertSee('Unregistered Visitor is set by the system');

        $tag = RfidTag::query()->create(['uid' => 'CAT-TAG-1', 'status' => RfidTag::STATUS_AVAILABLE]);
        $form = ['rfid_tag_id' => $tag->id, 'plate_number' => 'CAT 1001', 'vehicle_owner_name' => 'Visitor', 'vehicle_type' => 'Car'];

        $this->actingAs($this->admin)->post(route('vehicle-registry.store'), $form + ['category' => 'unregistered_visitor'])
            ->assertSessionHasErrors(['category' => 'Unregistered Visitor cannot be picked here: the system sets it for vehicles the camera sees without a registered tag. Choose Faculty & Staff or Registered Visitor.']);

        // An old client sending "student" gets a Registered Visitor.
        $this->actingAs($this->admin)->post(route('vehicle-registry.store'), $form + ['category' => 'student'])->assertRedirect();
        $this->assertSame('registered_visitor', Vehicle::query()->where('plate_number', 'CAT 1001')->value('category'));
    }

    public function test_registered_visitor_uses_the_same_rfid_flow_as_faculty_and_staff(): void
    {
        $vehicle = Vehicle::query()->create(['plate_number' => 'VIS 2002', 'vehicle_owner_name' => 'Visitor', 'category' => 'registered_visitor', 'vehicle_type' => 'Car']);
        $tag = RfidTag::query()->create(['uid' => 'VIS-TAG-2', 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        $result = app(RfidIngestService::class)->ingest(['tag_uid' => 'VIS-TAG-2', 'scan_location' => 'gate-1']);

        $this->assertSame(['verified', 'ENTRY', 'registered_visitor'], [$result->scanLog->verification_status, $result->scanLog->resolved_event_type, $result->scanLog->vehicle_category]);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
        $this->assertSame('registered_visitor', VehicleEvent::query()->sole()->vehicle_category);
    }

    public function test_labels_and_filters_use_the_new_names_also_for_old_history(): void
    {
        $this->assertSame(
            ['Faculty & Staff', 'Registered Visitor', 'Registered Visitor', 'Unregistered Visitor', 'Unregistered Visitor', 'N/A'],
            array_map([VehicleCategory::class, 'label'], ['faculty_staff', 'registered_visitor', 'student', 'unregistered_visitor', 'guest', null])
        );

        $vehicle = Vehicle::query()->create(['plate_number' => 'OLD 3003', 'vehicle_owner_name' => 'Old', 'category' => 'registered_visitor', 'vehicle_type' => 'Car']);
        $eventId = $this->eventFor($vehicle);
        // History saved before Phase 4 still says "student".
        DB::table('vehicle_events')->where('id', $eventId)->update(['vehicle_category' => 'student']);
        $vehicle->forceFill(['category' => 'student'])->save();

        $this->actingAs($this->admin)->get(route('logs.index', ['tab' => 'events', 'category' => 'registered_visitor']))
            ->assertOk()
            ->assertSee('OLD 3003')
            ->assertSee('<option value="unregistered_visitor"', false)
            ->assertSee('Faculty &amp; Staff', false);
        $this->actingAs($this->admin)->get(route('logs.index', ['tab' => 'events', 'category' => 'faculty_staff']))
            ->assertOk()
            ->assertDontSee('OLD 3003');
    }

    protected function eventFor(Vehicle $vehicle): int
    {
        $tag = RfidTag::query()->create(['uid' => 'EVT-'.$vehicle->id, 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        return (int) app(RfidIngestService::class)->ingest(['tag_uid' => $tag->uid, 'scan_location' => 'gate-1'])->scanLog->correlated_vehicle_event_id;
    }
}
