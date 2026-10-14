<?php

namespace Tests\Feature;

use App\Models\PlateCorrection;
use App\Models\PlateProfile;
use App\Models\User;
use App\Models\VisitorRecord;
use App\Services\MovementCountService;
use App\Services\PlateMatchService;
use App\Services\VisitorPresenceService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Gate direction work, Phase C: the same unregistered vehicle across passes
 * and gates. IN / OUT is counted whatever the plate; the plate ties the
 * passes (also one character off, when the rest agrees); a low match waits
 * in Review; guard corrections are remembered.
 */
class PlateMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $this->travelTo(Carbon::parse('2026-10-14 09:15:00', 'Asia/Manila'));
    }

    public function test_in_at_gate_1_and_out_at_gate_2_are_counted_then_paired_once_the_plate_is_known(): void
    {
        $this->pass('in-1', 'gate-1', 'IN', 'read', 'ABC-1234');
        $this->travel(25)->minutes();
        $this->pass('out-1', 'gate-2', 'OUT', 'unreadable', null);

        // Counted at once, plate or not.
        $counts = app(MovementCountService::class)->counts('today');
        $this->assertSame([1, 1], [$counts['in'], $counts['out']]);
        $this->assertSame(['gate-1' => [1, 0], 'gate-2' => [0, 1]], collect($counts['gates'])->map(fn (array $row) => [$row['in'], $row['out']])->all());

        // Without the OUT's plate the vehicle may still be inside (an estimate).
        $inside = app(VisitorPresenceService::class)->possiblyInside();
        $this->assertSame(['ABC 1234'], $inside['vehicles']->map(fn ($item) => $item['profile']->plate_number)->all());
        $this->actingAs($this->admin)->get(route('visitors.index'))->assertOk()->assertSee('Posibleng nasa loob pa')->assertSee('tantya lang');
        $this->actingAs($this->admin)->get(route('dashboard.index'))->assertOk()->assertSee('+1 posibleng nasa loob pa (unregistered, tantya lang)');

        // A guard types the OUT's plate: one trip, Gate 1 -> Gate 2, 25 minutes inside.
        $out = VisitorRecord::query()->where('external_event_key', 'out-1')->sole();
        $this->actingAs($this->admin)->patch(route('visitors.records.plate', $out), ['plate_number' => 'ABC 1234']);
        $profile = PlateProfile::query()->where('plate_key', 'ABC1234')->sole();
        $this->assertSame([2, PlateMatchService::MANUAL], [$profile->fresh()->visit_count, $out->fresh()->match_confidence]);
        $trip = app(VisitorPresenceService::class)->trips($profile)->sole();
        $this->assertSame(['gate-1', 'gate-2', 25], [$trip['in']->gate, $trip['out']->gate, $trip['minutes']]);
        $this->assertTrue(app(VisitorPresenceService::class)->possiblyInside()['vehicles']->isEmpty());

        $this->actingAs($this->admin)->get(route('visitors.profiles.show', $profile))->assertOk()
            ->assertSee('Movements')
            ->assertSee('IN · Gate 1 · Oct 14, 2026 · 9:15 AM', false)
            ->assertSee('OUT · Gate 2 · 9:40 AM', false)
            ->assertSee('25 min inside');
    }

    public function test_one_character_off_at_the_other_gate_is_tied_high_or_medium_when_type_colour_and_time_agree(): void
    {
        $this->pass('in-a', 'gate-1', 'IN', 'read', 'ABC-1234');
        $this->pass('in-b', 'gate-1', 'IN', 'read', 'NAB-5678');
        $this->travel(20)->minutes();

        // B read as 8 (an easy mix-up), same type and colour, IN then OUT: high.
        $this->pass('out-a', 'gate-2', 'OUT', 'read', 'A8C-1234');
        $high = VisitorRecord::query()->where('external_event_key', 'out-a')->sole();
        $abc = PlateProfile::query()->where('plate_key', 'ABC1234')->sole();
        // The record keeps what was read; the profile ties it to ABC 1234.
        $this->assertSame([$abc->id, PlateMatchService::HIGH, 'A8C-1234'], [$high->plate_profile_id, $high->match_confidence, $high->plate_number]);
        $this->assertStringContainsString('ABC 1234 with B read as 8; same type; same colour; IN at Gate 1 20 min before', $high->match_reason);
        $this->assertFalse(PlateProfile::query()->where('plate_key', 'A8C1234')->exists());

        // 7 read as 1 (not an easy mix-up), same type and colour: medium.
        $this->pass('out-b', 'gate-2', 'OUT', 'read', 'NAB-5618');
        $medium = VisitorRecord::query()->where('external_event_key', 'out-b')->sole();
        $this->assertSame([PlateProfile::query()->where('plate_key', 'NAB5678')->value('id'), PlateMatchService::MEDIUM], [$medium->plate_profile_id, $medium->match_confidence]);

        $this->assertSame(2, $abc->fresh()->visit_count);
        $this->assertSame(0, VisitorRecord::query()->plateToCheck()->count());
        $this->actingAs($this->admin)->get(route('visitors.index'))->assertOk()->assertSee('Matched (high)')->assertSee('Matched (medium)');
    }

    public function test_a_low_match_is_not_tied_and_waits_in_review_then_the_correction_is_learned(): void
    {
        $this->pass('in-a', 'gate-1', 'IN', 'read', 'ABC-1234');
        $this->travel(20)->minutes();

        // 3 read as 8 and a different colour: low, not tied, suggested in Review.
        $this->pass('out-a', 'gate-2', 'OUT', 'read', 'ABC-1284', color: 'Red');
        $low = VisitorRecord::query()->where('external_event_key', 'out-a')->sole();
        $abc = PlateProfile::query()->where('plate_key', 'ABC1234')->sole();
        $this->assertSame([null, PlateMatchService::LOW, $abc->id], [$low->plate_profile_id, $low->match_confidence, $low->suggested_profile_id]);
        $this->assertFalse(PlateProfile::query()->where('plate_key', 'ABC1284')->exists());
        $this->assertSame(1, VisitorRecord::query()->plateToCheck()->count());
        // Still counted.
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['out']);

        $this->actingAs($this->admin)->get(route('visitors.index', ['tab' => 'review']))->assertOk()
            ->assertSee('Possible match')
            ->assertSee('Possible match: ABC 1234 with', false)
            ->assertSee('value="pick_match:'.$abc->id.'"', false)
            ->assertSee('value="ABC 1234"', false);

        // The guard confirms the suggested plate: tied, and the correction is remembered.
        $this->actingAs($this->admin)->patch(route('visitors.records.plate', $low), ['plate_number' => 'ABC 1234']);
        $this->assertSame([$abc->id, PlateMatchService::MANUAL, null], [$low->fresh()->plate_profile_id, $low->fresh()->match_confidence, $low->fresh()->suggested_profile_id]);
        $this->assertSame(['read_key' => 'ABC1284', 'plate_key' => 'ABC1234', 'times' => 1], PlateCorrection::query()->sole()->only(['read_key', 'plate_key', 'times']));

        // Next day the camera misreads it the same way: the right plate at once.
        $this->travel(1)->days();
        $this->pass('in-c', 'gate-1', 'IN', 'read', 'ABC-1284', color: 'Red');
        $learned = VisitorRecord::query()->where('external_event_key', 'in-c')->sole();
        $this->assertSame(['ABC 1234', $abc->id, PlateMatchService::LEARNED], [$learned->plate_number, $learned->plate_profile_id, $learned->match_confidence]);
        // And 3 <-> 8 now counts as an easy mix-up.
        $this->assertTrue(app(PlateMatchService::class)->easyMixup('8', '3'));
        $this->assertSame(3, $abc->fresh()->visit_count);
    }

    public function test_no_sensible_time_or_two_equal_candidates_is_low(): void
    {
        // IN then IN again: not a sensible pair.
        $this->pass('in-a', 'gate-1', 'IN', 'read', 'ABC-1234');
        $this->travel(5)->minutes();
        $this->pass('in-b', 'gate-2', 'IN', 'read', 'A8C-1234');
        $record = VisitorRecord::query()->where('external_event_key', 'in-b')->sole();
        $this->assertSame([PlateMatchService::LOW, null], [$record->match_confidence, $record->plate_profile_id]);
        $this->assertStringContainsString('its last pass was also IN', $record->match_reason);

        // Two known plates one character from the read, and equally good: a guard decides.
        $this->pass('in-c', 'gate-1', 'IN', 'read', 'XYZ-1235');
        $this->pass('in-d', 'gate-1', 'IN', 'read', 'XYZ-1299');
        $this->travel(10)->minutes();
        $this->pass('out-e', 'gate-2', 'OUT', 'read', 'XYZ-1239');
        $tie = VisitorRecord::query()->where('external_event_key', 'out-e')->sole();
        $this->assertSame([PlateMatchService::LOW, null], [$tie->match_confidence, $tie->plate_profile_id]);
        $this->assertStringContainsString('Two known plates fit equally', $tie->match_reason);
    }

    protected function pass(string $key, string $gate, string $direction, string $status, ?string $plate, string $color = 'White'): void
    {
        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->postJson(route('api.integration.crossings'), [
            'external_event_key' => $key,
            'camera_role' => $gate,
            'direction' => $direction,
            'event_time' => now()->toIso8601String(),
            'track_id' => 5,
            'confidence' => 0.9,
            'detected_vehicle_type' => 'Car',
            'detection_metadata' => ['rfid_status' => 'no_pass', 'direction_confidence' => 'high'],
        ])->assertCreated();

        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->postJson(route('api.integration.visitor-plates'), array_filter([
            'external_event_key' => $key,
            'camera_role' => $gate,
            'event_time' => now()->toIso8601String(),
            'plate_status' => $status,
            'plate_number' => $plate,
            'plate_confidence' => 0.9,
            'best_guess' => $plate,
            'vehicle_color' => $color,
            'detected_vehicle_type' => 'Car',
        ]))->assertSuccessful();
    }
}
