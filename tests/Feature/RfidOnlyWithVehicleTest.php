<?php

namespace Tests\Feature;

use App\Models\EventReceiveLog;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\RfidIngestService;
use App\Services\RfidTagMatcher;
use App\Support\CameraFiles;
use App\Support\DeviceFiles;
use App\Support\RfidIngestResult;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * RFID only with a vehicle: a tag read is recorded only when the camera sees
 * a vehicle cross the line (or "RFID only" while the camera is offline).
 */
class RfidOnlyWithVehicleTest extends TestCase
{
    use RefreshDatabase;

    protected const UNKNOWN = 'E280689400004031D6456CE8';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        File::deleteDirectory(DeviceFiles::directory());
        // Buffer times are seconds with decimals: keep them exact.
        $this->freezeTime();
    }

    protected function tearDown(): void
    {
        File::delete(CameraFiles::statusPath());
        File::deleteDirectory(DeviceFiles::directory());

        parent::tearDown();
    }

    public function test_a_read_without_a_vehicle_is_not_recorded(): void
    {
        $this->cameraOnline();
        $vehicle = $this->registeredVehicle('BUF 1001', 'BUF-TAG-1');

        $this->postRead('BUF-TAG-1')->assertCreated()
            ->assertJsonPath('outcome', RfidIngestResult::BUFFERED)
            ->assertJsonPath('saved', false);
        $this->postRead(self::UNKNOWN)->assertJsonPath('outcome', RfidIngestResult::BUFFERED);

        // Kiosk USB reader too.
        $this->actingAs($this->admin)->postJson(route('stations.rfid-scan', 'gate-1'), ['tag_uid' => 'BUF-TAG-1'])
            ->assertCreated()->assertJsonPath('outcome', RfidIngestResult::BUFFERED);

        $this->assertSame([0, 0, 0, 0], [RfidScanLog::query()->count(), VehicleEvent::query()->count(), VisitorRecord::query()->count(), EventReceiveLog::query()->where('status', 'ingested')->count()]);
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);

        // Nobody crossed: the reads expire; a vehicle much later gets no tag.
        $this->travel(2)->minutes();
        $this->cameraOnline();
        $this->crossing('IN', 'k-later', 'no_pass')->assertCreated()->assertJsonPath('rfid_scan', null);
        $this->assertSame([0, 1], [RfidScanLog::query()->count(), VisitorRecord::query()->count()]);
        $this->assertNull(VisitorRecord::query()->sole()->tag_uid);
    }

    public function test_crossing_with_a_registered_tag_makes_one_registered_record(): void
    {
        $this->cameraOnline();
        $vehicle = $this->registeredVehicle('REG 2002', 'REG-TAG-2');
        $this->buffer([$this->presence('REG-TAG-2', firstAgo: 6, lastAgo: 0.5, peakAgo: 1, reads: 42, rssi: -48)]);

        $this->crossing('IN', 'k-reg', 'matched')->assertCreated()
            ->assertJsonPath('rfid_scan.event_type', 'ENTRY')
            ->assertJsonPath('rfid_scan.plate_number', 'REG 2002')
            ->assertJsonPath('visitor_record_id', null);

        $scan = RfidScanLog::query()->sole();
        $crossing = VehicleCrossing::query()->sole();
        $this->assertSame(['verified', RfidScanLog::FUSION_CAMERA, $crossing->id, 'rfid_buffer'], [$scan->verification_status, $scan->fusion_status, $scan->vehicle_crossing_id, $scan->payload_json['extra_payload']['source']]);
        $this->assertSame(42, $scan->payload_json['extra_payload']['reads']);
        $this->assertSame($scan->id, $crossing->rfid_scan_log_id);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
        $this->assertSame(1, VehicleEvent::query()->count());
        $this->assertSame(0, VisitorRecord::query()->count());
    }

    public function test_crossing_without_a_tag_makes_one_unregistered_visitor(): void
    {
        $this->cameraOnline();
        $this->buffer([]);

        $this->crossing('OUT', 'k-none', 'no_pass')->assertCreated()->assertJsonPath('rfid_scan', null);

        $this->assertSame([0, 1], [RfidScanLog::query()->count(), VisitorRecord::query()->count()]);
        $this->assertSame('OUT', VisitorRecord::query()->sole()->direction);
    }

    public function test_unknown_tag_goes_on_the_visitor_record_with_register_this_tag(): void
    {
        $this->cameraOnline();
        $this->buffer([$this->presence(self::UNKNOWN, firstAgo: 4, lastAgo: 1, peakAgo: 2)]);

        $this->crossing('IN', 'k-unknown', 'no_pass')->assertCreated()->assertJsonPath('unknown_tag', self::UNKNOWN);

        $record = VisitorRecord::query()->sole();
        $this->assertSame(self::UNKNOWN, $record->tag_uid);
        $this->assertSame(0, RfidScanLog::query()->count());

        $registerUrl = route('registry.index', ['tab' => 'vehicles', 'register_tag' => self::UNKNOWN]);
        $this->actingAs($this->admin)->get(route('visitors.index'))
            ->assertOk()->assertSee('Unknown tag '.self::UNKNOWN)->assertSee('Register this tag')->assertSee(e($registerUrl), false);
        $this->actingAs($this->admin)->get(route('dashboard.index'))->assertOk()->assertSee('Register this tag');
    }

    public function test_repeated_reads_make_one_record(): void
    {
        $this->cameraOnline();
        $this->registeredVehicle('REP 3003', 'REP-TAG-3');

        // The reader posts one event per cooldown; the kiosk may send many.
        foreach (range(1, 5) as $ignored) {
            $this->postRead('REP-TAG-3');
        }
        $this->buffer([$this->presence('REP-TAG-3', firstAgo: 8, lastAgo: 0.2, peakAgo: 1, reads: 120)]);

        $this->crossing('IN', 'k-rep-1', 'matched')->assertCreated();
        // The same car with a new track ID right after: no second record.
        $this->crossing('IN', 'k-rep-2', 'matched')->assertCreated()->assertJsonPath('rfid_scan', null);

        $this->assertSame(1, RfidScanLog::query()->count());
        $this->assertSame(1, VehicleEvent::query()->count());
        $this->assertSame(0, VisitorRecord::query()->count());
    }

    public function test_a_parked_tag_is_not_given_to_a_passing_vehicle(): void
    {
        $this->cameraOnline();
        $parked = $this->registeredVehicle('PRK 4004', 'PRK-TAG-4', Vehicle::STATE_INSIDE);
        $this->buffer([$this->presence('PRK-TAG-4', firstAgo: 300, lastAgo: 0.3, peakAgo: 2, reads: 900, stationary: true)]);

        $this->crossing('OUT', 'k-pass', 'no_pass')->assertCreated()->assertJsonPath('rfid_scan', null);

        $this->assertSame(0, RfidScanLog::query()->count());
        $this->assertSame(1, VisitorRecord::query()->count());
        $this->assertSame(Vehicle::STATE_INSIDE, $parked->fresh()->current_state);

        // Reads that reached Laravel (no device-service buffer) also become parked.
        File::delete(DeviceFiles::rfidBufferPath());
        $matcher = app(RfidTagMatcher::class);
        foreach (range(0, 69, 3) as $second) {
            $matcher->addRead('gate-1', 'PRK-TAG-4', now()->subSeconds(70 - $second));
        }
        $this->assertNull($matcher->pick('gate-1', now()));

        // The stationary time is a setting and reaches the device program.
        SystemSetting::query()->updateOrCreate(['setting_key' => 'rfid_stationary_seconds'], ['setting_value' => '90']);
        app(\App\Services\DeviceRegistryService::class)->exportRuntimeConfig();
        $this->assertSame(90, json_decode(File::get(DeviceFiles::runtimeConfigPath()), true)['rfid']['stationary_seconds']);
    }

    public function test_two_vehicles_each_get_their_own_tag(): void
    {
        $this->cameraOnline();
        $first = $this->registeredVehicle('TWO 5001', 'TWO-TAG-1');
        $second = $this->registeredVehicle('TWO 5002', 'TWO-TAG-2');
        // Car A is closest to the antenna 7 s ago, car B 1 s ago (B has fewer reads).
        $this->buffer([
            $this->presence('TWO-TAG-1', firstAgo: 12, lastAgo: 3, peakAgo: 7, reads: 80, rssi: -45),
            $this->presence('TWO-TAG-2', firstAgo: 5, lastAgo: 0.2, peakAgo: 1, reads: 20, rssi: -47),
        ]);

        $this->crossing('IN', 'k-car-b', 'matched')->assertJsonPath('rfid_scan.plate_number', 'TWO 5002');
        $this->crossing('IN', 'k-car-a', 'matched', secondsAgo: 7)->assertJsonPath('rfid_scan.plate_number', 'TWO 5001');

        $this->assertSame(2, RfidScanLog::query()->count());
        $this->assertSame([Vehicle::STATE_INSIDE, Vehicle::STATE_INSIDE], [$first->fresh()->current_state, $second->fresh()->current_state]);

        // A third vehicle right after gets neither (both are used).
        $this->travel(4)->seconds();
        $this->cameraOnline();
        $this->buffer([
            $this->presence('TWO-TAG-1', firstAgo: 16, lastAgo: 7, peakAgo: 11, reads: 80, rssi: -45),
            $this->presence('TWO-TAG-2', firstAgo: 9, lastAgo: 4.2, peakAgo: 5, reads: 20, rssi: -47),
        ]);
        $this->crossing('IN', 'k-car-c', 'no_pass')->assertJsonPath('rfid_scan', null);
        $this->assertSame(2, RfidScanLog::query()->count());
    }

    public function test_the_detector_window_claims_the_registered_tag_for_its_vehicle(): void
    {
        $this->cameraOnline();
        $this->registeredVehicle('DET 6006', 'DET-TAG-6');
        $this->buffer([
            $this->presence('DET-TAG-6', firstAgo: 3, lastAgo: 0.2, peakAgo: 1),
            $this->presence(self::UNKNOWN, firstAgo: 3, lastAgo: 0.2, peakAgo: 0.5),
        ]);

        $this->match('k-det-1')->assertJsonPath('matched', true)->assertJsonPath('vehicle.plate_number', 'DET 6006')
            ->assertJsonPath('overlay.label', 'REGISTERED - DET 6006');
        // Another vehicle's window 5 s later cannot take it.
        $this->travel(5)->seconds();
        $this->cameraOnline();
        $this->buffer([$this->presence('DET-TAG-6', firstAgo: 8, lastAgo: 5.2, peakAgo: 6)]);
        $this->match('k-det-2')->assertJsonPath('matched', false);

        $this->assertSame(0, RfidScanLog::query()->count());
    }

    public function test_registration_modes_and_test_scan_accept_a_tag_without_a_record(): void
    {
        $this->cameraOnline();
        $now = microtime(true);
        File::ensureDirectoryExists(DeviceFiles::directory());
        File::put(DeviceFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'readers' => ['gate-1' => [
                'state' => 'connected', 'last_tag' => self::UNKNOWN, 'last_tag_at' => now()->toIso8601String(),
                'recent_tags' => [['epc' => self::UNKNOWN, 'rssi' => -60, 'epoch' => $now]],
            ]],
        ]));

        // Add Vehicle / Replace Tag "Scan tag" and Register Tags read from the reader.
        $this->actingAs($this->admin)->getJson(route('registry.tags.uhf-reads', ['after' => $now - 5]))
            ->assertOk()->assertJsonPath('reads.0.epc', self::UNKNOWN);
        $this->actingAs($this->admin)->postJson(route('registry.tags.lookup'), ['uid' => self::UNKNOWN])->assertOk();
        $this->actingAs($this->admin)->postJson(route('rfid-inventory.store'), ['uid' => self::UNKNOWN, 'tag_type' => 'vehicle', 'auto_number' => 1])
            ->assertCreated();

        // Settings › Test Scan: a preview.
        $this->registeredVehicle('TST 7007', 'TST-TAG-7');
        $this->actingAs($this->admin)->postJson(route('rfid-scans.store'), ['tag_uid' => 'TST-TAG-7', 'scan_location' => 'gate-1'])
            ->assertOk()
            ->assertJsonPath('outcome', RfidIngestResult::PREVIEW)
            ->assertJsonPath('vehicle.plate_number', 'TST 7007');
        $this->actingAs($this->admin)->postJson(route('rfid-scans.store'), ['tag_uid' => 'NOT-IN-REGISTRY', 'scan_location' => 'gate-1'])
            ->assertOk()->assertJsonPath('outcome', RfidIngestResult::PREVIEW);

        $this->assertSame(0, RfidScanLog::query()->count());
        $this->assertSame(0, VehicleEvent::query()->count());
        $this->assertTrue(RfidTag::query()->where('uid', self::UNKNOWN)->exists());
    }

    public function test_camera_offline_records_registered_tags_rfid_only(): void
    {
        $vehicle = $this->registeredVehicle('OFF 8008', 'OFF-TAG-8');
        \App\Models\Gate::query()->where('code', 'gate-1')->update(['reader_manual' => true, 'reader_ip' => '192.0.2.10', 'reader_port' => 49152]);

        // A short hiccup (3 s): still waits for the camera.
        $this->cameraOnline(running: false, offlineFor: 3);
        $this->postRead('OFF-TAG-8')->assertJsonPath('outcome', RfidIngestResult::BUFFERED);
        $this->assertSame(0, RfidScanLog::query()->count());

        // Offline for 30 s: RFID only.
        $this->cameraOnline(running: false, offlineFor: 30);
        $this->postRead('OFF-TAG-8')->assertCreated()->assertJsonPath('outcome', RfidIngestResult::RECORDED);
        $scan = RfidScanLog::query()->sole();
        $this->assertSame(['ENTRY', RfidScanLog::FUSION_TOGGLE], [$scan->resolved_event_type, $scan->fusion_status]);
        $this->assertStringContainsString('RFID only (camera offline)', $scan->fusion_note);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);

        // Same cooldown.
        $this->postRead('OFF-TAG-8')->assertOk()->assertJsonPath('duplicate_ignored', true);
        // Unknown tag: not recorded, only counted.
        $this->postRead(self::UNKNOWN)->assertJsonPath('outcome', RfidIngestResult::UNKNOWN_TAG)->assertJsonPath('saved', false);
        $this->assertSame(1, RfidScanLog::query()->count());
        $diagnostics = app(RfidTagMatcher::class)->diagnostics()['gate-1'];
        $this->assertSame([1, 1], [$diagnostics['rfid_only'], $diagnostics['unknown_while_offline']]);

        // The gate card says so; the diagnostics are in System status.
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))
            ->assertOk()->assertSee('data-rfid-only', false)->assertSee('RFID only (camera offline)');
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'status']))
            ->assertOk()->assertSee('data-rfid-diagnostics', false)->assertSee('Unknown, camera offline');

        // A crossing when the camera is back does not record it twice.
        $this->cameraOnline();
        $this->buffer([$this->presence('OFF-TAG-8', firstAgo: 2, lastAgo: 0.2, peakAgo: 1)]);
        $this->crossing('IN', 'k-back', 'matched')->assertJsonPath('rfid_scan', null);
        $this->assertSame(1, RfidScanLog::query()->count());

        // Fallback off: nothing without the camera.
        $this->travel(2)->minutes();
        SystemSetting::query()->updateOrCreate(['setting_key' => 'rfid_offline_fallback'], ['setting_value' => '0']);
        $this->cameraOnline(running: false, offlineFor: 60);
        $this->postRead('OFF-TAG-8')->assertJsonPath('outcome', RfidIngestResult::BUFFERED);
        $this->assertSame(1, RfidScanLog::query()->count());
    }

    public function test_cleanup_removes_old_reads_without_a_vehicle_after_a_backup(): void
    {
        $storage = sys_get_temp_dir().'/rfid-cleanup-test-'.uniqid();
        $this->app->useStoragePath($storage);

        $vehicle = $this->registeredVehicle('CLN 9009', 'CLN-TAG-9');
        $recorded = app(RfidIngestService::class)->ingest(['tag_uid' => 'CLN-TAG-9', 'scan_location' => 'gate-1'])->scanLog;
        $base = ['scan_location' => 'gate-1', 'scan_time' => now(), 'source_mode' => 'hardware_placeholder', 'reader_name' => 'Gate 1 UHF Reader'];
        RfidScanLog::query()->create($base + ['tag_uid' => self::UNKNOWN, 'verification_status' => 'unknown_tag', 'is_anomaly' => true]);
        RfidScanLog::query()->create($base + ['tag_uid' => self::UNKNOWN, 'verification_status' => 'unknown_tag', 'is_anomaly' => true]);
        RfidScanLog::query()->create($base + ['tag_uid' => 'CLN-TAG-9', 'vehicle_id' => $vehicle->id, 'verification_status' => 'verified', 'fusion_status' => RfidScanLog::FUSION_SCAN_ONLY]);

        $this->artisan('rfid:cleanup-unmatched', ['--dry-run' => true])->expectsOutputToContain('would remove 3')->assertSuccessful();
        $this->assertSame(4, RfidScanLog::query()->count());

        $this->artisan('rfid:cleanup-unmatched')->expectsOutputToContain('Removed 3')->assertSuccessful();
        $this->assertSame([$recorded->id], RfidScanLog::query()->pluck('id')->all());
        $backup = File::directories($storage.'/backups')[0];
        $this->assertCount(3, json_decode(File::get($backup.'/removed_rfid_scans.json'), true));

        File::deleteDirectory($storage);
    }

    protected function cameraOnline(bool $running = true, ?int $offlineFor = null, string $gate = 'gate-1'): void
    {
        File::ensureDirectoryExists(dirname(CameraFiles::statusPath()));
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'cameras' => [$gate => [
                'camera_role' => $gate, 'camera_running' => $running, 'calibration_ready' => true,
                'offline_since' => $offlineFor !== null ? now()->subSeconds($offlineFor)->toIso8601String() : null,
            ]],
        ]));
    }

    /**
     * The device service's rfid_buffer.json (devices/tag_buffer.py).
     *
     * @param  list<array<string, mixed>>  $presences
     */
    protected function buffer(array $presences, string $gate = 'gate-1'): void
    {
        File::ensureDirectoryExists(DeviceFiles::directory());
        File::put(DeviceFiles::rfidBufferPath(), json_encode([
            'generated_at' => $this->now(),
            'window_seconds' => 20, 'absent_seconds' => 5, 'stationary_seconds' => 60,
            'gates' => [$gate => $presences],
            'raw_reads' => [$gate => array_sum(array_column($presences, 'reads'))],
            'presences_started' => [$gate => count($presences)],
            'ended' => [],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function presence(string $epc, float $firstAgo, float $lastAgo, float $peakAgo, int $reads = 10, ?float $rssi = -50, bool $stationary = false): array
    {
        $now = $this->now();

        return [
            'epc' => $epc, 'session' => round($now - $firstAgo, 3),
            'first_seen' => $now - $firstAgo, 'last_seen' => $now - $lastAgo, 'peak_at' => $now - $peakAgo,
            'reads' => $reads, 'max_rssi' => $rssi, 'stationary' => $stationary, 'samples' => [],
        ];
    }

    protected function now(): float
    {
        return now()->getTimestamp() + now()->micro / 1_000_000;
    }

    protected function postRead(string $uid): TestResponse
    {
        return $this->withHeaders(['X-Api-Key' => 'test-detector-key', 'X-Source-Name' => 'philcst-uhf-reader'])
            ->postJson(route('api.integration.rfid-scans'), [
                'tag_uid' => $uid, 'scan_location' => 'gate-1', 'reader_name' => 'Gate 1 UHF Reader',
                'payload_json' => ['source' => 'uhf_ethernet', 'protocol' => 'cc', 'rssi' => -55],
            ]);
    }

    protected function crossing(string $direction, string $key, string $rfidStatus, int $secondsAgo = 0): TestResponse
    {
        return $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->postJson(route('api.integration.crossings'), [
            'external_event_key' => $key,
            'camera_role' => 'gate-1',
            'direction' => $direction,
            'event_time' => now()->subSeconds($secondsAgo)->toIso8601String(),
            'track_id' => crc32($key) % 1000,
            'confidence' => 0.9,
            'detection_metadata' => ['rfid_status' => $rfidStatus],
        ]);
    }

    protected function match(string $eventKey): TestResponse
    {
        return $this->withHeaders(['X-Api-Key' => 'test-detector-key'])
            ->getJson(route('api.integration.rfid-match', ['camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(), 'event_key' => $eventKey]))
            ->assertOk();
    }

    protected function registeredVehicle(string $plate, string $uid, string $state = Vehicle::STATE_OUTSIDE): Vehicle
    {
        $vehicle = Vehicle::query()->create([
            'plate_number' => $plate, 'vehicle_owner_name' => 'Buffer Owner', 'category' => 'faculty_staff', 'vehicle_type' => 'Car',
        ]);
        $vehicle->forceFill(['current_state' => $state])->save();
        $tag = RfidTag::query()->create(['uid' => $uid, 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        return $vehicle;
    }
}
