<?php

namespace Tests\Feature;

use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\RfidIngestService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI Phase 4: Dashboard, Activity Logs and the Station kiosk. (Guests page removed in Phase 0.)
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

    public function test_dashboard_has_kpis_needs_attention_and_hourly_chart(): void
    {
        // Phase 1: gates have no fixed direction, so the anomaly is a lost tag read.
        $this->vehicle('ANO 101', 'ANO-TAG', 'INSIDE')->rfidTag->forceFill(['status' => 'lost'])->save();
        app(RfidIngestService::class)->ingest(['tag_uid' => 'ANO-TAG', 'scan_location' => 'gate-1']);

        $html = $this->actingAs($this->admin)->get(route('dashboard.index'))->assertOk()->getContent();

        // Phase 7 (visitor model): IN / OUT counts.
        foreach (['Inside Campus', 'IN Today', 'OUT Today', 'IN / OUT Counts', 'Alerts', 'Needs attention', 'Live activity', "Today's traffic"] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // Phase 0: no guest pass card or overstay items.
        $this->assertStringNotContainsString('Active Guests', $html);
        $this->assertStringNotContainsString('Overstay', $html);
        $this->assertStringContainsString('Anomaly', $html);

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
        app(RfidIngestService::class)->ingest(['tag_uid' => 'LOG-TAG', 'scan_location' => 'gate-1']);
        $this->vehicle('ALR 101', 'ALR-TAG', 'INSIDE')->rfidTag->forceFill(['status' => 'lost'])->save();
        app(RfidIngestService::class)->ingest(['tag_uid' => 'ALR-TAG', 'scan_location' => 'gate-1']); // anomaly (lost tag)

        $this->actingAs($this->admin)
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('Registered')
            ->assertDontSee('>Guest Pass<', false)
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
            app(RfidIngestService::class)->ingest(['tag_uid' => 'CSV-TAG-'.$i, 'scan_location' => 'gate-1']);
        }

        $csv = $this->actingAs($this->admin)->get(route('vehicle-events.export.csv', ['all' => 1]))->streamedContent();

        foreach (range(1, 12) as $i) {
            $this->assertStringContainsString('CSV '.$i, $csv);
        }
    }

    public function test_station_kiosk_has_the_big_result_banner_and_short_logs(): void
    {
        $this->vehicle('KSK 101', 'KSK-TAG', 'OUTSIDE');
        app(RfidIngestService::class)->ingest(['tag_uid' => 'KSK-TAG', 'scan_location' => 'gate-1']);

        $this->actingAs($this->admin)
            ->get(route('gates.kiosk', 'gate-1'))
            ->assertOk()
            ->assertSee('data-scan-result', false)
            ->assertSee('READY')
            ->assertSee('station-log-compact', false)
            ->assertSee('KSK 101')
            ->assertDontSee('Entries Today')
            ->assertDontSee('<span>Owner</span>', false);
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
