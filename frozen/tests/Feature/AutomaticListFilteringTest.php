<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\RfidScanLog;
use App\Models\User;
use App\Models\VehicleEvent;
use App\Services\RfidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class AutomaticListFilteringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->admin = User::query()->where('role', 'admin')->firstOrFail();
    }

    public static function lists(): array
    {
        return [['registry.index', 'vehicles', 'registry-vehicles'], ['registry.index', 'tags', 'registry-tags'],
            ['visitors.index', 'records', 'visitors-records'], ['visitors.index', 'plates', 'visitors-plates'],
            ['logs.index', 'events', 'logs-events'], ['logs.index', 'scans', 'logs-scans'], ['logs.index', 'alerts', 'logs-alerts']];
    }

    #[DataProvider('lists')]
    public function test_existing_lists_expose_html_results_without_changing_routes_or_post_forms(string $route, string $tab, string $id): void
    {
        $response = $this->actingAs($this->admin)->withHeaders(['Accept' => 'text/html', 'X-Auto-Filter' => '1'])
            ->get(route($route, ['tab' => $tab]))->assertOk()->assertSee('data-auto-list="'.$id.'"', false)
            ->assertSee('data-auto-update="results"', false)->assertSee('js/auto-filters.js', false);
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//form[@data-auto-filter and @method="GET"]')->length);
        $this->assertSame(0, $xpath->query('//form[@data-auto-filter and @method="POST"]')->length);
        $this->assertSame(0, $xpath->query('//form[@data-auto-filter]//button[@type="submit" and not(ancestor::noscript)]')->length);
    }

    public function test_automatic_requests_receive_field_errors_without_redirecting_to_broader_results(): void
    {
        $this->actingAs($this->admin)->withHeaders(['Accept' => 'text/html', 'X-Auto-Filter' => '1'])
            ->get(route('logs.index', ['plate_text' => '0', 'date_from' => '2026-10-31', 'date_to' => '2026-10-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('date_to')->assertJsonMissingPath('errors.plate_text');
        $this->get(route('registry.index', ['q' => ['bad']]))->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_zero_is_a_real_scan_search_and_pagination_keeps_the_combined_filters(): void
    {
        foreach (range(1, 15) as $index) {
            RfidScanLog::query()->create(['tag_uid' => 'UID0-'.$index, 'scan_location' => 'gate-1', 'scan_time' => now(),
                'verification_status' => 'unknown_tag', 'fusion_status' => 'scan_only']);
        }
        RfidScanLog::query()->create(['tag_uid' => 'RFIDXY', 'scan_location' => 'gate-1', 'scan_time' => now(), 'verification_status' => 'unknown_tag']);
        $filters = ['history_q' => '0', 'scan_location' => 'gate-1', 'verification_status' => 'unknown_tag'];
        $page = app(RfidService::class)->scanHistory($filters);
        $this->assertSame(15, $page->total());
        $this->assertCount(12, $page->items());
        $response = $this->actingAs($this->admin)->get(route('logs.index', ['tab' => 'scans'] + $filters))->assertOk();
        $this->assertSame(15, $response->viewData('scanLogs')->total());
        parse_str(parse_url($response->viewData('scanLogs')->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame(['tab' => 'scans'] + $filters + ['page' => '2'], $query);
    }

    public function test_event_filter_pagination_and_exports_use_the_same_one_character_criteria(): void
    {
        $camera = Camera::query()->forRole('gate-1')->firstOrFail();
        foreach (range(1, 22) as $index) {
            VehicleEvent::query()->create(['event_type' => 'ENTRY', 'event_status' => 'completed', 'event_origin' => 'manual',
                'plate_text' => 'ZERO-000'.$index, 'camera_id' => $camera->id, 'vehicle_type' => 'Car', 'event_time' => now(), 'match_status' => 'open']);
        }
        VehicleEvent::query()->create(['event_type' => 'ENTRY', 'event_status' => 'completed', 'event_origin' => 'manual',
            'plate_text' => 'OTHER', 'camera_id' => $camera->id, 'vehicle_type' => 'Car', 'event_time' => now(), 'match_status' => 'open']);
        $filters = ['plate_text' => '0', 'gate' => 'gate-1', 'event_type' => 'ENTRY', 'date_from' => now()->toDateString(), 'date_to' => now()->toDateString()];
        $response = $this->actingAs($this->admin)->withHeaders(['Accept' => 'text/html', 'X-Auto-Filter' => '1'])
            ->get(route('logs.index', ['tab' => 'events'] + $filters))->assertOk();
        $this->assertSame(22, $response->viewData('logs')->total());
        parse_str(parse_url($response->viewData('logs')->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame(['tab' => 'events'] + $filters + ['page' => '2'], $query);
        $csv = $this->get(route('vehicle-events.export.csv', $filters + ['all' => 1]))->assertOk()->streamedContent();
        $this->assertCount(23, explode("\n", trim($csv)));
        $this->assertStringNotContainsString('OTHER', $csv);
        $this->get(route('logs.print', $filters))->assertOk()->assertDontSee('OTHER');
    }

    public function test_edit_review_and_settings_forms_are_outside_automatic_filtering(): void
    {
        foreach ([route('settings.index'), route('visitors.index', ['tab' => 'review']), route('vehicle-events.create')] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertDontSee('data-auto-filter', false)->assertDontSee('js/auto-filters.js', false);
        }
    }

    public function test_list_reads_keep_the_existing_authentication_and_admin_boundaries(): void
    {
        $this->withHeaders(['Accept' => 'application/json', 'X-Auto-Filter' => '1'])->get(route('registry.index'))->assertUnauthorized();
        $guard = User::factory()->create(['role' => 'guard']);
        $this->actingAs($guard)->get(route('registry.index'))->assertForbidden();
    }
}
