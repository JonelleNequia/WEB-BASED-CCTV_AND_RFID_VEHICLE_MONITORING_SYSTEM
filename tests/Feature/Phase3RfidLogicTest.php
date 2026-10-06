<?php

namespace Tests\Feature;

use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Services\RfidIngestService;
use App\Services\RfidService;
use App\Services\VehicleOccupancyService;
use App\Support\RfidIngestResult;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 3: station-fixed direction, cooldown, anomalies and the shared
 * inside count. (Guest pass rules were removed in Phase 0.)
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

        $entry = $this->scan('FIX-TAG-1', 'gate-1');
        $this->assertSame(RfidIngestResult::RECORDED, $entry->outcome);
        $this->assertSame('ENTRY', $entry->scanLog->resolved_event_type);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);

        $exit = $this->scan('FIX-TAG-1', 'gate-2');
        $this->assertSame(RfidIngestResult::RECORDED, $exit->outcome);
        $this->assertSame('EXIT', $exit->scanLog->resolved_event_type);
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
        $this->assertFalse($exit->scanLog->is_anomaly);
    }

    public function test_every_gate_records_in_and_out(): void
    {
        // Phase 1 (gates): no fixed direction per gate. A vehicle inside that
        // passes Gate 1 is going OUT; no "already inside" anomaly any more.
        $vehicle = $this->registeredVehicle('ANO 2002', 'ANO-TAG-2', Vehicle::STATE_INSIDE);

        $out = $this->scan('ANO-TAG-2', 'gate-1');
        $this->assertSame(RfidIngestResult::RECORDED, $out->outcome);
        $this->assertSame('EXIT', $out->scanLog->resolved_event_type);
        $this->assertSame('exit', $out->scanLog->scan_direction);
        $this->assertFalse($out->scanLog->is_anomaly);
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
        $this->assertSame('EXIT', VehicleEvent::query()->findOrFail($out->scanLog->correlated_vehicle_event_id)->event_type);

        $this->travel(2)->minutes(); // past the 60-second cooldown
        $in = $this->scan('ANO-TAG-2', 'gate-1');
        $this->assertSame('ENTRY', $in->scanLog->resolved_event_type);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
    }

    public function test_test_scan_previews_the_toggle_without_moving_the_vehicle(): void
    {
        $vehicle = $this->registeredVehicle('TGL 3003', 'TGL-TAG-3', Vehicle::STATE_INSIDE);

        $result = app(RfidService::class)->simulate(['tag_uid' => 'TGL-TAG-3', 'scan_location' => 'gate-1']);

        $this->assertSame([\App\Support\RfidIngestResult::PREVIEW, false], [$result->outcome, $result->isSaved()]);
        $this->assertStringContainsString('Registered tag: TGL 3003. It is recorded (OUT', $result->message);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
    }

    public function test_cooldown_ignores_repeat_reads_per_tag_and_station(): void
    {
        $this->registeredVehicle('CDN 4004', 'CDN-TAG-4');

        $this->scan('CDN-TAG-4', 'gate-1');
        $this->travel(30)->seconds();
        $duplicate = $this->scan('CDN-TAG-4', 'gate-1');

        $this->assertTrue($duplicate->isDuplicate());
        $this->assertSame(1, RfidScanLog::query()->where('tag_uid', 'CDN-TAG-4')->count());
        $this->assertSame(1, VehicleEvent::query()->where('plate_text', 'CDN 4004')->count());

        // Another station is not blocked by the entrance cooldown.
        $this->assertFalse($this->scan('CDN-TAG-4', 'gate-2')->isDuplicate());

        $this->travel(31)->seconds();
        $this->assertFalse($this->scan('CDN-TAG-4', 'gate-1')->isDuplicate());
    }

    public function test_cooldown_is_configurable_with_a_10_second_minimum(): void
    {
        // Phase 3 (visitor model): below 10 s counts as 10 s.
        SystemSetting::query()->updateOrCreate(['setting_key' => 'rfid_cooldown_seconds'], ['setting_value' => '5']);
        $this->registeredVehicle('CFG 5005', 'CFG-TAG-5');

        $this->scan('CFG-TAG-5', 'gate-1');
        $this->travel(6)->seconds();
        $this->assertTrue($this->scan('CFG-TAG-5', 'gate-1')->isDuplicate());

        $this->travel(5)->seconds();
        $this->assertFalse($this->scan('CFG-TAG-5', 'gate-1')->isDuplicate());
    }

    public function test_inside_count_is_registered_vehicles_only(): void
    {
        $this->registeredVehicle('INS 0001', 'INS-TAG-1');
        $this->registeredVehicle('INS 0002', 'INS-TAG-2');
        $this->scan('INS-TAG-1', 'gate-1');
        $this->scan('INS-TAG-2', 'gate-1');

        // Phase 0: guest passes are gone; vehicles without a tag never count as inside.
        $counts = app(VehicleOccupancyService::class)->counts();
        $this->assertSame(['registered' => 2, 'total' => 2], $counts);

        $this->actingAs($this->admin())
            ->getJson(route('dashboard.live-state'))
            ->assertJsonPath('metrics.vehicles_inside', 2);
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
