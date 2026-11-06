<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use App\Models\VehicleEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class ActivityLogTimeFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
    }

    private function event(string $at, string $plate): VehicleEvent
    {
        return VehicleEvent::create(['event_type' => 'ENTRY', 'event_status' => 'completed', 'event_origin' => 'manual',
            'plate_text' => $plate, 'event_time' => $at, 'camera_id' => Camera::forRole('gate-1')->value('id'), 'vehicle_category' => 'faculty_staff']);
    }

    public function test_daily_window_includes_the_whole_end_minute_on_every_selected_date_and_all_exports(): void
    {
        $ids = [];
        foreach (['2026-11-03', '2026-11-04', '2026-11-05'] as $day) {
            foreach (['07:59:59', '08:00:00', '09:00:00', '10:00:00', '10:00:59', '10:01:00'] as $index => $time) {
                $event = $this->event("$day $time", 'WIN'.$day.'X'.$index);
                if ($index >= 1 && $index <= 4) {
                    $ids[] = $event->id;
                }
            }
            foreach (range(1, 8) as $index) {
                $ids[] = $this->event("$day 08:30:00", 'EXTRA'.$day.'X'.$index)->id;
            }
        }
        $this->event('2026-11-02 09:00:00', 'BEFORE');
        $this->event('2026-11-06 09:00:00', 'AFTER');
        $filters = ['date_from' => '2026-11-03', 'date_to' => '2026-11-05', 'time_from' => '08:00', 'time_to' => '10:00'];
        $response = $this->get(route('logs.index', $filters))->assertOk();
        $this->assertSame(count($ids), $response->viewData('logs')->total());
        $this->assertCount(15, $response->viewData('logs')->items());
        $this->assertSame(count($ids), $response->viewData('eventLogSummary')['total']);
        $all = [];
        foreach (range(1, 3) as $page) {
            $data = $this->getJson(route('logs.index', $filters + ['page' => $page]))->assertOk()->json();
            $all = array_merge($all, array_column($data['logs'], 'id'));
        }
        sort($all);
        sort($ids);
        $this->assertSame($ids, $all);
        $print = $this->get(route('logs.print', $filters + ['page' => 2]))->assertOk();
        $this->assertCount(count($ids), $print->viewData('rows'));
        $this->assertSame($ids, VehicleEvent::whereIn('plate_text', $print->viewData('rows')->pluck('plate'))->orderBy('id')->pluck('id')->all());
        $print->assertSee('8:00 AM')->assertSee('10:00 AM')->assertSee('Asia/Manila');
        $csv = $this->get(route('vehicle-events.export.csv', $filters + ['page' => 2]))->assertOk()->streamedContent();
        $this->assertCount(count($ids) + 1, array_filter(explode("\n", trim($csv))));
        $rows = array_map(str_getcsv(...), array_filter(explode("\n", trim($csv))));
        $this->assertSame($ids, VehicleEvent::whereIn('plate_text', array_column(array_slice($rows, 1), 3))->orderBy('id')->pluck('id')->all());
        $this->assertStringNotContainsString('BEFORE', $csv);
        $this->assertStringNotContainsString('AFTER', $csv);
    }

    public static function invalidWindows(): array
    {
        return [[['time_from' => '24:00'], 'time_from'], [['time_to' => '8:00'], 'time_to'], [['time_to' => '10:00:01'], 'time_to'], [['time_from' => ['bad']], 'time_from'],
            [['time_from' => '22:00', 'time_to' => '02:00'], 'time_to'], [['date_from' => '2026-02-30'], 'date_from'],
            [['date_to' => 'next Tuesday'], 'date_to'], [['date_from' => '2026-11-05', 'date_to' => '2026-11-03'], 'date_to']];
    }

    #[DataProvider('invalidWindows')]
    public function test_direct_table_print_and_csv_requests_reject_invalid_windows(array $filters, string $field): void
    {
        foreach (['logs.index', 'logs.print', 'vehicle-events.export.csv'] as $route) {
            $this->getJson(route($route, $filters))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public static function oneSidedWindows(): array
    {
        return [[['time_from' => '08:00'], ['START', 'END', 'NEXT', 'LAST']],
            [['time_to' => '10:00'], ['MIDNIGHT', 'BEFORE', 'START', 'END']],
            [['time_from' => '10:00', 'time_to' => '10:00'], ['END']],
            [['time_from' => '00:00', 'time_to' => '00:00'], ['MIDNIGHT']],
            [['time_from' => '23:59', 'time_to' => '23:59'], ['LAST']], [['time_from' => '', 'time_to' => ''], ['MIDNIGHT', 'BEFORE', 'START', 'END', 'NEXT', 'LAST']]];
    }

    #[DataProvider('oneSidedWindows')]
    public function test_one_sided_blank_equal_minute_and_midnight_windows_without_dates(array $filters, array $expected): void
    {
        foreach (['MIDNIGHT' => '00:00:59', 'BEFORE' => '07:59:59', 'START' => '08:00:00', 'END' => '10:00:59', 'NEXT' => '10:01:00', 'LAST' => '23:59:59'] as $plate => $time) {
            $this->event('2026-11-03 '.$time, $plate);
        }
        $rows = $this->getJson(route('logs.index', $filters))->assertOk()->json('logs');
        $plates = array_column($rows, 'plate_number');
        sort($plates);
        sort($expected);
        $this->assertSame($expected, $plates);
    }

    public function test_occurrence_times_and_manila_midnight_apply_to_every_source_on_a_utc_installation(): void
    {
        $prior = date_default_timezone_get();
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        try {
            $this->event('2026-11-02 15:59:59', 'BEFORE');
            $event = $this->event('2026-11-02 16:00:00', 'MIDNIGHT');
            $event->forceFill(['created_at' => '2026-11-05 05:00:00'])->save(); // Receipt time must not drive filtering.
            $this->event('2026-11-02 16:01:00', 'AFTER');
            $guest = \App\Models\GuestVehicleObservation::create(['plate_number' => 'GUEST', 'observed_at' => '2026-11-02 16:00:59', 'location' => 'gate-1']);
            $scan = \App\Models\RfidScanLog::create(['tag_uid' => 'FIXTURE', 'scan_time' => '2026-11-02 16:00:20', 'scan_location' => 'gate-1', 'verification_status' => 'unknown_tag']);
            $visitor = \App\Models\VisitorRecord::create(['external_event_key' => 'midnight-visitor', 'gate' => 'gate-1', 'direction' => 'IN',
                'seen_at' => '2026-11-02 16:00:30', 'plate_status' => 'unreadable', 'registration_status' => 'review']);
            $filters = ['date_from' => '2026-11-03', 'date_to' => '2026-11-03', 'time_from' => '00:00', 'time_to' => '00:00'];
            $data = $this->getJson(route('logs.index', $filters))->assertOk()->json();
            $this->assertSame(4, $data['total']);
            foreach ($data['logs'] as $row) {
                $this->assertStringContainsString('Nov 3, 2026 · 12:00 AM', $row['display_time']);
                $this->assertStringStartsWith('2026-11-03 00:00:', $row['event_time_export']);
            }
            $this->assertEqualsCanonicalizing(['vehicle_event:'.$event->id, 'guest_observation:'.$guest->id, 'rfid_scan:'.$scan->id, 'visitor_record:'.$visitor->id],
                array_map(fn ($row) => $row['record_type'].':'.$row['id'], $data['logs']));
            $this->get(route('logs.print', $filters))->assertOk()->assertViewHas('dateRange', 'Nov 3, 2026')->assertViewHas('rows', fn ($rows) => $rows->count() === 4);
            $csv = $this->get(route('vehicle-events.export.csv', $filters))->assertOk()->streamedContent();
            $this->assertCount(5, array_filter(explode("\n", trim($csv))));
            $this->assertStringNotContainsString('BEFORE', $csv);
            $this->assertStringNotContainsString('AFTER', $csv);
        } finally {
            date_default_timezone_set($prior);
        }
    }

    public function test_combined_criteria_and_archived_gate_history_survive_the_time_window(): void
    {
        $gate = \App\Models\Gate::create(['code' => 'gate-99', 'name' => 'Historical gate']);
        $gate->forceFill(['archived_at' => now(), 'is_active' => false])->save();
        $camera = Camera::create(['camera_name' => 'Historical camera', 'camera_role' => 'gate-99']);
        $inside = $this->event('2026-11-03 08:30:00', 'ABC-00123');
        $inside->forceFill(['camera_id' => $camera->id, 'match_status' => 'open'])->save();
        $outside = $this->event('2026-11-03 10:01:00', 'ABC-00123');
        $outside->forceFill(['camera_id' => $camera->id, 'match_status' => 'open'])->save();
        $this->event('2026-11-03 08:30:00', 'ABC-00123');
        $filters = ['plate_text' => 'abc001', 'gate' => 'gate-99', 'category' => 'faculty_staff', 'event_type' => 'ENTRY', 'match_status' => 'open', 'time_from' => '08:00', 'time_to' => '10:00'];
        $this->getJson(route('logs.index', $filters))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('logs.0.id', $inside->id)->assertJsonPath('summary.entries', 1);
        $this->get(route('logs.print', $filters))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1)->assertSee('Historical gate');
        $this->get(route('vehicle-events.export.csv', $filters))->assertOk();
        $filters['event_type'] = 'EXIT';
        $this->getJson(route('logs.index', $filters))->assertJsonPath('total', 0);
    }

    public function test_invalid_html_requests_never_redirect_to_broader_results_or_an_export(): void
    {
        foreach (['logs.index', 'logs.print', 'vehicle-events.export.csv'] as $route) {
            $this->get(route($route, ['time_from' => '22:00', 'time_to' => '02:00']))->assertUnprocessable()->assertSee('Overnight periods require separate selections');
        }
    }

    public function test_time_filtered_reads_and_exports_preserve_admin_authorization(): void
    {
        auth()->forgetGuards();
        $this->app['session']->flush();
        foreach (['logs.index', 'logs.print', 'vehicle-events.export.csv'] as $route) {
            $this->getJson(route($route, ['time_from' => '08:00']))->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create(['role' => 'guard']));
        foreach (['logs.index', 'logs.print', 'vehicle-events.export.csv'] as $route) {
            $this->getJson(route($route, ['time_to' => '10:00']))->assertForbidden();
        }
    }

    public function test_existing_period_and_print_presets_retain_the_daily_time_window(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-05 12:00:00', 'Asia/Manila'));
        $this->event('2026-11-02 08:30:00', 'EARLIER');
        $this->event('2026-11-04 08:30:00', 'YESTERDAY');
        $today = $this->event('2026-11-05 08:30:00', 'TODAY');
        $this->event('2026-11-05 11:00:00', 'TOO-LATE');
        $this->getJson(route('logs.index', ['period' => 'today', 'time_from' => '08:00', 'time_to' => '10:00']))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('logs.0.id', $today->id);
        $filters = ['date_from' => '2026-11-03', 'date_to' => '2026-11-05', 'time_from' => '08:00', 'time_to' => '10:00'];
        $this->get(route('logs.print', $filters + ['report' => 'today']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->pluck('plate')->all() === ['TODAY']);
        $this->get(route('logs.print', $filters + ['report' => 'all']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 3)->assertSee('Daily 8:00 AM');
    }
}
