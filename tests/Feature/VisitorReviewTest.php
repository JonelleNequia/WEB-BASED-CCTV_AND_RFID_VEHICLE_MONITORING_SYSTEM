<?php

namespace Tests\Feature;

use App\Models\PlateProfile;
use App\Models\ReviewAction;
use App\Models\User;
use App\Models\VisitorRecord;
use App\Services\MovementCountService;
use App\Services\PlateMatchService;
use App\Services\VisitorRecordService;
use App\Services\VisitorReviewService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Gate direction work, Phase D: Visitors › Review, one card at a time.
 * Plates to check and unknown directions wait there; counts never wait;
 * every action updates the record, counts, plate profile and ranking at
 * once and is kept in the audit log.
 */
class VisitorReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $guard;

    protected int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->freezeTime();
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $this->guard = User::query()->create(['name' => 'Gate Guard', 'email' => 'guard.review@philcst.local', 'password' => Hash::make('password'), 'role' => 'guard']);
    }

    public function test_what_waits_in_review_is_counted_already_and_shown_as_cards(): void
    {
        $known = $this->record('IN', 'read', 'ABC 1234', 0.95, profile: true);
        $unreadable = $this->record('IN', 'unreadable', null, 0.1, guess: 'ABC-1Z34');
        $unsure = $this->record('OUT', 'read', 'KVC 5555', 0.4);
        $lowMatch = $this->record('OUT', 'read', 'ABC 1284', 0.9, match: PlateMatchService::LOW, suggested: $known->plate_profile_id);
        $unknownWay = $this->record('UNKNOWN', 'read', 'NAB 7777', 0.95, profile: true);
        $this->record('IN', 'read', 'ZTE 1000', 0.97, profile: true); // clear: not in Review

        $queue = app(VisitorReviewService::class)->queue()->pluck('id')->sort()->values()->all();
        $this->assertSame([$unreadable->id, $unsure->id, $lowMatch->id, $unknownWay->id], $queue);

        // Counted already: three IN and two OUT; the unknown direction is not.
        $counts = app(MovementCountService::class)->counts('today');
        $this->assertSame([3, 2, 1], [$counts['in'], $counts['out'], $counts['unknown']]);

        // Known plates like the read, with how many times they passed.
        $suggestions = app(VisitorReviewService::class)->suggestions($lowMatch->fresh());
        $this->assertSame('ABC 1234', $suggestions->first()['profile']->plate_number);

        $this->actingAs($this->guard)->get(route('visitors.index', ['tab' => 'review']))
            ->assertOk()
            ->assertSee('Review (4)')
            ->assertSee('review-card-'.$unreadable->id, false)
            ->assertSee('Plate unreadable')
            ->assertSee('Not sure')
            ->assertSee('Possible match')
            ->assertSee('Direction unknown')
            ->assertSee('value="pick_match:'.$known->plate_profile_id.'"', false)
            ->assertSee('<kbd>C</kbd> Confirm', false)
            ->assertSee('list="review-plate-options"', false)
            ->assertSee('visitor-review.js', false)
            ->assertDontSee('review-card-'.$known->id, false);

        // Counter in the sidebar (guards too) and on the Dashboard.
        $this->actingAs($this->guard)->get(route('gates.index'))->assertOk()->assertSee('4 to review');
        $this->actingAs($this->admin)->get(route('dashboard.index'))->assertOk()->assertSee('<span>4</span> to review', false);
    }

    public function test_each_action_updates_record_profile_counts_and_the_audit_log(): void
    {
        $known = $this->record('IN', 'read', 'ABC 1234', 0.95, profile: true);
        $unreadable = $this->record('OUT', 'unreadable', null, 0.1, guess: 'ABC-1Z34');
        $lowMatch = $this->record('OUT', 'read', 'ABC 1284', 0.9, match: PlateMatchService::LOW, suggested: $known->plate_profile_id);
        $unsure = $this->record('UNKNOWN', 'read', 'KVC 5555', 0.4);
        $ghost = $this->record('IN', 'unreadable', null, 0.0);
        $twice = $this->record('IN', 'unreadable', null, 0.0);
        $profile = PlateProfile::query()->findOrFail($known->plate_profile_id);

        // Pick the known plate: tied, the profile and ranking count it, the correction is learned.
        $this->act($lowMatch, 'pick_match', ['profile_id' => $profile->id])
            ->assertOk()->assertJsonPath('needs', ['plate' => false, 'direction' => false])->assertJsonPath('remaining', 4);
        $this->assertSame([$profile->id, 'ABC 1234'], [$lowMatch->fresh()->plate_profile_id, $lowMatch->fresh()->plate_number]);
        $this->assertSame(2, $profile->fresh()->visit_count);
        $this->assertSame('ABC1234', app(PlateMatchService::class)->learnedPlateKey('ABC1284'));

        // Type the plate of an unreadable one.
        $this->act($unreadable, 'correct_plate', ['plate' => 'abc 1234'])->assertOk()->assertJsonPath('remaining', 3);
        $this->assertSame(3, $profile->fresh()->visit_count);

        // Set OUT first: the plate still waits, so the card stays.
        $this->act($unsure, 'set_direction', ['direction' => 'OUT'])->assertOk()->assertJsonPath('needs', ['plate' => true, 'direction' => false]);
        $this->assertSame('OUT', $unsure->fresh()->direction);
        $this->assertSame(3, app(MovementCountService::class)->counts('today')['out']);
        // Then confirm the read: done.
        $this->act($unsure, 'confirm')->assertOk()->assertJsonPath('needs', ['plate' => false, 'direction' => false]);
        $this->assertSame([PlateMatchService::CONFIRMED, 'KVC5555'], [$unsure->fresh()->match_confidence, $unsure->fresh()->plateProfile->plate_key]);

        // A name is kept with the plate.
        $this->act($unsure, 'set_name', ['name' => 'Juan Dela Cruz (delivery)'])->assertOk();
        $this->assertSame('Juan Dela Cruz (delivery)', $unsure->fresh()->plateProfile->owner_name);
        // Not before the plate is known.
        $this->act($ghost, 'set_name', ['name' => 'Someone'])->assertUnprocessable()->assertJsonValidationErrors('name');

        // Not a vehicle: no longer counted. No plate visible: still counted.
        $before = app(MovementCountService::class)->counts('today')['in'];
        $this->act($ghost, 'dismiss', ['reason' => 'not_vehicle'])->assertOk();
        $this->assertSame([VisitorRecord::STATUS_DISMISSED, $before - 1], [$ghost->fresh()->status, app(MovementCountService::class)->counts('today')['in']]);
        $this->act($twice, 'no_plate')->assertOk()->assertJsonPath('remaining', 0);
        $this->assertSame([VisitorRecord::STATUS_ACTIVE, VisitorRecord::PLATE_UNREADABLE], [$twice->fresh()->status, $twice->fresh()->plate_status]);
        $this->assertSame($before - 1, app(MovementCountService::class)->counts('today')['in']);

        // Audit log: who, when, before and after.
        $log = ReviewAction::query()->orderBy('id')->get();
        $this->assertSame(['pick_match', 'correct_plate', 'set_direction', 'confirm', 'set_name', 'dismiss', 'no_plate'], $log->pluck('action')->all());
        $this->assertSame(['ABC 1284 (read, 90%)', 'ABC 1234'], [$log[0]->from_value, $log[0]->to_value]);
        $this->assertSame(['unreadable (guess ABC-1Z34)', 'ABC 1234'], [$log[1]->from_value, $log[1]->to_value]);
        $this->assertSame(['UNKNOWN', 'OUT'], [$log[2]->from_value, $log[2]->to_value]);
        $this->assertSame([$this->guard->id], $log->pluck('user_id')->unique()->values()->all());
        $this->actingAs($this->admin)->get(route('visitors.index', ['tab' => 'review']))->assertOk()
            ->assertSee('Recent review actions')->assertSee('Picked a known plate')->assertSee('Gate Guard')->assertSee('Nothing to review');
    }

    public function test_without_javascript_the_buttons_post_the_form(): void
    {
        $known = $this->record('IN', 'read', 'ABC 1234', 0.95, profile: true);
        $low = $this->record('OUT', 'read', 'ABC 1284', 0.9, match: PlateMatchService::LOW, suggested: $known->plate_profile_id);

        $this->actingAs($this->guard)->from(route('visitors.index', ['tab' => 'review']))
            ->patch(route('visitors.records.review', $low), ['action' => 'pick_match:'.$known->plate_profile_id, 'plate' => 'ABC 1284'])
            ->assertRedirect(route('visitors.index', ['tab' => 'review']))
            ->assertSessionHas('status', "Record #{$low->id}: plate set to ABC 1234.");

        $this->actingAs($this->guard)->patch(route('visitors.records.review', $low), ['action' => 'nothing'])->assertSessionHasErrors('action');
    }

    protected function act(VisitorRecord $record, string $action, array $input = []): TestResponse
    {
        return $this->actingAs($this->guard)->patchJson(route('visitors.records.review', $record), ['action' => $action] + $input);
    }

    protected function record(string $direction, string $status, ?string $plate, float $confidence, ?string $guess = null, bool $profile = false, ?string $match = null, ?int $suggested = null): VisitorRecord
    {
        $record = VisitorRecord::query()->create([
            'external_event_key' => 'review-'.(++$this->sequence),
            'gate' => 'gate-1',
            'direction' => $direction,
            'seen_at' => now(),
            'plate_status' => $status,
            'plate_number' => $plate,
            'plate_key' => $plate ? str_replace(' ', '', $plate) : null,
            'plate_confidence' => $confidence,
            'ocr_plate_number' => $guess,
            'vehicle_type' => 'Car',
            'vehicle_color' => 'White',
            'match_confidence' => $match,
            'suggested_profile_id' => $suggested,
        ]);

        if ($profile) {
            $service = app(VisitorRecordService::class);
            $record->forceFill(['plate_profile_id' => $service->profileFor($plate, $record->seen_at)->id])->save();
            $service->refreshProfiles([$record->plate_profile_id]);
        }

        return $record->fresh();
    }
}
