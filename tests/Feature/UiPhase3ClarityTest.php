<?php

namespace Tests\Feature;

use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VisitorRecord;
use App\Services\DeviceRegistryService;
use App\Services\RfidIngestService;
use App\Support\CameraFiles;
use App\Support\StatusBadge;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * UI Phase 3: one color system and no confusing information.
 */
class UiPhase3ClarityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    protected function tearDown(): void
    {
        File::delete(CameraFiles::statusPath());

        parent::tearDown();
    }

    public function test_one_color_system_on_logs_and_the_kiosk(): void
    {
        $vehicle = $this->registeredVehicle('COL 1001', 'COL-TAG');
        app(RfidIngestService::class)->ingest(['tag_uid' => 'COL-TAG', 'scan_location' => 'gate-1']);       // registered IN
        app(RfidIngestService::class)->ingest(['tag_uid' => 'WHO-ARE-YOU', 'scan_location' => 'gate-1']);   // unknown tag
        VisitorRecord::query()->create(['external_event_key' => 'col-v1', 'gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now(),
            'status' => 'active', 'plate_status' => 'read', 'plate_number' => 'VIS 2002', 'plate_key' => 'VIS2002']);
        VisitorRecord::query()->create(['external_event_key' => 'col-v2', 'gate' => 'gate-1', 'direction' => 'UNKNOWN', 'seen_at' => now(),
            'status' => 'active', 'plate_status' => 'unreadable']);

        $logs = collect($this->actingAs($this->admin)->getJson(route('logs.index', ['tab' => 'events']))->assertOk()->json('logs'));
        $row = fn (string $plate) => $logs->firstWhere('plate_number', $plate);

        // Status is a real status, not the plate again.
        $this->assertSame(['Inside', 'matched'], [$row('COL 1001')['status_label'], $row('COL 1001')['status_badge_class']]);
        $this->assertSame(['Plate read', 'matched'], [$row('VIS 2002')['status_label'], $row('VIS 2002')['status_badge_class']]);
        $this->assertSame(['Plate unreadable', 'manual-review'], [$row('Plate unreadable')['status_label'], $row('Plate unreadable')['status_badge_class']]);
        $this->assertSame('manual-review', $logs->firstWhere('rfid_tag_uid', 'WHO-ARE-YOU')['status_badge_class']); // yellow, not red

        // Blue for IN / OUT, gray for unregistered, yellow for an unknown tag, red only for anomalies.
        $this->assertSame('info', StatusBadge::movementTone(['event_type' => 'ENTRY']));
        $this->assertSame('neutral', StatusBadge::movementTone(['event_type' => 'UNREGISTERED']));
        $this->assertSame('warning', StatusBadge::movementTone(['event_type' => 'UNKNOWN TAG']));
        $this->assertSame('critical', StatusBadge::movementTone(['event_type' => 'ENTRY', 'anomaly' => true]));

        $kiosk = collect($this->actingAs($this->admin)->getJson(route('stations.state', 'gate-1'))->assertOk()->json('logs'));
        $this->assertSame('info', $kiosk->firstWhere('plate_number', 'COL 1001')['tone']);
        $this->assertSame('warning', $kiosk->firstWhere('event_type', 'UNKNOWN TAG')['tone']);
        $this->actingAs($this->admin)->get(route('visitors.index'))
            ->assertOk()->assertSee('badge-tone-info', false)->assertSee('Plate unreadable');
        $this->assertNotNull($vehicle);
    }

    public function test_registry_shows_one_status_and_a_confirmed_actions_menu(): void
    {
        $vehicle = $this->registeredVehicle('OFF 3003', 'OFF-TAG');
        $vehicle->forceFill(['status' => 'inactive', 'current_state' => Vehicle::STATE_INSIDE])->save();
        RfidTag::query()->create(['uid' => 'DIS-TAG', 'status' => RfidTag::STATUS_DISABLED]);

        $html = $this->actingAs($this->admin)->get(route('registry.index', ['tab' => 'vehicles']))->assertOk()->getContent();
        $row = substr($html, strpos($html, 'OFF 3003'), 3000);
        $this->assertStringContainsString('>Inactive<', $row);
        $this->assertStringNotContainsString('>Inside<', $row);
        $this->assertStringContainsString('Activate vehicle…', $row);
        $this->assertStringContainsString('data-confirm="Activate OFF 3003 again?', $row);

        $this->actingAs($this->admin)->get(route('registry.index', ['tab' => 'tags']))
            ->assertOk()->assertSee('Disabled: cannot be assigned');
    }

    public function test_devices_list_shows_one_row_per_device(): void
    {
        $insert = fn (string $key, ?string $mac, string $ip) => DB::table('network_devices')->insertGetId([
            'device_key' => $key, 'mac' => $mac, 'ip' => $ip, 'kind' => 'rfid_reader', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $reader = $insert('70:19:88:BF:D6:51', '70:19:88:BF:D6:51', '192.168.2.116');
        $insert('ip:192.168.2.116', null, '192.168.2.116');                 // same reader, saved by IP only
        $insert('AA:AA:AA:00:00:01', 'AA:AA:AA:00:00:01', '192.168.1.1');   // two different routers with the same IP
        $insert('BB:BB:BB:00:00:02', 'BB:BB:BB:00:00:02', '192.168.1.1');

        $devices = collect(app(DeviceRegistryService::class)->panelPayload()['devices']);

        $this->assertSame([$reader], $devices->where('ip', '192.168.2.116')->pluck('id')->values()->all());
        $this->assertCount(2, $devices->where('ip', '192.168.1.1'));
        $this->assertSame(4, DB::table('network_devices')->count()); // nothing deleted
    }

    public function test_system_status_says_ok_delayed_or_offline_and_hides_the_table(): void
    {
        File::ensureDirectoryExists(CameraFiles::directory());
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'cameras' => ['gate-1' => ['camera_running' => true], 'gate-2' => ['camera_running' => false, 'last_error' => 'The camera did not answer in time. Check its cable and network.']],
            'metrics' => ['gate-1' => ['ms' => ['pipeline' => ['avg' => 5675, 'p95' => 12051]], 'values' => ['decoder_threads' => 1]]],
        ]));

        $html = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'status']))->assertOk()->getContent();

        // It used to say "No bottleneck" next to a 5,675 ms delay.
        $this->assertStringNotContainsString('No bottleneck', $html);
        $this->assertStringNotContainsString('No delay on this PC', $html);
        // A1 (detection): each gate shows its live video line, then its detection line.
        $this->assertMatchesRegularExpression('/<strong class="gate-state-name">Gate 1<\/strong>.*?Delayed<\/span>\s*<span class="text-muted">The live view is 5\.7 s behind\./s', $html);
        $this->assertMatchesRegularExpression('/<strong class="gate-state-name">Gate 2<\/strong>.*?Offline<\/span>\s*<span class="text-muted">The camera did not answer in time\./s', $html);
        $this->assertStringContainsString('<details class="advanced-section" data-advanced-diagnostics>', $html); // closed
    }

    public function test_both_camera_cards_look_the_same(): void
    {
        $html = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'cameras']))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'Source: manual address'));
        $this->assertSame(2, substr_count($html, 'Stream and snapshot options appear when a camera is added'));
        $this->assertStringNotContainsString('Manual source', $html);
    }

    protected function registeredVehicle(string $plate, string $uid): Vehicle
    {
        $vehicle = Vehicle::query()->create(['plate_number' => $plate, 'vehicle_owner_name' => 'Owner', 'category' => 'faculty_staff', 'vehicle_type' => 'Car']);
        $tag = RfidTag::query()->create(['uid' => $uid, 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        return $vehicle;
    }
}
