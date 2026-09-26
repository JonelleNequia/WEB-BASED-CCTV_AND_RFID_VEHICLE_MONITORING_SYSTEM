<?php

namespace Tests\Feature;

use App\Models\ActiveSession;
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

    public function test_guest_passes_get_sequential_display_numbers_and_no_vehicle(): void
    {
        $service = app(VehicleRegistryService::class);

        $first = $service->registerRfidTag(['uid' => 'GP-UID-1', 'tag_number' => 101, 'tag_type' => 'guest_pass']);
        $second = $service->registerRfidTag(['uid' => 'GP-UID-2', 'tag_number' => 102, 'tag_type' => 'guest_pass']);
        $vehicleTag = $service->registerRfidTag(['uid' => 'VT-UID-1', 'tag_number' => 1]);

        $this->assertSame('G-01', $first->display_number);
        $this->assertSame('G-02', $second->display_number);
        $this->assertSame('Guest Pass #G-02', $second->label);
        $this->assertSame(RfidTag::TYPE_VEHICLE, $vehicleTag->tag_type);
        $this->assertNull($vehicleTag->display_number);

        $first->forceFill(['vehicle_id' => 999])->save();
        $this->assertNull($first->fresh()->vehicle_id);
    }

    public function test_each_issue_is_a_new_visit_and_a_pass_cannot_be_issued_twice(): void
    {
        $pass = app(VehicleRegistryService::class)
            ->registerRfidTag(['uid' => 'GP-UID-9', 'tag_number' => 109, 'tag_type' => 'guest_pass']);

        $firstVisit = GuestVisit::query()->create([
            'rfid_tag_id' => $pass->id,
            'active_rfid_tag_id' => $pass->id,
            'plate' => 'GST 001',
            'entry_at' => now(),
            'status' => GuestVisit::STATUS_ACTIVE,
        ]);

        $this->assertTrue($pass->fresh()->activeGuestVisit->is($firstVisit));

        try {
            GuestVisit::query()->create([
                'rfid_tag_id' => $pass->id,
                'active_rfid_tag_id' => $pass->id,
                'plate' => 'GST 002',
                'status' => GuestVisit::STATUS_ACTIVE,
            ]);
            $this->fail('A pass that is already issued must not be issued again.');
        } catch (QueryException) {
            $this->assertSame(1, GuestVisit::query()->count());
        }

        // Closing the visit frees the pass for the next guest.
        $firstVisit->update(['active_rfid_tag_id' => null, 'status' => GuestVisit::STATUS_COMPLETED, 'exit_at' => now()]);

        GuestVisit::query()->create([
            'rfid_tag_id' => $pass->id,
            'active_rfid_tag_id' => $pass->id,
            'plate' => 'GST 002',
            'status' => GuestVisit::STATUS_ACTIVE,
        ]);

        $this->assertSame(2, $pass->guestVisits()->count());
    }

    public function test_guest_pass_cannot_be_assigned_to_a_registered_vehicle(): void
    {
        $service = app(VehicleRegistryService::class);
        $pass = $service->registerRfidTag(['uid' => 'GP-UID-3', 'tag_number' => 103, 'tag_type' => 'guest_pass']);

        $this->assertFalse($service->availableTags()->contains('id', $pass->id));

        $this->expectException(ValidationException::class);
        $service->register([
            'rfid_tag_id' => $pass->id,
            'plate_number' => 'NOP 123',
            'vehicle_type' => 'Car',
            'category' => 'faculty_staff',
        ]);
    }

    public function test_registry_rejects_guest_category(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $tag = app(VehicleRegistryService::class)->registerRfidTag(['uid' => 'VT-UID-7', 'tag_number' => 7]);

        $this->actingAs($admin)
            ->from(route('vehicle-registry.index'))
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

    public function test_close_stale_sessions_dry_run_changes_nothing_then_archives(): void
    {
        $registeredEvent = VehicleEvent::query()->create([
            'event_type' => 'ENTRY',
            'event_status' => VehicleEvent::STATUS_COMPLETED,
            'event_origin' => 'rfid_simulated',
            'vehicle_category' => 'faculty_staff',
            'event_time' => now(),
        ]);
        ActiveSession::query()->create(['entry_event_id' => $registeredEvent->id, 'entry_time' => now(), 'status' => 'open']);

        foreach (range(1, 3) as $index) {
            $guestEvent = VehicleEvent::query()->create([
                'event_type' => 'ENTRY',
                'event_status' => VehicleEvent::STATUS_COMPLETED,
                'event_origin' => 'guest_cctv',
                'vehicle_category' => 'guest',
                'match_status' => 'open',
                'event_time' => now()->subDays($index),
            ]);
            ActiveSession::query()->create([
                'entry_event_id' => $guestEvent->id,
                'entry_time' => now()->subDays($index),
                'status' => 'open',
            ]);
        }

        $this->assertSame(4, ActiveSession::query()->where('status', 'open')->count());

        $this->artisan('guests:close-stale-sessions', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 3 session(s) would be archived')
            ->assertSuccessful();
        $this->assertSame(4, ActiveSession::query()->where('status', 'open')->count());

        $this->artisan('guests:close-stale-sessions')
            ->expectsOutputToContain('Archived 3 guest session(s)')
            ->assertSuccessful();

        $this->assertSame(1, ActiveSession::query()->where('status', 'open')->count());
        $this->assertSame(3, ActiveSession::query()->where('status', 'archived')->whereNotNull('archived_at')->count());
        $this->assertSame('archived', VehicleEvent::query()->where('event_origin', 'guest_cctv')->value('match_status'));
    }
}
