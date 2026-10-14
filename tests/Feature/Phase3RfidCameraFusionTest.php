<?php

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Services\RfidIngestService;
use App\Support\CameraFiles;
use App\Support\RfidIngestResult;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 3 (visitor model): RFID + camera fusion.
 */
class Phase3RfidCameraFusionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->freezeTime();
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    protected function tearDown(): void
    {
        File::delete(CameraFiles::statusPath());

        parent::tearDown();
    }

    public function test_camera_direction_with_lookback_sets_the_state_in_and_out_at_the_same_gate(): void
    {
        $this->cameraOnline();
        $vehicle = $this->registeredVehicle('FUS 1001', 'FUS-TAG-1');

        // The reader reads the tag on approach; nothing is saved until the
        // camera sees the crossing (RFID only with a vehicle).
        $read = $this->scan('FUS-TAG-1');
        $this->assertSame(RfidIngestResult::BUFFERED, $read->outcome);
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
        $this->assertSame([0, 0], [VehicleEvent::query()->count(), RfidScanLog::query()->count()]);

        $this->travel(8)->seconds(); // inside the 10 s lookback
        $this->cameraOnline();
        $this->crossing('IN', 'k-in')->assertCreated()->assertJsonPath('rfid_scan.event_type', 'ENTRY');

        $scan = RfidScanLog::query()->sole();
        $crossing = VehicleCrossing::query()->where('external_event_key', 'k-in')->sole();
        $this->assertSame(['ENTRY', RfidScanLog::FUSION_CAMERA, $crossing->id, false], [$scan->resolved_event_type, $scan->fusion_status, $scan->vehicle_crossing_id, $scan->is_anomaly]);
        $this->assertSame($scan->id, $crossing->rfid_scan_log_id);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
        $event = VehicleEvent::query()->sole();
        $this->assertSame('ENTRY', $event->event_type);
        $this->assertSame($crossing->crossed_at->toDateTimeString(), $event->event_time->toDateTimeString());

        // Later the same vehicle leaves through the same gate.
        $this->travel(2)->minutes();
        $this->cameraOnline();
        $this->scan('FUS-TAG-1');
        $this->travel(3)->seconds();
        $this->crossing('OUT', 'k-out')->assertCreated()->assertJsonPath('rfid_scan.event_type', 'EXIT');
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
    }

    public function test_crossing_that_arrives_first_is_used_when_the_tag_is_read_within_2_s(): void
    {
        $this->cameraOnline();
        $vehicle = $this->registeredVehicle('FUS 2002', 'FUS-TAG-2');

        $this->crossing('IN', 'k-first');
        $this->travel(1)->seconds(); // the RFID check after a crossing lasts 2 s
        $this->cameraOnline();

        $read = $this->scan('FUS-TAG-2');
        $this->assertSame([RfidIngestResult::RECORDED, 'ENTRY', RfidScanLog::FUSION_CAMERA], [$read->outcome, $read->scanLog->resolved_event_type, $read->scanLog->fusion_status]);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
    }

    public function test_a_read_outside_the_window_is_not_linked_and_the_lookback_is_configurable(): void
    {
        SystemSetting::query()->updateOrCreate(['setting_key' => 'rfid_lookback_seconds'], ['setting_value' => '3']);
        $this->cameraOnline();
        $this->registeredVehicle('FUS 3003', 'FUS-TAG-3');

        $this->scan('FUS-TAG-3');
        $this->travel(5)->seconds(); // more than 3 s before the crossing
        $this->cameraOnline();
        $this->crossing('IN', 'k-late')->assertCreated()->assertJsonPath('rfid_scan', null);
        $this->assertSame(0, RfidScanLog::query()->count());

        // The detector gets the window lengths from the export.
        app(\App\Services\SettingsService::class)->exportCameraRuntimeConfig();
        $config = json_decode(File::get(CameraFiles::path('camera_runtime_config.json')), true);
        $this->assertSame([2, 3], [$config['cameras']['gate-1']['rfid_window_seconds'], $config['cameras']['gate-1']['rfid_lookback_seconds']]);
    }

    public function test_direction_that_does_not_fit_the_state_is_recorded_and_flagged(): void
    {
        $this->cameraOnline();
        $vehicle = $this->registeredVehicle('ANO 4004', 'ANO-TAG-4', Vehicle::STATE_INSIDE);

        $this->scan('ANO-TAG-4');
        $this->crossing('IN', 'k-anomaly');

        $scan = RfidScanLog::query()->sole();
        $this->assertSame(['ENTRY', RfidIngestResult::ANOMALY, true], [$scan->resolved_event_type, $scan->outcome, $scan->is_anomaly]);
        $this->assertStringContainsString('already inside', $scan->anomaly_reason);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
        $this->assertNotNull(VehicleEvent::query()->sole()->anomaly_reason);
    }

    public function test_unknown_camera_direction_of_a_registered_vehicle_uses_its_state(): void
    {
        // Gate direction work, Phase A: the camera saw it but could not tell:
        // the vehicle's state decides (Inside -> OUT), "batay sa state".
        $this->cameraOnline();
        $vehicle = $this->registeredVehicle('UNK 5005', 'UNK-TAG-5', Vehicle::STATE_INSIDE);

        $this->scan('UNK-TAG-5');
        $this->crossing('UNKNOWN', 'k-unknown', 'track too short');

        $scan = RfidScanLog::query()->sole();
        $this->assertSame(['EXIT', RfidScanLog::FUSION_TOGGLE, RfidIngestResult::RECORDED, false],
            [$scan->resolved_event_type, $scan->fusion_status, $scan->outcome, $scan->is_anomaly]);
        $this->assertStringContainsString('could not tell IN from OUT (track too short); vehicle state used (Inside -> OUT)', $scan->fusion_note);
        $this->assertSame([Vehicle::STATE_OUTSIDE, 0, 1], [$vehicle->fresh()->current_state, (int) $vehicle->fresh()->entries_today_count, (int) $vehicle->fresh()->exits_today_count]);
        $event = VehicleEvent::query()->sole();
        $this->assertSame(['EXIT', 'OUT', 'state', null], [$event->event_type, $event->direction, $event->direction_source, $event->direction_confidence]);
        // Counted, and not waiting in Review.
        $counts = app(\App\Services\MovementCountService::class)->counts('today');
        $this->assertSame([0, 1, 0], [$counts['in'], $counts['out'], $counts['unknown']]);
        $this->actingAs($this->admin)->get(route('visitors.index', ['tab' => 'review']))->assertOk()->assertSee('Nothing to review');

        // Outside -> IN.
        $other = $this->registeredVehicle('UNK 5006', 'UNK-TAG-6', Vehicle::STATE_OUTSIDE);
        $this->travel(1)->minutes();
        $this->scan('UNK-TAG-6');
        $this->crossing('UNKNOWN', 'k-unknown-2', 'gate direction not set');
        $this->assertSame(['ENTRY', 'state'], [VehicleEvent::query()->where('vehicle_id', $other->id)->sole()->event_type, VehicleEvent::query()->where('vehicle_id', $other->id)->sole()->direction_source]);
        $this->assertSame(Vehicle::STATE_INSIDE, $other->fresh()->current_state);
    }

    /**
     * A registered "Direction unknown" record saved before Phase A (the
     * state was not used then): Set IN / Set OUT still works on it.
     */
    protected function olderUnknownRecord(Vehicle $vehicle): VehicleEvent
    {
        $event = VehicleEvent::query()->sole();
        $event->forceFill(['event_type' => VehicleEvent::TYPE_UNKNOWN, 'direction' => 'UNKNOWN', 'resulting_state' => Vehicle::STATE_INSIDE, 'anomaly_reason' => null])->save();
        RfidScanLog::query()->sole()->forceFill([
            'resolved_event_type' => VehicleEvent::TYPE_UNKNOWN, 'scan_direction' => null, 'resulting_state' => Vehicle::STATE_INSIDE,
            'fusion_status' => RfidScanLog::FUSION_DIRECTION_UNKNOWN, 'is_anomaly' => false, 'anomaly_reason' => null,
        ])->save();
        Vehicle::query()->whereKey($vehicle->id)->update(['current_state' => Vehicle::STATE_INSIDE, 'entries_today_count' => 0, 'exits_today_count' => 0]);

        return $event->fresh();
    }

    public function test_set_out_on_a_registered_unknown_record_moves_the_vehicle_and_is_logged(): void
    {
        $this->cameraOnline();
        $vehicle = $this->registeredVehicle('SET 7007', 'SET-TAG-7', Vehicle::STATE_INSIDE);
        $this->scan('SET-TAG-7');
        $this->crossing('UNKNOWN', 'k-set', 'went up 9% of the zone');
        $event = $this->olderUnknownRecord($vehicle);

        // In Visitors › Review (guards too) and Activity Logs.
        $this->actingAs($this->admin)->get(route('visitors.index', ['tab' => 'review']))
            ->assertOk()->assertSee('SET 7007')->assertSee('Review (1)')->assertSee(route('vehicle-events.direction', $event));
        $this->actingAs($this->admin)->get(route('logs.index'))->assertOk()->assertSee(route('vehicle-events.direction', $event));

        $this->actingAs($this->admin)->patch(route('vehicle-events.direction', $event), ['direction' => 'OUT'])
            ->assertSessionHas('status', 'SET 7007 set to OUT.');

        $event->refresh();
        $this->assertSame(['EXIT', 'OUT', 'manual'], [$event->event_type, $event->direction, $event->direction_source]);
        $this->assertSame([Vehicle::STATE_OUTSIDE, 1], [$vehicle->fresh()->current_state, (int) $vehicle->fresh()->exits_today_count]);
        $scan = RfidScanLog::query()->sole();
        $this->assertSame(['EXIT', RfidScanLog::FUSION_SET_BY_STAFF, false], [$scan->resolved_event_type, $scan->fusion_status, $scan->is_anomaly]);
        $this->assertSame('OUT', VehicleCrossing::query()->sole()->direction);
        $correction = \App\Models\DirectionCorrection::query()->sole();
        $this->assertSame(['UNKNOWN', 'OUT', 'went up 9% of the zone', $this->admin->id], [$correction->detected_direction, $correction->corrected_direction, $correction->detected_reason, $correction->corrected_by]);
        $this->assertSame(1, app(\App\Services\MovementCountService::class)->counts('today')['out']);

        // Only once: it has a direction now.
        $this->actingAs($this->admin)->patch(route('vehicle-events.direction', $event), ['direction' => 'IN'])->assertSessionHasErrors('direction');
        $this->actingAs($this->admin)->get(route('visitors.index', ['tab' => 'review']))->assertOk()->assertSee('Nothing to review');
    }

    public function test_set_in_while_already_inside_is_flagged_like_a_camera_anomaly(): void
    {
        $this->cameraOnline();
        $vehicle = $this->registeredVehicle('ANO 8008', 'ANO-TAG-8', Vehicle::STATE_INSIDE);
        $this->scan('ANO-TAG-8');
        $this->crossing('UNKNOWN', 'k-ano');
        $this->olderUnknownRecord($vehicle);

        $this->actingAs($this->admin)->patch(route('vehicle-events.direction', VehicleEvent::query()->sole()), ['direction' => 'IN']);

        $this->assertStringContainsString('already inside', (string) VehicleEvent::query()->sole()->anomaly_reason);
        $this->assertTrue(RfidScanLog::query()->sole()->is_anomaly);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
    }

    public function test_an_unknown_unregistered_visitor_is_set_from_the_review_tab_by_a_guard(): void
    {
        $this->cameraOnline();
        $guard = User::query()->where('role', '!=', 'admin')->first()
            ?? User::factory()->create(['role' => 'guard']);
        $this->crossing('UNKNOWN', 'k-visitor')->assertCreated();
        // The detector says there was no tag (its RFID window ran out).
        $crossing = VehicleCrossing::query()->sole();
        $crossing->forceFill(['detection_metadata_json' => ['rfid_status' => 'no_pass']])->save();
        $record = app(\App\Services\VisitorRecordService::class)->createFromCrossing($crossing->fresh());
        $this->assertSame('UNKNOWN', $record->direction);

        $this->actingAs($guard)->get(route('visitors.index', ['tab' => 'review']))->assertOk()->assertSee('Unregistered Visitor');
        $this->actingAs($guard)->patch(route('visitors.records.direction', $record), ['direction' => 'IN'])
            ->assertSessionHas('status', "Record #{$record->id} set to IN.");

        $this->assertSame('IN', $record->fresh()->direction);
        $this->assertSame('IN', $crossing->fresh()->direction);
        $this->assertSame(1, app(\App\Services\MovementCountService::class)->counts('today')['in']);
        $this->assertSame($guard->id, \App\Models\DirectionCorrection::query()->sole()->corrected_by);
    }

    public function test_no_camera_or_camera_offline_uses_the_vehicle_state_at_once(): void
    {
        $vehicle = $this->registeredVehicle('TGL 6006', 'TGL-TAG-6');

        // No detector status at all: RFID only.
        $read = $this->scan('TGL-TAG-6');
        $this->assertSame([RfidIngestResult::RECORDED, 'ENTRY', RfidScanLog::FUSION_TOGGLE], [$read->outcome, $read->scanLog->resolved_event_type, $read->scanLog->fusion_status]);
        $this->assertStringContainsString('RFID only (detector not running)', $read->scanLog->fusion_note);

        // Detector running, this gate's camera offline (since when unknown).
        $this->travel(2)->minutes();
        $this->cameraOnline(running: false);
        $read = $this->scan('TGL-TAG-6');
        $this->assertSame(['EXIT', "RFID only (camera offline): IN/OUT from the vehicle's state."], [$read->scanLog->resolved_event_type, $read->scanLog->fusion_note]);
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
    }

    public function test_read_without_a_crossing_is_not_recorded_and_moves_nothing(): void
    {
        $this->cameraOnline();
        $vehicle = $this->registeredVehicle('SCN 7007', 'SCN-TAG-7');
        $this->scan('SCN-TAG-7');

        $this->travel(21)->seconds(); // lookback 10 + lookahead 2 + delivery 6 (+2 to spare)
        $this->cameraOnline();
        $this->actingAs($this->admin)->getJson(route('stations.state', 'gate-1'))->assertOk()->assertJsonMissing(['plate_number' => 'SCN 7007']);

        $this->assertSame(0, RfidScanLog::query()->count());
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
        $this->assertSame(0, VehicleEvent::query()->count());

        // The camera going offline later does not record it either.
        $this->cameraOnline(running: false);
        $this->artisan('rfid:finalize-pending')->assertSuccessful();
        $this->assertSame(0, RfidScanLog::query()->count());
    }

    public function test_the_detector_match_decides_which_read_a_crossing_belongs_to(): void
    {
        $this->cameraOnline();
        $this->registeredVehicle('TWO 0001', 'TWO-TAG-1');
        $this->registeredVehicle('TWO 0002', 'TWO-TAG-2');
        $this->scan('TWO-TAG-1');
        $this->travel(2)->seconds();
        $this->cameraOnline();
        $this->scan('TWO-TAG-2');

        // Two cars, two detector windows more than 3 s apart (closer than that
        // counts as the same car with a new track ID). Car B's window claims
        // the newest read (TWO-TAG-2); car A's window then claims TWO-TAG-1.
        $this->matchFor('k-car-b', 'TWO 0002');
        $this->travel(4)->seconds();
        $this->cameraOnline();
        $this->matchFor('k-car-a', 'TWO 0001');

        // Car A's crossing takes its claimed read, although TWO-TAG-2 is closer in time.
        $this->crossing('IN', 'k-car-a')->assertJsonPath('rfid_scan.plate_number', 'TWO 0001');
        $this->crossing('IN', 'k-car-b')->assertJsonPath('rfid_scan.plate_number', 'TWO 0002');
        $this->assertSame(2, RfidScanLog::query()->where('resolved_event_type', 'ENTRY')->count());
    }

    public function test_unknown_tag_without_a_vehicle_is_not_recorded_and_no_visitor_record(): void
    {
        $this->cameraOnline();
        $first = $this->scan('E280689400004031D6456CE8');
        $this->assertSame(RfidIngestResult::BUFFERED, $first->outcome);
        $this->travel(20)->seconds();
        $this->cameraOnline();
        $this->scan('E280689400004031D6456CE8');

        $this->assertSame(0, RfidScanLog::query()->count());
        $this->assertSame(0, GuestVehicleObservation::query()->count());
        $this->assertSame(0, VehicleEvent::query()->count());

        // Register this tag still opens the prefilled form.
        $registerUrl = route('registry.index', ['tab' => 'vehicles', 'register_tag' => 'E280689400004031D6456CE8']);
        $this->actingAs($this->admin)->get($registerUrl)
            ->assertOk()->assertSee('value="E280689400004031D6456CE8"', false)->assertSee('data-prefill="1"', false);
    }

    public function test_cooldown_has_a_10_second_minimum_and_the_old_zero_becomes_60(): void
    {
        $this->actingAs($this->admin)->put(route('settings.update'), [
            'section' => 'stations', 'rfid_cooldown_seconds' => 5,
        ])->assertSessionHasErrors('rfid_cooldown_seconds');

        $migration = require database_path('migrations/2026_10_01_000004_rfid_camera_fusion.php');
        $migration->down();
        SystemSetting::query()->updateOrCreate(['setting_key' => 'rfid_cooldown_seconds'], ['setting_value' => '0']);
        Gate::query()->where('code', 'gate-2')->update(['reader_type' => 'nfc', 'reader_name' => 'Gate 2 NFC Reader']);
        $migration->up();

        $this->assertSame('60', SystemSetting::query()->where('setting_key', 'rfid_cooldown_seconds')->value('setting_value'));
        // UHF readers only: an NFC gate became a UHF gate.
        $this->assertSame(['uhf_ethernet', 'Gate 2 UHF Reader'], [Gate::query()->where('code', 'gate-2')->value('reader_type'), Gate::query()->where('code', 'gate-2')->value('reader_name')]);
        $this->assertArrayNotHasKey('nfc', Gate::READER_TYPES);
    }

    protected function cameraOnline(bool $running = true, string $gate = 'gate-1'): void
    {
        File::ensureDirectoryExists(dirname(CameraFiles::statusPath()));
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'cameras' => [$gate => ['camera_role' => $gate, 'camera_running' => $running, 'calibration_ready' => true]],
        ]));
    }

    protected function scan(string $uid): RfidIngestResult
    {
        return app(RfidIngestService::class)->ingest(['tag_uid' => $uid, 'scan_location' => 'gate-1'], 'hardware_placeholder');
    }

    protected function crossing(string $direction, string $key, ?string $reason = null): TestResponse
    {
        return $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->postJson(route('api.integration.crossings'), array_filter([
            'external_event_key' => $key,
            'camera_role' => 'gate-1',
            'direction' => $direction,
            'direction_reason' => $reason,
            'event_time' => now()->toIso8601String(),
            'track_id' => 1,
            'confidence' => 0.9,
        ]));
    }

    protected function matchFor(string $eventKey, string $expectedPlate): void
    {
        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])
            ->getJson(route('api.integration.rfid-match', ['camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(), 'event_key' => $eventKey]))
            ->assertOk()
            ->assertJsonPath('vehicle.plate_number', $expectedPlate);
    }

    protected function registeredVehicle(string $plate, string $uid, string $state = Vehicle::STATE_OUTSIDE): Vehicle
    {
        $vehicle = Vehicle::query()->create([
            'plate_number' => $plate, 'vehicle_owner_name' => 'Fusion Owner', 'category' => 'faculty_staff', 'vehicle_type' => 'Car',
        ]);
        $vehicle->forceFill(['current_state' => $state])->save();
        $tag = RfidTag::query()->create(['uid' => $uid, 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        return $vehicle;
    }
}
