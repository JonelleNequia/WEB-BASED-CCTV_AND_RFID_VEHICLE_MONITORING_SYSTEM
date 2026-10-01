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
