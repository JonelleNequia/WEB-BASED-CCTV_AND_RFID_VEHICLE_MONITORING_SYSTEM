<?php

namespace Tests\Feature;

use App\Models\NetworkDevice;
use App\Models\User;
use App\Services\DeviceRegistryService;
use App\Support\DeviceFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Live view work, Phase 2: an RFID reader that works without a terminal.
 * The reader (RFC 5737: 198.51.100.116) is outside this PC's router network
 * (203.0.113.0/24) until it is moved.
 */
class ReaderNetworkTest extends TestCase
{
    use RefreshDatabase;

    protected const MAC = '70:19:88:BF:D6:51';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        File::deleteDirectory(DeviceFiles::directory());

        $registry = app(DeviceRegistryService::class);
        $registry->ingestScan(['scan' => ['complete' => true], 'devices' => [[
            'key' => self::MAC, 'mac' => self::MAC, 'ip' => '198.51.100.116', 'reachable' => true, 'online' => true,
            'kind' => 'rfid_reader', 'confidence' => 'confirmed', 'name' => 'UHF RFID reader',
            'reader' => ['transport' => 'tcp', 'port' => 49152, 'protocol' => 'cc', 'work_mode' => 'active', 'confirmed' => true, 'confirmed_by' => 'signature'],
        ]]]);
        $this->actingAs($this->admin)
            ->postJson(route('settings.devices.assign', NetworkDevice::query()->firstOrFail()), ['station' => 'gate-1', 'role' => 'reader'])
            ->assertOk();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(DeviceFiles::directory());

        parent::tearDown();
    }

    public function test_a_reader_outside_the_pcs_network_offers_the_move_in_plain_words(): void
    {
        $this->serviceStatus(['gate-1' => ['state' => 'active', 'ip' => '198.51.100.254', 'interface' => 'USB 10/100 LAN', 'reader_ip' => '198.51.100.116']]);

        $card = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()->getContent();
        $this->assertStringContainsString('The reader is set up for another network than this PC.', $card);
        $this->assertStringContainsString('It works for now through a temporary workaround (development only).', $card);
        $this->assertStringContainsString('data-read-url="'.route('settings.gate.reader.network.read', 'gate-1').'"', $card);
        $this->assertStringContainsString('js/reader-network.js', $card);

        // Advanced: the details, the NetModuleConfig guide and the workaround.
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'reader-network']))->assertOk()
            ->assertSee('MAC '.self::MAC)->assertSee('198.51.100.116')->assertSee('203.0.113.0/24')
            ->assertSee('Temporary workaround active: extra address 198.51.100.254 on USB 10/100 LAN.')
            ->assertSee('Open NetModuleConfig')->assertSee('Do not change')
            ->assertSee('sudo tools/start/allow-reader-workaround-mac.sh');
    }

    public function test_read_then_apply_go_to_the_device_service_and_the_login_is_not_kept(): void
    {
        $this->serviceStatus();

        $id = $this->actingAs($this->admin)->postJson(route('settings.gate.reader.network.read', 'gate-1'))->assertOk()->json('request_id');
        $request = $this->runtime()['reader_network_request'];
        $this->assertSame([$id, 'read', 'gate-1', self::MAC, 49152], [$request['id'], $request['action'], $request['station'], $request['mac'], $request['port']]);

        // The device service read the settings: the dialog shows them.
        $this->serviceStatus([], ['request_id' => $id, 'station' => 'gate-1', 'action' => 'read', 'state' => 'read',
            'current' => ['ip' => '198.51.100.116', 'work_mode' => 'TCP server', 'local_port' => 49152],
            'proposal' => ['ip' => '203.0.113.250', 'netmask' => '255.255.255.0', 'gateway' => '203.0.113.1', 'network' => '203.0.113.0/24']]);
        $this->actingAs($this->admin)->getJson(route('settings.gate.reader.network', 'gate-1'))->assertOk()
            ->assertJsonPath('outside', true)->assertJsonPath('move.state', 'read')->assertJsonPath('move.proposal.ip', '203.0.113.250');

        // Apply needs the confirmation and a valid address.
        $this->actingAs($this->admin)->postJson(route('settings.gate.reader.network.apply', 'gate-1'), ['mode' => 'static', 'ip' => '203.0.113.250'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->actingAs($this->admin)->postJson(route('settings.gate.reader.network.apply', 'gate-1'), ['mode' => 'static', 'ip' => 'not-an-ip', 'confirm' => true])
            ->assertJsonValidationErrors('ip');
        $this->actingAs($this->admin)->postJson(route('settings.gate.reader.network.apply', 'gate-1'), [
            'mode' => 'static', 'ip' => '203.0.113.250', 'confirm' => true, 'username' => 'admin', 'password' => 'abc',
        ])->assertOk();
        $request = $this->runtime()['reader_network_request'];
        $this->assertSame(['apply', 'static', '203.0.113.250', 'admin', 'abc'], [$request['action'], $request['mode'], $request['ip'], $request['username'], $request['password']]);

        // The module login is kept for that one request only.
        app(DeviceRegistryService::class)->exportRuntimeConfig();
        $this->assertArrayNotHasKey('password', $this->runtime()['reader_network_request']);
        $this->assertSame('apply', $this->runtime()['reader_network_request']['action']);

        $this->actingAs($this->admin)->postJson(route('settings.gate.reader.network.read', 'gate-9'))->assertNotFound();
    }

    public function test_a_reader_in_the_pcs_network_needs_nothing(): void
    {
        NetworkDevice::query()->update(['ip' => '203.0.113.250']);
        $this->serviceStatus(['gate-1' => ['state' => 'not_needed']]);

        $this->actingAs($this->admin)->getJson(route('settings.gate.reader.network', 'gate-1'))->assertJsonPath('outside', false);
        $card = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))->getContent();
        $this->assertStringNotContainsString('data-reader-network=', $card);
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'reader-network']))->assertSee('In this network');
    }

    public function test_the_workaround_can_be_switched_off_and_reaches_the_device_service(): void
    {
        app(DeviceRegistryService::class)->exportRuntimeConfig();
        $this->assertTrue($this->runtime()['reader_workaround']['enabled']);

        $this->actingAs($this->admin)->post(route('settings.reader-workaround'), ['enabled' => '0'])
            ->assertSessionHas('status', 'Temporary workaround turned off.');
        $this->assertFalse($this->runtime()['reader_workaround']['enabled']);
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'reader-network']))->assertSee('The workaround is')->assertSee('Turn it on');
    }

    /**
     * @return array<string, mixed>
     */
    protected function runtime(): array
    {
        return json_decode(File::get(DeviceFiles::runtimeConfigPath()), true);
    }

    /**
     * @param  array<string, mixed>  $workaround
     * @param  array<string, mixed>|null  $readerNetwork
     */
    protected function serviceStatus(array $workaround = [], ?array $readerNetwork = null): void
    {
        File::ensureDirectoryExists(DeviceFiles::directory());
        File::put(DeviceFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'network' => ['interfaces' => [
                ['name' => 'en7', 'label' => 'USB 10/100 LAN', 'ip' => '203.0.113.2', 'network' => '203.0.113.0/24', 'gateway' => '203.0.113.1', 'link_local' => false],
                ['name' => 'en7', 'label' => 'USB 10/100 LAN', 'ip' => '198.51.100.254', 'network' => '198.51.100.0/24', 'gateway' => null, 'link_local' => false],
            ]],
            'readers' => ['gate-1' => ['state' => 'connected']],
            'reader_workaround' => $workaround,
            'reader_network' => $readerNetwork,
        ]));
    }
}
