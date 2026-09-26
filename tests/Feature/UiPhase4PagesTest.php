<?php

namespace Tests\Feature;

use App\Models\GuestVisit;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\GuestPassService;
use App\Services\RfidIngestService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI Phase 4: Dashboard, Guests, Activity Logs and the Station kiosk.
 */
class UiPhase4PagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    public function test_dashboard_has_five_kpis_needs_attention_and_hourly_chart(): void
    {
        $visit = $this->issuedVisit('GP-DASH-1', 'DSH 101');
        $visit->forceFill(['status' => GuestVisit::STATUS_OVERSTAY, 'valid_until' => now()->subHour()])->save();

        $this->vehicle('ANO 101', 'ANO-TAG', 'INSIDE');
        app(RfidIngestService::class)->ingest(['tag_uid' => 'ANO-TAG', 'scan_location' => 'entrance']);

        $html = $this->actingAs($this->admin)->get(route('dashboard.index'))->assertOk()->getContent();

        foreach (['Inside Campus', 'Entries Today', 'Exits Today', 'Active Guests', 'Alerts', 'Needs attention', 'Live activity', "Today's traffic"] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringContainsString('Overstay', $html);
        $this->assertStringContainsString('Anomaly', $html);
        $this->assertStringContainsString(route('guest-passes.visits.show', $visit), $html);

        $this->actingAs($this->admin)
            ->getJson(route('dashboard.live-state'))
            ->assertOk()
            ->assertJsonCount(24, 'hourly')
            ->assertJsonPath('attention.0.action_label', fn ($label) => filled($label))
            ->assertJsonStructure(['metrics' => ['alerts_total'], 'attention' => [['kind', 'title', 'action_url']]]);
    }

    public function test_activity_logs_use_one_table_with_chips_and_toolbar_exports(): void
    {
        $this->vehicle('LOG 101', 'LOG-TAG', 'OUTSIDE');
        app(RfidIngestService::class)->ingest(['tag_uid' => 'LOG-TAG', 'scan_location' => 'entrance']);
        $this->vehicle('ALR 101', 'ALR-TAG', 'INSIDE');
        app(RfidIngestService::class)->ingest(['tag_uid' => 'ALR-TAG', 'scan_location' => 'entrance']); // anomaly

        $this->actingAs($this->admin)
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('Registered')
            ->assertSee('Guest Pass')
            ->assertSee('Manual')
            ->assertSee('Alerts')
            ->assertSee('This Week')
            ->assertSee('data-event-log-report-print="current"', false)
            ->assertSee('all=1', false)
            ->assertDontSee('data-event-log-print="0"', false)
            ->assertSee('LOG 101')
            ->assertSee('ALR 101');

        $this->actingAs($this->admin)
            ->get(route('logs.index', ['log_type' => 'alerts']))
            ->assertOk()
            ->assertSee('ALR 101')
            ->assertDontSee('LOG 101');

        $this->actingAs($this->admin)
            ->getJson(route('logs.index', ['log_type' => 'alerts']))
            ->assertOk()
            ->assertJsonPath('logs.0.plate_number', 'ALR 101')
            ->assertJsonPath('logs.0.is_alert', true);
    }

    public function test_toolbar_csv_exports_every_filtered_row(): void
    {
        foreach (range(1, 12) as $i) {
            $this->vehicle('CSV '.$i, 'CSV-TAG-'.$i, 'OUTSIDE');
            app(RfidIngestService::class)->ingest(['tag_uid' => 'CSV-TAG-'.$i, 'scan_location' => 'entrance']);
        }

        $csv = $this->actingAs($this->admin)->get(route('vehicle-events.export.csv', ['all' => 1]))->streamedContent();

        foreach (range(1, 12) as $i) {
            $this->assertStringContainsString('CSV '.$i, $csv);
        }
    }

    public function test_guests_page_lists_active_visits_above_history(): void
    {
        $active = $this->issuedVisit('GP-ACT-1', 'ACT 101');
        $overstay = $this->issuedVisit('GP-OVR-1', 'OVR 101');
        $overstay->forceFill(['status' => GuestVisit::STATUS_OVERSTAY])->save();
        $done = $this->issuedVisit('GP-DONE-1', 'DON 101');
        app(GuestPassService::class)->closeManually($done, 'Test');

        $html = $this->actingAs($this->admin)->get(route('guests.index'))->assertOk()->getContent();

        $inside = strpos($html, 'Inside now');
        $history = strpos($html, 'History');
        $this->assertNotFalse($inside);
        $this->assertLessThan($history, $inside);
        $this->assertLessThan($history, strpos($html, 'ACT 101'));
        $this->assertLessThan(strpos($html, 'ACT 101'), strpos($html, 'OVR 101'), 'Overstay is listed first.');
        $this->assertGreaterThan($history, strpos($html, 'DON 101'));
        $this->assertStringContainsString('is-alert-row', $html);
        $this->assertStringContainsString('data-duration-since', $html);
        $this->assertStringContainsString('close-visit-modal', $html);
        $this->assertNotNull($active);
    }

    public function test_station_kiosk_has_the_big_result_banner_and_short_logs(): void
    {
        $this->vehicle('KSK 101', 'KSK-TAG', 'OUTSIDE');
        app(RfidIngestService::class)->ingest(['tag_uid' => 'KSK-TAG', 'scan_location' => 'entrance']);

        $this->actingAs($this->admin)
            ->get(route('stations.entrance'))
            ->assertOk()
            ->assertSee('data-scan-result', false)
            ->assertSee('READY')
            ->assertSee('station-log-compact', false)
            ->assertSee('KSK 101')
            ->assertDontSee('Entries Today')
            ->assertDontSee('<span>Owner</span>', false);
    }

    protected function issuedVisit(string $uid, string $plate): GuestVisit
    {
        $pass = RfidTag::query()->create(['uid' => $uid, 'tag_type' => RfidTag::TYPE_GUEST_PASS, 'status' => RfidTag::STATUS_AVAILABLE]);

        return app(GuestPassService::class)->issue($pass, ['plate' => $plate, 'id_presented' => 'UMID']);
    }

    protected function vehicle(string $plate, string $uid, string $state): Vehicle
    {
        $vehicle = Vehicle::query()->create([
            'plate_number' => $plate,
            'vehicle_owner_name' => 'Owner '.$plate,
            'category' => 'faculty_staff',
            'vehicle_type' => 'Car',
        ]);
        $tag = RfidTag::query()->create(['uid' => $uid, 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $uid, 'current_state' => $state])->save();

        return $vehicle;
    }
}
