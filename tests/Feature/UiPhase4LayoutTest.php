<?php

namespace Tests\Feature;

use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\RfidIngestService;
use App\Services\VisitorRecordService;
use App\Support\CameraFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * UI Phase 4 (layout): compact pages, sidebar dots, one result per gate,
 * Visitors menus, shorter Gates & Readers, empty states after a reset.
 */
class UiPhase4LayoutTest extends TestCase
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

    public function test_sidebar_shows_three_dots_with_tooltips_and_no_reader_details(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard.index'))->assertOk()->getContent();

        foreach (['detector', 'cameras', 'readers'] as $key) {
            $this->assertStringContainsString('data-health="'.$key.'"', $html);
        }
        $this->assertMatchesRegularExpression('/data-health="cameras" title="Cameras: [^"]+"/', $html);
        $this->assertStringNotContainsString('No tag read yet', $html);
        $this->assertStringContainsString('href="'.route('settings.index', ['tab' => 'status']).'"', $html);

        $this->actingAs($this->admin)->getJson(route('system.health'))
            ->assertOk()
            ->assertJsonCount(3, 'health')
            ->assertJsonStructure(['health' => [['key', 'label', 'ok', 'detail']]]);
    }

    public function test_dashboard_shows_one_period_at_a_time_and_the_latest_eight(): void
    {
        $vehicle = $this->vehicle('DSH 101', 'DSH-TAG');
        foreach (range(1, 10) as $i) {
            $this->travel(2)->minutes();
            app(RfidIngestService::class)->ingest(['tag_uid' => 'DSH-TAG', 'scan_location' => 'gate-1']);
        }

        $html = $this->actingAs($this->admin)->get(route('dashboard.index'))->assertOk()->getContent();

        // Today shown, the other periods behind the segmented control.
        $this->assertStringContainsString('data-segment-panel="today" >', $html);
        foreach (['week', 'month', 'year'] as $period) {
            $this->assertStringContainsString('data-segment-panel="'.$period.'"  hidden', $html);
        }
        // Both rankings in one card with tabs; "View all" for live activity.
        $this->assertStringContainsString('data-segments="dashboard-ranking"', $html);
        $this->assertStringContainsString('View all', $html);
        $this->assertSame(8, substr_count($html, 'class="stream-item stream-item-compact"'));
        $this->assertCount(8, $this->actingAs($this->admin)->getJson(route('dashboard.live-state'))->json('latest_events'));
        $this->assertNotNull($vehicle);
    }

    public function test_kiosk_and_gate_monitor_show_the_latest_vehicle_of_their_gate(): void
    {
        $this->vehicle('BIG 202', 'BIG-TAG');
        app(RfidIngestService::class)->ingest(['tag_uid' => 'BIG-TAG', 'scan_location' => 'gate-1']);

        // Gate 1: IN, plate, category. Gate 2: nothing there yet (the list still shows every gate).
        $this->actingAs($this->admin)->get(route('gates.kiosk', 'gate-1'))->assertOk()
            ->assertSee('<strong class="scan-result-word" data-scan-word>IN</strong>', false)
            ->assertSee('<span class="scan-result-title" data-scan-title>BIG 202</span>', false)
            ->assertSee('class="scan-result is-verified"', false);
        $this->actingAs($this->admin)->get(route('gates.kiosk', 'gate-2'))->assertOk()
            ->assertSee('class="scan-result is-idle"', false)
            ->assertSee('READY')
            ->assertSee('BIG 202');

        $logs = $this->actingAs($this->admin)->getJson(route('stations.state', 'gate-1'))->json('logs');
        $this->assertSame(['IN', 'IN', 'Faculty & Staff', 'gate-1', 'info'],
            [$logs[0]['direction'], $logs[0]['direction_label'], $logs[0]['category_label'], $logs[0]['gate'], $logs[0]['tone']]);

        $this->actingAs($this->admin)->get(route('gates.index'))->assertOk()
            ->assertSee('class="gate-latest result-verified"', false)
            ->assertSee('<span class="gate-latest-direction">IN</span>', false);
    }

    public function test_an_offline_camera_gets_a_small_placeholder(): void
    {
        File::ensureDirectoryExists(CameraFiles::directory());
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'cameras' => ['gate-1' => ['camera_running' => true], 'gate-2' => ['camera_running' => false, 'last_error' => 'The camera did not answer in time. Check its cable.']],
        ]));

        $html = $this->actingAs($this->admin)->get(route('gates.index'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'class="feed-offline"'));
        $this->assertStringContainsString('Check connection', $html);
        $this->assertMatchesRegularExpression('/data-gate="gate-2".*?class="gate-feed is-offline"/s', $html);

        // Guards get the placeholder without the Settings link.
        $guard = User::query()->create(['name' => 'Guard', 'email' => 'guard.u4@philcst.local', 'password' => bcrypt('password'), 'role' => 'guard']);
        $this->actingAs($guard)->get(route('gates.kiosk', 'gate-2'))->assertOk()
            ->assertSee('data-frame-message', false)
            ->assertDontSee('Check connection');
    }

    public function test_visitors_rows_have_one_menu_and_plates_are_ranked_by_entries(): void
    {
        $service = app(VisitorRecordService::class);
        $service->createManual(['gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now()->subHour(), 'plate_number' => 'ONE 111'], $this->admin);
        foreach ([3, 2] as $hours) {
            $service->createManual(['gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now()->subHours($hours), 'plate_number' => 'TWO 222'], $this->admin);
        }

        $html = $this->actingAs($this->admin)->get(route('visitors.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-visitor-action="plate"', $html);
        $this->assertStringContainsString('data-visitor-action="note"', $html);
        $this->assertStringContainsString('data-visitor-action="dismiss"', $html);
        $this->assertStringContainsString('id="visitor-plate-modal"', $html);
        $this->assertStringNotContainsString('<th>Plate profile</th>', $html);

        $plates = $this->actingAs($this->admin)->get(route('visitors.index', ['tab' => 'plates']))->assertOk()->getContent();
        $this->assertLessThan(strpos($plates, '>ONE 111<'), strpos($plates, '>TWO 222<'));
        $this->assertStringContainsString('Register this vehicle', $plates);
        $this->assertStringContainsString('data-visitor-action="merge"', $plates);
        $this->assertStringContainsString('id="visitor-merge-modal"', $plates);

        $recent = $this->actingAs($this->admin)->get(route('visitors.index', ['tab' => 'plates', 'sort' => 'recent']))->getContent();
        $this->assertLessThan(strpos($recent, '>TWO 222<'), strpos($recent, '>ONE 111<'));
    }

    public function test_gates_and_readers_lists_devices_first_with_a_short_warning(): void
    {
        $html = $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'devices']))->assertOk()->getContent();

        $this->assertStringContainsString('data-devices-banner', $html);
        $this->assertLessThan(strpos($html, 'data-devices-stations'), strpos($html, 'data-devices-list'));
        $this->assertLessThan(strpos($html, 'data-devices-network'), strpos($html, 'data-devices-stations'));
    }

    public function test_empty_tables_after_a_reset_say_nothing_has_passed_yet(): void
    {
        $this->actingAs($this->admin)->get(route('logs.index'))->assertOk()
            ->assertSee('No vehicles have passed yet')
            ->assertDontSee('No records matched the current filters');
        $this->actingAs($this->admin)->get(route('logs.index', ['plate_text' => 'ZZZ']))->assertOk()
            ->assertSee('No records matched the current filters');
        $this->actingAs($this->admin)->get(route('logs.index', ['tab' => 'scans']))->assertOk()->assertSee('No tag reads yet');
        $this->actingAs($this->admin)->get(route('dashboard.index'))->assertOk()
            ->assertSee('No vehicles have passed yet')
            ->assertSee('No registered vehicle has entered yet.');
        $this->actingAs($this->admin)->get(route('gates.kiosk', 'gate-1'))->assertOk()->assertSee('No vehicles have passed yet');
    }

    protected function vehicle(string $plate, string $uid): Vehicle
    {
        $vehicle = Vehicle::query()->create(['plate_number' => $plate, 'vehicle_owner_name' => 'Owner', 'category' => 'faculty_staff', 'vehicle_type' => 'Car']);
        $tag = RfidTag::query()->create(['uid' => $uid, 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        return $vehicle;
    }
}
