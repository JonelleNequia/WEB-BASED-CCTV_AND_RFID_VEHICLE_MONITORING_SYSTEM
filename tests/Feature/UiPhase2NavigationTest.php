<?php

namespace Tests\Feature;

use App\Models\RfidTag;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\RfidIngestService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI Phase 2: six-item sidebar, tabbed pages, Gate Monitor and redirects
 * from every old page URL.
 */
class UiPhase2NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $this->guard = User::query()->create([
            'name' => 'Gate Guard',
            'email' => 'guard@philcst.local',
            'password' => 'password',
            'role' => 'guard',
        ]);
    }

    public function test_admin_sidebar_has_six_items_and_no_station_links(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard.index'))->assertOk()->getContent();

        // Phase 0: "Guests" (guest passes) was removed; Phase 5 (visitor model) added "Visitors".
        preg_match_all('/class="nav-link[^"]*"/', $html, $links);
        $this->assertCount(6, $links[0]);

        foreach (['Dashboard', 'Gate Monitor', 'Visitors', 'Registry', 'Activity Logs', 'Settings'] as $label) {
            $this->assertStringContainsString('<span class="nav-label">'.$label.'</span>', $html);
        }

        foreach (['Guests', 'Entrance Station', 'Exit Station', 'RFID Desk', 'Camera Calibration', 'System Status', 'Vehicle Registry'] as $old) {
            $this->assertStringNotContainsString('<span class="nav-label">'.$old.'</span>', $html);
        }

        $this->assertStringContainsString('sidebar-health', $html);
        $this->assertStringContainsString('Detector', $html);
        $this->assertStringContainsString('Readers', $html);
    }

    public function test_guard_sees_only_the_gate_monitor_and_visitors(): void
    {
        $this->actingAs($this->guard)->get('/')->assertRedirect(route('gates.index'));

        $html = $this->actingAs($this->guard)->get(route('gates.index'))->assertOk()->getContent();
        preg_match_all('/class="nav-link[^"]*"/', $html, $links);
        // Phase 5 (visitor model): guards correct visitor plates too.
        $this->assertCount(2, $links[0]);
        $this->assertStringContainsString('<span class="nav-label">Visitors</span>', $html);

        $this->actingAs($this->guard)->get(route('registry.index'))->assertForbidden();
    }

    public function test_old_urls_redirect_to_the_new_page_and_tab_with_filters(): void
    {
        $redirects = [
            '/vehicle-registry' => '/registry?tab=vehicles',
            '/rfid-inventory' => '/registry?tab=tags',
            '/rfid-inventory?tag_type=guest_pass' => '/registry?tab=tags',
            '/rfid-scans?history_q=ABC' => '/logs?tab=scans&history_q=ABC',
            '/guest-observations' => '/logs?tab=alerts',
            '/vehicle-events?period=month' => '/logs?tab=events&period=month',
            '/camera-calibration' => '/settings?tab=calibration',
            '/system-status' => '/settings?tab=status',
            // Phase 0: guest pass pages are gone; old links open the camera alerts.
            '/guest-passes?status=overstay' => '/logs?tab=alerts&status=overstay',
            // Phase 8 (visitor model): Guests became Visitors.
            '/guests' => '/visitors',
        ];

        foreach ($redirects as $from => $to) {
            $this->actingAs($this->admin)->get($from)->assertRedirect(url($to));
        }
    }

    public function test_tabs_are_url_synced(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('registry.index', ['tab' => 'tags']))
            ->assertOk()
            ->assertSee('Register Tags')
            ->assertDontSee('Guest Passes')
            ->assertSee('?tab=vehicles', false)
            ->getContent();

        $this->assertMatchesRegularExpression('#href="[^"]*\?tab=tags"\s+class="tab is-active"\s+aria-current="page"#', $html);

        // The removed Guest Passes tab falls back to the first tab.
        $this->actingAs($this->admin)
            ->get(route('registry.index', ['tab' => 'passes']))
            ->assertOk()
            ->assertDontSee('Register Guest Pass');

        $this->actingAs($this->admin)
            ->get(route('logs.index', ['tab' => 'nope']))
            ->assertOk()
            ->assertSee('Quick Manual Log');
    }

    public function test_gate_monitor_shows_both_gates_and_opens_kiosks(): void
    {
        $this->actingAs($this->guard)
            ->get(route('gates.index'))
            ->assertOk()
            ->assertSee('Open Gate 1 Kiosk')
            ->assertSee('Open Gate 2 Kiosk')
            ->assertSee(route('gates.kiosk', 'gate-1'), false)
            ->assertSee(route('gates.kiosk', 'gate-2'), false)
            ->assertSee('data-gate="gate-1"', false)
            ->assertSee('data-gate="gate-2"', false);

        $this->actingAs($this->guard)
            ->getJson(route('gates.state'))
            ->assertOk()
            ->assertJsonStructure(['detector_running', 'gates' => ['gate-1' => ['camera_running', 'stream_url', 'logs'], 'gate-2']]);
    }

    public function test_gate_monitor_shows_the_latest_vehicle_per_gate(): void
    {
        RfidTag::query()->create(['uid' => 'GATE-UNKNOWN-1', 'status' => RfidTag::STATUS_AVAILABLE]);
        app(RfidIngestService::class)->ingest(['tag_uid' => 'GATE-UNKNOWN-1', 'scan_location' => 'gate-2'], 'station_reader');

        $state = $this->actingAs($this->admin)->getJson(route('gates.state'))->json('gates');

        // UI Phase 4: the newest row is the big result; a tag that is not on a vehicle is a yellow "TAG?".
        $this->assertSame([], $state['gate-1']['logs']);
        $this->assertSame(['GATE-UNKNOWN-1', 'TAG?', 'Unknown tag', 'warning'], [
            $state['gate-2']['logs'][0]['plate_number'], $state['gate-2']['logs'][0]['direction_label'],
            $state['gate-2']['logs'][0]['category_label'], $state['gate-2']['logs'][0]['tone'],
        ]);
        $this->actingAs($this->admin)->get(route('gates.index'))->assertOk()
            ->assertSee('class="gate-latest result-unknown"', false)
            ->assertSee('No vehicles have passed yet');
    }

    public function test_sidebar_shows_the_alert_count(): void
    {
        RfidTag::query()->create(['uid' => 'LOST-TAG-1', 'status' => RfidTag::STATUS_LOST]);
        app(RfidIngestService::class)->ingest(['tag_uid' => 'LOST-TAG-1', 'scan_location' => 'gate-1'], 'station_reader');

        $this->actingAs($this->admin)
            ->get(route('dashboard.index'))
            ->assertSee('class="nav-badge"', false);

        $this->actingAs($this->admin)
            ->get(route('logs.index', ['tab' => 'alerts']))
            ->assertOk()
            ->assertSee('Anomalies Today')
            ->assertSee('LOST-TAG-1');
    }

    public function test_each_settings_tab_saves_only_its_own_fields(): void
    {
        // Phase 0: the guest pass section is gone; its fields are never saved.
        $this->actingAs($this->admin)
            ->put(route('settings.update'), ['section' => 'guest-pass', 'guest_pass_validity_minutes' => 120])
            ->assertSessionHasErrors();
        $this->assertFalse(SystemSetting::query()->where('setting_key', 'guest_pass_validity_minutes')->exists());

        SystemSetting::query()->updateOrCreate(['setting_key' => 'perf_stream_fps'], ['setting_value' => '12']);

        $this->actingAs($this->admin)
            ->from(route('settings.index', ['tab' => 'stations']))
            ->put(route('settings.update'), [
                'section' => 'stations',
                'entrance_portal_label' => 'Main Gate',
                'exit_portal_label' => 'Back Gate',
                'entrance_reader_type' => 'nfc',
                'exit_reader_type' => 'nfc',
                'rfid_cooldown_seconds' => 30,
            ])
            ->assertSessionHasNoErrors();

        // Phase 1: the old label fields rename Gate 1 / Gate 2.
        $this->assertSame(['Main Gate', 'Back Gate'], \App\Models\Gate::query()->orderBy('sort_order')->pluck('name')->all());
        // The Cameras tab's value was not touched by the stations save.
        $this->assertSame('12', SystemSetting::query()->where('setting_key', 'perf_stream_fps')->value('setting_value'));

        $this->actingAs($this->admin)
            ->put(route('settings.update'), ['section' => 'stations', 'gates' => ['gate-1' => ['name' => '']]])
            ->assertSessionHasErrors('gates.gate-1.name');
    }
}
