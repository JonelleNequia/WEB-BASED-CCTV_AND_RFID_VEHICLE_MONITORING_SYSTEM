<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\Gate;
use App\Models\GuestVehicleObservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\MovementCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class DashboardOccurrenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        $this->travelTo(\Carbon\Carbon::parse('2026-11-04 12:00:00', 'Asia/Manila'));
    }

    private function event(array $values = []): VehicleEvent
    {
        return VehicleEvent::create($values + ['event_type' => 'ENTRY', 'event_status' => 'completed',
            'event_origin' => 'manual', 'plate_text' => '395ISX', 'event_time' => now(),
            'camera_id' => Camera::forRole('gate-1')->value('id'), 'match_status' => 'open']);
    }

    private function visitor(string $key, array $values = []): VisitorRecord
    {
        return VisitorRecord::create($values + ['external_event_key' => $key, 'seen_at' => now(), 'gate' => 'gate-1',
            'direction' => 'IN', 'plate_number' => '395ISX', 'plate_status' => 'read', 'plate_confidence' => .99, 'registration_status' => 'guest']);
    }

    public function test_linked_evidence_is_one_row_but_separate_same_plate_same_time_events_stay_distinct(): void
    {
        $vehicle = Vehicle::create(['plate_number' => '395ISX', 'category' => 'faculty_staff']);
        $record = $this->visitor('linked-ocr', ['vehicle_id' => $vehicle->id, 'registration_status' => 'registered']);
        $event = $this->event(['vehicle_id' => $vehicle->id, 'visitor_record_id' => $record->id,
            'external_event_key' => 'linked-ocr', 'event_origin' => 'plate_review']);
        GuestVehicleObservation::create(['external_event_key' => 'linked-ocr', 'observed_at' => now(), 'location' => 'gate-1', 'plate_number' => '395ISX']);
        $second = $this->event(['vehicle_id' => $vehicle->id]);
        $review = $this->visitor('review-only', ['direction' => 'UNKNOWN', 'plate_status' => 'unreadable', 'registration_status' => 'review']);
        GuestVehicleObservation::create(['external_event_key' => 'review-only', 'observed_at' => now(), 'location' => 'gate-1', 'plate_number' => '395ISX']);
        $guest = $this->visitor('guest-only');
        $legacy = GuestVehicleObservation::create(['observed_at' => now(), 'location' => 'gate-2', 'plate_number' => 'LEG123', 'detection_metadata_json' => ['direction' => 'OUT']]);
        $rows = collect($this->getJson(route('dashboard.live-state'))->assertOk()->json('latest_events'));
        $expected = ['vehicle_event:'.$event->id, 'vehicle_event:'.$second->id, 'visitor_record:'.$review->id,
            'visitor_record:'.$guest->id, 'guest_observation:'.$legacy->id];
        $this->assertCount(5, $rows);
        $this->assertEqualsCanonicalizing($expected, $rows->pluck('key')->all());
        $logs = $this->getJson(route('logs.index'))->assertOk()->json('logs');
        $this->assertEqualsCanonicalizing($expected, array_map(fn ($log) => $log['record_type'].':'.$log['id'], $logs));
        $this->assertSame(2, $rows->where('title', 'IN • 395ISX')->where('badge_label', 'Registered')->count());
        $this->assertSame('Direction unknown', $rows->firstWhere('key', 'visitor_record:'.$review->id)['direction_label']);
    }

    public function test_direction_uses_the_recorded_occurrence_rather_than_stale_detector_metadata(): void
    {
        $event = $this->event(['event_type' => 'EXIT', 'match_status' => 'closed', 'detection_metadata_json' => ['direction' => 'UNKNOWN']]);
        $unknown = $this->event(['event_type' => 'UNKNOWN', 'detection_metadata_json' => ['direction' => 'IN']]);
        $visitor = $this->visitor('guest-unknown', ['direction' => 'UNKNOWN']);
        $rows = collect($this->getJson(route('dashboard.live-state'))->assertOk()->json('latest_events'));
        $this->assertSame('OUT • 395ISX', $rows->firstWhere('key', 'vehicle_event:'.$event->id)['title']);
        $this->assertSame('Direction unknown • 395ISX', $rows->firstWhere('key', 'vehicle_event:'.$unknown->id)['title']);
        $this->assertNotContains('Exit', $rows->pluck('badge_label'));
        $this->assertSame('Direction unknown', $rows->firstWhere('key', 'visitor_record:'.$visitor->id)['direction_label']);
        $this->assertNotContains('Direction unknown', $rows->pluck('badge_label'));
    }

    public function test_pending_to_completed_evidence_keeps_one_row_without_replaying_movements(): void
    {
        $legacy = GuestVehicleObservation::create(['external_event_key' => 'late-canonical', 'observed_at' => now(),
            'location' => 'gate-1', 'plate_number' => '395ISX', 'detection_metadata_json' => ['direction' => 'IN']]);
        $event = $this->event(['external_event_key' => 'late-canonical', 'event_status' => VehicleEvent::STATUS_PENDING_DETAILS,
            'event_origin' => 'guest_cctv']);
        $this->getJson(route('dashboard.live-state'))->assertOk()->assertJsonCount(1, 'latest_events')
            ->assertJsonPath('latest_events.0.key', 'guest_observation:'.$legacy->id);
        $event->forceFill(['event_status' => VehicleEvent::STATUS_COMPLETED])->save();
        $this->getJson(route('dashboard.live-state'))->assertOk()->assertJsonCount(1, 'latest_events')
            ->assertJsonPath('latest_events.0.key', 'vehicle_event:'.$event->id);
        $this->getJson(route('dashboard.live-state'))->assertJsonCount(1, 'latest_events');
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('guest_vehicle_observations', 1);
    }

    public function test_dashboard_gate_rows_match_gate_monitor_and_period_logs_while_totals_keep_history(): void
    {
        Gate::where('code', 'gate-1')->update(['name' => 'Renamed Main Gate']);
        Gate::where('code', 'gate-2')->update(['is_active' => false]);
        $zero = Gate::create(['code' => 'gate-3', 'name' => 'Service Gate', 'is_active' => true, 'sort_order' => 3]);
        $archived = Gate::create(['code' => 'gate-4', 'name' => 'Former Gate', 'is_active' => false]);
        $archived->forceFill(['archived_at' => now()])->save();
        $oldCamera = Camera::create(['camera_role' => 'gate-4', 'camera_name' => 'Historical camera']);
        foreach (['2026-11-04', '2026-11-02', '2026-10-31', '2026-01-01', '2025-12-31'] as $day) {
            $this->event(['event_time' => $day.' 10:00:00']);
        }
        $this->event(['event_time' => '2026-11-04 11:00:00', 'camera_id' => $oldCamera->id]);
        $this->event(['event_type' => 'EXIT', 'camera_id' => Camera::forRole('gate-2')->value('id')]);
        $data = $this->getJson(route('dashboard.live-state'))->assertOk()->json();
        $this->assertSame(['gate-1', $zero->code], array_keys($this->get(route('gates.index'))->assertOk()->viewData('gates')));
        foreach (array_keys(MovementCountService::PERIODS) as $period) {
            $counts = $data['movement_counts'][$period];
            $this->assertSame(Gate::codes(), array_keys($counts['gates']));
            $this->assertSame('Renamed Main Gate', $counts['gates']['gate-1']['label']);
            $this->assertSame(['label' => 'Service Gate', 'in' => 0, 'out' => 0], $counts['gates']['gate-3']);
            foreach (['ENTRY' => 'in', 'EXIT' => 'out'] as $direction => $key) {
                $all = $this->getJson(route('logs.index', ['period' => $period, 'event_type' => $direction]))->assertOk()->json('total');
                $this->assertSame($all, $counts[$key]);
                foreach (Gate::codes() as $code) {
                    $logs = $this->getJson(route('logs.index', ['period' => $period, 'event_type' => $direction, 'gate' => $code]))->assertOk()->json('total');
                    $this->assertSame($logs, $counts['gates'][$code][$key]);
                }
            }
        }
        $this->assertArrayHasKey('gate-4', app(MovementCountService::class)->counts('today')['gates']);
        $this->get(route('dashboard.index'))->assertOk()->assertSee('Operational gates')->assertSee('including inactive and archived gates');
        $this->getJson(route('logs.index', ['gate' => 'gate-4']))->assertJsonPath('total', 1);
    }

    public function test_review_count_matches_the_review_tab_including_unknown_registered_events_and_zero(): void
    {
        $this->getJson(route('dashboard.live-state'))->assertJsonPath('metrics.review_count', 0);
        $vehicle = Vehicle::create(['plate_number' => 'REV123', 'category' => 'faculty_staff']);
        $unknown = $this->event(['event_type' => 'UNKNOWN', 'vehicle_id' => $vehicle->id]);
        $this->visitor('review', ['direction' => 'UNKNOWN', 'plate_status' => 'unreadable', 'registration_status' => 'review']);
        $this->visitor('dismissed', ['direction' => 'UNKNOWN', 'plate_status' => 'unreadable', 'status' => 'dismissed']);
        $count = $this->get(route('visitors.index', ['tab' => 'review']))->assertOk()->viewData('reviewCount');
        $this->assertSame(2, $count);
        $this->getJson(route('dashboard.live-state'))->assertJsonPath('metrics.review_count', $count);
        $this->patchJson(route('vehicle-events.direction', $unknown), ['direction' => 'OUT'])->assertRedirect();
        $this->getJson(route('dashboard.live-state'))->assertJsonPath('metrics.review_count', 1);
        $response = $this->get(route('dashboard.index'))->assertOk()->assertDontSee("Today's traffic")->assertDontSee('data-dashboard-chart', false);
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//section[@data-dashboard-needs-attention]//a[@data-dashboard-review]')->length);
        $this->assertSame(0, $xpath->query('//a[@data-dashboard-review]/ancestor::a')->length);
    }

    public function test_no_operational_gates_retains_global_history_and_existing_authorization(): void
    {
        $this->event();
        Gate::query()->update(['is_active' => false]);
        $this->getJson(route('dashboard.live-state'))->assertOk()->assertJsonPath('movement_counts.today.gates', [])
            ->assertJsonPath('movement_counts.today.in', 1);
        $this->get(route('dashboard.index'))->assertOk()->assertSee('No operational gates configured.');
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->actingAs(User::factory()->create(['role' => 'guard']));
        foreach (['dashboard.index', 'dashboard.live-state'] as $route) {
            $this->getJson(route($route))->assertForbidden();
        }
        auth()->forgetGuards();
        $this->app['session']->flush();
        $this->getJson(route('dashboard.live-state'))->assertUnauthorized();
    }

    public function test_legacy_directions_and_unknowns_agree_with_activity_report_summaries(): void
    {
        $this->event();
        foreach (['IN', 'OUT', 'UNKNOWN'] as $index => $direction) {
            GuestVehicleObservation::create(['plate_number' => 'LEG'.(1000 + $index), 'observed_at' => now(),
                'location' => 'gate-1', 'observation_source' => 'cctv', 'detection_metadata_json' => ['direction' => $direction]]);
        }
        foreach (array_keys(MovementCountService::PERIODS) as $period) {
            $counts = $this->getJson(route('dashboard.live-state'))->assertOk()->json('movement_counts.'.$period);
            $report = $this->get(route('logs.print', ['period' => $period]))->assertOk();
            $summary = $report->viewData('byGate');
            foreach (['in', 'out', 'unknown'] as $key) {
                $this->assertSame($summary['total'][$key], $counts[$key]);
            }
            $main = collect($summary['rows'])->firstWhere('label', Gate::labelFor('gate-1'));
            $this->assertSame($main['in'], $counts['gates']['gate-1']['in']);
            $this->assertSame($main['out'], $counts['gates']['gate-1']['out']);
        }
    }
}
