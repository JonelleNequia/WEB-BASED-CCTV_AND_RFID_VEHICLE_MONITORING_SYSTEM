<?php

namespace Tests\Feature;

use App\Models\PlateProfile;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VisitorRecord;
use App\Services\RfidIngestService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 6 (visitor model): Visitor Ranking and "Register this vehicle".
 */
class Phase6VisitorRankingTest extends TestCase
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
    }

    public function test_dashboard_shows_registered_and_unregistered_rankings_separately(): void
    {
        $this->registeredVehicle('REG 1001', 'RANK-TAG-1');
        app(RfidIngestService::class)->ingest(['tag_uid' => 'RANK-TAG-1', 'scan_location' => 'gate-1']);

        $this->visits('VIS-4321', ['IN', 'OUT', 'IN']);
        $this->visits('ABC-1234', ['IN']);

        $this->actingAs($this->admin)->get(route('dashboard.index'))
            ->assertOk()
            // UI Phase 4: both rankings in one card with tabs.
            ->assertSee('Most Entries')
            ->assertSee('data-segment="registered"', false)
            ->assertSee('data-segment="visitors"', false)
            ->assertSeeInOrder(['VIS 4321', 'ABC 1234', 'Register this vehicle']);

        $live = $this->actingAs($this->admin)->getJson(route('dashboard.live-state'))->assertOk();
        $this->assertSame(['REG 1001'], array_column($live->json('frequent_entry_vehicles'), 'plate_number'));
        $this->assertSame(['VIS 4321', 'ABC 1234'], array_column($live->json('frequent_unregistered_visitors'), 'plate_number'));
        $this->assertSame([2, 3], [$live->json('frequent_unregistered_visitors.0.entries_count'), $live->json('frequent_unregistered_visitors.0.visit_count')]);
    }

    public function test_register_this_vehicle_prefills_add_vehicle_and_moves_the_plate_history(): void
    {
        $this->visits('VIS-4321', ['IN', 'OUT', 'IN', 'OUT']);
        $profile = PlateProfile::query()->where('plate_key', 'VIS4321')->sole();
        $profile->forceFill(['vehicle_type' => 'Car', 'note' => 'Supplier'])->save();

        $this->actingAs($this->admin)->get(route('registry.index', ['tab' => 'vehicles', 'register_plate' => $profile->id]))
            ->assertOk()
            ->assertSee('Registering <strong>VIS 4321</strong>', false)
            ->assertSee('name="plate_profile_id" value="'.$profile->id.'"', false)
            ->assertSee('value="VIS 4321"', false)
            ->assertSee('<option value="registered_visitor" selected>', false);

        $tag = RfidTag::query()->create(['uid' => 'NEW-VIS-TAG', 'status' => RfidTag::STATUS_AVAILABLE]);
        $this->actingAs($this->admin)
            ->post(route('vehicle-registry.store'), [
                'rfid_tag_id' => $tag->id,
                'plate_number' => 'VIS 4321',
                'vehicle_owner_name' => 'Supplier Driver',
                'category' => 'registered_visitor',
                'vehicle_type' => 'Car',
                'plate_profile_id' => $profile->id,
            ])
            ->assertSessionHas('status', 'VIS 4321 was saved to the local vehicle registry. 4 earlier visits as an unregistered visitor moved to this vehicle.');

        $vehicle = Vehicle::query()->where('plate_number', 'VIS 4321')->sole();
        $this->assertSame($vehicle->id, $profile->fresh()->vehicle_id);
        $this->assertNotNull($profile->fresh()->registered_at);
        $this->assertSame(4, VisitorRecord::query()->where('vehicle_id', $vehicle->id)->count());

        // The vehicle's history: 2 entries before registration count in the registered ranking.
        $live = $this->actingAs($this->admin)->getJson(route('dashboard.live-state'))->assertOk();
        $this->assertSame([['VIS 4321', 2]], array_map(fn ($row) => [$row['plate_number'], $row['total_entries_count']], $live->json('frequent_entry_vehicles')));
        $this->assertSame([], $live->json('frequent_unregistered_visitors'));

        $this->actingAs($this->admin)->getJson(route('registry.vehicles.show', $vehicle))
            ->assertOk()
            ->assertJsonPath('camera_visits', 4)
            ->assertJsonPath('plate_profile_url', route('visitors.profiles.show', $profile));

        // The link no longer opens Add Vehicle for this plate.
        $this->actingAs($this->admin)->get(route('registry.index', ['tab' => 'vehicles', 'register_plate' => $profile->id]))
            ->assertOk()->assertDontSee('Registering <strong>VIS 4321</strong>', false);
        $this->actingAs($this->admin)->get(route('visitors.profiles.show', $profile))->assertOk()->assertSee('In the Registry since');
    }

    public function test_a_registered_plate_seen_without_its_tag_stays_with_the_vehicle(): void
    {
        $vehicle = $this->registeredVehicle('NFB 9863', 'NFB-TAG');

        // The camera reads the plate but no tag was read (reader missed it).
        $this->visits('NFB-9863', ['IN']);

        $record = VisitorRecord::query()->sole();
        $this->assertSame($vehicle->id, $record->vehicle_id);
        $this->assertSame($vehicle->id, PlateProfile::query()->sole()->vehicle_id);
        $this->assertSame([], app(\App\Services\VisitorRecordService::class)->unregisteredRanking()->all());
        $this->actingAs($this->admin)->get(route('visitors.index'))->assertOk()->assertSee('Registered vehicle · tag not read');
    }

    public function test_adding_or_correcting_a_vehicle_with_a_known_plate_also_moves_its_history(): void
    {
        $this->visits('ABC-1234', ['IN', 'IN']);
        $this->visits('XYZ-9876', ['IN']);

        // Plain Add Vehicle with the same plate (typed differently).
        $tag = RfidTag::query()->create(['uid' => 'PLAIN-TAG', 'status' => RfidTag::STATUS_AVAILABLE]);
        $this->actingAs($this->admin)->post(route('vehicle-registry.store'), [
            'rfid_tag_id' => $tag->id, 'plate_number' => 'abc1234', 'category' => 'faculty_staff', 'vehicle_type' => 'Car',
        ])->assertSessionHasNoErrors();
        $vehicle = Vehicle::query()->where('plate_number', 'ABC1234')->sole();
        $this->assertSame(2, VisitorRecord::query()->where('vehicle_id', $vehicle->id)->count());

        // A vehicle's plate corrected to XYZ 9876 takes that plate's visit.
        $other = $this->registeredVehicle('XYZ 9870', 'OTHER-TAG');
        $this->actingAs($this->admin)->put(route('vehicle-registry.update', $other), [
            'rfid_tag_id' => $other->rfid_tag_id, 'plate_number' => 'XYZ 9876', 'category' => 'faculty_staff', 'vehicle_type' => 'Car',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, VisitorRecord::query()->where('vehicle_id', $other->id)->count());
    }

    /**
     * Unregistered visits of one plate (one crossing + plate vote each).
     *
     * @param  list<string>  $directions
     */
    protected function visits(string $plate, array $directions): void
    {
        foreach ($directions as $direction) {
            $key = 'rank-'.(++$this->sequence);
            $this->travel(5)->minutes();
            $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->post(route('api.integration.crossings'), [
                'external_event_key' => $key,
                'camera_role' => 'gate-1',
                'direction' => $direction,
                'event_time' => now()->toIso8601String(),
                'detected_vehicle_type' => 'Car',
                'detection_metadata' => json_encode(['rfid_status' => 'no_pass', 'bbox_xyxy' => [100, 100, 200, 200]]),
                'snapshot' => UploadedFile::fake()->image('c.jpg'),
            ], ['Accept' => 'application/json'])->assertCreated();
            $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->postJson(route('api.integration.visitor-plates'), [
                'external_event_key' => $key,
                'camera_role' => 'gate-1',
                'event_time' => now()->toIso8601String(),
                'plate_status' => 'read',
                'plate_number' => $plate,
                'plate_confidence' => 0.9,
                'detected_vehicle_type' => 'Car',
            ])->assertOk();
        }
    }

    protected function registeredVehicle(string $plate, string $uid): Vehicle
    {
        $vehicle = Vehicle::query()->create(['plate_number' => $plate, 'vehicle_owner_name' => 'Owner', 'category' => 'faculty_staff', 'vehicle_type' => 'Car']);
        $tag = RfidTag::query()->create(['uid' => $uid, 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        return $vehicle;
    }
}
