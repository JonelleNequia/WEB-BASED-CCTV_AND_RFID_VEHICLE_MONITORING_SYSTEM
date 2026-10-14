<?php

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\MovementCountService;
use App\Services\RfidIngestService;
use App\Support\CameraFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Gate direction work, Phase A: both gates record IN and OUT.
 *
 * Gate 1: away from the camera = IN, toward it = OUT. Gate 2: away = OUT,
 * toward = IN. The detector maps away / toward with the gate's setting
 * (tests/test_vehicle_pass.py); here each pass arrives as IN / OUT / UNKNOWN
 * with its confidence, with or without a registered tag:
 * Registered IN, Registered OUT, Unregistered IN, Unregistered OUT, and an
 * unclear direction (registered: the vehicle's state; unregistered:
 * Direction unknown, not counted, in Review).
 */
class GateDirectionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected int $sequence = 0;

    protected string $lastKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->freezeTime();
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        Gate::query()->where('code', 'gate-1')->update(['away_direction' => 'IN']);
        Gate::query()->where('code', 'gate-2')->update(['away_direction' => 'OUT']);
        $this->camerasOnline();
    }

    /** Both gates' cameras are watching (the detector's status, fresh). */
    protected function camerasOnline(): void
    {
        File::ensureDirectoryExists(dirname(CameraFiles::statusPath()));
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'cameras' => collect(['gate-1', 'gate-2'])->mapWithKeys(fn (string $gate) => [$gate => ['camera_role' => $gate, 'camera_running' => true, 'calibration_ready' => true]])->all(),
        ]));
    }

    protected function tearDown(): void
    {
        File::delete(CameraFiles::statusPath());

        parent::tearDown();
    }

    public function test_every_gate_records_registered_and_unregistered_in_and_out(): void
    {
        foreach (['gate-1' => ['away' => 'IN', 'toward' => 'OUT'], 'gate-2' => ['away' => 'OUT', 'toward' => 'IN']] as $gate => $mapping) {
            foreach ($mapping as $movement => $direction) {
                // Registered: the tag decides who, the camera decides IN / OUT.
                $state = $direction === 'IN' ? Vehicle::STATE_OUTSIDE : Vehicle::STATE_INSIDE;
                $vehicle = $this->registeredVehicle($state);
                $this->scan($vehicle->rfid_tag_uid, $gate);
                $this->crossing($gate, $direction, 'high', "{$movement} from the camera")->assertCreated();

                $event = VehicleEvent::query()->where('vehicle_id', $vehicle->id)->sole();
                $this->assertSame(
                    [$direction === 'IN' ? 'ENTRY' : 'EXIT', $direction, 'camera', 'high', null],
                    [$event->event_type, $event->direction, $event->direction_source, $event->direction_confidence, $event->anomaly_reason],
                    "Registered {$direction} at {$gate} ({$movement})"
                );
                $this->assertSame($direction === 'IN' ? Vehicle::STATE_INSIDE : Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);

                // Unregistered: no tag.
                $this->crossing($gate, $direction, 'medium', "{$movement} from the camera")->assertCreated();
                $record = VisitorRecord::query()->where('external_event_key', $this->lastKey)->sole();
                $this->assertSame([$gate, $direction, 'camera', 'medium'], [$record->gate, $record->direction, $record->direction_source, $record->direction_confidence],
                    "Unregistered {$direction} at {$gate} ({$movement})");
            }
        }

        $counts = app(MovementCountService::class)->counts('today');
        $this->assertSame([4, 4, 0], [$counts['in'], $counts['out'], $counts['unknown']]);
        $this->assertSame(['gate-1' => [2, 2], 'gate-2' => [2, 2]], collect($counts['gates'])->map(fn (array $row) => [$row['in'], $row['out']])->all());
    }

    public function test_an_unclear_direction_uses_the_state_when_registered_and_goes_to_review_when_not(): void
    {
        foreach (['gate-1', 'gate-2'] as $gate) {
            // Registered, inside: OUT "batay sa state".
            $vehicle = $this->registeredVehicle(Vehicle::STATE_INSIDE);
            $this->scan($vehicle->rfid_tag_uid, $gate);
            $this->crossing($gate, 'UNKNOWN', null, 'went up 4% of the zone')->assertCreated();
            $event = VehicleEvent::query()->where('vehicle_id', $vehicle->id)->sole();
            $this->assertSame(['EXIT', 'state', null], [$event->event_type, $event->direction_source, $event->direction_confidence]);
            $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);

            // Unregistered: Direction unknown, not counted, in Review.
            $this->crossing($gate, 'UNKNOWN', null, 'went up 4% of the zone')->assertCreated();
            $record = VisitorRecord::query()->where('external_event_key', $this->lastKey)->sole();
            $this->assertSame(['UNKNOWN', null], [$record->direction, $record->direction_source]);
        }

        $counts = app(MovementCountService::class)->counts('today');
        $this->assertSame([0, 2, 2], [$counts['in'], $counts['out'], $counts['unknown']]);

        $this->actingAs($this->admin)->get(route('visitors.index', ['tab' => 'review']))
            ->assertOk()->assertSee('Review (2)')->assertSee('Unregistered Visitor');
        // Activity Logs marks the state-based direction.
        $this->actingAs($this->admin)->get(route('logs.index'))->assertOk()->assertSee('batay sa state');
        $this->actingAs($this->admin)->get(route('logs.print', ['report' => 'today']))->assertOk()->assertSee('OUT (batay sa state)');
    }

    public function test_the_camera_wins_over_a_state_that_does_not_fit_and_the_state_follows(): void
    {
        $vehicle = $this->registeredVehicle(Vehicle::STATE_INSIDE);
        $this->scan($vehicle->rfid_tag_uid, 'gate-2');
        $this->crossing('gate-2', 'IN', 'high', 'toward the camera')->assertCreated();

        $event = VehicleEvent::query()->sole();
        $this->assertSame(['ENTRY', 'camera', 'high'], [$event->event_type, $event->direction_source, $event->direction_confidence]);
        $this->assertStringContainsString('already inside', (string) $event->anomaly_reason);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['in']);
    }

    protected function crossing(string $gate, string $direction, ?string $confidence, string $reason): TestResponse
    {
        $this->travel(5)->seconds();
        $this->camerasOnline();

        return $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->postJson(route('api.integration.crossings'), [
            'external_event_key' => $this->lastKey = 'matrix-'.(++$this->sequence),
            'camera_role' => $gate,
            'direction' => $direction,
            'direction_reason' => $reason,
            'event_time' => now()->toIso8601String(),
            'track_id' => $this->sequence,
            'confidence' => 0.9,
            'detection_metadata' => ['direction_confidence' => $confidence, 'rfid_status' => 'no_pass'],
        ]);
    }

    protected function scan(string $uid, string $gate): void
    {
        // Well apart from the previous pass, so the read belongs to the next one.
        $this->travel(30)->seconds();
        $this->camerasOnline();
        app(RfidIngestService::class)->ingest(['tag_uid' => $uid, 'scan_location' => $gate], 'hardware_placeholder');
    }

    protected function registeredVehicle(string $state): Vehicle
    {
        $number = ++$this->sequence;
        $vehicle = Vehicle::query()->create([
            'plate_number' => sprintf('MTX %04d', $number), 'vehicle_owner_name' => 'Matrix Owner', 'category' => 'faculty_staff', 'vehicle_type' => 'Car',
        ]);
        $vehicle->forceFill(['current_state' => $state])->save();
        $tag = RfidTag::query()->create(['uid' => 'MTX-TAG-'.$number, 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        return $vehicle;
    }
}
