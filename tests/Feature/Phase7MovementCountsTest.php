<?php

namespace Tests\Feature;

use App\Models\GuestVehicleObservation;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\MovementCountService;
use App\Services\RfidIngestService;
use App\Services\VehicleOccupancyService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 7 (visitor model): IN / OUT per period, category and gate; "inside"
 * for registered vehicles only; Activity Logs filters in CSV and print.
 */
class Phase7MovementCountsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        // A Wednesday mid-month, 10 AM in Manila.
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'Asia/Manila'));
        $this->scenario();
    }

    public function test_counts_per_period_category_and_gate_from_every_source(): void
    {
        $today = app(MovementCountService::class)->counts('today');

        // IN: Faculty RFID, Registered Visitor RFID, unregistered camera, plate-only Faculty.
        // OUT: Faculty RFID, unregistered camera, older camera guest record.
        $this->assertSame([4, 3, 1], [$today['in'], $today['out'], $today['unknown']]);
        $this->assertSame(
            ['faculty_staff' => [2, 1], 'registered_visitor' => [1, 0], 'unregistered_visitor' => [1, 2]],
            collect($today['categories'])->map(fn (array $row): array => [$row['in'], $row['out']])->all()
        );
        $this->assertSame(
            ['gate-1' => [2, 2], 'gate-2' => [2, 1]],
            collect($today['gates'])->map(fn (array $row): array => [$row['in'], $row['out']])->all()
        );

        // Yesterday's entry is in the week, month and year, not today.
        $all = app(MovementCountService::class)->allPeriods();
        $this->assertSame([4, 5, 5, 5], [$all['today']['in'], $all['week']['in'], $all['month']['in'], $all['year']['in']]);
    }

    public function test_inside_is_registered_vehicles_only_and_a_plate_only_read_does_not_change_it(): void
    {
        $occupancy = app(VehicleOccupancyService::class);

        // Faculty went IN then OUT; the Registered Visitor is inside; unregistered visitors never are.
        $this->assertSame(['registered' => 1, 'total' => 1], $occupancy->counts());
        $this->assertSame(['faculty_staff' => 0, 'registered_visitor' => 1], collect($occupancy->insideByCategory())->map->inside->all());

        // Option A: NFB 9863's plate was read IN without its tag: counted, but still OUTSIDE.
        $this->assertSame(Vehicle::STATE_OUTSIDE, Vehicle::query()->where('plate_number', 'NFB 9863')->value('current_state'));

        $this->actingAs($this->admin)->getJson(route('dashboard.live-state'))
            ->assertOk()
            ->assertJsonPath('metrics.vehicles_inside', 1)
            ->assertJsonPath('metrics.total_vehicles_entered_today', 4)
            ->assertJsonPath('movement_counts.today.gates.gate-2.in', 2)
            ->assertJsonPath('movement_counts.today.categories.unregistered_visitor.out', 2)
            ->assertJsonPath('inside_by_category.registered_visitor.inside', 1);

        $this->actingAs($this->admin)->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('IN / OUT Counts')
            ->assertSee('data-movement="today.gates.gate-1.out">2<', false)
            ->assertSee('data-movement="today.unknown">1<', false);
    }

    public function test_activity_logs_filter_by_gate_category_and_movement_also_in_csv_and_print(): void
    {
        // Gate 2 only.
        $this->actingAs($this->admin)->get(route('logs.index', ['tab' => 'events', 'gate' => 'gate-2']))
            ->assertOk()->assertSee('RVS 2002')->assertSee('NFB 9863')->assertDontSee('LEG 777');

        // Unregistered Visitors: camera records and the older guest record, no registered vehicle.
        $this->actingAs($this->admin)->get(route('logs.index', ['tab' => 'events', 'category' => 'unregistered_visitor']))
            ->assertOk()->assertSee('LEG 777')->assertDontSee('RVS 2002');

        // Movement: direction unknown.
        $unknown = $this->actingAs($this->admin)->getJson(route('logs.index', ['tab' => 'events', 'event_type' => 'UNKNOWN']))->assertOk();
        $this->assertSame(['visitor_record'], array_values(array_unique(array_column($unknown->json('logs'), 'record_type'))));
        $this->assertSame(1, $unknown->json('total'));

        // IN at Gate 1 today: the Faculty RFID entry and the unregistered camera IN
        // (its duplicate and the guest copy hidden); yesterday's entry only without the period.
        $in = $this->actingAs($this->admin)->getJson(route('logs.index', ['tab' => 'events', 'event_type' => 'ENTRY', 'gate' => 'gate-1', 'period' => 'today']))->assertOk();
        $this->assertSame(2, $in->json('total'));
        $this->assertSame(3, $this->actingAs($this->admin)->getJson(route('logs.index', ['tab' => 'events', 'event_type' => 'ENTRY', 'gate' => 'gate-1']))->json('total'));

        $csv = $this->actingAs($this->admin)->get(route('vehicle-events.export.csv', ['gate' => 'gate-2', 'all' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Category,Gate', $csv);
        $this->assertStringContainsString('RVS 2002', $csv);
        $this->assertStringContainsString('"Registered Visitor","Gate 2"', $csv);
        $this->assertStringNotContainsString('LEG 777', $csv);

        $print = $this->actingAs($this->admin)->get(route('logs.index', ['tab' => 'events', 'gate' => 'gate-2', 'event_type' => 'ENTRY']))
            ->assertOk()->viewData('printReports');
        $this->assertSame('Gate 2 · IN', $print['current']['filters_label']);
        $this->assertSame(['IN'], array_values(array_unique(array_column($print['current']['rows']->all(), 'movement'))));
        $this->assertSame(['Gate 2'], array_values(array_unique(array_column($print['current']['rows']->all(), 'gate'))));
    }

    protected function scenario(): void
    {
        $faculty = $this->registeredVehicle('FAC 1001', 'FAC-TAG', 'faculty_staff');
        $this->registeredVehicle('RVS 2002', 'RVS-TAG', 'registered_visitor');
        $this->registeredVehicle('NFB 9863', 'NFB-TAG', 'faculty_staff');
        $ingest = app(RfidIngestService::class);

        // Yesterday: one Faculty entry (week / month / year, not today); back out the same day.
        $this->travel(-1)->days();
        $ingest->ingest(['tag_uid' => 'FAC-TAG', 'scan_location' => 'gate-1']);
        $this->travel(1)->hours();
        $ingest->ingest(['tag_uid' => 'FAC-TAG', 'scan_location' => 'gate-1']);
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'Asia/Manila'));

        // Today, registered (no camera online: direction from the vehicle state).
        $ingest->ingest(['tag_uid' => 'FAC-TAG', 'scan_location' => 'gate-1']);   // IN  gate-1
        $ingest->ingest(['tag_uid' => 'RVS-TAG', 'scan_location' => 'gate-2']);   // IN  gate-2
        $this->travel(2)->minutes();
        $ingest->ingest(['tag_uid' => 'FAC-TAG', 'scan_location' => 'gate-2']);   // OUT gate-2
        $this->assertSame(Vehicle::STATE_OUTSIDE, $faculty->fresh()->current_state);

        // Today, camera with no registered tag read.
        $this->travel(1)->minutes();
        $first = $this->visit('gate-1', 'IN');                                      // counted
        $this->travel(2)->seconds();
        $this->visit('gate-1', 'IN');                                               // same car, new track ID: duplicate
        $this->travel(1)->minutes();
        $this->visit('gate-1', 'OUT', box: [400, 100, 500, 200]);                   // counted
        $this->travel(1)->minutes();
        $this->visit('gate-2', 'UNKNOWN');                                          // direction unknown
        $this->travel(1)->minutes();
        $this->visit('gate-2', 'IN', plate: 'NFB-9863');                            // registered plate, tag not read

        // Older camera guest record (before visitor records), and the guest copy of $first.
        foreach ([['legacy-1', 'LEG 777', 'OUT'], [$first, 'DUP 000', 'IN']] as [$key, $plate, $direction]) {
            GuestVehicleObservation::query()->create([
                'external_event_key' => $key, 'plate_number' => $plate, 'vehicle_type' => 'Car', 'location' => 'gate-1',
                'detection_metadata_json' => ['direction' => $direction], 'observation_source' => 'cctv',
                'status' => 'pending_review', 'observed_at' => now(),
            ]);
        }
    }

    protected function visit(string $gate, string $direction, ?string $plate = null, array $box = [100, 100, 200, 200]): string
    {
        $key = 'p7-'.(++$this->sequence);
        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->post(route('api.integration.crossings'), [
            'external_event_key' => $key,
            'camera_role' => $gate,
            'direction' => $direction,
            'event_time' => now()->toIso8601String(),
            'detected_vehicle_type' => 'Car',
            'detection_metadata' => json_encode(['rfid_status' => 'no_pass', 'bbox_xyxy' => $box]),
            'snapshot' => UploadedFile::fake()->image('c.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        if ($plate) {
            $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->postJson(route('api.integration.visitor-plates'), [
                'external_event_key' => $key, 'camera_role' => $gate, 'event_time' => now()->toIso8601String(),
                'plate_status' => 'read', 'plate_number' => $plate, 'plate_confidence' => 0.9,
            ])->assertOk();
        }

        return $key;
    }

    protected function registeredVehicle(string $plate, string $uid, string $category): Vehicle
    {
        $vehicle = Vehicle::query()->create(['plate_number' => $plate, 'vehicle_owner_name' => 'Owner', 'category' => $category, 'vehicle_type' => 'Car']);
        $tag = RfidTag::query()->create(['uid' => $uid, 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        return $vehicle;
    }
}
