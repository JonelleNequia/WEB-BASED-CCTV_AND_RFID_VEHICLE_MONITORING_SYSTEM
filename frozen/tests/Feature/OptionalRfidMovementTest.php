<?php

namespace Tests\Feature;

use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\DetectorRfidMatchService;
use App\Services\MovementCountService;
use App\Services\RfidIngestService;
use App\Services\RfidTagMatcher;
use App\Services\VehicleOccupancyService;
use App\Services\VehicleRegistryService;
use App\Services\VisitorRecordService;
use App\Support\CameraFiles;
use App\Support\RfidIngestResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class OptionalRfidMovementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $sequence = 0;

    private ?string $priorCameraStatus = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->travelTo(now()->startOfDay()->addHours(12));
        Storage::fake('public');
        $this->priorCameraStatus = File::exists(CameraFiles::statusPath()) ? File::get(CameraFiles::statusPath()) : null;
        $this->admin = User::query()->where('role', 'admin')->firstOrFail();
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        if ($this->priorCameraStatus === null) {
            File::delete(CameraFiles::statusPath());
        } else {
            File::put(CameraFiles::statusPath(), $this->priorCameraStatus);
        }
        parent::tearDown();
    }

    private function registry(): VehicleRegistryService
    {
        return app(VehicleRegistryService::class);
    }

    private function vehicle(string $plate = 'TAG-0001', bool $tagged = false): Vehicle
    {
        return $this->registry()->register(['plate_number' => $plate, 'vehicle_type' => 'Car',
            'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Original Registrar', 'auto_register_tag' => true,
            ...($tagged ? ['rfid_uid' => 'ASSIGNED-'.(++$this->sequence), 'display_number' => (string) $this->sequence] : [])])->fresh();
    }

    private function pass(?string $plate, string $direction = 'IN', ?\Carbon\Carbon $at = null, float $confidence = 0.95): VisitorRecord
    {
        $at ??= now()->addMinutes(2 * ++$this->sequence);
        $crossing = VehicleCrossing::query()->create([
            'camera_id' => \App\Models\Camera::forRole('gate-1')->value('id'),
            'external_event_key' => 'optional-pass-'.(++$this->sequence), 'gate' => 'gate-1', 'direction' => $direction,
            'crossed_at' => $at, 'detection_metadata_json' => ['rfid_status' => 'no_pass'],
        ]);
        app(VisitorRecordService::class)->createFromCrossing($crossing);

        $record = app(VisitorRecordService::class)->applyPlateReading([
            'external_event_key' => $crossing->external_event_key, 'camera_role' => 'gate-1', 'event_time' => $at->toIso8601String(),
            'plate_status' => $plate ? 'read' : 'unreadable', 'plate_number' => $plate, 'plate_confidence' => $confidence,
        ]);
        // This fixture represents an actual plate explicitly confirmed by staff.
        return $plate !== null && $confidence >= .6 ? \Tests\fixtures\ConfirmedActualPlate::for($record, $plate) : $record;
    }

    private function online(): void
    {
        File::ensureDirectoryExists(dirname(CameraFiles::statusPath()));
        File::put(CameraFiles::statusPath(), json_encode(['service_running' => true, 'updated_at' => now()->toIso8601String(),
            'cameras' => ['gate-1' => ['camera_role' => 'gate-1', 'camera_running' => true, 'calibration_ready' => true]]]));
    }

    public function test_untagged_registration_has_no_dummy_tag_and_retains_staff_default_rules(): void
    {
        $data = ['plate_number' => '  ab  00001 ', 'vehicle_type' => 'Motorcycle', 'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Ma. José Dela Cruz',
            'rfid_uid' => '  ', 'auto_register_tag' => true];
        $id = $this->postJson(route('vehicle-registry.store'), $data)->assertCreated()->json('vehicle_id');
        $vehicle = Vehicle::findOrFail($id);
        $this->assertNull($vehicle->rfid_tag_id);
        $this->assertNull($vehicle->rfid_tag_uid);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertSame('AB 00001', $vehicle->plate_number);
        $this->assertSame('Ma. José Dela Cruz', $this->registry()->rememberedStaffName());
        $this->postJson(route('vehicle-registry.store'), array_replace($data, ['plate_number' => 'AB00001', 'registered_by' => 'Different Registrar']))
            ->assertUnprocessable()->assertJsonValidationErrors('plate_number');
        $this->postJson(route('vehicle-registry.store'), ['plate_number' => 'OTHER-0002', 'vehicle_type' => 'Car'])
            ->assertUnprocessable()->assertJsonValidationErrors('registered_by');
        $this->assertSame('Ma. José Dela Cruz', $this->registry()->rememberedStaffName());
        $this->get(route('registry.index'))->assertOk()->assertSee('No RFID assigned')->assertDontSee('RFID (optional)')->assertSee('Tag number');
        $this->getJson(route('registry.vehicles.show', $vehicle))->assertOk()->assertJsonPath('tag', null);
    }

    public function test_supplied_tags_remain_strict_and_invalid_plates_cannot_register_without_a_tag(): void
    {
        foreach (['---', 'ABC/0001'] as $plate) {
            $this->postJson(route('vehicle-registry.store'), ['vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Staff', 'plate_number' => $plate, 'vehicle_type' => 'Car'])
                ->assertUnprocessable()->assertJsonValidationErrors('plate_number');
        }
        $this->postJson(route('vehicle-registry.store'), ['vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Staff', 'plate_number' => 'GOOD-0001',
            'vehicle_type' => 'Car', 'rfid_tag_id' => 99999])->assertUnprocessable()->assertJsonValidationErrors('rfid_tag_id');
        $tag = RfidTag::query()->create(['uid' => 'AVAILABLE', 'tag_number' => 1, 'status' => 'available']);
        $id = $this->postJson(route('vehicle-registry.store'), ['vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Staff', 'plate_number' => 'GOOD-0001',
            'vehicle_type' => 'Car', 'rfid_tag_id' => $tag->id, 'display_number' => $tag->number_label])->assertCreated()->json('vehicle_id');
        $this->assertSame($id, $tag->fresh()->vehicle_id);
        $this->assertDatabaseCount('vehicle_rfid_tags', 1);
    }

    public function test_ocr_plate_in_out_and_unknown_update_one_occurrence_without_fake_rfid(): void
    {
        $vehicle = $this->vehicle();
        $in = $this->pass($vehicle->plate_number);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $event = $in->movementEvent;
        $this->assertSame('plate_review', $event->event_origin);
        $this->assertSame($in->id, $event->visitor_record_id);
        $this->assertNull($event->rfid_scan_log_id);
        $this->pass($vehicle->plate_number, 'UNKNOWN');
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
        $this->assertSame([1, 0], [$vehicle->fresh()->entries_today_count, $vehicle->fresh()->exits_today_count]);
        $out = $this->pass($vehicle->plate_number, 'OUT');
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
        $this->assertSame([1, 1], [$vehicle->fresh()->entries_today_count, $vehicle->fresh()->exits_today_count]);
        $this->assertSame([1, 1, 1], array_values(array_intersect_key(app(MovementCountService::class)->counts('today'), array_flip(['in', 'out', 'unknown']))));
        $this->assertDatabaseCount('rfid_scan_logs', 0);
        $this->assertDatabaseCount('vehicle_rfid_tags', 0);
        $this->assertDatabaseCount('vehicle_events', 3);
        $this->getJson(route('logs.index'))->assertOk()->assertJsonPath('total', 3);
        $this->assertSame(0, app(VehicleOccupancyService::class)->counts()['total']);
    }

    public function test_manual_confirmation_retries_late_ocr_and_older_corrections_reconcile_chronologically(): void
    {
        $vehicle = $this->vehicle();
        $older = $this->pass(null, 'IN', now()->addMinutes(1));
        $newer = $this->pass($vehicle->plate_number, 'OUT', now()->addMinutes(4));
        $newest = $this->pass($vehicle->plate_number, 'IN', now()->addMinutes(8));
        Storage::disk('public')->put('kept/vehicle.jpg', 'snapshot');
        $older->forceFill(['snapshot_path' => 'kept/vehicle.jpg', 'plate_image_path' => 'kept/crop.jpg'])->save();
        foreach (range(1, 3) as $retry) {
            $this->patchJson(route('visitors.records.review', $older), ['action' => 'correct_plate', 'plate' => 'tag0001'])->assertOk();
        }
        $event = $older->fresh()->movementEvent;
        $this->assertSame('plate_review', $event->event_origin);
        $this->assertSame('kept/vehicle.jpg', $event->vehicle_image_path);
        $this->assertSame('kept/crop.jpg', $event->plate_image_path);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
        $this->assertSame($newest->seen_at->timestamp, $vehicle->fresh()->last_seen_at->timestamp);
        $this->assertSame([2, 1], [$vehicle->fresh()->entries_today_count, $vehicle->fresh()->exits_today_count]);
        $this->assertDatabaseCount('review_actions', 3);
        $this->assertDatabaseCount('vehicle_events', 3);
        app(VisitorRecordService::class)->applyPlateReading([
            'external_event_key' => $older->external_event_key, 'camera_role' => 'gate-1', 'event_time' => $older->seen_at->toIso8601String(),
            'plate_status' => 'read', 'plate_number' => 'WRONG-1234', 'plate_confidence' => 0.99,
        ]);
        $this->assertSame($event->id, $older->fresh()->movementEvent->id);
        $this->assertSame([2, 1], [$vehicle->fresh()->entries_today_count, $vehicle->fresh()->exits_today_count]);
        // Correcting an older identified pass to Guest retracts only that effect.
        $this->patchJson(route('visitors.records.review', $older), ['action' => 'correct_plate', 'plate' => 'GUEST-0099'])->assertOk();
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
        $this->assertSame([1, 1], [$vehicle->fresh()->entries_today_count, $vehicle->fresh()->exits_today_count]);
        $this->assertSame(3, app(MovementCountService::class)->counts('today')['in'] + app(MovementCountService::class)->counts('today')['out']);
    }

    public function test_late_rfid_reuses_the_plate_event_and_does_not_count_or_toggle_twice(): void
    {
        $vehicle = $this->vehicle(tagged: true);
        $this->online();
        $record = $this->pass($vehicle->plate_number, at: now());
        $eventId = $record->movementEvent->id;
        $result = app(RfidIngestService::class)->ingest(['tag_uid' => $vehicle->rfid_tag_uid, 'scan_location' => 'gate-1', 'scan_time' => now()->addSecond()->toIso8601String()]);
        $this->assertSame(RfidIngestResult::RECORDED, $result->outcome);
        $this->assertSame($eventId, $result->scanLog->correlated_vehicle_event_id);
        $this->assertSame($record->id, VehicleEvent::findOrFail($eventId)->visitor_record_id);
        $this->assertSame([1, 0], [$vehicle->fresh()->entries_today_count, $vehicle->fresh()->exits_today_count]);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('rfid_scan_logs', 1);
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['in']);
        $this->getJson(route('logs.index'))->assertJsonPath('total', 1);
    }

    public function test_later_assignment_and_replacement_preserve_registration_state_and_default(): void
    {
        $vehicle = $this->vehicle();
        $record = $this->pass($vehicle->plate_number);
        $snapshot = $vehicle->fresh()->only(['id', 'plate_number', 'registered_by', 'current_state', 'entries_today_count']);
        $first = RfidTag::query()->create(['uid' => 'FIRST-TAG']);
        $assigned = $this->registry()->replaceTag($vehicle->fresh(), ['rfid_tag_id' => $first->id, 'old_tag_status' => 'lost']);
        $second = RfidTag::query()->create(['uid' => 'SECOND-TAG']);
        $replaced = $this->registry()->replaceTag($assigned, ['rfid_tag_id' => $second->id, 'old_tag_status' => 'lost']);
        $this->assertSame($snapshot, $replaced->only(array_keys($snapshot)));
        $this->assertSame('lost', $first->fresh()->status);
        $this->assertSame($vehicle->id, $first->fresh()->vehicle_id);
        $this->assertSame($second->id, $replaced->rfid_tag_id);
        $this->assertSame('Original Registrar', $this->registry()->rememberedStaffName());
        $this->assertSame($record->movementEvent->id, $record->fresh()->movementEvent->id);
        $this->assertDatabaseCount('vehicles', 1);
    }

    public function test_lost_uid_cannot_be_reactivated_reassigned_recreated_or_deleted(): void
    {
        $vehicle = $this->vehicle(tagged: true);
        $tag = $vehicle->rfidTag;
        $this->registry()->setTagStatus($tag, 'lost');
        $this->assertDatabaseHas('unavailable_rfid_uids', ['uid' => $tag->uid, 'marked_by' => $this->admin->id]);
        $this->assertSame('active', $vehicle->fresh()->status);
        $this->postJson(route('registry.tags.status', $tag), ['status' => 'available'])->assertUnprocessable();
        $this->postJson(route('registry.tags.status', $tag), ['status' => 'disabled'])->assertUnprocessable();
        $this->assertFalse($this->registry()->lookupTagForVehicle(strtolower('  '.implode(' ', str_split($tag->uid)).'  '))['ok']);
        $this->assertFalse($this->registry()->availableTags()->contains('id', $tag->id));
        foreach (['recreate', 'assign', 'delete', 'change_uid'] as $operation) {
            try {
                match ($operation) {
                    'recreate' => $this->registry()->registerRfidTag(['uid' => strtolower($tag->uid), 'auto_number' => true]),
                    'assign' => $this->registry()->replaceTag($this->vehicle('OTHER-0002'), ['rfid_tag_id' => $tag->id]),
                    'delete' => $tag->fresh()->delete(),
                    'change_uid' => $tag->fresh()->forceFill(['uid' => 'CHANGED-LOST'])->save(),
                };
                $this->fail('Lost UID mutation must be rejected: '.$operation);
            } catch (ValidationException) {
                $this->assertSame('lost', $tag->fresh()->status);
            }
        }
    }

    public function test_lost_reads_and_already_buffered_lost_tags_are_ignored_and_camera_processing_continues(): void
    {
        $vehicle = $this->vehicle(tagged: true);
        $this->online();
        app(RfidTagMatcher::class)->addRead('gate-1', $vehicle->rfid_tag_uid, now());
        $presence = app(RfidTagMatcher::class)->presences('gate-1')[0];
        $this->registry()->setTagStatus($vehicle->rfidTag, 'lost');
        $this->assertFalse(app(RfidTagMatcher::class)->claim('gate-1', $presence, 'stale', now()));
        $this->assertNull(app(DetectorRfidMatchService::class)->find('gate-1', now(), eventKey: 'stale'));
        $result = app(RfidIngestService::class)->ingest(['tag_uid' => strtolower($vehicle->rfid_tag_uid), 'scan_location' => 'gate-1']);
        $this->assertSame('ignored', $result->outcome);
        $this->assertFalse($result->isSaved());
        $this->assertFalse($result->toArray()['anomaly']);
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.rfid-scans'), ['tag_uid' => $vehicle->rfid_tag_uid, 'scan_location' => 'gate-1'])
            ->assertOk()->assertJsonPath('outcome', 'ignored')->assertJsonPath('saved', false)->assertJsonPath('anomaly', false);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertDatabaseCount('event_receive_logs', 0);
        $record = $this->pass($vehicle->plate_number, at: now());
        $this->assertSame('registered', $record->registration_status);
        $this->assertSame(Vehicle::STATE_INSIDE, $vehicle->fresh()->current_state);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
    }

    public function test_legacy_conflict_report_does_not_repair_assignments_or_enable_a_lost_uid(): void
    {
        $vehicle = $this->vehicle(tagged: true);
        $this->registry()->setTagStatus($vehicle->rfidTag, 'lost');
        DB::table('vehicle_rfid_tags')->where('id', $vehicle->rfid_tag_id)->update(['status' => 'assigned']);
        $this->artisan('rfid:lost-conflicts')->expectsOutputToContain('conflict(s). Read-only')->assertSuccessful();
        $this->assertSame('assigned', $vehicle->rfidTag()->first()->status);
        $this->assertFalse($this->registry()->lookupTagForVehicle($vehicle->rfid_tag_uid)['ok']);
        $this->assertSame('ignored', app(RfidIngestService::class)->ingest(['tag_uid' => $vehicle->rfid_tag_uid, 'scan_location' => 'gate-1'])->outcome);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
    }

    public function test_clearer_retry_snapshot_is_shared_by_evidence_and_the_existing_plate_movement(): void
    {
        $vehicle = $this->vehicle();
        $record = $this->pass($vehicle->plate_number);
        $event = $record->movementEvent;
        $this->withHeader('X-Api-Key', 'test-detector-key')->post(route('api.integration.crossings'), [
            'external_event_key' => $record->external_event_key, 'camera_role' => 'gate-1', 'direction' => 'IN',
            'event_time' => $record->seen_at->toIso8601String(),
            'snapshot' => \Illuminate\Http\UploadedFile::fake()->image('clearer.jpg'),
        ])->assertOk()->assertJsonPath('duplicate', true);
        $snapshot = $record->crossing->fresh()->snapshot_path;
        $this->assertTrue(\App\Support\OccurrenceMedia::exists($snapshot));
        $this->assertSame($snapshot, $record->fresh()->snapshot_path);
        $this->assertSame($snapshot, $event->fresh()->vehicle_image_path);
        $this->assertSame('IN', $record->crossing->fresh()->direction);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('vehicle_events', 1);
    }

    public function test_already_saved_legacy_pending_read_cannot_move_after_loss_or_block_the_finalizer_forever(): void
    {
        $vehicle = $this->vehicle(tagged: true);
        $scan = RfidScanLog::query()->create([
            'vehicle_id' => $vehicle->id, 'vehicle_rfid_tag_id' => $vehicle->rfid_tag_id, 'tag_uid' => $vehicle->rfid_tag_uid,
            'scan_location' => 'gate-1', 'scan_time' => now()->subMinutes(2), 'verification_status' => 'verified',
            'fusion_status' => RfidScanLog::FUSION_PENDING,
        ]);
        $this->registry()->setTagStatus($vehicle->rfidTag, 'lost');
        File::delete(CameraFiles::statusPath());
        $fusion = app(\App\Services\RfidCameraFusionService::class);
        $this->assertSame(1, $fusion->finalizeExpired());
        $this->assertSame('ignored', $scan->fresh()->outcome);
        $this->assertSame(RfidScanLog::FUSION_SCAN_ONLY, $scan->fresh()->fusion_status);
        $this->assertSame(0, $fusion->finalizeExpired());
        $this->assertSame('OUTSIDE', $vehicle->fresh()->current_state);
        $this->assertDatabaseCount('rfid_scan_logs', 1);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertDatabaseCount('vehicle_occupancy_baselines', 0);
    }

    public function test_unknown_plate_direction_changes_occupancy_only_after_staff_sets_a_real_direction(): void
    {
        $vehicle = $this->vehicle();
        $record = $this->pass($vehicle->plate_number, 'UNKNOWN');
        $eventId = $record->movementEvent->id;
        $this->assertSame('OUTSIDE', $vehicle->fresh()->current_state);
        $this->assertSame(0, app(\App\Services\VisitorReviewService::class)->count());
        $this->get(route('visitors.index', ['tab' => 'review']))->assertOk()->assertViewHas('reviewCount', 1);
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'set_direction', 'direction' => 'IN'])->assertOk();
        $this->assertSame($eventId, $record->fresh()->movementEvent->id);
        $this->assertSame('INSIDE', $vehicle->fresh()->current_state);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('direction_corrections', 1);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
    }

    public function test_preexisting_newer_state_is_a_baseline_not_replayed_by_an_older_correction(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->forceFill(['current_state' => 'OUTSIDE', 'last_seen_at' => now()->addMinutes(10),
            'daily_count_date' => now()->toDateString(), 'entries_today_count' => 1, 'exits_today_count' => 1])->save();
        $legacy = VehicleEvent::query()->create(['vehicle_id' => $vehicle->id, 'event_type' => 'EXIT',
            'event_status' => 'completed', 'event_origin' => 'rfid_hardware', 'event_time' => now()->addMinutes(10)]);
        $before = $legacy->fresh()->getRawOriginal();
        $record = $this->pass(null, at: now()->addMinutes(2));
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => $vehicle->plate_number])->assertOk();
        $this->assertSame('OUTSIDE', $vehicle->fresh()->current_state);
        $this->assertSame(now()->addMinutes(10)->timestamp, $vehicle->fresh()->last_seen_at->timestamp);
        $this->assertSame([2, 1], [$vehicle->fresh()->entries_today_count, $vehicle->fresh()->exits_today_count]);
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
        $this->assertDatabaseCount('vehicle_occupancy_baselines', 1);
    }

    public function test_registered_unknown_direction_card_updates_the_same_crossing_and_keeps_plate_provenance(): void
    {
        $vehicle = $this->vehicle();
        $record = $this->pass($vehicle->plate_number, 'UNKNOWN');
        $event = $record->movementEvent;
        $this->patch(route('vehicle-events.direction', $event), ['direction' => 'IN'])->assertRedirect();
        $this->assertSame('IN', $record->fresh()->direction);
        $this->assertSame('IN', $record->crossing->fresh()->direction);
        $this->assertSame('INSIDE', $vehicle->fresh()->current_state);
        $this->assertSame('plate_review', $event->fresh()->event_origin);
        $this->assertSame('manual', $event->fresh()->direction_source);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
        $this->assertDatabaseCount('active_sessions', 0);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->get(route('visitors.index', ['tab' => 'review']))->assertOk()->assertViewHas('reviewCount', 0);
    }

    public function test_additive_upgrade_denies_legacy_lost_uids_without_rewriting_existing_rows_or_replaying_history(): void
    {
        $vehicle = $this->vehicle(tagged: true);
        $vehicle->forceFill(['current_state' => 'INSIDE', 'entries_today_count' => 4, 'last_seen_at' => now()])->save();
        $event = VehicleEvent::query()->create(['vehicle_id' => $vehicle->id, 'event_type' => 'ENTRY',
            'event_status' => 'completed', 'event_origin' => 'rfid_hardware', 'event_time' => now()]);
        $migration = require database_path('migrations/2026_11_03_000002_plate_movements_and_unavailable_tags.php');
        $migration->down(); // Isolated RefreshDatabase fixture only: simulate the old schema.
        DB::table('vehicle_rfid_tags')->where('id', $vehicle->rfid_tag_id)->update([
            'status' => 'lost', 'uid' => ' assigned-1 ', 'tag_uid' => 'ASSIGNED-1',
        ]);
        $beforeVehicle = (array) DB::table('vehicles')->find($vehicle->id);
        $beforeTag = (array) DB::table('vehicle_rfid_tags')->find($vehicle->rfid_tag_id);
        $beforeEvent = (array) DB::table('vehicle_events')->find($event->id);
        $migration->up();
        $this->assertSame($beforeVehicle, (array) DB::table('vehicles')->find($vehicle->id));
        $this->assertSame($beforeTag, (array) DB::table('vehicle_rfid_tags')->find($vehicle->rfid_tag_id));
        $this->assertSame($beforeEvent, array_intersect_key((array) DB::table('vehicle_events')->find($event->id), $beforeEvent));
        $this->assertDatabaseCount('vehicle_occupancy_baselines', 0);
        $this->assertDatabaseCount('unavailable_rfid_uids', 1);
        $this->assertDatabaseHas('unavailable_rfid_uids', ['uid' => 'ASSIGNED-1', 'tag_id' => $vehicle->rfid_tag_id, 'marked_by' => null]);
        $this->assertFalse($event->fresh()->occupancy_managed);
        $this->assertTrue(RfidTag::isUnavailableUid('a s s i g n e d-1'));
    }

    public function test_late_rfid_conflict_uses_review_and_staff_resolves_the_same_event(): void
    {
        $first = $this->vehicle('FIRST-0001', true);
        $second = $this->vehicle('SECOND-0002', true);
        $this->online();
        $record = $this->pass($first->plate_number, at: now());
        $eventId = $record->movementEvent->id;
        $result = app(RfidIngestService::class)->ingest(['tag_uid' => $second->rfid_tag_uid, 'scan_location' => 'gate-1', 'scan_time' => now()->addSecond()->toIso8601String()]);
        $this->assertSame('anomaly', $result->outcome);
        $this->assertSame('review', $record->fresh()->registration_status);
        $this->assertSame('OUTSIDE', $first->fresh()->current_state);
        $this->assertSame('OUTSIDE', $second->fresh()->current_state);
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['in']);
        $this->getJson(route('logs.index'))->assertJsonPath('total', 1)->assertJsonPath('logs.0.log_type', 'review');
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => $second->plate_number])->assertOk();
        $this->assertSame('INSIDE', $second->fresh()->current_state);
        $this->assertSame('OUTSIDE', $first->fresh()->current_state);
        $this->assertSame(1, $second->fresh()->entries_today_count);
        $this->assertSame(0, $first->fresh()->entries_today_count);
        $this->assertSame($eventId, $record->fresh()->movementEvent->id);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('rfid_scan_logs', 1);
        $this->getJson(route('logs.index'))->assertJsonPath('total', 1)->assertJsonPath('logs.0.log_type', 'registered');
    }

    public function test_conflicting_late_ocr_does_not_overwrite_staff_evidence_when_the_event_has_verified_rfid(): void
    {
        $vehicle = $this->vehicle(tagged: true);
        $this->online();
        $record = $this->pass($vehicle->plate_number, at: now());
        app(RfidIngestService::class)->ingest(['tag_uid' => $vehicle->rfid_tag_uid, 'scan_location' => 'gate-1', 'scan_time' => now()->addSecond()->toIso8601String()]);
        app(VisitorRecordService::class)->applyPlateReading([
            'external_event_key' => $record->external_event_key, 'camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(),
            'plate_status' => 'read', 'plate_number' => 'OTHER-0002', 'plate_confidence' => 0.95,
        ]);
        $this->assertSame('registered', $record->fresh()->registration_status);
        $this->assertSame(\App\Support\PlateNumber::display($vehicle->plate_number), $record->fresh()->plate_number);
        $this->assertSame(0, app(\App\Services\VisitorReviewService::class)->count());
        $this->assertSame($vehicle->id, $record->movementEvent->vehicle_id);
        $this->assertSame('INSIDE', $vehicle->fresh()->current_state);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->getJson(route('logs.index'))->assertJsonPath('total', 1);
    }

    public function test_projection_failure_rolls_back_classification_audit_profile_and_occupancy_together(): void
    {
        $vehicle = $this->vehicle();
        $record = $this->pass(null);
        $before = $record->fresh()->getRawOriginal();
        DB::statement("CREATE TRIGGER fail_projection BEFORE INSERT ON vehicle_events BEGIN SELECT RAISE(ABORT, 'Isolated fixture projection failure'); END");
        try {
            app(VisitorRecordService::class)->correctPlate($record, $vehicle->plate_number, $this->admin);
            $this->fail('The projection must fail in this isolated fixture.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame($before, $record->fresh()->getRawOriginal());
            $this->assertDatabaseCount('review_actions', 0);
            $this->assertDatabaseCount('vehicle_events', 0);
            $this->assertDatabaseCount('vehicle_occupancy_baselines', 0);
            $this->assertSame('OUTSIDE', $vehicle->fresh()->current_state);
        }
    }

    public function test_unauthorized_mutations_stay_blocked_for_optional_registration_and_tag_changes(): void
    {
        $vehicle = $this->vehicle();
        $tag = RfidTag::query()->create(['uid' => 'AUTH-AVAILABLE']);
        $guard = User::factory()->create(['role' => 'guard']);
        $this->actingAs($guard)->postJson(route('vehicle-registry.store'), ['plate_number' => 'AUTH-0002', 'vehicle_type' => 'Car', 'registered_by' => 'Guard'])
            ->assertForbidden();
        $this->postJson(route('registry.vehicles.replace-tag', $vehicle), ['rfid_tag_id' => $tag->id, 'old_tag_status' => 'lost'])->assertForbidden();
        $this->postJson(route('registry.tags.status', $tag), ['status' => 'lost'])->assertForbidden();
        $this->assertSame('available', $tag->fresh()->status);
        $this->assertDatabaseCount('unavailable_rfid_uids', 0);
        $this->assertDatabaseCount('vehicles', 1);
    }
}
