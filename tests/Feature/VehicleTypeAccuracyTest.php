<?php

namespace Tests\Feature;

use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
use App\Models\VehicleTypeCorrection;
use App\Models\VisitorRecord;
use App\Services\SettingsService;
use App\Support\VehicleType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A2 (detection): three vehicle types, guards' type corrections are logged,
 * and `detection:accuracy` measures the camera on the live gates.
 */
class VehicleTypeAccuracyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    public function test_labels_become_three_types(): void
    {
        $this->assertSame([VehicleType::CAR, VehicleType::CAR, VehicleType::MOTORCYCLE, VehicleType::TRUCK_BUS, VehicleType::TRUCK_BUS, null],
            array_map([VehicleType::class, 'category'], ['Pickup Truck', 'SUV', 'tricycle', 'Bus', 'Truck/Bus', 'Others']));
    }

    public function test_a_guard_corrects_the_type_and_it_is_logged(): void
    {
        $record = $this->visitorRecord('Truck/Bus');
        $guard = User::query()->create(['name' => 'Guard', 'email' => 'guard.type@philcst.local', 'password' => bcrypt('password'), 'role' => 'guard']);

        $this->actingAs($guard)->get(route('visitors.index'))->assertOk()
            ->assertSee('data-visitor-action="type"', false)
            ->assertSee('id="visitor-type-modal"', false)
            ->assertSee('Sedan, hatchback, SUV, AUV, pickup, van');

        $this->actingAs($guard)->patch(route('visitors.records.type', $record), ['vehicle_type' => 'Car'])->assertRedirect();
        $this->actingAs($guard)->patch(route('visitors.records.type', $record), ['vehicle_type' => 'Bicycle'])->assertSessionHasErrors('vehicle_type');

        $correction = VehicleTypeCorrection::query()->sole();
        $this->assertSame(['Truck/Bus', 'Car', $guard->id, 'gate-1'], [$correction->detected_type, $correction->corrected_type, $correction->corrected_by, $correction->gate]);
        $this->assertSame('Car', $record->fresh()->vehicle_type);
        $this->assertSame('Truck/Bus', $record->crossing->fresh()->vehicle_type); // the camera's answer is kept
    }

    public function test_accuracy_report_uses_the_registry_and_guard_corrections(): void
    {
        $this->registeredCrossing('Car', 'Car');
        $this->registeredCrossing('Car', 'Truck/Bus');      // an AUV the camera called a truck
        $this->registeredCrossing('Truck', 'Truck/Bus');
        $wrong = $this->visitorRecord('Truck/Bus');
        $this->visitorRecord('Motorcycle');
        $this->actingAs($this->admin)->patch(route('visitors.records.type', $wrong), ['vehicle_type' => 'Car']);

        $this->artisan('detection:accuracy', ['--days' => 7])
            ->expectsOutputToContain('2 of 3 correct (66.7%)')
            ->expectsOutputToContain('1 of 2 camera record(s) corrected (50.0%)')
            ->expectsOutputToContain('2 car(s) saved as Truck/Bus, 0 Truck/Bus saved as Car.')
            ->assertSuccessful();
        $this->artisan('detection:accuracy', ['--gate' => 'gate-9'])->assertFailed();
    }

    public function test_type_settings_reach_the_detector(): void
    {
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'cameras']))->assertOk()
            ->assertSee('Vehicle type')->assertSee('perf_type_truck_min_height', false);

        $performance = app(SettingsService::class)->performanceSettings(['perf_type_truck_min_height' => '60', 'perf_type_car_min_aspect' => '1.4']);
        $this->assertSame([0.6, 1.4, 'yolov8s.pt', 1], [$performance['type_truck_min_height'], $performance['type_car_min_aspect'], $performance['type_model'], $performance['type_second_pass']]);
        // A3 (detection): counting limits, as shares of the zone's height.
        $counting = app(SettingsService::class)->performanceSettings(['perf_cross_margin' => '8', 'perf_cross_min_points' => '4', 'perf_cross_min_move' => '12']);
        $this->assertSame([0.08, 4, 0.12], [$counting['cross_margin'], $counting['cross_min_points'], $counting['cross_min_move']]);
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'cameras']))->assertOk()
            ->assertSee('Counting')->assertSee('perf_cross_margin', false);
    }

    protected function visitorRecord(string $detected): VisitorRecord
    {
        $crossing = $this->crossing($detected);

        return VisitorRecord::query()->create([
            'external_event_key' => $crossing->external_event_key, 'vehicle_crossing_id' => $crossing->id, 'gate' => 'gate-1',
            'direction' => 'IN', 'seen_at' => now(), 'status' => 'active', 'plate_status' => 'unreadable', 'vehicle_type' => $detected,
        ]);
    }

    protected function registeredCrossing(string $registryType, string $detected): VehicleCrossing
    {
        static $n = 0;
        $n++;
        $vehicle = Vehicle::query()->create(['plate_number' => 'ACC '.$n, 'vehicle_owner_name' => 'Owner', 'category' => 'faculty_staff', 'vehicle_type' => $registryType]);
        $tag = RfidTag::query()->create(['uid' => 'ACC-TAG-'.$n, 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $scan = RfidScanLog::query()->create([
            'tag_uid' => $tag->uid, 'vehicle_id' => $vehicle->id, 'scan_location' => 'gate-1', 'scan_direction' => 'entry',
            'scan_time' => now(), 'verification_status' => 'verified', 'source_mode' => 'hardware_placeholder',
        ]);
        $crossing = $this->crossing($detected);
        $crossing->forceFill(['rfid_scan_log_id' => $scan->id])->save();

        return $crossing;
    }

    protected function crossing(string $detected): VehicleCrossing
    {
        return VehicleCrossing::query()->create([
            'gate' => 'gate-1', 'direction' => 'IN', 'crossed_at' => now(), 'vehicle_type' => $detected,
            'external_event_key' => 'acc-'.uniqid(), 'detection_metadata_json' => [],
        ]);
    }
}
