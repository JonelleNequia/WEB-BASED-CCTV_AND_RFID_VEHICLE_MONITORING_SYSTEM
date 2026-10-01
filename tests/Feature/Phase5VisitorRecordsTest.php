<?php

namespace Tests\Feature;

use App\Models\PlateProfile;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VisitorRecord;
use App\Services\RfidIngestService;
use App\Support\CameraFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 5 (visitor model): Unregistered Visitor records and plate profiles.
 */
class Phase5VisitorRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $this->guard = User::query()->create([
            'name' => 'Gate Guard', 'email' => 'guard.test@philcst.local', 'password' => Hash::make('password'), 'role' => 'guard',
        ]);
    }

    protected function tearDown(): void
    {
        File::delete(CameraFiles::statusPath());

        parent::tearDown();
    }

    public function test_no_pass_crossing_becomes_a_record_and_the_plate_builds_a_profile(): void
    {
        $this->crossing('k-1', 'IN')->assertCreated()->assertJsonPath('visitor_record_id', fn ($id) => $id !== null);

        $record = VisitorRecord::query()->sole();
        $this->assertSame(['gate-1', 'IN', VisitorRecord::PLATE_PENDING, VisitorRecord::STATUS_ACTIVE], [$record->gate, $record->direction, $record->plate_status, $record->status]);
        $this->assertNotNull($record->snapshot_path); // the crossing's (full-resolution) snapshot

        $this->plate('k-1', 'read', 'ABC-1234', 0.82, image: true)->assertOk()->assertJsonPath('visitor_record.plate_number', 'ABC 1234');

        $record->refresh();
        $profile = PlateProfile::query()->sole();
        $this->assertSame(['ABC 1234', 'ABC1234', 0.82, $profile->id], [$record->plate_number, $record->plate_key, $record->plate_confidence, $record->plate_profile_id]);
        Storage::disk('public')->assertExists($record->plate_image_path);
        $this->assertSame([1, 'ABC1234'], [$profile->visit_count, $profile->plate_key]);

        // The same plate leaves two hours later through the same gate: second visit.
        $this->travel(2)->hours();
        $this->crossing('k-2', 'OUT');
        $this->plate('k-2', 'read', 'ABC-1234', 0.9);
        $this->assertSame(2, $profile->fresh()->visit_count);
        $this->assertTrue($profile->fresh()->last_seen_at->greaterThan($profile->first_seen_at));
    }

    public function test_unreadable_plate_keeps_a_best_guess_and_a_guard_correction_wins(): void
    {
        $this->crossing('k-u', 'IN');
        $this->plate('k-u', 'unreadable', null, 0.4, bestGuess: 'ABC-1Z34');

        $record = VisitorRecord::query()->sole();
        // Not a PH layout (a letter among the digits), so the guess stays as read.
        $this->assertSame([VisitorRecord::PLATE_UNREADABLE, null, 'ABC-1Z34', null], [$record->plate_status, $record->plate_number, $record->ocr_plate_number, $record->plate_profile_id]);
        $this->assertSame('Plate unreadable', $record->plateLabel());

        $this->actingAs($this->guard)->get(route('visitors.index'))
            ->assertOk()->assertSee('Plate unreadable')->assertSee('Best guess: ABC-1Z34')->assertSee('Correct plate');

        $this->actingAs($this->guard)->patch(route('visitors.records.plate', $record), ['plate_number' => 'abc 1234'])
            ->assertSessionHasNoErrors();
        $record->refresh();
        $this->assertSame([VisitorRecord::PLATE_CORRECTED, 'ABC 1234', 'ABC-1Z34', $this->guard->id], [$record->plate_status, $record->plate_number, $record->ocr_plate_number, $record->corrected_by]);
        $this->assertSame(1, PlateProfile::query()->where('plate_key', 'ABC1234')->value('visit_count'));

        // A late detector update does not undo the guard's plate.
        $this->plate('k-u', 'read', 'ABD-1234', 0.9);
        $this->assertSame('ABC 1234', $record->fresh()->plate_number);
    }

    public function test_merging_a_misread_plate_moves_its_visits_and_later_reads(): void
    {
        $this->crossing('k-a', 'IN');
        $this->plate('k-a', 'read', 'ABC-1234', 0.9);
        $this->travel(10)->minutes();
        $this->crossing('k-b', 'OUT');
        $this->plate('k-b', 'read', 'ABC-1284', 0.8); // misread 3 -> 8

        $right = PlateProfile::query()->where('plate_key', 'ABC1234')->sole();
        $wrong = PlateProfile::query()->where('plate_key', 'ABC1284')->sole();

        // Guards cannot merge.
        $this->actingAs($this->guard)->post(route('visitors.profiles.merge', $wrong), ['target_id' => $right->id])->assertForbidden();

        $this->actingAs($this->admin)->post(route('visitors.profiles.merge', $wrong), ['target_id' => $right->id])
            ->assertRedirect(route('visitors.profiles.show', $right));

        $this->assertSame([2, $right->id, 0], [$right->fresh()->visit_count, $wrong->fresh()->merged_into_id, $wrong->fresh()->visit_count]);
        $this->assertSame([$right->id, $right->id], VisitorRecord::query()->orderBy('id')->pluck('plate_profile_id')->all());

        // The camera misreads it the same way again: the visit goes to the right plate.
        $this->travel(1)->days();
        $this->crossing('k-c', 'IN');
        $this->plate('k-c', 'read', 'ABC-1284', 0.8);
        $this->assertSame(3, $right->fresh()->visit_count);
        $this->actingAs($this->admin)->get(route('visitors.profiles.show', $wrong))->assertRedirect(route('visitors.profiles.show', $right));
        $this->actingAs($this->admin)->get(route('visitors.profiles.show', $right))->assertOk()->assertSee('ABC 1284');
    }

    public function test_registered_vehicles_never_become_visitor_records(): void
    {
        // Crossing matched to a registered tag: no record.
        $this->crossing('k-reg', 'IN', 'matched')->assertJsonPath('visitor_record_id', null);
        $this->assertSame(0, VisitorRecord::query()->count());
        $this->travel(1)->minutes();

        // No-pass crossing, then the registered tag is read late (within the window): record dismissed.
        $this->cameraOnline();
        $vehicle = Vehicle::query()->create(['plate_number' => 'REG 1001', 'vehicle_owner_name' => 'Staff', 'category' => 'faculty_staff', 'vehicle_type' => 'Car']);
        $tag = RfidTag::query()->create(['uid' => 'REG-TAG-1', 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        $this->crossing('k-late', 'IN');
        $this->travel(2)->seconds();
        $this->cameraOnline();
        app(RfidIngestService::class)->ingest(['tag_uid' => 'REG-TAG-1', 'scan_location' => 'gate-1'], 'hardware_placeholder');

        $record = VisitorRecord::query()->where('external_event_key', 'k-late')->sole();
        $this->assertSame(VisitorRecord::STATUS_DISMISSED, $record->status);
        $this->assertStringContainsString('REG 1001', $record->status_note);
    }

    public function test_the_same_vehicle_sent_twice_is_a_duplicate_and_not_counted(): void
    {
        // New track ID for the same car: same box, 2 s later.
        $this->crossing('k-d1', 'IN');
        $this->travel(2)->seconds();
        $this->crossing('k-d2', 'IN');
        $this->assertSame(VisitorRecord::STATUS_DUPLICATE, VisitorRecord::query()->where('external_event_key', 'k-d2')->value('status'));

        // Same plate and direction 30 s later, different box.
        $this->travel(30)->seconds();
        $this->crossing('k-d3', 'IN', box: [400, 100, 500, 200]);
        $this->plate('k-d1', 'read', 'NBC-123', 0.9);
        $this->plate('k-d3', 'read', 'NBC-123', 0.9);

        $this->assertSame(VisitorRecord::STATUS_DUPLICATE, VisitorRecord::query()->where('external_event_key', 'k-d3')->value('status'));
        $this->assertSame(1, PlateProfile::query()->where('plate_key', 'NBC123')->value('visit_count'));
        $this->actingAs($this->admin)->get(route('visitors.index', ['status' => 'duplicate']))->assertOk()->assertSee('Same plate as record');
    }

    public function test_guard_can_keep_a_visitor_note_and_dismiss_a_false_alarm(): void
    {
        $this->crossing('k-n', 'IN');
        $this->plate('k-n', 'read', 'DLV-4321', 0.9);
        $profile = PlateProfile::query()->sole();

        $this->actingAs($this->guard)->patch(route('visitors.profiles.note', $profile), ['note' => 'School supplies delivery'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['School supplies delivery', $this->guard->id], [$profile->fresh()->note, $profile->fresh()->note_updated_by]);
        $this->actingAs($this->guard)->get(route('visitors.index', ['tab' => 'plates']))->assertOk()->assertSee('DLV 4321')->assertSee('School supplies delivery');

        $record = VisitorRecord::query()->sole();
        $this->actingAs($this->guard)->patch(route('visitors.records.dismiss', $record), ['reason' => 'Not a vehicle'])->assertSessionHasNoErrors();
        $this->assertSame(VisitorRecord::STATUS_DISMISSED, $record->fresh()->status);
        $this->assertSame(0, $profile->fresh()->visit_count);
        $this->actingAs($this->guard)->get(route('gates.index'))->assertOk()->assertSee(route('visitors.index'), false);
    }

    protected function crossing(string $key, string $direction, string $rfidStatus = 'no_pass', array $box = [100, 100, 200, 200]): TestResponse
    {
        return $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->post(route('api.integration.crossings'), [
            'external_event_key' => $key,
            'camera_role' => 'gate-1',
            'direction' => $direction,
            'event_time' => now()->toIso8601String(),
            'track_id' => 5,
            'confidence' => 0.9,
            'detected_vehicle_type' => 'Car',
            'detection_metadata' => json_encode(['rfid_status' => $rfidStatus, 'bbox_xyxy' => $box]),
            'snapshot' => UploadedFile::fake()->image('crossing.jpg', 2560, 1440),
        ], ['Accept' => 'application/json']);
    }

    protected function plate(string $key, string $status, ?string $plate, float $confidence, ?string $bestGuess = null, bool $image = false): TestResponse
    {
        return $this->withHeaders(['X-Api-Key' => 'test-detector-key'])->post(route('api.integration.visitor-plates'), array_filter([
            'external_event_key' => $key,
            'camera_role' => 'gate-1',
            'event_time' => now()->toIso8601String(),
            'plate_status' => $status,
            'plate_number' => $plate,
            'plate_confidence' => $confidence,
            'best_guess' => $bestGuess ?? $plate,
            'vehicle_color' => 'White',
            'detected_vehicle_type' => 'Car',
            'ocr_details' => json_encode(['frames_checked' => 3]),
            'plate_image' => $image ? UploadedFile::fake()->image('plate.jpg', 240, 80) : null,
        ]), ['Accept' => 'application/json']);
    }

    protected function cameraOnline(): void
    {
        File::ensureDirectoryExists(dirname(CameraFiles::statusPath()));
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'cameras' => ['gate-1' => ['camera_role' => 'gate-1', 'camera_running' => true, 'calibration_ready' => true]],
        ]));
    }
}
