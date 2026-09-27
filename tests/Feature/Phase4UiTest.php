<?php

namespace Tests\Feature;

use App\Models\GuestVehicleObservation;
use App\Models\GuestVisit;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\GuestPassService;
use App\Services\RfidIngestService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4: Guest Passes page, Station pop-ups, RFID Tags, RFID Desk,
 * Dashboard, Event Logs and Settings.
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

    public function test_guest_passes_page_shows_cards_and_visits(): void
    {
        $visit = $this->issuedVisit('GP-PAGE-1', 'PGE 101');
        $this->guestPass('GP-PAGE-2');

        $this->actingAs($this->admin)
            ->get(route('guests.index'))
            ->assertOk()
            ->assertSee('Active Guests')
            ->assertSee('Passes Available')
            ->assertSee('Overstay')
            ->assertSee('PGE 101')
            ->assertSee('G-01')
            ->assertSee('Manual guest entry (fallback)')
            ->assertDontSee('No RFID required');

        $this->actingAs($this->admin)
            ->get(route('guest-passes.visits.show', $visit))
            ->assertOk()
            ->assertSee('PGE 101');
    }

    public function test_navigation_shows_guest_passes(): void
    {
        // UI Phase 2: Guests page + Registry › Guest Passes; no Guest Monitoring.
        $this->actingAs($this->admin)
            ->get(route('dashboard.index'))
            ->assertSee('Guests')
            ->assertSee(route('guests.index'), false)
            ->assertDontSee('Guest Monitoring');
    }

    public function test_manual_close_requires_a_reason_and_frees_the_pass(): void
    {
        $visit = $this->issuedVisit('GP-CLOSE-1', 'CLS 101');

        $this->actingAs($this->admin)
            ->post(route('guest-passes.visits.close', $visit), [])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->admin)
            ->post(route('guest-passes.visits.close', $visit), ['reason' => 'Left through the service gate'])
            ->assertRedirect();

        $this->assertSame(GuestVisit::STATUS_COMPLETED, $visit->fresh()->status);
        $this->assertStringContainsString('service gate', $visit->fresh()->notes);
        $this->assertSame(RfidTag::STATUS_AVAILABLE, $visit->rfidTag->fresh()->status);
    }

    public function test_mark_lost_closes_visit_and_marks_pass_lost(): void
    {
        $visit = $this->issuedVisit('GP-LOST-9', 'LST 101');

        $this->actingAs($this->admin)
            ->post(route('guest-passes.visits.lost', $visit), ['reason' => 'Guest drove off with card'])
            ->assertRedirect();

        $this->assertSame(GuestVisit::STATUS_LOST_TAG, $visit->fresh()->status);
        $this->assertSame(RfidTag::STATUS_LOST, $visit->rfidTag->fresh()->status);
    }

    public function test_entrance_scan_opens_issue_prompt_with_camera_prefill(): void
    {
        $this->guestPass('GP-POP-1');
        GuestVehicleObservation::query()->create([
            'plate_text' => 'OCR 777',
            'plate_number' => 'OCR 777',
            'vehicle_type' => 'Car',
            'vehicle_color' => 'Red',
            'location' => 'entrance',
            'observation_source' => 'cctv',
            'status' => 'pending_review',
            'observed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('stations.rfid-scan', 'entrance'), ['tag_uid' => 'GP-POP-1'])
            ->assertCreated()
            ->assertJsonPath('requires_issue', true)
            ->assertJsonPath('issue.pass_label', 'Guest Pass #G-01')
            ->assertJsonPath('issue.prefill.plate', 'OCR 777')
            ->assertJsonPath('issue.prefill.color', 'Red')
            ->assertJsonPath('issue.requires_id', true);

        $this->actingAs($this->admin)
            ->postJson($response->json('issue.url'), [
                'plate' => 'OCR 777',
                'driver_name' => 'Maria Santos',
                'id_presented' => 'UMID',
                'valid_minutes' => 120,
                'rfid_scan_log_id' => $response->json('issue.rfid_scan_log_id'),
            ])
            ->assertCreated()
            ->assertJsonPath('guest_visit.pass', 'G-01');

        $visit = GuestVisit::query()->firstOrFail();
        $this->assertSame(120, (int) round($visit->entry_at->diffInMinutes($visit->valid_until)));
        $this->assertSame('guest_pass_entry', RfidScanLog::query()->findOrFail($response->json('issue.rfid_scan_log_id'))->verification_status);
    }

    public function test_exit_scan_returns_card_return_reminder(): void
    {
        $visit = $this->issuedVisit('GP-RET-1', 'RET 101', ['id_presented' => "Driver's License"]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('stations.rfid-scan', 'exit'), ['tag_uid' => 'GP-RET-1'])
            ->assertCreated()
            ->assertJsonPath('outcome', 'guest_pass_exit')
            ->assertJsonPath('card_return.id_presented', "Driver's License")
            ->assertJsonPath('card_return.pass_label', 'Guest Pass #G-01');

        $this->actingAs($this->admin)
            ->postJson($response->json('card_return.url'))
            ->assertOk();

        $this->assertStringContainsString('Card and ID returned', $visit->fresh()->notes);
    }

    public function test_station_logs_show_pass_number_not_guest_owner_na(): void
    {
        $this->issuedVisit('GP-LOG-1', null, ['driver_name' => 'Pedro Reyes']);

        $this->actingAs($this->admin)
            ->getJson(route('api.recent-station-logs'))
            ->assertOk()
            ->assertJsonPath('logs.0.verification_label', 'Guest Pass #G-01')
            ->assertJsonPath('logs.0.plate_number', 'G-01')
            ->assertJsonPath('logs.0.owner_name', 'Pedro Reyes');
    }

    public function test_station_page_has_issue_modal_on_entrance_and_card_return_on_exit(): void
    {
        $this->actingAs($this->admin)->get(route('stations.entrance'))->assertOk()
            ->assertSee('data-issue-modal', false)
            ->assertDontSee('data-card-return-modal', false);

        $this->actingAs($this->admin)->get(route('stations.exit'))->assertOk()
            ->assertSee('data-card-return-modal', false)
            ->assertDontSee('data-issue-modal', false);
    }

    public function test_rfid_tags_page_registers_guest_pass_and_filters_by_type(): void
    {
        $this->actingAs($this->admin)
            ->post(route('rfid-inventory.store'), ['tag_number' => 501, 'uid' => 'NEWPASS01', 'tag_type' => 'guest_pass'])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('rfid-inventory.store'), ['tag_number' => 502, 'uid' => 'NEWVEH01', 'tag_type' => 'vehicle'])
            ->assertRedirect();

        $this->assertSame('G-01', RfidTag::query()->where('uid', 'NEWPASS01')->value('display_number'));
        $this->actingAs($this->admin)->get(route('registry.index', ['tab' => 'tags'])); // consume the flash message

        $this->actingAs($this->admin)
            ->get(route('registry.index', ['tab' => 'tags', 'tag_type' => 'guest_pass']))
            ->assertOk()
            ->assertSee(' vehicle · 1 pass')
            ->assertSee('Assigned')
            ->assertSee('NEWPASS01')
            ->assertDontSee('NEWVEH01');
    }

    public function test_rfid_desk_explains_station_rules_and_lists_attention_items(): void
    {
        $this->guestPass('GP-DESK-1');
        app(RfidIngestService::class)->ingest(['tag_uid' => 'GP-DESK-1', 'scan_location' => 'exit']);

        $this->actingAs($this->admin)
            ->get(route('settings.index', ['tab' => 'test-scan']))
            ->assertOk()
            ->assertDontSee('Same station scan can become ENTRY or EXIT')
            ->assertSee('Station readers decide the direction')
            ->assertSee('was never issued')
            ->assertSee('GP-DESK-1');

        $this->actingAs($this->admin)
            ->get(route('logs.index', ['tab' => 'scans', 'verification_status' => 'anomaly']))
            ->assertOk()
            ->assertSee('GP-DESK-1');
    }

    public function test_rfid_desk_can_simulate_a_guest_pass(): void
    {
        $pass = $this->guestPass('GP-SIM-1');

        $this->actingAs($this->admin)
            ->postJson(route('rfid-scans.store'), ['vehicle_rfid_tag_id' => $pass->id, 'scan_location' => 'entrance'])
            ->assertCreated()
            ->assertJsonPath('outcome', 'issue_required');
    }

    public function test_dashboard_shows_active_guests_overstay_and_no_pass_alerts(): void
    {
        $this->issuedVisit('GP-DASH-1', 'DSH 101');
        $overstay = $this->issuedVisit('GP-DASH-2', 'DSH 202');
        $overstay->forceFill(['status' => GuestVisit::STATUS_OVERSTAY])->save();
        GuestVehicleObservation::query()->create([
            'vehicle_type' => 'Car',
            'location' => 'entrance',
            'observation_source' => 'cctv',
            'status' => 'pending_review',
            'observed_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('Active Guests')
            ->assertSee('Needs attention')
            ->assertSee('no-pass')
            ->assertDontSee('Guest Observations Today');

        $this->actingAs($this->admin)
            ->getJson(route('dashboard.live-state'))
            ->assertJsonPath('metrics.active_guests', 2)
            ->assertJsonPath('metrics.overstay_guests', 1)
            ->assertJsonPath('metrics.no_pass_alerts_today', 1)
            ->assertJsonPath('metrics.vehicles_inside', 2);
    }

    public function test_event_logs_label_guest_passes_filter_and_count_guests(): void
    {
        $this->issuedVisit('GP-LOGS-1', 'EVT 303');
        $vehicle = $this->registeredVehicle('REG 404', 'REG-TAG-404');
        app(RfidIngestService::class)->ingest(['tag_uid' => 'REG-TAG-404', 'scan_location' => 'entrance']);

        $this->actingAs($this->admin)
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('Guest Pass #G-01')
            ->assertSee('Alerts');

        $this->actingAs($this->admin)
            ->get(route('logs.index', ['log_type' => 'guest_pass']))
            ->assertOk()
            ->assertSee('EVT 303')
            ->assertDontSee('REG 404');

        $this->actingAs($this->admin)
            ->get(route('logs.index', ['log_type' => 'guest_pass']))
            ->assertViewHas('eventLogSummary', fn (array $summary): bool => $summary['guests'] === 1);

        $csv = $this->actingAs($this->admin)
            ->get(route('vehicle-events.export.csv', ['log_type' => 'guest_pass']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Guest Pass #G-01', $csv);
        $this->assertStringContainsString('EVT 303', $csv);
        $this->assertNotNull($vehicle);
    }

    public function test_settings_has_reader_configuration_and_guest_pass_sections(): void
    {
        $this->actingAs($this->admin)
            ->get(route('settings.index', ['tab' => 'stations']))
            ->assertOk()
            // Plug-and-detect: readers are picked in Devices; the address is under Advanced.
            ->assertSee('Reader Type')
            ->assertSee('UHF (network reader)')
            ->assertSee('Advanced: manual reader address')
            ->assertDontSee('Entrance Reader Name');

        // UI Phase 2: each section is its own Settings tab.
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'guest-pass']))->assertSee('Default Validity (minutes)');
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
            ->assertSessionHasErrors(['entrance_reader_ip', 'entrance_reader_port']);

        // A public internet address (like the old typo) is rejected.
        $this->actingAs($this->admin)
            ->from(route('settings.index'))
            ->put(route('settings.update'), $this->settingsPayload([
                'entrance_reader_manual' => '1',
                'entrance_reader_ip' => '8.8.8.8',
                'entrance_reader_port' => 6000,
            ]))
            ->assertSessionHasErrors(['entrance_reader_ip']);

        $this->actingAs($this->admin)
            ->from(route('settings.index'))
            ->put(route('settings.update'), $this->settingsPayload([
                'entrance_reader_type' => 'uhf_ethernet',
                'entrance_reader_manual' => '1',
                'entrance_reader_ip' => '192.168.100.50',
                'entrance_reader_port' => 6000,
                'guest_pass_validity_minutes' => 180,
                'rfid_cooldown_seconds' => 30,
                'guest_pass_require_id' => '0',
            ]))
            ->assertSessionHasNoErrors();

        $settings = SystemSetting::query()->pluck('setting_value', 'setting_key');
        $this->assertSame('uhf_ethernet', $settings['entrance_reader_type']);
        $this->assertSame('192.168.100.50', $settings['entrance_reader_ip']);
        $this->assertSame('Entrance UHF Reader', $settings['entrance_rfid_reader_name']);
        $this->assertSame('180', $settings['guest_pass_validity_minutes']);
        $this->assertSame('0', $settings['guest_pass_require_id']);
        $this->assertFalse(app(GuestPassService::class)->requiresId());
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
                'entrance' => ['camera_name' => 'Entrance Camera', 'source_type' => 'webcam', 'source_value' => '0'],
                'exit' => ['camera_name' => 'Exit Camera', 'source_type' => 'webcam', 'source_value' => '0'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function issuedVisit(string $uid, ?string $plate, array $details = []): GuestVisit
    {
        return app(GuestPassService::class)->issue($this->guestPass($uid), array_merge([
            'plate' => $plate,
            'id_presented' => 'UMID',
        ], $details));
    }

    protected function guestPass(string $uid): RfidTag
    {
        return RfidTag::query()->create([
            'uid' => $uid,
            'tag_type' => RfidTag::TYPE_GUEST_PASS,
            'status' => RfidTag::STATUS_AVAILABLE,
        ]);
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
