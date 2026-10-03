<?php

namespace Tests\Feature;

use App\Models\GuestVehicleObservation;
use App\Models\PlateProfile;
use App\Models\RfidScanLog;
use App\Models\User;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\MovementCountService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 8 (visitor model): archive repeated unknown-tag guest records,
 * convert camera guest records into Unregistered Visitor records, Guests
 * becomes Visitors.
 */
class Phase8CleanupTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'Asia/Manila'));
    }

    public function test_dry_run_changes_nothing_then_cleanup_archives_and_converts_once(): void
    {
        $data = $this->oldData();

        $this->artisan('visitors:cleanup', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN: nothing will be saved.')
            ->expectsOutputToContain('Would archive 4 unknown-tag guest record(s); would convert 4 camera guest record(s)')
            ->assertSuccessful();
        $this->assertSame(0, VisitorRecord::query()->where('source', VisitorRecord::SOURCE_LEGACY)->count());
        $this->assertSame(0, GuestVehicleObservation::query()->withArchived()->whereNotNull('archived_at')->count());

        $this->artisan('visitors:cleanup')
            ->expectsOutputToContain('Archived 4 unknown-tag guest record(s); converted 4 camera guest record(s)')
            ->assertSuccessful();

        // Archived: kept, with the reason; never shown.
        $archived = GuestVehicleObservation::query()->withArchived()->whereIn('id', $data['tag_a'])->get();
        $this->assertCount(3, $archived->whereNotNull('archived_at'));
        $this->assertStringContainsString('TAG-REPEATED read 3 times', $archived->first()->archive_reason);
        $this->assertSame(0, GuestVehicleObservation::query()->whereIn('id', [...$data['tag_a'], $data['tag_b']])->count());

        // Converted: one visitor record each, linked; the dual-written one keeps its record.
        $legacy = VisitorRecord::query()->where('source', VisitorRecord::SOURCE_LEGACY)->orderBy('id')->get();
        $this->assertCount(3, $legacy);
        $this->assertSame([['DPF 233', 'IN', 'active'], ['DPF 233', 'OUT', 'active'], ['NBC 123', 'IN', 'dismissed']],
            $legacy->map(fn (VisitorRecord $record): array => [$record->plate_number, $record->direction, $record->status])->all());
        $this->assertSame($data['existing_record'], GuestVehicleObservation::query()->find($data['dual'])->visitor_record_id);
        $this->assertSame(2, PlateProfile::query()->where('plate_key', 'DPF233')->value('visit_count'));

        // Running it again changes nothing.
        $this->artisan('visitors:cleanup')
            ->expectsOutputToContain('Archived 0 unknown-tag guest record(s); converted 0 camera guest record(s)')
            ->assertSuccessful();
        $this->assertSame(3, VisitorRecord::query()->where('source', VisitorRecord::SOURCE_LEGACY)->count());
    }

    public function test_after_cleanup_every_vehicle_is_counted_and_listed_once(): void
    {
        $this->oldData();
        $before = app(MovementCountService::class)->counts('today');
        $this->artisan('visitors:cleanup')->assertSuccessful();
        $after = app(MovementCountService::class)->counts('today');

        // Tag-read records are never counted, not even before the cleanup; after
        // it the record whose alert a registered tag closed is no longer counted;
        // nothing is counted twice.
        $this->assertSame([3, 1], [$before['in'], $before['out']]);
        $this->assertSame([2, 1], [$after['in'], $after['out']]);

        // Activity Logs: the converted record, not its old guest copy or vehicle-log copy.
        $logs = $this->actingAs($this->admin)->getJson(route('logs.index', ['tab' => 'events', 'plate_text' => 'DPF']))->assertOk();
        $this->assertSame(['visitor_record', 'visitor_record'], array_column($logs->json('logs'), 'record_type'));

        // Archived tag-read records are gone from alerts and the kiosk.
        $this->actingAs($this->admin)->get(route('logs.index', ['tab' => 'alerts']))->assertOk()->assertDontSee('TAG-REPEATED');
        $this->actingAs($this->admin)->getJson(route('stations.state', 'gate-1'))->assertOk()->assertJsonMissing(['plate_number' => 'TAGGED']);
    }

    public function test_guests_is_visitors_and_a_guard_records_a_visitor_by_hand(): void
    {
        $guard = User::query()->create(['name' => 'Gate Guard', 'email' => 'guard.p8@philcst.local', 'password' => Hash::make('password'), 'role' => 'guard']);

        $this->actingAs($guard)->get('/guests')->assertRedirect(route('visitors.index'));
        $this->actingAs($this->admin)->get(route('logs.index', ['tab' => 'alerts']))
            ->assertOk()->assertDontSee('Add Guest Observation')->assertSee('Add visitor manually');
        $this->actingAs($guard)->get(route('visitors.index', ['add' => 1]))->assertOk()->assertSee('Save visitor');

        $this->actingAs($guard)->post(route('visitors.records.store'), [
            'gate' => 'gate-2', 'direction' => 'IN', 'seen_at' => now()->subMinutes(5)->format('Y-m-d\TH:i'),
            'plate_number' => 'mnl 4567', 'vehicle_type' => 'Van', 'note' => 'Camera was offline',
        ])->assertRedirect(route('visitors.index'));

        $record = VisitorRecord::query()->sole();
        $this->assertSame([VisitorRecord::SOURCE_MANUAL, 'MNL 4567', 'corrected', $guard->id, 'Camera was offline'],
            [$record->source, $record->plate_number, $record->plate_status, $record->corrected_by, $record->status_note]);
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['categories']['unregistered_visitor']['in']);
        $this->actingAs($guard)->get(route('visitors.index'))->assertOk()->assertSee('Recorded by hand')->assertSee('Typed by Gate Guard');
    }

    /**
     * Data as it was before the visitor records.
     *
     * @return array<string, mixed>
     */
    protected function oldData(): array
    {
        $tagRecord = function (string $tag, string $plate = 'TAGGED'): int {
            $observation = GuestVehicleObservation::query()->create([
                'plate_number' => $plate, 'vehicle_type' => 'Guest', 'location' => 'gate-1', 'observation_source' => 'cctv',
                'status' => 'pending_review', 'observed_at' => now(), 'notes' => "Guest RFID tag {$tag} scanned at Gate 1 (guest).",
            ]);
            RfidScanLog::query()->create([
                'tag_uid' => $tag, 'scan_location' => 'gate-1', 'scan_direction' => 'entry', 'reader_name' => 'Gate 1 UHF Reader',
                'scan_time' => now(), 'verification_status' => 'guest', 'source_mode' => 'hardware_placeholder',
                'guest_vehicle_observation_id' => $observation->id,
            ]);

            return $observation->id;
        };
        $camera = fn (string $key, ?string $plate, string $direction, string $status = 'pending_review'): int => GuestVehicleObservation::query()->create([
            'external_event_key' => $key, 'plate_number' => $plate, 'vehicle_type' => 'Car', 'location' => 'gate-1',
            'observation_source' => 'cctv', 'status' => $status, 'observed_at' => now(),
            'detection_metadata_json' => ['direction' => $direction],
        ])->id;

        $tagA = [$tagRecord('TAG-REPEATED'), $tagRecord('TAG-REPEATED'), $tagRecord('TAG-REPEATED')];
        $tagB = $tagRecord('TAG-ONCE');

        $this->travel(2)->minutes();
        $camera('old-1', 'DPF-233', 'IN');
        // Its copy in the vehicle logs (made by the old guest flow).
        VehicleEvent::query()->create([
            'event_type' => 'ENTRY', 'event_status' => VehicleEvent::STATUS_COMPLETED, 'event_origin' => 'guest_cctv',
            'plate_text' => 'DPF-233', 'vehicle_category' => 'guest', 'event_time' => now(), 'external_event_key' => 'old-1', 'match_status' => 'open',
        ]);
        $this->travel(5)->minutes();
        $camera('old-2', '233DPF', 'OUT');
        $this->travel(5)->minutes();
        $camera('old-3', 'NBC-123', 'IN', GuestVehicleObservation::STATUS_RESOLVED);

        // Since Phase 5 the detector also wrote a visitor record for the same crossing.
        $this->travel(5)->minutes();
        $existing = VisitorRecord::query()->create([
            'external_event_key' => 'new-1', 'gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now(),
            'status' => 'active', 'plate_status' => 'unreadable',
        ]);
        $dual = $camera('new-1', null, 'IN');

        return ['tag_a' => $tagA, 'tag_b' => $tagB, 'existing_record' => $existing->id, 'dual' => $dual];
    }
}
