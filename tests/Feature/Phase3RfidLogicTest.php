<?php

namespace Tests\Feature;

use App\Models\GuestVisit;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\GuestPassService;
use App\Services\RfidIngestService;
use App\Services\RfidService;
use App\Services\VehicleOccupancyService;
use App\Support\RfidIngestResult;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 3: station-fixed direction, cooldown, anomalies, guest pass rules,
 * overstay, and the shared inside count.
 */
class Phase3RfidLogicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_entrance_records_entry_and_exit_records_exit(): void
    {
        $vehicle = $this->registeredVehicle('FIX 1001', 'FIX-TAG-1');

        $entry = $this->scan('FIX-TAG-1', 'entrance');
        $this->assertSame(RfidIngestResult::RECORDED, $entry->outcome);
        $this->assertSame('ENTRY', $entry->scanLog->resolved_event_type);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);

        $exit = $this->scan('FIX-TAG-1', 'exit');
        $this->assertSame(RfidIngestResult::RECORDED, $exit->outcome);
        $this->assertSame('EXIT', $exit->scanLog->resolved_event_type);
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
        $this->assertFalse($exit->scanLog->is_anomaly);
    }

    public function test_direction_mismatch_is_recorded_and_flagged(): void
    {
        $vehicle = $this->registeredVehicle('ANO 2002', 'ANO-TAG-2', Vehicle::STATE_INSIDE);

        $result = $this->scan('ANO-TAG-2', 'entrance');

        $this->assertSame(RfidIngestResult::ANOMALY, $result->outcome);
        $this->assertSame('ENTRY', $result->scanLog->resolved_event_type);
        $this->assertTrue($result->scanLog->is_anomaly);
        $this->assertStringContainsString('already inside', $result->scanLog->anomaly_reason);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);

        $event = VehicleEvent::query()->findOrFail($result->scanLog->correlated_vehicle_event_id);
        $this->assertSame('ENTRY', $event->event_type);
        $this->assertNotNull($event->anomaly_reason);

        $exitWhileOutside = $this->scan('ANO-TAG-2', 'exit');
        $this->assertFalse($exitWhileOutside->scanLog->is_anomaly);
        $this->travel(2)->minutes(); // past the 60-second cooldown
        $again = $this->scan('ANO-TAG-2', 'exit');
        $this->assertTrue($again->scanLog->is_anomaly);
        $this->assertStringContainsString('already outside', $again->scanLog->anomaly_reason);
    }

    public function test_rfid_desk_keeps_the_toggle(): void
    {
        $vehicle = $this->registeredVehicle('TGL 3003', 'TGL-TAG-3', Vehicle::STATE_INSIDE);

        $result = app(RfidService::class)->simulate(['tag_uid' => 'TGL-TAG-3', 'scan_location' => 'entrance']);

        $this->assertSame('EXIT', $result->scanLog->resolved_event_type);
        $this->assertFalse($result->scanLog->is_anomaly);
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
    }

    public function test_cooldown_ignores_repeat_reads_per_tag_and_station(): void
    {
        $this->registeredVehicle('CDN 4004', 'CDN-TAG-4');

        $this->scan('CDN-TAG-4', 'entrance');
        $this->travel(30)->seconds();
        $duplicate = $this->scan('CDN-TAG-4', 'entrance');

        $this->assertTrue($duplicate->isDuplicate());
        $this->assertSame(1, RfidScanLog::query()->where('tag_uid', 'CDN-TAG-4')->count());
        $this->assertSame(1, VehicleEvent::query()->where('plate_text', 'CDN 4004')->count());

        // Another station is not blocked by the entrance cooldown.
        $this->assertFalse($this->scan('CDN-TAG-4', 'exit')->isDuplicate());

        $this->travel(31)->seconds();
        $this->assertFalse($this->scan('CDN-TAG-4', 'entrance')->isDuplicate());
    }

    public function test_cooldown_is_configurable(): void
    {
        SystemSetting::query()->updateOrCreate(['setting_key' => 'rfid_cooldown_seconds'], ['setting_value' => '5']);
        $this->registeredVehicle('CFG 5005', 'CFG-TAG-5');

        $this->scan('CFG-TAG-5', 'entrance');
        $this->travel(6)->seconds();

        $this->assertFalse($this->scan('CFG-TAG-5', 'entrance')->isDuplicate());
    }

    public function test_guest_pass_issue_exit_and_available_again(): void
    {
        $pass = $this->guestPass('GP-FLOW-1');

        $atEntrance = $this->scan('GP-FLOW-1', 'entrance');
        $this->assertSame(RfidIngestResult::ISSUE_REQUIRED, $atEntrance->outcome);
        $this->assertSame(0, VehicleEvent::query()->count(), 'An available pass must not create an ENTRY by itself.');

        $visit = app(GuestPassService::class)->issue($pass, [
            'plate' => 'gst 101',
            'driver_name' => 'Juan Dela Cruz',
            'purpose' => 'Delivery',
            'id_presented' => "Driver's License",
        ], $this->admin()->id, $atEntrance->scanLog);

        $this->assertSame(RfidTag::STATUS_ISSUED, $pass->fresh()->status);
        $this->assertSame('GST 101', $visit->plate);
        $this->assertSame(1, app(VehicleOccupancyService::class)->counts()['guests']);
        $entryEvent = VehicleEvent::query()->where('guest_visit_id', $visit->id)->where('event_type', 'ENTRY')->firstOrFail();
        $this->assertSame('guest_pass', $entryEvent->event_origin);

        // A second read at the entrance (after the cooldown) is ignored.
        $this->travel(2)->minutes();
        $this->assertSame(RfidIngestResult::IGNORED, $this->scan('GP-FLOW-1', 'entrance')->outcome);

        $atExit = $this->scan('GP-FLOW-1', 'exit');
        $this->assertSame(RfidIngestResult::GUEST_PASS_EXIT, $atExit->outcome);

        $visit->refresh();
        $this->assertSame(GuestVisit::STATUS_COMPLETED, $visit->status);
        $this->assertNotNull($visit->exit_at);
        $this->assertNull($visit->active_rfid_tag_id);
        $this->assertSame(RfidTag::STATUS_AVAILABLE, $pass->fresh()->status);
        $this->assertSame(0, app(VehicleOccupancyService::class)->counts()['guests']);

        $exitEvent = VehicleEvent::query()->where('guest_visit_id', $visit->id)->where('event_type', 'EXIT')->firstOrFail();
        $this->assertSame($entryEvent->id, $exitEvent->matched_entry_id);

        // The same pass can be issued to the next guest as a new visit.
        $next = app(GuestPassService::class)->issue($pass->fresh(), ['plate' => 'GST 202', 'id_presented' => 'School ID']);
        $this->assertNotSame($visit->id, $next->id);
    }

    public function test_a_pass_that_is_already_issued_cannot_be_issued_again(): void
    {
        $pass = $this->guestPass('GP-DOUBLE-1');
        app(GuestPassService::class)->issue($pass, ['plate' => 'DBL 111', 'id_presented' => 'UMID']);

        $this->expectException(ValidationException::class);
        app(GuestPassService::class)->issue($pass->fresh(), ['plate' => 'DBL 222', 'id_presented' => 'UMID']);
    }

    public function test_issue_endpoint_rejects_double_issue(): void
    {
        $pass = $this->guestPass('GP-HTTP-1');
        $payload = ['plate' => 'HTP 101', 'id_presented' => 'UMID'];

        $this->actingAs($this->admin())
            ->postJson(route('guest-passes.issue', $pass), $payload)
            ->assertCreated()
            ->assertJsonPath('guest_visit.pass', 'G-01');

        $this->actingAs($this->admin())
            ->postJson(route('guest-passes.issue', $pass), $payload)
            ->assertUnprocessable();
    }

    public function test_required_id_is_enforced_when_enabled(): void
    {
        $pass = $this->guestPass('GP-ID-1');

        $this->expectException(ValidationException::class);
        app(GuestPassService::class)->issue($pass, ['plate' => 'NOID 1']);
    }

    public function test_available_pass_at_exit_is_an_anomaly(): void
    {
        $this->guestPass('GP-EXIT-1');

        $result = $this->scan('GP-EXIT-1', 'exit');

        $this->assertSame(RfidIngestResult::ANOMALY, $result->outcome);
        $this->assertTrue($result->scanLog->is_anomaly);
        $this->assertSame('guest_pass_not_issued', $result->scanLog->verification_status);
    }

    public function test_lost_and_disabled_passes_raise_alerts(): void
    {
        $this->guestPass('GP-LOST-1', RfidTag::STATUS_LOST);
        $this->guestPass('GP-OFF-1', RfidTag::STATUS_DISABLED);

        $lost = $this->scan('GP-LOST-1', 'entrance');
        $disabled = $this->scan('GP-OFF-1', 'exit');

        $this->assertSame(RfidIngestResult::ALERT, $lost->outcome);
        $this->assertTrue($lost->scanLog->is_anomaly);
        $this->assertSame(RfidIngestResult::ALERT, $disabled->outcome);
        $this->assertSame(0, GuestVisit::query()->count());
    }

    public function test_overstay_marks_visits_past_valid_until(): void
    {
        $pass = $this->guestPass('GP-OVER-1');
        $visit = app(GuestPassService::class)->issue($pass, [
            'plate' => 'OVR 999',
            'id_presented' => 'UMID',
            'valid_until' => now()->addMinutes(30)->toIso8601String(),
        ]);

        $this->assertSame(0, app(GuestPassService::class)->markOverstays());

        $this->travel(31)->minutes();
        $this->artisan('guests:mark-overstay')->assertSuccessful();

        $this->assertSame(GuestVisit::STATUS_OVERSTAY, $visit->fresh()->status);
        // An overstaying guest is still inside and can still exit normally.
        $this->assertSame(1, app(VehicleOccupancyService::class)->counts()['guests']);
        $this->assertSame(RfidIngestResult::GUEST_PASS_EXIT, $this->scan('GP-OVER-1', 'exit')->outcome);
        $this->assertSame(GuestVisit::STATUS_COMPLETED, $visit->fresh()->status);
    }

    public function test_inside_count_is_registered_inside_plus_open_guest_visits(): void
    {
        $this->registeredVehicle('INS 0001', 'INS-TAG-1');
        $this->registeredVehicle('INS 0002', 'INS-TAG-2');
        $this->scan('INS-TAG-1', 'entrance');
        $this->scan('INS-TAG-2', 'entrance');

        app(GuestPassService::class)->issue($this->guestPass('GP-INS-1'), ['plate' => 'GIN 1', 'id_presented' => 'UMID']);

        $counts = app(VehicleOccupancyService::class)->counts();
        $this->assertSame(['registered' => 2, 'guests' => 1, 'total' => 3], $counts);

        $this->actingAs($this->admin())
            ->getJson(route('dashboard.live-state'))
            ->assertJsonPath('metrics.vehicles_inside', 3);
    }

    public function test_station_endpoint_returns_issue_required_for_available_pass(): void
    {
        $this->guestPass('GP-STATION-1');

        $this->actingAs($this->admin())
            ->postJson(route('stations.rfid-scan', 'entrance'), ['tag_uid' => 'GP-STATION-1'])
            ->assertCreated()
            ->assertJsonPath('outcome', RfidIngestResult::ISSUE_REQUIRED)
            ->assertJsonPath('requires_issue', true)
            ->assertJsonPath('guest_pass.display_number', 'G-01');
    }

    protected function scan(string $uid, string $location, $at = null): RfidIngestResult
    {
        return app(RfidIngestService::class)->ingest(array_filter([
            'tag_uid' => $uid,
            'scan_location' => $location,
            'scan_time' => $at?->toIso8601String(),
        ]), 'station_reader');
    }

    protected function admin(): User
    {
        return User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    protected function guestPass(string $uid, string $status = RfidTag::STATUS_AVAILABLE): RfidTag
    {
        return RfidTag::query()->create([
            'uid' => $uid,
            'tag_type' => RfidTag::TYPE_GUEST_PASS,
            'status' => $status,
        ]);
    }

    protected function registeredVehicle(string $plate, string $uid, string $state = Vehicle::STATE_OUTSIDE): Vehicle
    {
        $vehicle = Vehicle::query()->create([
            'plate_number' => $plate,
            'vehicle_owner_name' => 'Phase Three Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);

        $tag = RfidTag::query()->create([
            'uid' => $uid,
            'status' => RfidTag::STATUS_ASSIGNED,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now(),
        ]);

        $vehicle->forceFill([
            'current_state' => $state,
            'rfid_tag_id' => $tag->id,
            'rfid_tag_uid' => $tag->uid,
        ])->save();

        return $vehicle;
    }
}
