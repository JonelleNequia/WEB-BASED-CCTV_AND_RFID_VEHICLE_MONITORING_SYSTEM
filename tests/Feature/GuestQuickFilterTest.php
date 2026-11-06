<?php

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VisitorRecord;
use App\Services\PlateMatchService;
use App\Support\VisitorRecordLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class GuestQuickFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
    }

    private function record(array $attributes = []): VisitorRecord
    {
        return VisitorRecord::create($attributes + ['external_event_key' => 'quick-'.\Illuminate\Support\Str::uuid(), 'gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now()->subDay(),
            'status' => 'active', 'source' => 'camera', 'plate_status' => 'read', 'plate_number' => 'ABC 1234',
            'plate_confidence' => 0.95, 'registration_status' => 'guest']);
    }

    private function ids(array $filters = []): array
    {
        return $this->get(route('visitors.index', $filters))->assertOk()->viewData('records')->pluck('id')->sort()->values()->all();
    }

    public function test_quick_filters_use_occurrence_decisions_and_source_not_current_registry_or_readable_text(): void
    {
        $vehicle = Vehicle::create(['plate_number' => 'ABC 1234', 'category' => 'faculty_staff']);
        $oldGuest = $this->record(['vehicle_id' => $vehicle->id]);
        $registered = $this->record(['vehicle_id' => $vehicle->id, 'registration_status' => 'registered', 'registration_category' => 'faculty_staff']);
        $manual = $this->record(['source' => 'manual', 'plate_status' => 'corrected', 'corrected_by' => auth()->id()]);
        $cameraCorrected = $this->record(['plate_status' => 'corrected', 'corrected_by' => auth()->id()]);
        $review = $this->record(['registration_status' => 'review', 'registration_reason' => 'Confirm the identifier type.']);
        $this->assertSame([$registered->id], $this->ids(['record_filter' => 'registered']));
        $this->assertSame([$oldGuest->id, $manual->id, $cameraCorrected->id], $this->ids(['record_filter' => 'unregistered']));
        $this->assertSame([$manual->id], $this->ids(['record_filter' => 'manual']));
        $this->assertCount(5, $this->ids());
        foreach (['registered' => 'registered', 'unregistered' => 'unregistered_visitor'] as $filter => $logType) {
            $logs = $this->getJson(route('logs.index', ['log_type' => $logType]))->assertOk()->json('logs');
            $ids = collect($logs)->where('record_type', 'visitor_record')->pluck('id')->sort()->values()->all();
            $this->assertSame($ids, $this->ids(['record_filter' => $filter]));
        }
        $vehicle->forceFill(['status' => 'inactive', 'category' => 'student'])->save();
        $this->assertSame([$registered->id], $this->ids(['record_filter' => 'registered']));
        $this->assertContains($oldGuest->id, $this->ids(['record_filter' => 'unregistered']));
        $this->assertNotContains($review->id, $this->ids(['record_filter' => 'unregistered']));
    }

    public function test_legacy_and_review_query_predicates_match_the_existing_display_contract(): void
    {
        $earlier = Vehicle::create(['plate_number' => 'OLD 1234', 'category' => 'faculty_staff']);
        $earlier->forceFill(['created_at' => now()->subDays(2)])->save();
        $later = Vehicle::create(['plate_number' => 'NEW 1234', 'category' => 'faculty_staff']);
        $expected = ['registered' => [], 'unregistered' => []];
        foreach ([null, 'guest', 'registered', 'review'] as $decision) {
            foreach ([null, $earlier->id, $later->id] as $vehicle) {
                foreach ([[], ['plate_status' => 'unreadable'], ['plate_confidence' => 0.2], ['match_confidence' => PlateMatchService::LOW],
                    ['plate_confidence' => null], ['plate_status' => 'unreadable', 'reviewed_at' => now()],
                    ['plate_status' => 'unreadable', 'status' => 'dismissed'], ['registration_reason' => 'No plate visible (checked by staff).']] as $evidence) {
                    $record = $this->record($evidence + ['registration_status' => $decision, 'registration_category' => 'faculty_staff', 'vehicle_id' => $vehicle]);
                    $type = VisitorRecordLog::fields($record->fresh())['log_type'];
                    if ($type === 'registered' || $type === 'unregistered_visitor') {
                        $expected[$type === 'registered' ? 'registered' : 'unregistered'][] = $record->id;
                    }
                }
            }
        }
        foreach ($expected as $filter => $ids) {
            $actual = [];
            for ($page = 1; $page <= (int) ceil(count($ids) / 20); $page++) {
                $actual = array_merge($actual, $this->ids(['record_filter' => $filter, 'status' => 'all', 'page' => $page]));
            }
            sort($actual);
            $this->assertSame($ids, $actual, $filter.' must match the presentation, including nullable legacy evidence.');
        }
    }

    public function test_confirmed_review_occurrence_filters_agree_with_its_canonical_activity_event_without_extra_effects(): void
    {
        $vehicle = Vehicle::create(['plate_number' => '395ISX', 'category' => 'faculty_staff', 'vehicle_type' => 'Car']);
        $vehicle->forceFill(['created_at' => now()->subDays(2)])->save();
        $record = $this->record(['registration_status' => 'review', 'plate_number' => null, 'ocr_plate_number' => '395ISX']);
        $this->assertSame([], $this->ids(['record_filter' => 'registered']));
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => '395ISX'])
            ->assertOk()->assertJsonPath('registration_status', 'registered');
        $record->refresh();
        $before = $record->getRawOriginal();
        $vehicleBefore = $vehicle->fresh()->getRawOriginal();
        $this->assertSame([$record->id], $this->ids(['record_filter' => 'registered']));
        $this->assertSame([], $this->ids(['record_filter' => 'unregistered']));
        $this->assertSame([], $this->ids(['record_filter' => 'manual']));
        $this->getJson(route('logs.index', ['log_type' => 'registered']))->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('logs.0.record_type', 'vehicle_event')
            ->assertJsonPath('logs.0.visitor_record_id', $record->id);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('review_actions', 1);
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        $this->assertSame($vehicleBefore, $vehicle->fresh()->getRawOriginal());
    }

    public function test_combined_filters_archived_gate_history_pagination_and_empty_results(): void
    {
        Gate::where('code', 'gate-2')->update(['archived_at' => now()]);
        $ids = [];
        foreach (range(1, 23) as $number) {
            $ids[] = $this->record(['source' => 'manual', 'gate' => 'gate-2', 'plate_status' => 'corrected', 'plate_number' => 'WIN 0123'])->id;
        }
        $this->record(['source' => 'camera', 'gate' => 'gate-2', 'plate_status' => 'corrected', 'plate_number' => 'WIN 0123']);
        $this->record(['source' => 'manual', 'gate' => 'gate-2', 'plate_status' => 'corrected', 'plate_number' => 'WIN 0123', 'status' => 'duplicate']);
        $filters = ['record_filter' => 'manual', 'q' => 'win-01', 'gate' => 'gate-2', 'plate_status' => 'corrected'];
        $page = $this->get(route('visitors.index', $filters))->assertOk();
        $this->assertSame(23, $page->viewData('records')->total());
        $this->assertCount(20, $page->viewData('records'));
        $url = $page->viewData('records')->nextPageUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame($filters + ['page' => '2'], $query);
        $this->assertCount(3, $this->get($url)->assertOk()->viewData('records'));
        $this->assertCount(1, $this->ids($filters + ['status' => 'duplicate']));
        $this->assertSame(24, $this->get(route('visitors.index', $filters + ['status' => 'all']))->assertOk()->viewData('records')->total());
        $this->assertSame([], $this->ids(array_replace($filters, ['q' => 'MISSING'])));
        $page->assertSee('gate-2')->assertSee('Historical filter:');
    }

    public function test_all_retains_other_criteria_reset_defaults_and_accessible_links(): void
    {
        $this->record(['source' => 'manual']);
        $filters = ['record_filter' => 'manual', 'q' => 'ABC', 'gate' => 'gate-1', 'plate_status' => 'read', 'status' => 'all', 'page' => 2];
        $response = $this->get(route('visitors.index', $filters))->assertOk();
        $html = $response->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $links = $xpath->query('//*[@data-auto-update="record-filters"]/a');
        $this->assertCount(4, $links);
        $this->assertSame(['All', 'Registered', 'Unregistered Guests', 'Manual entries'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($links)));
        parse_str(parse_url($links[0]->getAttribute('href'), PHP_URL_QUERY), $query);
        $this->assertSame(['tab' => 'records', 'q' => 'ABC', 'gate' => 'gate-1', 'plate_status' => 'read', 'status' => 'all'], $query);
        $this->assertSame('true', $links[3]->getAttribute('aria-current'));
        $response->assertSee('name="record_filter" value="manual"', false)->assertSee('data-auto-reset', false);
        $this->get(route('visitors.index'))->assertOk()->assertSee('value="active" selected', false);
    }

    public function test_invalid_filters_are_validated_and_other_tabs_stay_separate(): void
    {
        foreach (['nonsense', ['registered']] as $invalid) {
            $this->getJson(route('visitors.index', ['record_filter' => $invalid]))->assertUnprocessable()->assertJsonValidationErrors('record_filter');
        }
        $this->get(route('visitors.index', ['record_filter' => 'bad', 'gate' => 'gate-1', 'q' => 'ABC']))
            ->assertRedirect(route('visitors.index', ['gate' => 'gate-1', 'q' => 'ABC']))->assertSessionHasErrors('record_filter');
        foreach (['plates', 'review'] as $tab) {
            $this->get(route('visitors.index', ['tab' => $tab, 'record_filter' => 'registered']))
                ->assertOk()->assertDontSee('data-auto-update="record-filters"', false);
        }
    }

    public function test_existing_guard_access_and_signed_out_protection_are_preserved(): void
    {
        $record = $this->record(['source' => 'manual']);
        $guard = User::factory()->create(['role' => 'guard']);
        $this->actingAs($guard);
        $this->assertSame([$record->id], $this->ids(['record_filter' => 'manual']));
        $this->get(route('visitors.index', ['record_filter' => 'manual']))->assertSee('Correct plate')->assertSee('Correct type')->assertSee('Dismiss…');
        auth()->logout();
        $this->get(route('visitors.index', ['record_filter' => 'registered']))->assertRedirect(route('login'));
    }
}
