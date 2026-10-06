<?php

namespace Tests\Feature;

use App\Models\NetworkDevice;
use App\Models\User;
use App\Services\DeviceRegistryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * B2 (Settings): "+ Add camera" / "+ Add reader" open the add-device wizard,
 * which lists devices by a plain name and adds them with the existing assign.
 */
class AddDeviceWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_gates_tab_has_the_wizard_and_its_links(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $html = $this->actingAs($admin)->get(route('settings.index', ['tab' => 'gates']))->assertOk()->getContent();

        $this->assertStringContainsString('id="add-device-modal"', $html);
        $this->assertStringContainsString('js/add-device.js', $html);
        $this->assertStringContainsString('data-add-device="reader" data-gate="gate-1"', $html);
        $config = json_decode(html_entity_decode(preg_replace('/^.*<script id="add-device-data" type="application\/json">(.*?)<\/script>.*$/s', '$1', $html)), true);
        $this->assertSame(['indexUrl', 'scanUrl', 'uhfStatusUrl', 'gatesStateUrl', 'manualUrl', 'gates'], array_keys($config));
        $this->assertSame(['name', 'stream_url'], array_keys($config['gates']['gate-1']));
    }

    public function test_devices_have_a_plain_name(): void
    {
        $make = fn (string $kind, array $values) => NetworkDevice::query()->find(DB::table('network_devices')->insertGetId([
            'device_key' => uniqid(), 'kind' => $kind, 'ip' => '192.0.2.'.random_int(2, 250), 'created_at' => now(), 'updated_at' => now(), ...$values,
        ]));
        $registry = app(DeviceRegistryService::class);

        $this->assertSame('TP-Link VIGI-C240 Camera', $registry->friendlyName($make('camera', ['brand' => 'TP-Link VIGI', 'vendor' => 'TP-Link Systems Inc.', 'model' => 'VIGI-C240'])));
        $this->assertSame('Hikvision Camera', $registry->friendlyName($make('camera', ['vendor' => 'Hikvision'])));
        $this->assertSame('Network Camera', $registry->friendlyName($make('camera', [])));
        $this->assertSame('UHF RFID Reader', $registry->friendlyName($make('rfid_reader', ['vendor' => 'Nanjing Qinheng Microelectronics Co., Ltd.'])));
        $this->assertSame('UHF RFID Reader', collect($registry->panelPayload()['devices'])->firstWhere('kind', 'rfid_reader')['friendly_name']);
    }
}
