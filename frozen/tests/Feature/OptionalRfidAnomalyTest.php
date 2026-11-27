<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCrossing;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\AlertSummaryService;
use App\Services\MovementCountService;
use App\Services\RfidTagMatcher;
use App\Services\VehicleRegistryService;
use App\Services\VisitorRecordService;
use App\Support\VisitorRecordLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class OptionalRfidAnomalyTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->travelTo(now()->startOfDay()->addHours(12));
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        Storage::fake('public');
        Storage::fake('local');
    }

    private function register(array $extra = []): Vehicle
    {
        return app(VehicleRegistryService::class)->register($extra + [
            'plate_number' => '395ISX', 'vehicle_type' => 'Car',
            'category' => 'faculty_staff', 'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Fixture Registrar',
        ])->fresh();
    }

    private function pass(string $direction = 'IN', ?string $plate = '395ISX'): VisitorRecord
    {
        $this->travel(2)->minutes();
        $crossing = VehicleCrossing::create([
            'external_event_key' => 'optional-anomaly-'.++$this->sequence,
            'camera_id' => Camera::forRole('gate-1')->value('id'), 'gate' => 'gate-1',
            'crossed_at' => now(), 'direction' => $direction, 'vehicle_type' => 'Car',
        ]);
        app(VisitorRecordService::class)->createFromCrossing($crossing);

        $record = app(VisitorRecordService::class)->applyPlateReading([
            'external_event_key' => $crossing->external_event_key, 'camera_role' => 'gate-1',
            'event_time' => now()->toIso8601String(), 'plate_status' => $plate ? 'read' : 'unreadable',
            'plate_number' => $plate, 'plate_confidence' => .99,
        ]);
        // This fixture represents an actual plate explicitly confirmed by staff.
        return $plate !== null ? \Tests\fixtures\ConfirmedActualPlate::for($record, $plate) : $record;
    }

    public function test_new_untagged_registration_does_not_create_a_movement_or_anomaly(): void
    {
        $this->postJson(route('vehicle-registry.store'), [
            'plate_number' => '395ISX', 'vehicle_type' => 'Car', 'category' => 'faculty_staff',
            'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Fixture Registrar',
        ])->assertCreated();
        $vehicle = Vehicle::sole();
        $this->assertNull($vehicle->rfid_tag_id);
        $this->assertSame('Fixture Registrar', $vehicle->registered_by);
        $this->assertSame('Fixture Registrar', app(VehicleRegistryService::class)->rememberedStaffName());
        foreach (['vehicle_events', 'visitor_records', 'rfid_scan_logs', 'vehicle_rfid_tags', 'vehicle_occupancy_baselines'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, app(AlertSummaryService::class)->counts()['total']);
        $this->getJson(route('logs.index'))->assertJsonPath('total', 0);
    }

    public static function identificationPaths(): array
    {
        return [['ocr'], ['staff']];
    }

    #[DataProvider('identificationPaths')]
    public function test_legacy_guest_marker_is_not_a_registered_plate_anomaly(string $path): void
    {
        $vehicle = $this->register();
        $record = $this->pass(plate: null);
        // Use the actual legacy detector endpoint which writes the Guest marker.
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.guest-observation'), [
            'external_event_key' => $record->external_event_key, 'camera_role' => 'gate-1',
            'detected_vehicle_type' => 'Car', 'event_time' => $record->seen_at->toIso8601String(),
            'snapshot' => UploadedFile::fake()->image('guest-fixture.jpg'),
            'detection_metadata' => ['direction' => 'IN'],
        ])->assertCreated();
        $event = VehicleEvent::where('external_event_key', $record->external_event_key)->sole();
        $this->assertSame('Unregistered guest', $event->anomaly_reason);

        foreach (range(1, 3) as $retry) {
            if ($path === 'staff') {
                $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => '395isx'])->assertOk();
            } else {
                app(VisitorRecordService::class)->applyPlateReading([
                    'external_event_key' => $record->external_event_key, 'camera_role' => 'gate-1',
                    'event_time' => $record->seen_at->toIso8601String(), 'plate_status' => 'read',
                    'plate_number' => '395isx', 'plate_confidence' => .99,
                ]);
                \Tests\fixtures\ConfirmedActualPlate::for($record, '395ISX');
            }
        }
        $event->refresh();
        $this->assertNull($event->anomaly_reason);
        $this->assertSame($vehicle->id, $event->vehicle_id);
        $this->assertSame($record->id, $event->visitor_record_id);
        $this->assertSame('395ISX', $event->plate_text);
        $this->assertSame('faculty_staff', $event->vehicle_category);
        $this->assertDatabaseCount('vehicle_events', 1);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
        $this->assertDatabaseCount('review_actions', 1);
        $this->assertSame(1, $vehicle->fresh()->entries_today_count);
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['in']);
        $this->assertSame(0, app(AlertSummaryService::class)->counts()['total']);
        $this->getJson(route('logs.index'))->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.log_type_label', 'Registered')->assertJsonPath('logs.0.category_label', 'Faculty & Staff')
            ->assertJsonPath('logs.0.status_label', 'Inside')->assertJsonPath('logs.0.is_alert', false);
        $this->getJson(route('logs.index', ['log_type' => 'alerts']))->assertJsonPath('total', 0);
        $this->getJson(route('logs.index', ['category' => 'unregistered_visitor']))->assertJsonPath('total', 0);
        $this->get(route('vehicle-events.show', $event))->assertOk()->assertDontSee('Tag not read')->assertDontSee('Unregistered guest');
        $csv = $this->get(route('vehicle-events.export.csv', ['all' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Registered', $csv);
        $this->assertStringNotContainsString('Anomaly', $csv);
        $this->assertSame('Faculty & Staff', $this->get(route('logs.print'))->assertOk()->viewData('rows')->sole()['category']);
    }

    public static function historyPaths(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('historyPaths')]
    public function test_registration_does_not_replay_or_reclassify_earlier_guest_passes(bool $explicitTransfer): void
    {
        $record = $this->pass();
        $before = $record->fresh()->only(['registration_status', 'registration_category', 'seen_at', 'direction']);
        $this->travel(2)->minutes();
        $vehicle = $this->register($explicitTransfer ? ['plate_profile_id' => $record->plate_profile_id] : []);
        $this->assertEquals($before, $record->fresh()->only(array_keys($before)));
        $this->assertSame('guest', $record->fresh()->registration_status);
        $this->assertSame($explicitTransfer ? $vehicle->id : null, $record->fresh()->vehicle_id);
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->current_state);
        $this->assertSame(0, $vehicle->entries_today_count);
        $this->assertDatabaseCount('vehicle_events', 0);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
        $this->assertSame(1, app(MovementCountService::class)->counts('today')['in']);
        $this->getJson(route('logs.index'))->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.log_type_label', 'Unregistered Guest')->assertJsonPath('logs.0.is_alert', false);
        $this->assertSame('Unregistered Guest', $this->csvRows()[1][1]);
        $this->assertSame('Unregistered Guest', $this->get(route('logs.print'))->viewData('rows')->sole()['category']);
        // Repeating the explicit relationship transfer is not movement replay.
        if ($explicitTransfer) {
            foreach (range(1, 2) as $retry) {
                app(VisitorRecordService::class)->transferHistoryToVehicle($vehicle, $record->plate_profile_id);
            }
        }
        $this->pass();
        $this->pass('OUT');
        $this->assertDatabaseCount('vehicle_events', 2);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
        $this->assertSame('guest', $record->fresh()->registration_status);
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
        $this->assertSame([2, 1], array_values(array_intersect_key(app(MovementCountService::class)->counts('today'), array_flip(['in', 'out']))));
        $this->getJson(route('logs.index', ['log_type' => 'registered']))->assertJsonPath('total', 2);
        $this->getJson(route('logs.index', ['log_type' => 'unregistered_visitor']))->assertJsonPath('total', 1);
    }

    public function test_ineligible_registry_category_is_not_made_eligible_by_optional_rfid(): void
    {
        $this->postJson(route('vehicle-registry.store'), [
            'plate_number' => '395ISX', 'vehicle_type' => 'Car', 'category' => 'unregistered_visitor',
            'vehicle_owner_name' => 'Fixture Owner', 'registered_by' => 'Fixture Registrar',
        ])->assertUnprocessable()->assertJsonValidationErrors('category');
        // A pre-existing ineligible row is not an eligible exact plate match.
        Vehicle::create(['plate_number' => '395ISX', 'category' => 'unregistered_visitor', 'vehicle_type' => 'Car']);
        $record = $this->pass();
        $this->assertFalse($record->isRegisteredAtOccurrence());
        $this->assertNull($record->vehicle_id);
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public function test_adoption_does_not_clear_a_real_reason_on_a_legacy_event(): void
    {
        $vehicle = $this->register();
        $record = $this->pass(plate: null);
        $reason = 'Camera saw this vehicle go IN, but it was already inside (an OUT was missed).';
        $event = VehicleEvent::create([
            'external_event_key' => $record->external_event_key, 'camera_id' => $record->camera_id,
            'event_time' => $record->seen_at, 'event_type' => 'ENTRY', 'event_origin' => 'guest_cctv',
            'event_status' => 'completed', 'match_status' => VehicleEvent::MATCH_NO_PASS_ALERT, 'anomaly_reason' => $reason,
        ]);
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'correct_plate', 'plate' => '395ISX'])->assertOk();
        $this->assertSame($reason, $event->fresh()->anomaly_reason);
        $this->assertSame($vehicle->id, $event->fresh()->vehicle_id);
        $this->getJson(route('logs.index', ['log_type' => 'alerts']))->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.alert_reason', $reason);
    }

    public function test_legacy_plate_only_presentation_does_not_warn_about_an_unassigned_tag(): void
    {
        $vehicle = $this->register();
        // An unprocessed legacy occurrence has no managed event/state effect.
        $record = VisitorRecord::create([
            'external_event_key' => 'legacy-untagged', 'vehicle_id' => $vehicle->id,
            'registration_status' => 'registered', 'registration_category' => 'faculty_staff',
            'plate_number' => '395ISX', 'plate_status' => 'read', 'plate_confidence' => .99,
            'gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now(), 'status' => 'active', 'source' => 'camera',
        ]);
        $fields = VisitorRecordLog::fields($record);
        $this->assertSame('Plate identified', $fields['status_label']);
        $this->assertFalse($fields['is_alert']);
        $this->assertStringNotContainsString('no tag read', $fields['source_label']);
        $this->assertStringNotContainsString('no tag read', $fields['state_label']);
        $this->getJson(route('logs.index'))->assertJsonPath('logs.0.status_label', 'Plate identified');
        $this->assertSame('Plate identified', $this->csvRows()[1][10]);
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public function test_real_repeat_in_reason_survives_plate_retries_and_is_visible_in_full_details(): void
    {
        $vehicle = $this->register();
        $this->pass();
        $unknown = $this->pass('UNKNOWN');
        $event = $unknown->movementEvent;
        $this->patch(route('vehicle-events.direction', $event), ['direction' => 'IN'])->assertRedirect();
        $reason = $event->fresh()->anomaly_reason;
        $this->assertStringContainsString('already inside (an OUT was missed)', $reason);
        $this->patchJson(route('visitors.records.review', $unknown), ['action' => 'correct_plate', 'plate' => '395ISX'])->assertOk();
        $this->assertSame($reason, $event->fresh()->anomaly_reason);
        $this->getJson(route('logs.index', ['log_type' => 'alerts']))->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.status_label', 'Anomaly')->assertJsonPath('logs.0.alert_reason', $reason);
        $this->get(route('vehicle-events.show', $event))->assertOk()->assertSee('Anomaly')->assertSee($reason);
        $this->assertStringContainsString('Anomaly', $this->get(route('vehicle-events.export.csv', ['all' => 1]))->streamedContent());
        $this->assertSame(2, $this->get(route('logs.print'))->viewData('rows')->count());
        $this->assertSame(2, $vehicle->fresh()->entries_today_count);
        $this->assertDatabaseCount('vehicle_events', 2);
        $this->assertDatabaseCount('rfid_scan_logs', 0);
    }

    public function test_actual_rfid_repeat_entry_retains_one_saved_alert_through_retries(): void
    {
        $vehicle = $this->register(['display_number' => '123', 'rfid_uid' => 'ANOMALY-FIXTURE-UID', 'auto_register_tag' => true]);
        foreach (['first', 'repeat'] as $key) {
            $this->travel(2)->minutes();
            app(RfidTagMatcher::class)->addRead('gate-1', $vehicle->rfid_tag_uid, now());
            $payload = ['external_event_key' => $key, 'camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(), 'direction' => 'IN'];
            $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.crossings'), $payload)->assertCreated();
        }
        foreach (range(1, 2) as $retry) {
            $this->postJson(route('api.integration.crossings'), $payload)->assertOk();
        }
        $this->assertDatabaseCount('vehicle_events', 2);
        $this->assertDatabaseCount('rfid_scan_logs', 2);
        $this->assertSame(1, app(AlertSummaryService::class)->counts()['anomalies']);
        $this->assertSame(1, $this->get(route('logs.index', ['tab' => 'alerts']))->viewData('alerts')->total());
        $this->getJson(route('logs.index', ['log_type' => 'alerts']))->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.status_label', 'Anomaly');
    }

    private function csvRows(): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $this->get(route('vehicle-events.export.csv', ['all' => 1]))->assertOk()->streamedContent());
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
