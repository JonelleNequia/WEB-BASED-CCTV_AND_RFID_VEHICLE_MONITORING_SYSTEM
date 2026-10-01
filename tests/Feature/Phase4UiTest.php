<?php

namespace Tests\Feature;

use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\RfidIngestService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4: RFID Tags, RFID Desk, Dashboard and Settings.
 * (Guest Passes page and Station pop-ups were removed in Phase 0.)
 */
class Phase4UiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    public function test_rfid_tags_page_registers_vehicle_tags_and_filters_by_status(): void
    {
        // Phase 0: every new tag is a vehicle tag, even if an old form still sends "guest_pass".
        $this->actingAs($this->admin)
            ->post(route('rfid-inventory.store'), ['tag_number' => 501, 'uid' => 'NEWTAG01', 'tag_type' => 'guest_pass'])
            ->assertRedirect();

        $tag = RfidTag::query()->where('uid', 'NEWTAG01')->firstOrFail();
        $this->assertSame([RfidTag::TYPE_VEHICLE, null], [$tag->tag_type, $tag->display_number]);
        $this->actingAs($this->admin)->get(route('registry.index', ['tab' => 'tags'])); // consume the flash message

        $this->actingAs($this->admin)
            ->get(route('registry.index', ['tab' => 'tags', 'status' => 'available']))
            ->assertOk()
            ->assertSee('NEWTAG01')
            ->assertDontSee('Guest pass')
            ->assertDontSee('Issued');
    }

    public function test_rfid_desk_explains_station_rules_and_lists_attention_items(): void
    {
        // A tag marked lost is flagged when it is read at a gate.
        RfidTag::query()->create(['uid' => 'SPARE-DESK-1', 'status' => RfidTag::STATUS_LOST]);
        app(RfidIngestService::class)->ingest(['tag_uid' => 'SPARE-DESK-1', 'scan_location' => 'gate-2']);

        $this->actingAs($this->admin)
            ->get(route('settings.index', ['tab' => 'test-scan']))
            ->assertOk()
            ->assertDontSee('Same station scan can become ENTRY or EXIT')
            ->assertSee('Every gate records IN and OUT')
            ->assertDontSee('Guest Pass')
            ->assertSee('is LOST but was scanned')
            ->assertSee('SPARE-DESK-1');

        $this->actingAs($this->admin)
            ->get(route('logs.index', ['tab' => 'scans', 'verification_status' => 'anomaly']))
            ->assertOk()
            ->assertSee('SPARE-DESK-1');
    }

    public function test_dashboard_shows_no_pass_alerts_and_no_guest_pass_cards(): void
    {
        GuestVehicleObservation::query()->create([
            'vehicle_type' => 'Car',
            'location' => 'gate-1',
            'observation_source' => 'cctv',
            'status' => 'pending_review',
            'observed_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertDontSee('Active Guests')
            ->assertDontSee('overstay')
            ->assertSee('Needs attention')
            ->assertSee('no-pass')
            ->assertDontSee('Guest Observations Today');

        $this->actingAs($this->admin)
            ->getJson(route('dashboard.live-state'))
            ->assertJsonMissingPath('metrics.active_guests')
            ->assertJsonMissingPath('metrics.overstay_guests')
            ->assertJsonPath('metrics.no_pass_alerts_today', 1)
            ->assertJsonPath('metrics.vehicles_inside', 0);
    }

    public function test_settings_has_reader_configuration_and_no_guest_pass_section(): void
    {
        $this->actingAs($this->admin)
            ->get(route('settings.index', ['tab' => 'stations']))
            ->assertOk()
            // Plug-and-detect: readers are picked in Devices; the address is under Advanced.
            ->assertSee('Reader type')
            ->assertSee('UHF (network reader)')
            ->assertSee('Advanced: manual reader address')
            ->assertDontSee('Entrance Reader Name');

        // UI Phase 2: each section is its own Settings tab.
        // Phase 0: the Guest Pass Rules tab is gone (an old link opens the first tab).
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'guest-pass']))->assertOk()->assertDontSee('Default Validity (minutes)')->assertDontSee('Guest Pass Rules');
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'cameras']))->assertSee('Camera Sources');

        // UHF without a manual address is fine (the reader comes from Devices).
        $this->actingAs($this->admin)
            ->from(route('settings.index'))
            ->put(route('settings.update'), $this->settingsPayload([
                'entrance_reader_type' => 'uhf_ethernet',
                'entrance_reader_ip' => '',
            ]))
            ->assertSessionHasNoErrors();

        $payload = $this->settingsPayload([
            'entrance_reader_type' => 'uhf_ethernet',
            'entrance_reader_manual' => '1',
            'entrance_reader_ip' => '',
        ]);

        $this->actingAs($this->admin)
            ->from(route('settings.index'))
            ->put(route('settings.update'), $payload)
            // Phase 1: the old entrance_* fields are Gate 1's fields.
            ->assertSessionHasErrors(['gates.gate-1.reader_ip', 'gates.gate-1.reader_port']);

        // A public internet address (like the old typo) is rejected.
        $this->actingAs($this->admin)
            ->from(route('settings.index'))
            ->put(route('settings.update'), $this->settingsPayload([
                'entrance_reader_manual' => '1',
                'entrance_reader_ip' => '8.8.8.8',
                'entrance_reader_port' => 6000,
            ]))
            ->assertSessionHasErrors(['gates.gate-1.reader_ip']);

        $this->actingAs($this->admin)
            ->from(route('settings.index'))
            ->put(route('settings.update'), $this->settingsPayload([
                'entrance_reader_type' => 'uhf_ethernet',
                'entrance_reader_manual' => '1',
                'entrance_reader_ip' => '192.168.100.50',
                'entrance_reader_port' => 6000,
                'rfid_cooldown_seconds' => 30,
            ]))
            ->assertSessionHasNoErrors();

        $gate = \App\Models\Gate::query()->where('code', 'gate-1')->firstOrFail();
        $this->assertSame(['Main Entrance', 'uhf_ethernet', '192.168.100.50', 6000, true, 'Main Entrance UHF Reader'],
            [$gate->name, $gate->reader_type, $gate->reader_ip, $gate->reader_port, $gate->reader_manual, $gate->reader_name]);
        $settings = SystemSetting::query()->pluck('setting_value', 'setting_key');
        $this->assertSame('30', $settings['rfid_cooldown_seconds']);
        $this->assertArrayNotHasKey('entrance_reader_type', $settings->all());
        $this->assertArrayNotHasKey('guest_pass_validity_minutes', $settings->all());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'matching_threshold_matched' => 75,
            'matching_threshold_manual_review' => 50,
            'operating_mode' => 'manual',
            'deployment_mode' => 'offline_local',
            'cctv_simulation_mode' => 'enabled',
            'rfid_simulation_mode' => 'enabled',
            'entrance_portal_label' => 'Main Entrance',
            'exit_portal_label' => 'Main Exit',
            'entrance_reader_type' => 'nfc',
            'exit_reader_type' => 'nfc',
            'camera_configs' => [
                'gate-1' => ['camera_name' => 'Entrance Camera', 'source_type' => 'webcam', 'source_value' => '0'],
                'gate-2' => ['camera_name' => 'Exit Camera', 'source_type' => 'webcam', 'source_value' => '0'],
            ],
        ], $overrides);
    }

    protected function registeredVehicle(string $plate, string $uid): Vehicle
    {
        $vehicle = Vehicle::query()->create([
            'plate_number' => $plate,
            'vehicle_owner_name' => 'Phase Four Owner',
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);

        $tag = RfidTag::query()->create([
            'uid' => $uid,
            'status' => RfidTag::STATUS_ASSIGNED,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now(),
        ]);

        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        return $vehicle;
    }
}
