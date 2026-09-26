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

        preg_match_all('/class="nav-link[^"]*"/', $html, $links);
        $this->assertCount(6, $links[0]);

        foreach (['Dashboard', 'Gate Monitor', 'Registry', 'Guests', 'Activity Logs', 'Settings'] as $label) {
            $this->assertStringContainsString('<span class="nav-label">'.$label.'</span>', $html);
        }

        foreach (['Entrance Station', 'Exit Station', 'RFID Desk', 'Camera Calibration', 'System Status', 'Vehicle Registry'] as $old) {
            $this->assertStringNotContainsString('<span class="nav-label">'.$old.'</span>', $html);
        }

        $this->assertStringContainsString('sidebar-health', $html);
        $this->assertStringContainsString('Detector', $html);
        $this->assertStringContainsString('Readers', $html);
    }

    public function test_guard_sees_only_the_gate_monitor(): void
    {
        $this->actingAs($this->guard)->get('/')->assertRedirect(route('gates.index'));

        $html = $this->actingAs($this->guard)->get(route('gates.index'))->assertOk()->getContent();
        preg_match_all('/class="nav-link[^"]*"/', $html, $links);
        $this->assertCount(1, $links[0]);

        $this->actingAs($this->guard)->get(route('registry.index'))->assertForbidden();
    }

    public function test_old_urls_redirect_to_the_new_page_and_tab_with_filters(): void
    {
        $redirects = [
            '/vehicle-registry' => '/registry?tab=vehicles',
            '/rfid-inventory' => '/registry?tab=tags',
            '/rfid-inventory?tag_type=guest_pass' => '/registry?tab=passes',
            '/rfid-scans?history_q=ABC' => '/logs?tab=scans&history_q=ABC',
            '/guest-observations' => '/logs?tab=alerts',
            '/vehicle-events?period=month' => '/logs?tab=events&period=month',
            '/camera-calibration' => '/settings?tab=calibration',
            '/system-status' => '/settings?tab=status',
            '/guest-passes?status=overstay' => '/guests?status=overstay',
        ];

        foreach ($redirects as $from => $to) {
            $this->actingAs($this->admin)->get($from)->assertRedirect(url($to));
        }
    }

    public function test_tabs_are_url_synced(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('registry.index', ['tab' => 'passes']))
            ->assertOk()
            ->assertSee('Register Guest Pass')
            ->assertSee('?tab=vehicles', false)
            ->getContent();

        $this->assertMatchesRegularExpression('#href="[^"]*\?tab=passes"\s+class="tab is-active"\s+aria-current="page"#', $html);

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
            ->assertSee('Open Entrance Kiosk')
            ->assertSee('Open Exit Kiosk')
            ->assertSee(route('stations.entrance'), false)
            ->assertSee(route('stations.exit'), false)
            ->assertSee('data-gate="entrance"', false)
            ->assertSee('data-gate="exit"', false);

        $this->actingAs($this->guard)
            ->getJson(route('gates.state'))
            ->assertOk()
            ->assertJsonStructure(['detector_running', 'gates' => ['entrance' => ['camera_running', 'stream_url', 'latest_scan', 'logs'], 'exit']]);
    }

    public function test_gate_monitor_shows_the_latest_scan_per_gate(): void
    {
        RfidTag::query()->create(['uid' => 'GATE-UNKNOWN-1', 'status' => RfidTag::STATUS_AVAILABLE]);
        app(RfidIngestService::class)->ingest(['tag_uid' => 'GATE-UNKNOWN-1', 'scan_location' => 'exit'], 'station_reader');

        $state = $this->actingAs($this->admin)->getJson(route('gates.state'))->json('gates');

        $this->assertNull($state['entrance']['latest_scan']);
        $this->assertNotNull($state['exit']['latest_scan']);
    }

    public function test_sidebar_shows_the_alert_count(): void
    {
        RfidTag::query()->create(['uid' => 'LOST-PASS-1', 'tag_type' => RfidTag::TYPE_GUEST_PASS, 'status' => RfidTag::STATUS_LOST]);
        app(RfidIngestService::class)->ingest(['tag_uid' => 'LOST-PASS-1', 'scan_location' => 'entrance'], 'station_reader');

        $this->actingAs($this->admin)
            ->get(route('dashboard.index'))
            ->assertSee('class="nav-badge"', false);

        $this->actingAs($this->admin)
            ->get(route('logs.index', ['tab' => 'alerts']))
            ->assertOk()
            ->assertSee('Flagged RFID Scans')
            ->assertSee('LOST-PASS-1');
    }

    public function test_each_settings_tab_saves_only_its_own_fields(): void
    {
        $this->actingAs($this->admin)
            ->from(route('settings.index', ['tab' => 'guest-pass']))
            ->put(route('settings.update'), [
                'section' => 'guest-pass',
                'guest_pass_validity_minutes' => 120,
                'guest_pass_overstay_grace_minutes' => 15,
                'guest_pass_require_id' => '0',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.index', ['tab' => 'guest-pass']));

        $this->assertSame('120', SystemSetting::query()->where('setting_key', 'guest_pass_validity_minutes')->value('setting_value'));
        $this->assertSame('0', SystemSetting::query()->where('setting_key', 'guest_pass_require_id')->value('setting_value'));

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

        $this->assertSame('Main Gate', SystemSetting::query()->where('setting_key', 'entrance_portal_label')->value('setting_value'));
        // The guest pass tab's value was not touched by the stations save.
        $this->assertSame('120', SystemSetting::query()->where('setting_key', 'guest_pass_validity_minutes')->value('setting_value'));

        $this->actingAs($this->admin)
            ->put(route('settings.update'), ['section' => 'stations', 'exit_portal_label' => 'Back Gate'])
            ->assertSessionHasErrors('entrance_portal_label');
    }
}
