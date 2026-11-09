<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\CrossingSnapshotService;
use App\Services\MovementCountService;
use App\Services\RfidTagMatcher;
use App\Services\VehicleRegistryService;
use App\Services\VisitorRecordService;
use App\Support\OccurrenceMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class RegistrationSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->travelTo(now()->startOfDay()->addHours(12));
        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('occurrences');
        $this->withHeader('X-Api-Key', 'test-detector-key');
    }

    private function payload(string $key = 'snapshot-pass', string $gate = 'gate-1'): array
    {
        return ['external_event_key' => $key, 'camera_role' => $gate,
            'camera_id' => Camera::forRole($gate)->value('id'), 'event_time' => now()->toIso8601String(),
            'direction' => 'IN', 'detection_metadata' => ['rfid_status' => 'no_pass', 'picture' => ['score' => 100]]];
    }

    public function test_registered_rfid_image_reaches_the_same_canonical_event_without_a_visitor_record(): void
    {
        $vehicle = app(VehicleRegistryService::class)->register(['plate_number' => '395ISX', 'vehicle_type' => 'Car',
            'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Snapshot Registrar', 'auto_register_tag' => true, 'rfid_uid' => 'SNAPSHOT-UID', 'display_number' => '1']);
        $this->travel(1)->minutes();
        app(RfidTagMatcher::class)->addRead('gate-1', 'SNAPSHOT-UID', now());
        $this->postJson(route('api.integration.crossings'), $this->payload() + ['snapshot' => UploadedFile::fake()->image('vehicle.jpg')])->assertCreated();
        $crossing = VehicleCrossing::sole();
        $event = VehicleEvent::sole();
        $this->assertNotNull($crossing->snapshot_path);
        $this->assertSame($crossing->snapshot_path, $event->vehicle_image_path);
        $this->assertNotNull($event->rfid_scan_log_id);
        $this->assertDatabaseCount('visitor_records', 0);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
    }

    public function test_wrong_gate_cannot_replace_the_crossing_image_or_other_metadata(): void
    {
        $this->postJson(route('api.integration.crossings'), $this->payload() + ['snapshot' => UploadedFile::fake()->image('first.jpg')])->assertCreated();
        $crossing = VehicleCrossing::sole();
        $before = $crossing->getRawOriginal();
        $this->postJson(route('api.integration.crossings'), $this->payload(gate: 'gate-2') + ['snapshot' => UploadedFile::fake()->image('wrong.jpg')])->assertStatus(409);
        $this->assertSame($before, $crossing->fresh()->getRawOriginal());
        $this->assertDatabaseCount('vehicle_crossings', 1);
    }

    public function test_late_crossing_cannot_take_over_an_earlier_ocr_occurrence(): void
    {
        $data = ['external_event_key' => 'snapshot-pass', 'camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(),
            'plate_status' => 'unreadable'];
        $this->postJson(route('api.integration.visitor-plates'), $data)->assertOk();
        $before = VisitorRecord::sole()->getRawOriginal();
        $this->postJson(route('api.integration.crossings'), $this->payload(gate: 'gate-2') + ['snapshot' => UploadedFile::fake()->image('wrong.jpg')])->assertStatus(409);
        $this->assertSame($before, VisitorRecord::sole()->getRawOriginal());
        $this->assertDatabaseCount('vehicle_crossings', 0);
        $this->assertCount(0, Storage::disk('occurrences')->allFiles());
    }

    public function test_changed_camera_time_direction_and_unlinked_key_are_rejected_without_partial_updates(): void
    {
        $data = $this->payload();
        $this->postJson(route('api.integration.crossings'), $data + ['snapshot' => UploadedFile::fake()->image('first.jpg')])->assertCreated();
        $before = VehicleCrossing::sole()->getRawOriginal();
        foreach ([['event_time' => now()->addDay()->toIso8601String()], ['direction' => 'OUT']] as $change) {
            $this->postJson(route('api.integration.crossings'), array_replace($data, $change) + ['snapshot' => UploadedFile::fake()->image('wrong.jpg')])->assertStatus(409);
        }
        $outsideWindow = $data;
        $outsideWindow['detection_metadata']['picture'] = ['selected_at' => now()->addMinutes(21)->toIso8601String(), 'score' => 200];
        $this->postJson(route('api.integration.crossings'), $outsideWindow + ['snapshot' => UploadedFile::fake()->image('unrelated.jpg')])->assertStatus(409);
        $this->postJson(route('api.integration.crossings'), array_replace($data, ['camera_id' => Camera::forRole('gate-2')->value('id')]))->assertUnprocessable();
        $this->assertSame($before, VehicleCrossing::sole()->getRawOriginal());
        VehicleEvent::create(['external_event_key' => 'unlinked', 'event_status' => 'completed', 'event_type' => 'ENTRY',
            'event_origin' => 'manual', 'event_time' => now(), 'camera_id' => Camera::forRole('gate-1')->value('id')]);
        $this->postJson(route('api.integration.crossings'), $this->payload('unlinked') + ['snapshot' => UploadedFile::fake()->image('unlinked.jpg')])->assertStatus(409);
        $this->assertDatabaseCount('vehicle_crossings', 1);
        $this->assertCount(1, Storage::disk('occurrences')->allFiles());
    }

    public function test_clearer_rfid_image_changes_no_movement_identity_state_or_type_evidence(): void
    {
        $vehicle = app(VehicleRegistryService::class)->register(['plate_number' => '395ISX', 'vehicle_type' => 'E-bike',
            'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Snapshot Registrar', 'auto_register_tag' => true, 'rfid_uid' => 'CLEARER-SNAPSHOT-UID', 'display_number' => '1']);
        app(RfidTagMatcher::class)->addRead('gate-1', 'CLEARER-SNAPSHOT-UID', now());
        $data = $this->payload();
        $data['detection_metadata']['picture']['selected_at'] = now()->toIso8601String();
        $this->postJson(route('api.integration.crossings'), $data + ['snapshot' => UploadedFile::fake()->image('first.jpg')])->assertCreated();
        $before = $vehicle->fresh()->getRawOriginal();
        $event = VehicleEvent::sole();
        $identity = $event->only(['id', 'event_time', 'event_type', 'rfid_scan_log_id', 'vehicle_id', 'vehicle_type']);
        $path = $event->vehicle_image_path;
        $this->travel(2)->seconds();
        $data['detection_metadata']['picture'] = ['score' => 150, 'selected_at' => now()->toIso8601String()];
        $this->postJson(route('api.integration.crossings'), $data + ['snapshot' => UploadedFile::fake()->image('better.jpg', 120, 80)])
            ->assertOk()->assertJsonPath('image_update', 'stored');
        $this->assertEquals($identity, $event->fresh()->only(array_keys($identity)));
        $this->assertSame($before, $vehicle->fresh()->getRawOriginal());
        $this->assertNotSame($path, $event->fresh()->vehicle_image_path);
        $this->assertSame(VehicleCrossing::sole()->snapshot_path, $event->fresh()->vehicle_image_path);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('visitor_records', 0);
    }

    public function test_ordered_clearer_replacement_and_identical_retries_only_change_image_references(): void
    {
        $payload = $this->payload();
        $payload['detection_metadata']['picture']['selected_at'] = now()->toIso8601String();
        $image = UploadedFile::fake()->image('first.jpg', 80, 60);
        $this->postJson(route('api.integration.crossings'), $payload + ['snapshot' => $image])->assertCreated();
        $crossing = VehicleCrossing::sole();
        $first = $crossing->snapshot_path;
        $record = VisitorRecord::sole();
        $this->travel(2)->seconds();
        $better = $payload;
        $better['detection_metadata']['picture'] = ['score' => 150, 'selected_at' => now()->toIso8601String()];
        $image = UploadedFile::fake()->image('better.jpg', 160, 120);
        $this->postJson(route('api.integration.crossings'), $better + ['snapshot' => $image])->assertOk();
        $second = $crossing->fresh()->snapshot_path;
        $this->assertNotSame($first, $second);
        $this->assertSame($second, $record->fresh()->snapshot_path);
        $this->postJson(route('api.integration.crossings'), $better + ['snapshot' => $image])->assertOk();
        $this->assertSame($second, $crossing->fresh()->snapshot_path);
        $this->assertCount(2, Storage::disk('occurrences')->allFiles()); // old evidence retained, no retry copy
        $before = $crossing->fresh()->getRawOriginal();
        $this->postJson(route('api.integration.crossings'), $payload + ['snapshot' => UploadedFile::fake()->image('stale.jpg', 200, 100)])->assertStatus(409);
        $this->assertSame($before, $crossing->fresh()->getRawOriginal());
        $this->assertCount(2, Storage::disk('occurrences')->allFiles());
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['in']);
        $this->assertDatabaseCount('vehicle_crossings', 1);
    }

    public function test_absent_or_invalid_upload_preserves_existing_image_and_rejects_paths(): void
    {
        $this->postJson(route('api.integration.crossings'), $this->payload() + ['snapshot' => UploadedFile::fake()->image('first.jpg')])->assertCreated();
        $crossing = VehicleCrossing::sole();
        $before = $crossing->getRawOriginal();
        $this->postJson(route('api.integration.crossings'), $this->payload())->assertOk();
        $this->assertSame($crossing->snapshot_path, $crossing->fresh()->snapshot_path);
        foreach ([UploadedFile::fake()->create('not-image.jpg', 2, 'text/plain'), UploadedFile::fake()->image('large.jpg')->size(10241)] as $image) {
            $this->postJson(route('api.integration.crossings'), $this->payload() + ['snapshot' => $image])->assertUnprocessable();
        }
        foreach (['snapshot_path' => '../../.env', 'vehicle_image_path' => '/etc/passwd'] as $field => $value) {
            $this->postJson(route('api.integration.crossings'), $this->payload() + [$field => $value])->assertUnprocessable();
        }
        $this->assertSame($before, $crossing->fresh()->getRawOriginal());
        $this->assertCount(1, Storage::disk('occurrences')->allFiles());
    }

    public function test_private_media_requires_an_active_session_and_a_committed_reference(): void
    {
        $this->postJson(route('api.integration.crossings'), $this->payload() + ['snapshot' => UploadedFile::fake()->image('frame.jpg')])->assertCreated();
        $crossing = VehicleCrossing::sole();
        $url = $crossing->snapshot_url;
        $this->assertStringStartsWith('/evidence/occurrences/', $url);
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::where('role', 'admin')->firstOrFail())->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $guard = User::factory()->create(['role' => 'guard']);
        $this->actingAs($guard)->get($url)->assertOk();
        $guard->forceFill(['account_status' => 'inactive'])->save();
        $this->getJson($url)->assertUnauthorized();
        $this->getJson('/storage/'.$crossing->snapshot_path)->assertUnauthorized(); // No generic local-disk route bypass.
        $this->assertNull(OccurrenceMedia::url('../../.env'));
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        $this->get('/storage/'.$crossing->snapshot_path)->assertNotFound(); // Private UUID media has its own reference route.
        $this->get('/evidence/occurrences/..%2F..%2F.env')->assertNotFound();
        $prepared = app(CrossingSnapshotService::class)->prepare(UploadedFile::fake()->image('uncommitted.jpg'));
        $this->get(OccurrenceMedia::url($prepared['path']))->assertNotFound();
        app(CrossingSnapshotService::class)->discard($prepared);
    }

    public function test_viewing_media_does_not_finalize_or_replay_movements(): void
    {
        $this->postJson(route('api.integration.crossings'), $this->payload() + ['snapshot' => UploadedFile::fake()->image('frame.jpg')])->assertCreated();
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        $fusion = \Mockery::mock(\App\Services\RfidCameraFusionService::class);
        $fusion->shouldNotReceive('finalizeExpired');
        $this->app->instance(\App\Services\RfidCameraFusionService::class, $fusion);
        $this->get(VehicleCrossing::sole()->snapshot_url)->assertOk();
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
    }

    public function test_plate_corrections_late_rfid_and_crops_keep_the_same_occurrence_image(): void
    {
        $vehicle = app(VehicleRegistryService::class)->register(['plate_number' => '395ISX', 'vehicle_type' => 'Car',
            'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Snapshot Registrar', 'auto_register_tag' => true, 'rfid_uid' => 'LATE-SNAPSHOT-UID', 'display_number' => '1']);
        $this->travel(1)->minutes();
        $payload = $this->payload();
        $this->postJson(route('api.integration.crossings'), $payload + ['snapshot' => UploadedFile::fake()->image('frame.jpg')])->assertCreated();
        $record = VisitorRecord::sole();
        $path = $record->snapshot_path;
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => '395ISX'])->assertOk();
        $event = VehicleEvent::sole();
        $eventId = $event->id;
        $this->assertSame($path, $event->vehicle_image_path);
        // A genuinely late callback still supplies occurrence time, not receipt time.
        $this->travel(1)->hours();
        $ocr = ['external_event_key' => $record->external_event_key, 'camera_role' => 'gate-1',
            'event_time' => $record->seen_at->toIso8601String(), 'plate_status' => 'read', 'plate_number' => '395ISX', 'plate_confidence' => .99];
        $this->postJson(route('api.integration.visitor-plates'), $ocr + ['plate_image' => UploadedFile::fake()->image('crop.png', 160, 50)])->assertOk();
        $this->assertNotNull($event->fresh()->plate_image_path);
        $this->assertSame($path, $event->fresh()->vehicle_image_path);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertSame($eventId, VehicleEvent::sole()->id);
        $before = $record->fresh()->getRawOriginal();
        $wrong = $ocr;
        $wrong['camera_role'] = 'gate-2';
        $this->postJson(route('api.integration.visitor-plates'), $wrong + ['plate_image' => UploadedFile::fake()->image('wrong.jpg')])->assertStatus(409);
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        $this->assertCount(2, Storage::disk('occurrences')->allFiles());
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname(\App\Support\CameraFiles::statusPath()));
        \Illuminate\Support\Facades\File::put(\App\Support\CameraFiles::statusPath(), json_encode([
            'service_running' => true, 'updated_at' => now()->toIso8601String(),
            'cameras' => ['gate-1' => ['camera_running' => true, 'calibration_ready' => true]],
        ]));
        $late = app(\App\Services\RfidIngestService::class)->ingest(['tag_uid' => 'LATE-SNAPSHOT-UID',
            'scan_location' => 'gate-1', 'scan_time' => $record->seen_at->copy()->addSecond()->toIso8601String()]);
        $this->assertSame($eventId, $late->scanLog->correlated_vehicle_event_id);
        $this->assertSame($path, $event->fresh()->vehicle_image_path);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('rfid_scan_logs', 1);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        \Illuminate\Support\Facades\File::delete(\App\Support\CameraFiles::statusPath());
    }

    public function test_write_failure_preserves_the_registered_movement_without_an_image(): void
    {
        $vehicle = app(VehicleRegistryService::class)->register(['plate_number' => '395ISX', 'vehicle_type' => 'Car',
            'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Snapshot Registrar', 'auto_register_tag' => true, 'rfid_uid' => 'FAIL-SNAPSHOT-UID', 'display_number' => '1']);
        app(RfidTagMatcher::class)->addRead('gate-1', 'FAIL-SNAPSHOT-UID', now());
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('path')->once()->andThrow(new \RuntimeException('Fixture storage unavailable.'));
        Storage::set('occurrences', $disk);
        $this->postJson(route('api.integration.crossings'), $this->payload() + ['snapshot' => UploadedFile::fake()->image('frame.jpg')])
            ->assertCreated()->assertJsonPath('crossing.snapshot_status', 'failed')->assertJsonPath('image_update', 'failed');
        $this->assertNull(VehicleEvent::sole()->vehicle_image_path);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('rfid_scan_logs', 1);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        $this->getJson(route('logs.index'))->assertJsonPath('logs.0.image_status', 'Unavailable (image upload failed)')
            ->assertJsonPath('logs.0.status_label', 'Inside')->assertJsonPath('logs.0.is_alert', false);
        $this->get(route('vehicle-events.show', VehicleEvent::sole()))->assertSee('Unavailable (image upload failed)');
    }

    public function test_database_failure_discards_only_its_own_staged_file(): void
    {
        $this->postJson(route('api.integration.crossings'), $this->payload() + ['snapshot' => UploadedFile::fake()->image('kept.jpg')])->assertCreated();
        $kept = VehicleCrossing::sole()->snapshot_path;
        \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER snapshot_insert_failure BEFORE INSERT ON vehicle_crossings BEGIN SELECT RAISE(ABORT, 'fixture database failure'); END");
        $this->postJson(route('api.integration.crossings'), $this->payload('failed-pass') + ['snapshot' => UploadedFile::fake()->image('failed.jpg')])
            ->assertStatus(500)->assertJsonPath('message', 'Crossing could not be saved. Retry this occurrence.');
        $this->assertCount(1, Storage::disk('occurrences')->allFiles());
        $this->assertTrue(OccurrenceMedia::exists($kept));
        $this->assertDatabaseCount('vehicle_crossings', 1);
    }

    public function test_filename_collision_cannot_overwrite_or_delete_another_occurrence_image(): void
    {
        \Illuminate\Support\Str::createUuidsUsing(fn () => '22222222-2222-4222-8222-222222222222');
        try {
            $this->postJson(route('api.integration.crossings'), $this->payload() + ['snapshot' => UploadedFile::fake()->image('kept.jpg')])->assertCreated();
            $file = OccurrenceMedia::privateFile(VehicleCrossing::sole()->snapshot_path);
            $bytes = Storage::disk('occurrences')->get($file);
            $this->postJson(route('api.integration.crossings'), $this->payload('other-pass') + ['snapshot' => UploadedFile::fake()->image('other.jpg', 120, 80)])
                ->assertCreated()->assertJsonPath('image_update', 'failed');
            $this->assertSame($bytes, Storage::disk('occurrences')->get($file));
            $this->assertCount(1, Storage::disk('occurrences')->allFiles());
            $this->assertDatabaseCount('vehicle_crossings', 2);
            $this->assertNull(VehicleCrossing::where('external_event_key', 'other-pass')->sole()->snapshot_path);
        } finally {
            \Illuminate\Support\Str::createUuidsNormally();
        }
    }

    public function test_each_gate_and_direction_keeps_its_own_image_and_unknown_has_no_crop_requirement(): void
    {
        foreach (['gate-1', 'gate-2'] as $gate) {
            foreach (['IN', 'OUT', 'UNKNOWN'] as $direction) {
                $payload = $this->payload($gate.'-'.$direction, $gate);
                $payload['direction'] = $direction;
                $this->postJson(route('api.integration.crossings'), $payload + ['snapshot' => UploadedFile::fake()->image('same-name.jpg')])->assertCreated();
            }
        }
        $this->assertSame(6, VehicleCrossing::pluck('snapshot_path')->unique()->count());
        $this->assertSame(6, VisitorRecord::count());
        $this->assertSame(0, VisitorRecord::whereNotNull('plate_image_path')->count());
        foreach (VehicleCrossing::all() as $crossing) {
            $record = VisitorRecord::where('vehicle_crossing_id', $crossing->id)->sole();
            $this->assertSame($crossing->snapshot_path, $record->snapshot_path);
            $this->assertNotNull($record->snapshot_url);
        }
    }

    public function test_camera_offline_and_manual_history_do_not_capture_todays_scene(): void
    {
        $vehicle = app(VehicleRegistryService::class)->register(['plate_number' => '395ISX', 'vehicle_type' => 'Car',
            'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Snapshot Registrar', 'auto_register_tag' => true, 'rfid_uid' => 'OFFLINE-SNAPSHOT-UID', 'display_number' => '1']);
        $result = app(\App\Services\RfidIngestService::class)->ingest(['tag_uid' => 'OFFLINE-SNAPSHOT-UID', 'scan_location' => 'gate-1']);
        $this->assertTrue($result->isSaved());
        $this->assertNull(VehicleEvent::sole()->vehicle_image_path);
        $this->assertDatabaseCount('vehicle_crossings', 0);
        $frame = \App\Support\CameraFiles::framePath('gate-1', 'latest');
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($frame));
        $today = UploadedFile::fake()->image('today.jpg');
        \Illuminate\Support\Facades\File::put($frame, file_get_contents($today->getRealPath()));
        $record = app(VisitorRecordService::class)->createManual(['gate' => 'gate-1', 'direction' => 'IN',
            'seen_at' => now()->subDay()->toIso8601String(), 'plate_number' => 'OLD-1001'], User::where('role', 'admin')->firstOrFail());
        $this->assertNull($record->snapshot_path);
        $this->assertCount(0, Storage::disk('occurrences')->allFiles());
        \Illuminate\Support\Facades\File::delete($frame);
    }
}
