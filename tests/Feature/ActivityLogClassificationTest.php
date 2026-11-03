<?php

namespace Tests\Feature;

use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\VehicleRegistryService;
use App\Services\VisitorRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class ActivityLogClassificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        Storage::fake('public');
        Storage::fake('local');
        $this->admin = User::query()->where('role', 'admin')->firstOrFail();
    }

    public static function unlinkedScans(): array
    {
        return [['unknown_tag', 'Unknown tag'], ['unassigned_tag', 'Unassigned tag'], ['guest', 'Unknown tag']];
    }

    #[DataProvider('unlinkedScans')]
    public function test_unknown_and_unassigned_scans_are_not_registered_or_counted_as_visitors(string $verification, string $label): void
    {
        $scan = RfidScanLog::query()->create([
            'tag_uid' => 'CLASS-UNKNOWN', 'scan_location' => 'gate-1', 'scan_time' => now(),
            'verification_status' => $verification, 'fusion_status' => 'scan_only', 'is_anomaly' => true,
            // A stale category or readable plate in metadata is not registration evidence.
            'vehicle_category' => 'registered_visitor', 'payload_json' => ['vehicle_plate_number' => 'READ-1234'],
        ]);
        $before = $scan->fresh()->getRawOriginal();
        $this->actingAs($this->admin)->getJson(route('logs.index'))->assertOk()
            ->assertJsonPath('logs.0.log_type', 'rfid_scan')->assertJsonPath('logs.0.log_type_label', 'RFID Scan')
            ->assertJsonPath('logs.0.status_label', $label)->assertJsonPath('logs.0.category_label', 'N/A')
            ->assertJsonPath('summary.guests', 0)->assertJsonPath('summary.rfid', 1);
        foreach (['registered', 'unregistered_visitor'] as $type) {
            $this->getJson(route('logs.index', ['log_type' => $type]))->assertOk()->assertJsonPath('total', 0);
        }
        $this->getJson(route('logs.index', ['category' => 'registered_visitor']))->assertOk()->assertJsonPath('total', 0);
        $this->getJson(route('logs.index', ['log_type' => 'alerts']))->assertOk()->assertJsonPath('total', 1);
        $csv = $this->get(route('vehicle-events.export.csv', ['all' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString('RFID,"RFID Scan"', $csv);
        $print = $this->get(route('logs.print', ['event_type' => 'RFID']))->assertOk();
        $this->assertSame('—', $print->viewData('rows')->sole()['category']);
        $this->getJson(route('api.recent-station-logs'))->assertOk()->assertJsonPath('logs.0.event_type', 'UNKNOWN TAG');
        $this->getJson(route('logs.index', ['tab' => 'scans', 'history_q' => $scan->tag_uid]))->assertOk();
        $this->assertSame($before, $scan->fresh()->getRawOriginal());
    }

    private function vehicle(string $plate = 'REG-1234', string $category = 'faculty_staff'): Vehicle
    {
        $tag = RfidTag::query()->create(['uid' => 'CLASS-TAG-'.RfidTag::query()->count(), 'status' => 'available']);

        return app(VehicleRegistryService::class)->register(['rfid_tag_id' => $tag->id, 'plate_number' => $plate,
            'registered_by' => 'Test Registrar',
            'vehicle_owner_name' => 'Classification Owner', 'category' => $category, 'vehicle_type' => 'Car'])->fresh();
    }

    public static function registeredCategories(): array
    {
        return [['faculty_staff', 'Faculty & Staff'], ['registered_visitor', 'Registered Visitor'], ['parent', 'Registered Visitor']];
    }

    #[DataProvider('registeredCategories')]
    public function test_registered_relations_and_recorded_categories_remain_registered(string $category, string $label): void
    {
        $vehicle = $this->vehicle();
        // Historical snapshot categories can differ from the current Registry.
        RfidScanLog::query()->create(['tag_uid' => 'CLASS-REGISTERED', 'vehicle_id' => $vehicle->id,
            'vehicle_rfid_tag_id' => $vehicle->rfid_tag_id, 'vehicle_category' => $category, 'verification_status' => 'verified',
            'scan_time' => now(), 'scan_location' => 'gate-1', 'fusion_status' => 'scan_only']);
        $this->actingAs($this->admin)->getJson(route('logs.index', ['log_type' => 'registered', 'category' => $category]))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('logs.0.log_type', 'registered')
            ->assertJsonPath('logs.0.category_label', $label)->assertJsonPath('logs.0.status_label', 'Registered')
            ->assertJsonPath('summary.guests', 0);
        $this->assertSame(1, $this->get(route('logs.index', ['tab' => 'scans', 'verification_status' => 'verified']))
            ->assertOk()->viewData('scanLogs')->total());
    }

    public function test_saved_verified_receipts_keep_their_historical_meaning_without_relinking_a_vehicle(): void
    {
        $scan = RfidScanLog::query()->create(['tag_uid' => 'CLASS-OLD-VERIFIED', 'vehicle_category' => 'faculty_staff',
            'verification_status' => 'verified', 'scan_time' => now()->subDays(10), 'scan_location' => 'gate-1', 'fusion_status' => 'scan_only']);
        $before = $scan->fresh()->getRawOriginal();
        $this->actingAs($this->admin)->getJson(route('logs.index', ['log_type' => 'registered']))->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('logs.0.log_type', 'registered')->assertJsonPath('logs.0.category_label', 'Faculty & Staff');
        $this->assertSame($before, $scan->fresh()->getRawOriginal());
        $this->assertNull($scan->fresh()->vehicle_id);
    }

    public function test_the_verified_database_default_and_readable_metadata_do_not_prove_registration(): void
    {
        $scan = RfidScanLog::query()->create(['tag_uid' => 'CLASS-DEFAULT', 'scan_time' => now(), 'scan_location' => 'gate-1',
            'fusion_status' => 'scan_only', 'payload_json' => ['vehicle_plate_number' => 'READ-1234', 'registered' => true]]);
        $before = $scan->fresh()->getRawOriginal();
        $this->assertSame('verified', $scan->fresh()->verification_status);
        $this->actingAs($this->admin)->getJson(route('logs.index'))->assertOk()
            ->assertJsonPath('logs.0.log_type', 'rfid_scan')->assertJsonPath('logs.0.status_label', 'Registration unknown')
            ->assertJsonPath('logs.0.category_label', 'N/A')->assertJsonPath('summary.guests', 0);
        $this->getJson(route('logs.index', ['log_type' => 'registered']))->assertJsonPath('total', 0);
        $this->assertSame('Registration unknown', $scan->fresh()->verificationLabel);
        $this->assertSame(0, $this->get(route('logs.index', ['tab' => 'scans', 'verification_status' => 'verified']))
            ->assertOk()->viewData('scanLogs')->total());
        $this->assertSame($before, $scan->fresh()->getRawOriginal());
    }

    public function test_unknown_or_unassigned_verification_cannot_borrow_a_registered_category_from_a_link(): void
    {
        $vehicle = $this->vehicle();
        RfidScanLog::query()->create(['tag_uid' => 'CLASS-UNASSIGNED', 'vehicle_id' => $vehicle->id,
            'vehicle_category' => 'faculty_staff', 'verification_status' => 'unassigned_tag', 'scan_time' => now(), 'scan_location' => 'gate-1']);
        $this->actingAs($this->admin)->getJson(route('logs.index'))->assertOk()
            ->assertJsonPath('logs.0.plate_number', $vehicle->plate_number)->assertJsonPath('logs.0.log_type', 'rfid_scan')
            ->assertJsonPath('logs.0.category_label', 'N/A');
        $this->getJson(route('logs.index', ['log_type' => 'registered']))->assertJsonPath('total', 0);
        $this->getJson(route('logs.index', ['category' => 'faculty_staff']))->assertJsonPath('total', 0);
    }

    public function test_inactive_registered_vehicles_and_tags_keep_registration_separate_from_access_result(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->update(['status' => 'inactive']);
        RfidScanLog::query()->create(['tag_uid' => 'CLASS-INACTIVE', 'vehicle_id' => $vehicle->id,
            'vehicle_category' => 'faculty_staff', 'verification_status' => 'inactive_vehicle', 'is_anomaly' => true,
            'scan_time' => now(), 'scan_location' => 'gate-1', 'fusion_status' => 'scan_only']);
        $this->actingAs($this->admin)->getJson(route('logs.index', ['log_type' => 'registered']))->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('logs.0.status_label', 'Inactive vehicle')->assertJsonPath('logs.0.is_alert', true);
    }

    public function test_an_inventory_tag_without_a_vehicle_is_not_registration_evidence(): void
    {
        $tag = RfidTag::query()->create(['uid' => 'CLASS-LOST-UNASSIGNED', 'status' => 'lost']);
        RfidScanLog::query()->create(['tag_uid' => $tag->uid, 'vehicle_rfid_tag_id' => $tag->id,
            'verification_status' => 'inactive_tag', 'vehicle_category' => 'faculty_staff', 'scan_time' => now(), 'scan_location' => 'gate-1']);
        $this->actingAs($this->admin)->getJson(route('logs.index'))->assertOk()
            ->assertJsonPath('logs.0.log_type', 'rfid_scan')->assertJsonPath('logs.0.category_label', 'N/A')
            ->assertJsonPath('logs.0.status_label', 'Inactive tag')->assertJsonPath('logs.0.is_alert', true);
        $this->getJson(route('logs.index', ['log_type' => 'registered']))->assertJsonPath('total', 0);
    }

    public function test_legacy_guest_pass_and_assigned_guest_records_keep_their_supported_distinctions(): void
    {
        $vehicle = $this->vehicle();
        $guest = RfidScanLog::query()->create(['tag_uid' => 'CLASS-LEGACY-GUEST', 'vehicle_id' => $vehicle->id,
            'vehicle_rfid_tag_id' => $vehicle->rfid_tag_id, 'vehicle_category' => 'guest', 'verification_status' => 'guest',
            'scan_time' => now(), 'scan_location' => 'gate-1']);
        $pass = RfidScanLog::query()->create(['tag_uid' => 'CLASS-LEGACY-PASS', 'vehicle_category' => 'guest_pass',
            'verification_status' => 'guest_pass_lost', 'scan_time' => now(), 'scan_location' => 'gate-2']);
        $rows = $this->actingAs($this->admin)->getJson(route('logs.index'))->assertOk()->json('logs');
        $byId = collect($rows)->keyBy('id');
        $this->assertSame(['no_pass_alert', 'Unregistered Visitor', true],
            [$byId[$guest->id]['log_type'], $byId[$guest->id]['category_label'], $byId[$guest->id]['is_guest']]);
        $this->assertSame(['guest_pass', 'Old pass record', true],
            [$byId[$pass->id]['log_type'], $byId[$pass->id]['log_type_label'], $byId[$pass->id]['is_guest']]);
        $this->getJson(route('logs.index', ['log_type' => 'registered']))->assertJsonPath('total', 0);
        $this->getJson(route('logs.index', ['category' => 'unregistered_visitor']))->assertJsonPath('total', 1);
    }

    public static function plateReads(): array
    {
        return [['read', 'UNR-1234'], ['unreadable', null]];
    }

    #[DataProvider('plateReads')]
    public function test_future_camera_ingestion_never_registers_a_vehicle_from_readability_or_client_flags(string $status, ?string $plate): void
    {
        $this->vehicle('OTHER-9000');
        $base = ['external_event_key' => 'classification-camera', 'camera_role' => 'gate-1', 'direction' => 'IN',
            'event_time' => now()->toIso8601String(), 'detected_vehicle_type' => 'Car'];
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.crossings'),
            $base + ['detection_metadata' => ['rfid_status' => 'no_pass']])->assertCreated();
        $this->postJson(route('api.integration.visitor-plates'), $base + ['plate_status' => $status, 'plate_number' => $plate, 'plate_confidence' => 0.95,
            'registered' => true, 'vehicle_id' => Vehicle::query()->value('id'), 'vehicle_category' => 'registered_visitor'])
            ->assertOk();
        $record = VisitorRecord::query()->sole();
        $before = $record->fresh()->getRawOriginal();
        $this->assertNull($record->vehicle_id);
        $this->assertNull($record->plateProfile?->vehicle_id);
        $this->actingAs($this->admin)->getJson(route('logs.index'))->assertOk()
            ->assertJsonPath('logs.0.log_type', $status === 'read' ? 'unregistered_visitor' : 'review')
            ->assertJsonPath('logs.0.category_label', $status === 'read' ? 'Unregistered Visitor' : 'Review')
            ->assertJsonPath('logs.0.is_guest', $status === 'read')->assertJsonPath('summary.guests', $status === 'read' ? 1 : 0);
        $this->getJson(route('logs.index', ['log_type' => 'registered']))->assertJsonPath('total', 0);
        $this->getJson(route('logs.index', ['log_type' => 'unregistered_visitor']))->assertJsonPath('total', $status === 'read' ? 1 : 0);
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public function test_authoritative_plate_only_relation_remains_registered_but_does_not_change_vehicle_state(): void
    {
        $vehicle = $this->vehicle('REG-1234', 'registered_visitor');
        $this->travel(2)->seconds();
        $record = app(VisitorRecordService::class)->applyPlateReading(['external_event_key' => 'classification-known',
            'camera_role' => 'gate-1', 'event_time' => now()->toIso8601String(), 'plate_status' => 'read', 'plate_number' => 'REG-1234', 'plate_confidence' => 0.95]);
        $this->assertSame($vehicle->id, $record->vehicle_id);
        $this->actingAs($this->admin)->getJson(route('logs.index', ['log_type' => 'registered']))->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('logs.0.category_label', 'Registered Visitor')
            ->assertJsonPath('logs.0.status_label', 'Direction unknown')->assertJsonPath('summary.guests', 0);
        $this->assertSame(Vehicle::STATE_OUTSIDE, $vehicle->fresh()->current_state);
    }

    public function test_registering_a_plate_later_does_not_reclassify_its_earlier_activity(): void
    {
        $record = app(VisitorRecordService::class)->applyPlateReading(['external_event_key' => 'classification-before',
            'camera_role' => 'gate-1', 'event_time' => now()->subDay()->toIso8601String(), 'plate_status' => 'read', 'plate_number' => 'OLD-1234', 'plate_confidence' => 0.95]);
        $vehicle = $this->vehicle('OLD-1234');
        $this->assertNull($record->fresh()->vehicle_id); // Ordinary registration does not relink earlier passes.
        $before = $record->fresh()->getRawOriginal();
        $this->actingAs($this->admin)->getJson(route('logs.index', ['log_type' => 'registered']))->assertOk()->assertJsonPath('total', 0);
        $this->getJson(route('logs.index', ['log_type' => 'unregistered_visitor']))->assertOk()->assertJsonPath('total', 1);
        $this->assertSame($before, $record->fresh()->getRawOriginal());
    }

    public function test_readable_manual_event_without_registration_keeps_its_manual_source_and_unregistered_status(): void
    {
        VehicleEvent::query()->create(['event_type' => 'ENTRY', 'event_status' => 'completed', 'event_origin' => 'manual',
            'plate_text' => 'MAN-1234', 'vehicle_type' => 'Car', 'event_time' => now(), 'match_status' => 'open']);
        $this->actingAs($this->admin)->getJson(route('logs.index'))->assertOk()
            ->assertJsonPath('logs.0.log_type', 'manual')->assertJsonPath('logs.0.status_label', 'Unregistered');
        $this->getJson(route('logs.index', ['log_type' => 'registered']))->assertJsonPath('total', 0);
        // This feed's status is the movement label; it must not claim registration.
        $this->getJson(route('api.recent-event-logs'))->assertOk()->assertJsonPath('logs.0.status_label', 'Entry');
        $this->getJson(route('api.recent-station-logs'))->assertOk()->assertJsonPath('logs.0.registered', false);
    }
}
