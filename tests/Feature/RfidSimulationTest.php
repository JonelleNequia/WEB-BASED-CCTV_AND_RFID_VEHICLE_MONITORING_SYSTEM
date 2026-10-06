<?php

namespace Tests\Feature;

use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RfidSimulationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure the RFID scan page renders the offline simulation interface.
     */
    public function test_rfid_scan_page_renders_simulation_workspace(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->get(route('settings.index', ['tab' => 'test-scan']))
            ->assertOk()
            ->assertSee('Test Scan')
            ->assertSee('Check Tag')
            ->assertSee('Preview only. Nothing is recorded.');
    }

    /**
     * RFID only with a vehicle: a test scan is a preview (no record).
     */
    public function test_test_scan_is_a_preview_and_saves_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->post(route('rfid-scans.store'), [
                'tag_uid' => 'RFID-ABC-1001',
                'scan_location' => 'gate-1',
                'scan_direction' => 'entry',
                'reader_name' => 'Entrance RFID Reader (Simulated)',
                'scan_time' => now()->toIso8601String(),
                'notes' => 'Created from feature test.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_starts_with($message, 'Preview only, nothing saved.'));

        $this->assertSame(0, RfidScanLog::query()->count());
    }

    /**
     * The preview says what a crossing would record; nothing moves.
     */
    public function test_json_test_scan_shows_the_registered_vehicle_without_moving_it(): void
    {
        $user = User::factory()->create();
        [$vehicle] = $this->createAssignedVehicleWithTag('TOG-1001', 'RFID-TOGGLE-1001', Vehicle::STATE_OUTSIDE);

        $this->actingAs($user)
            ->postJson(route('rfid-scans.store'), [
                'tag_uid' => 'RFID-TOGGLE-1001',
                'scan_location' => 'gate-2',
                'reader_name' => 'Exit RFID Reader',
            ])
            ->assertOk()
            ->assertJsonPath('outcome', 'preview')
            ->assertJsonPath('vehicle.id', $vehicle->id)
            ->assertJsonPath('vehicle.plate_number', 'TOG-1001')
            ->assertJsonPath('action_taken', null)
            ->assertJsonPath('vehicle.current_state', Vehicle::STATE_OUTSIDE);

        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    /**
     * RFID only (no camera at the gate): the vehicle's state gives EXIT.
     */
    public function test_api_rfid_scan_at_exit_records_exit_for_inside_vehicle(): void
    {
        [$vehicle] = $this->createAssignedVehicleWithTag('TOG-2002', 'RFID-TOGGLE-2002', Vehicle::STATE_INSIDE);

        $this->withHeaders([
            'X-Api-Key' => 'test-detector-key',
            'X-Source-Name' => 'phpunit-rfid-reader',
        ])->postJson(route('api.integration.rfid-scans'), [
            'tag_uid' => 'RFID-TOGGLE-2002',
            'scan_location' => 'gate-2',
            'reader_name' => 'Exit RFID Reader',
        ])
            ->assertCreated()
            ->assertJsonPath('vehicle.id', $vehicle->id)
            ->assertJsonPath('action_taken', 'EXIT')
            ->assertJsonPath('new_state', Vehicle::STATE_OUTSIDE)
            ->assertJsonPath('vehicle.current_state', Vehicle::STATE_OUTSIDE);

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'current_state' => Vehicle::STATE_OUTSIDE,
        ]);

        $this->assertDatabaseHas('vehicle_events', [
            'vehicle_id' => $vehicle->id,
            'event_type' => 'EXIT',
            'resulting_state' => Vehicle::STATE_OUTSIDE,
        ]);
    }

    public function test_unknown_tag_is_flagged_for_registration_and_never_a_guest_record(): void
    {
        // Phase 3 (visitor model): an unknown tag no longer creates a guest
        // observation (one tag used to create a new record on every read).
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('rfid-scans.store'), [
                'tag_uid' => 'UNKNOWN-GUEST-1001',
                'scan_location' => 'gate-1',
                'reader_name' => 'Entrance RFID Reader',
            ])
            ->assertOk()
            ->assertJsonPath('scan.verification_status', 'unknown_tag')
            ->assertJsonPath('message', 'Preview only, nothing saved. Unknown tag UNKNOWN-GUEST-1001: not in the registry. Register this tag.');

        $this->assertSame(0, RfidScanLog::query()->count());
        $this->assertSame(0, GuestVehicleObservation::query()->count());
    }

    /**
     * @return array{Vehicle, RfidTag}
     */
    protected function createAssignedVehicleWithTag(string $plateNumber, string $tagUid, string $state): array
    {
        $vehicle = Vehicle::query()->create([
            'plate_number' => $plateNumber,
            'vehicle_owner_name' => 'Toggle Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);

        $vehicle->forceFill([
            'current_state' => $state,
        ])->save();

        $tag = RfidTag::query()->create([
            'uid' => $tagUid,
            'status' => RfidTag::STATUS_ASSIGNED,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now(),
        ]);

        $vehicle->forceFill([
            'rfid_tag_id' => $tag->id,
            'rfid_tag_uid' => $tag->uid,
        ])->save();

        return [$vehicle->fresh(), $tag->fresh()];
    }
}
