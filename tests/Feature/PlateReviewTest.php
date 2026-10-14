<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VisitorRecord;
use App\Services\MovementCountService;
use App\Services\PlateReadabilityService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gate direction work, Phase B: an unreadable plate, or one read with a low
 * confidence, is counted at once and goes to Visitors › Review for the
 * plate; Settings › Advanced › Plate readability says how big and sharp the
 * plates are at each gate and what to change on the camera.
 */
class PlateReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->freezeTime();
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    public function test_unreadable_and_unsure_plates_are_counted_and_wait_in_review(): void
    {
        $unreadable = $this->record('gate-1', 'IN', VisitorRecord::PLATE_UNREADABLE, null, 0.2, 'NAB-123');
        $unsure = $this->record('gate-2', 'OUT', VisitorRecord::PLATE_READ, 'KVC 1234', 0.45);
        $this->record('gate-1', 'OUT', VisitorRecord::PLATE_READ, 'ZTE 5555', 0.92);

        // Counted at once: the count never waits for the plate.
        $counts = app(MovementCountService::class)->counts('today');
        $this->assertSame([1, 2], [$counts['in'], $counts['out']]);

        $this->actingAs($this->admin)->get(route('visitors.index', ['tab' => 'review']))
            ->assertOk()
            ->assertSee('Review (2)')
            ->assertSee('To review')
            ->assertSee('review-card-'.$unreadable->id, false)
            ->assertSee('review-card-'.$unsure->id, false)
            ->assertSee('Plate unreadable')
            ->assertSee('Not sure')
            ->assertSee('45%')
            ->assertDontSee('ZTE 5555');

        // A guard types the plate: it leaves Review, the plate profile is made.
        $this->actingAs($this->admin)->patch(route('visitors.records.plate', $unreadable), ['plate_number' => 'nab 1234'])
            ->assertSessionHas('status', "Record #{$unreadable->id} plate set to NAB 1234.");
        $this->assertSame(['corrected', 'NAB 1234'], [$unreadable->fresh()->plate_status, $unreadable->fresh()->plate_number]);
        $this->assertNotNull($unreadable->fresh()->plate_profile_id);

        // "No plate visible": stays counted, without a plate, and leaves Review.
        $this->actingAs($this->admin)->patch(route('visitors.records.plate', $unsure), ['plate_number' => 'KVC 1234', 'unreadable' => '1']);
        $this->assertSame(['unreadable', null], [$unsure->fresh()->plate_status, $unsure->fresh()->plate_number]);
        $this->assertSame(0, VisitorRecord::query()->plateToCheck()->count());
        $this->assertSame(2, app(MovementCountService::class)->counts('today')['out']);
        $this->actingAs($this->admin)->get(route('visitors.index', ['tab' => 'review']))->assertOk()->assertSee('Nothing to review');
    }

    public function test_plate_readability_report_per_gate(): void
    {
        // Gate 1: small, blurred plates; Gate 2: big and sharp.
        foreach ([[90, 300], [100, 350], [110, 400]] as [$width, $sharpness]) {
            $this->record('gate-1', 'IN', VisitorRecord::PLATE_UNREADABLE, null, 0.1, null, ['reader' => 'plate_detector', 'plates_found' => 3, 'best_crop' => ['width_px' => $width, 'sharpness' => $sharpness]]);
        }
        foreach ([[180, 1500], [200, 1800]] as [$width, $sharpness]) {
            $this->record('gate-2', 'OUT', VisitorRecord::PLATE_READ, 'ABC 1234', 0.9, null, ['reader' => 'plate_detector', 'plates_found' => 4, 'best_crop' => ['width_px' => $width, 'sharpness' => $sharpness]]);
        }

        $report = app(PlateReadabilityService::class)->report();
        $this->assertSame([3, 0, 3, 100.0, 350.0], [$report['gate-1']['passes'], $report['gate-1']['read'], $report['gate-1']['unreadable'], $report['gate-1']['median_width_px'], $report['gate-1']['median_sharpness']]);
        $this->assertStringContainsString('Plates are small (about 100 px wide', $report['gate-1']['hints'][0]);
        $this->assertStringContainsString('faster shutter (1/500 s or faster)', $report['gate-1']['hints'][1]);
        $this->assertSame(['Plates are big and sharp enough.'], $report['gate-2']['hints']);

        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'plate-camera']))
            ->assertOk()
            ->assertSee('Plate readability')
            ->assertSee('100 px')
            ->assertSee('Recommended camera settings (TP-Link VIGI)')
            ->assertSee('1/500 s or faster');
    }

    /**
     * @param  array<string, mixed>|null  $ocr
     */
    protected function record(string $gate, string $direction, string $status, ?string $plate, ?float $confidence, ?string $guess = null, ?array $ocr = null): VisitorRecord
    {
        return VisitorRecord::query()->create([
            'external_event_key' => 'plate-review-'.(++$this->sequence),
            'gate' => $gate,
            'direction' => $direction,
            'seen_at' => now(),
            'plate_status' => $status,
            'plate_number' => $plate,
            'plate_key' => $plate ? str_replace(' ', '', $plate) : null,
            'plate_confidence' => $confidence,
            'ocr_plate_number' => $guess,
            'ocr_details_json' => $ocr,
            'vehicle_type' => 'Car',
        ]);
    }
}
