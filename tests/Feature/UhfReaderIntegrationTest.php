<?php

namespace Tests\Feature;

use App\Models\NetworkDevice;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DeviceRegistryService;
use App\Support\DeviceFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * UHF reader with the CC FF FF protocol (WCH Ethernet module, TCP server,
 * active mode): detection data, station assignment, EPC tags, status.
 *
 * Addresses are from the documentation ranges (RFC 5737).
 */
class UhfReaderIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected const EPC = 'E280689400005031D6458CE8';

    protected const MAC = '70:19:88:BF:D6:51';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        File::deleteDirectory(DeviceFiles::directory());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(DeviceFiles::directory());

        parent::tearDown();
    }

    public function test_signature_reader_is_assigned_with_its_format_and_the_rfid_cooldown(): void
    {
        $registry = app(DeviceRegistryService::class);
        $registry->ingestScan($this->scan());
        $reader = NetworkDevice::query()->where('mac', self::MAC)->firstOrFail();

        $this->assertSame(NetworkDevice::KIND_READER, $reader->kind);
        $this->assertSame('signature', $reader->readerDetails()['confirmed_by']);

        $this->actingAs($this->admin)
            ->postJson(route('settings.devices.assign', $reader), ['station' => 'entrance', 'role' => 'reader'])
            ->assertOk()
            ->assertJsonMissingPath('warning');

        $target = json_decode(File::get(DeviceFiles::runtimeConfigPath()), true)['stations']['entrance']['reader'];
        $this->assertSame([self::MAC, 49152, 'tcp', 'cc', 'active', 60], [
            $target['mac'], $target['port'], $target['transport'], $target['protocol'], $target['work_mode'], $target['cooldown_seconds'],
        ]);

        // The RFID cooldown in Settings is also the reader link's debounce.
        SystemSetting::query()->updateOrCreate(['setting_key' => 'rfid_cooldown_seconds'], ['setting_value' => '20']);
        $registry->exportRuntimeConfig();
        $target = json_decode(File::get(DeviceFiles::runtimeConfigPath()), true)['stations']['entrance']['reader'];
        $this->assertSame(20, $target['cooldown_seconds']);
    }

    public function test_reader_behind_an_extra_address_shows_the_warning_and_the_fix(): void
    {
        app(DeviceRegistryService::class)->ingestScan($this->scan());
        $reader = NetworkDevice::query()->where('mac', self::MAC)->firstOrFail();
        $this->actingAs($this->admin)
            ->postJson(route('settings.devices.assign', $reader), ['station' => 'entrance', 'role' => 'reader'])->assertOk();

        $payload = $this->actingAs($this->admin)->getJson(route('settings.devices.index'))->assertOk()->json();
        $device = collect($payload['devices'])->firstWhere('mac', self::MAC);

        $this->assertStringContainsString('198.51.100.0/24', $device['network_warning']['text']);
        $this->assertStringContainsString('198.51.100.1', $device['network_warning']['text']);
        $this->assertStringContainsString('no DHCP', $device['network_warning']['steps'][0]);
        $this->assertSame($device['network_warning'], $payload['stations']['entrance']['reader']['network_warning']);

        // Fixed: the reader moved into the router's network.
        $scan = $this->scan();
        $scan['devices'][0]['network_warning'] = null;
        app(DeviceRegistryService::class)->ingestScan($scan);
        $payload = $this->actingAs($this->admin)->getJson(route('settings.devices.index'))->json();
        $this->assertNull(collect($payload['devices'])->firstWhere('mac', self::MAC)['network_warning']);
    }

    public function test_epc_is_a_tag_id_and_repeated_reads_log_once(): void
    {
        // Registry › RFID Tags: register the EPC like any tag UID.
        $this->actingAs($this->admin)
            ->postJson(route('rfid-inventory.store'), ['uid' => strtolower(self::EPC), 'tag_type' => 'vehicle', 'auto_number' => 1])
            ->assertCreated();
        $this->assertTrue(RfidTag::query()->where('uid', self::EPC)->exists());

        // Add Vehicle "Scan tag" check accepts it.
        $this->actingAs($this->admin)
            ->postJson(route('registry.tags.lookup'), ['uid' => self::EPC])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $read = [
            'tag_uid' => self::EPC, 'scan_location' => 'entrance', 'reader_name' => 'Entrance UHF Reader',
            'payload_json' => ['source' => 'uhf_ethernet', 'protocol' => 'cc', 'rssi' => -70],
        ];
        $headers = ['X-Api-Key' => 'test-detector-key', 'X-Source-Name' => 'philcst-uhf-reader'];
        $this->postJson(route('api.integration.rfid-scans'), $read, $headers)->assertCreated();
        $this->postJson(route('api.integration.rfid-scans'), $read, $headers)->assertOk()->assertJsonPath('duplicate_ignored', true);

        $this->assertSame(1, RfidScanLog::query()->where('tag_uid', self::EPC)->count());
    }

    public function test_sidebar_and_registry_see_the_reader_status_and_last_tag(): void
    {
        $now = microtime(true);
        File::ensureDirectoryExists(DeviceFiles::directory());
        File::put(DeviceFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'readers' => [
                'entrance' => [
                    'state' => 'connected', 'ip' => '198.51.100.116', 'port' => 49152, 'protocol' => 'cc',
                    'last_tag' => self::EPC, 'last_rssi' => -70, 'last_tag_at' => now()->toIso8601String(),
                    'target' => ['mac' => self::MAC, 'device_id' => 1],
                    'recent_tags' => [
                        ['epc' => self::EPC, 'rssi' => -70, 'epoch' => $now],
                        ['epc' => 'E2000017221101441890ABCD', 'rssi' => -61, 'epoch' => $now - 60],
                    ],
                ],
                'exit' => ['state' => 'unassigned', 'target' => null],
            ],
        ]));

        $this->actingAs($this->admin)->getJson(route('devices.uhf-status'))
            ->assertOk()
            ->assertJsonCount(1, 'readers')
            ->assertJsonPath('readers.0.label', 'Entrance UHF')
            ->assertJsonPath('readers.0.ok', true)
            ->assertJsonPath('readers.0.epc', self::EPC);
        $this->assertStringStartsWith('…D6458CE8 · -70 dBm', $this->actingAs($this->admin)->getJson(route('devices.uhf-status'))->json('readers.0.tag_line'));

        $this->actingAs($this->admin)->getJson(route('registry.tags.uhf-reads', ['after' => $now - 30]))
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonCount(1, 'reads')
            ->assertJsonPath('reads.0.epc', self::EPC)
            ->assertJsonPath('reads.0.station', 'entrance');

        $this->actingAs($this->admin)->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('Entrance UHF')
            ->assertSee('-70 dBm')
            ->assertSee('js/sidebar-uhf.js');

        $this->actingAs($this->admin)->get(route('registry.index', ['tab' => 'vehicles']))
            ->assertOk()->assertSee('Read with UHF reader');
    }

    /**
     * @return array<string, mixed>
     */
    protected function scan(): array
    {
        return [
            'scan' => ['id' => 'scan-uhf', 'complete' => true],
            'devices' => [[
                'key' => self::MAC, 'mac' => self::MAC, 'ip' => '198.51.100.116', 'reachable' => true, 'online' => true,
                'interface' => 'en7', 'subnet' => '198.51.100.0/24',
                'kind' => 'rfid_reader', 'confidence' => 'confirmed', 'name' => 'UHF RFID reader',
                'vendor' => 'Nanjing Qinheng Microelectronics Co., Ltd.',
                'reader' => [
                    'transport' => 'tcp', 'port' => 49152, 'protocol' => 'cc', 'work_mode' => 'active',
                    'confirmed' => true, 'confirmed_by' => 'signature',
                ],
                'open_ports' => ['tcp' => [49152]],
                'network_warning' => [
                    'pc_ip' => '198.51.100.1', 'network' => '198.51.100.0/24', 'interface' => 'en7', 'interface_label' => 'USB 10/100 LAN',
                    'lan_network' => null, 'lan_gateway' => null, 'lan_ip' => null, 'no_dhcp' => true,
                ],
            ]],
        ];
    }
}
