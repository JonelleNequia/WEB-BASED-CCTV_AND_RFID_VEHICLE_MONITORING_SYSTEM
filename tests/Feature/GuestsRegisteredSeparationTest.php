<?php

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\PlateProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VisitorRecord;
use App\Services\VehicleRegistryService;
use App\Services\VisitorPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class GuestsRegisteredSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        Storage::fake('public');
        Storage::fake('occurrences');
        $this->travelTo(now()->startOfDay()->addHours(8));
    }

    private function record(array $attributes = []): VisitorRecord
    {
        return VisitorRecord::create($attributes + ['external_event_key' => 'separation-'.\Illuminate\Support\Str::uuid(),
            'gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now(), 'status' => 'active', 'source' => 'camera',
            'plate_number' => 'GUE 0123', 'plate_status' => 'corrected', 'registration_status' => 'guest']);
    }

    private function register(string $plate): Vehicle
    {
        return app(VehicleRegistryService::class)->register(['plate_number' => $plate, 'vehicle_owner_name' => 'Alice Torres',
            'registered_by' => 'Test Registrar', 'category' => 'faculty_staff', 'vehicle_type' => 'Car']);
    }

    private function csvIds(array $filters = []): array
    {
        $response = $this->get(route('visitors.export.csv', $filters))->assertOk();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, substr($response->streamedContent(), 3));
        rewind($stream);
        fgetcsv($stream, 0, ',', '"', '');
        $ids = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $ids[] = (int) $row[0];
        }
        fclose($stream);

        return $ids;
    }

    private function assertGuestReports(array $expected, array $filters = []): void
    {
        $page = $this->get(route('visitors.index', $filters))->assertOk()->viewData('records');
        $actual = $page->pluck('id')->all();
        foreach (range(2, max(2, $page->lastPage())) as $number) {
            if ($number <= $page->lastPage()) {
                $actual = [...$actual, ...$this->get(route('visitors.index', $filters + ['page' => $number]))->assertOk()->viewData('records')->pluck('id')->all()];
            }
        }
        $this->assertSame(count($expected), $page->total());
        $this->assertEqualsCanonicalizing($expected, $actual);
        $report = $this->get(route('visitors.print', $filters + ['page' => 2]))->assertOk()->viewData('report');
        $this->assertSame($actual, $report['rows']->pluck('id')->all());
        $this->assertSame($actual, $this->csvIds($filters + ['page' => 2]));
        foreach ($report['summaries'] as $summary) {
            $this->assertSame(count($expected), array_sum($summary));
        }
    }

    public function test_untagged_registry_vehicle_is_registered_after_actual_plate_confirmation_and_stays_out_of_guests(): void
    {
        $vehicle = $this->register('395ISX');
        $this->assertNull($vehicle->rfid_tag_id);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->travel(10)->seconds();
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.crossings'), [
            'external_event_key' => 'separation-real-path', 'camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(),
            'direction' => 'IN', 'detection_metadata' => ['rfid_status' => 'no_pass'], 'snapshot' => UploadedFile::fake()->image('vehicle.jpg'),
        ])->assertCreated();
        $this->postJson(route('api.integration.visitor-plates'), ['external_event_key' => 'separation-real-path',
            'camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(), 'plate_status' => 'read', 'plate_number' => '395ISX',
            'plate_confidence' => .95, 'ocr_details' => ['raw_reads' => [['text' => '395ISX', 'confidence' => .95]]],
            'plate_image' => UploadedFile::fake()->image('plate.jpg'),
        ])->assertOk();
        $record = VisitorRecord::where('external_event_key', 'separation-real-path')->sole();
        $this->assertSame('review', $record->registration_status);
        $this->get(route('visitors.index', ['tab' => 'review']))->assertOk()->assertSee('395ISX');
        $identity = $record->only(['id', 'external_event_key', 'seen_at', 'gate', 'direction', 'snapshot_path', 'plate_image_path']);
        $url = route('visitors.records.review', $record);
        $this->patchJson($url, ['action' => 'correct_plate', 'plate' => '395ISX'])->assertOk()->assertJsonPath('registration_status', 'registered');
        $record->refresh();
        $event = $record->movementEvent;
        $this->assertNotNull($event);
        $this->assertSame($vehicle->id, $record->vehicle_id);
        $this->assertEquals($identity, $record->only(array_keys($identity)));
        $this->assertGuestReports([]);
        $this->assertGuestReports([], ['record_filter' => 'manual', 'status' => 'all']);
        $this->getJson(route('logs.index', ['log_type' => 'registered']))->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.id', $event->id)->assertJsonPath('logs.0.visitor_record_id', $record->id)
            ->assertJsonPath('logs.0.record_type', 'vehicle_event');
        $this->patchJson($url, ['action' => 'correct_plate', 'plate' => '395ISX'])->assertOk();
        $this->assertSame($event->id, $record->fresh()->movementEvent->id);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('review_actions', 1);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
    }

    public function test_historical_guest_survives_later_registration_and_explicit_relationship_transfer(): void
    {
        $profile = PlateProfile::create(['plate_number' => 'OLD 0012', 'plate_key' => 'OLD0012', 'visit_count' => 1]);
        $old = $this->record(['plate_number' => 'OLD 0012', 'plate_profile_id' => $profile->id]);
        $guest = $this->record(['source' => 'manual']);
        $before = $old->getRawOriginal();
        $this->travel(1)->hours();
        $vehicle = $this->register('OLD 0012');
        app(\App\Services\VisitorRecordService::class)->transferHistoryToVehicle($vehicle, $profile->id);
        $this->assertSame($vehicle->id, $old->fresh()->vehicle_id);
        $this->assertSame('guest', $old->fresh()->registration_status);
        $registered = $this->record(['plate_number' => 'OLD 0012', 'vehicle_id' => $vehicle->id, 'registration_status' => 'registered', 'source' => 'manual']);
        $this->assertGuestReports([$old->id, $guest->id]);
        $this->assertGuestReports([$old->id, $guest->id], ['record_filter' => 'unregistered']);
        $this->assertGuestReports([$guest->id], ['record_filter' => 'manual']);
        $logs = $this->getJson(route('logs.index'))->assertOk()->json('logs');
        $this->assertSame('unregistered_visitor', collect($logs)->firstWhere('id', $old->id)['log_type']);
        $this->assertSame('registered', collect($logs)->firstWhere('id', $registered->id)['log_type']);
        foreach (['plate_number', 'seen_at', 'registration_status', 'direction', 'gate'] as $column) {
            $this->assertSame($before[$column], $old->fresh()->getRawOriginal($column));
        }
    }

    public function test_shared_list_counts_and_exports_exclude_registered_across_pages_show_and_manual_source(): void
    {
        $ids = [];
        foreach (range(1, 23) as $index) {
            $ids[] = $this->record(['source' => 'manual'])->id;
        }
        $review = $this->record(['registration_status' => 'review', 'plate_status' => 'unreadable']);
        foreach (['active', 'dismissed', 'duplicate'] as $status) {
            $this->record(['registration_status' => 'registered', 'source' => 'manual', 'status' => $status]);
        }
        $duplicate = $this->record(['source' => 'manual', 'status' => 'duplicate']);
        Gate::where('code', 'gate-1')->update(['archived_at' => now()]);
        $this->assertGuestReports([...$ids, $review->id], ['gate' => 'gate-1', 'q' => 'gue-01']);
        $this->assertGuestReports($ids, ['record_filter' => 'manual', 'gate' => 'gate-1', 'plate_status' => 'corrected']);
        $this->assertGuestReports($ids, ['record_filter' => 'unregistered']);
        $this->assertGuestReports([$duplicate->id], ['record_filter' => 'manual', 'status' => 'duplicate']);
        $this->assertGuestReports([...$ids, $review->id, $duplicate->id], ['status' => 'all']);
        $this->get(route('visitors.index', ['tab' => 'review']))->assertOk()->assertViewHas('reviewCount', 1);
    }

    public function test_presence_estimate_excludes_registered_but_keeps_full_pass_chronology(): void
    {
        $profile = PlateProfile::create(['plate_number' => 'CHR 0001', 'plate_key' => 'CHR0001']);
        $this->record(['plate_profile_id' => $profile->id]);
        $this->travel(1)->minutes();
        $this->record(['plate_profile_id' => $profile->id, 'registration_status' => 'registered', 'direction' => 'OUT']);
        $registeredProfile = PlateProfile::create(['plate_number' => 'REG 0001', 'plate_key' => 'REG0001']);
        $this->record(['plate_profile_id' => $registeredProfile->id, 'registration_status' => 'registered']);
        $this->record(['registration_status' => 'registered']);
        $this->record(['plate_number' => null, 'plate_status' => 'unreadable']);
        $this->record(['plate_number' => null, 'plate_status' => 'unreadable', 'registration_status' => 'review']);
        $presence = app(VisitorPresenceService::class)->possiblyInside();
        $this->assertCount(0, $presence['vehicles']);
        $this->assertSame(2, $presence['without_plate']);
    }

    public function test_registered_quick_filter_is_removed_and_old_urls_never_export_a_different_dataset(): void
    {
        $filters = ['tab' => 'records', 'record_filter' => 'registered', 'q' => '395', 'gate' => 'gate-1', 'plate_status' => 'read', 'status' => 'all', 'page' => 2];
        $response = $this->get(route('visitors.index', $filters))->assertRedirect()->assertSessionHasErrors('record_filter');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(array_diff_key($filters, ['record_filter' => true, 'page' => true]), $query);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('The Registered filter has been removed from Guests.');
        foreach (['visitors.index', 'visitors.print', 'visitors.export.csv'] as $route) {
            $this->getJson(route($route, $filters))->assertUnprocessable()->assertJsonValidationErrors('record_filter');
        }
        foreach (['visitors.print', 'visitors.export.csv'] as $route) {
            $this->get(route($route, $filters))->assertUnprocessable()->assertSee('The report was not generated.')
                ->assertSee('The Registered filter has been removed from Guests.');
        }
        $this->withHeader('X-Auto-Filter', '1')->get(route('visitors.index', $filters))->assertUnprocessable()->assertJsonValidationErrors('record_filter');
        $this->flushHeaders();
        $html = $this->get(route('visitors.index'))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $links = (new \DOMXPath($dom))->query('//*[@data-auto-update="record-filters"]/a');
        $this->assertSame(['All', 'Unregistered Guests', 'Manual entries'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($links)));
    }
}
