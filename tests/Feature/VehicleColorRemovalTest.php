<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\EventReceiveLog;
use App\Models\GuestVehicleObservation;
use App\Models\PlateProfile;
use App\Models\User;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\EventService;
use App\Services\PlateMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class VehicleColorRemovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->admin = User::query()->where('role', 'admin')->firstOrFail();
    }

    private function capture(array $extra = []): array
    {
        return array_replace([
            'external_event_key' => 'retired-color-capture', 'camera_role' => 'gate-1',
            'detected_vehicle_type' => 'Car', 'event_time' => now()->toIso8601String(),
            'vehicle_image_path' => 'fixture/color-retirement.jpg',
        ], $extra);
    }

    public function test_manual_events_and_visitors_do_not_require_or_save_deprecated_color(): void
    {
        $base = ['event_type' => 'ENTRY', 'plate_text' => 'CLR-0001', 'vehicle_type' => 'Car',
            'camera_id' => Camera::query()->forRole('gate-1')->value('id'),
            'roi_name' => 'Fixture zone', 'event_time' => now()->toDateTimeString()];
        $this->actingAs($this->admin)->post(route('vehicle-events.store'), $base)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('vehicle-events.store'), array_replace($base, ['plate_text' => 'CLR-0002',
            'vehicle_color' => ['ignored malformed legacy value'], 'detected_vehicle_color' => str_repeat('x', 1000)]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([null, null], VehicleEvent::query()->pluck('vehicle_color')->all());
        $this->assertSame([null, null], \App\Models\ActiveSession::query()->pluck('vehicle_color')->all());

        $this->post(route('visitors.records.store'), ['gate' => 'gate-1', 'direction' => 'IN',
            'seen_at' => now()->subSecond()->toDateTimeString(), 'plate_number' => 'CLR-0003',
            'vehicle_color' => ['ignored']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(VisitorRecord::query()->sole()->vehicle_color);
        $this->assertNull(PlateProfile::query()->sole()->vehicle_color);
    }

    public function test_completing_pending_details_preserves_legacy_color_without_requiring_it(): void
    {
        $event = app(EventService::class)->createDetectedEvent($this->capture());
        $event->forceFill(['vehicle_color' => 'HistoricalColorMarker'])->save();
        $this->actingAs($this->admin)->put(route('vehicle-events.complete', $event), [
            'plate_text' => 'CLR-0004', 'vehicle_type' => 'Car', 'vehicle_color' => ['ignored'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('HistoricalColorMarker', $event->fresh()->vehicle_color);
        $this->assertSame(VehicleEvent::STATUS_COMPLETED, $event->fresh()->event_status);
    }

    public static function captureRoutes(): array
    {
        return [['api.guest-observation'], ['api.integration.guest-observations'], ['api.integration.events']];
    }

    #[DataProvider('captureRoutes')]
    public function test_older_capture_payloads_ignore_color_including_nested_metadata_and_receive_logs(string $route): void
    {
        $metadata = ['track_id' => 123, 'vehicle_color' => 'retired', 'color_analysis' => ['result' => 'retired'],
            'frame_results' => [['vehicle_color' => 'retired', 'color_error' => 'retired', 'plate_score' => 0.9]],
            'overlay' => ['color' => 'green']];
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route($route, ['vehicle_color' => 'ignored query value']), $this->capture([
            'vehicle_color' => ['not a string'], 'detected_vehicle_color' => str_repeat('x', 1000),
            'detection_metadata' => json_encode($metadata),
        ]))->assertCreated();
        $observation = GuestVehicleObservation::query()->sole();
        $this->assertNull($observation->vehicle_color);
        $this->assertSame(['track_id' => 123, 'frame_results' => [['plate_score' => 0.9]], 'overlay' => ['color' => 'green']],
            \Illuminate\Support\Arr::except($observation->detection_metadata_json, [\App\Services\VehicleTypeEvidenceService::KEY]));
        foreach (EventReceiveLog::query()->get() as $log) {
            $payload = json_encode($log->payload_json);
            $this->assertStringNotContainsString('vehicle_color', $payload);
            $this->assertStringNotContainsString('color_analysis', $payload);
            $this->assertStringNotContainsString('color_error', $payload);
        }
        foreach (VehicleEvent::query()->get() as $event) {
            $this->assertNull($event->vehicle_color);
            $this->assertSame($observation->detection_metadata_json, $event->detection_metadata_json);
        }
    }

    public static function legacyDirections(): array
    {
        return [['IN'], ['OUT']];
    }

    #[DataProvider('legacyDirections')]
    public function test_legacy_camera_id_payloads_accept_and_ignore_deprecated_color(string $direction): void
    {
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.events'), [
            'camera_id' => Camera::query()->forRole('gate-1')->value('id'), 'direction' => $direction,
            'plate_number' => '000123', 'image_path' => 'fixture/legacy.jpg', 'vehicle_color' => ['ignored'],
        ])->assertCreated();
        $this->assertNull(GuestVehicleObservation::query()->sole()->vehicle_color);
        $this->assertSame('000123', GuestVehicleObservation::query()->sole()->plate_number);
        $this->assertArrayNotHasKey('vehicle_color', EventReceiveLog::query()->sole()->payload_json);
    }

    public function test_late_ocr_and_admin_updates_preserve_existing_colors_on_both_historical_records(): void
    {
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.guest-observation'), $this->capture())->assertCreated();
        $observation = GuestVehicleObservation::query()->sole();
        $event = VehicleEvent::query()->sole();
        $observation->forceFill(['vehicle_color' => 'HistoricalColorMarker',
            'detection_metadata_json' => ['vehicle_color' => 'HistoricalColorMarker', 'track_id' => 123]])->save();
        $event->forceFill(['vehicle_color' => 'DifferentHistoricalColor'])->save();

        $this->postJson(route('api.guest-observation'), $this->capture([
            'plate_number' => 'CLR-0005', 'vehicle_color' => 'new ignored color',
            'detection_metadata' => ['analysis_status' => 'complete', 'vehicle_color' => 'new ignored color', 'track_id' => 123],
        ]))->assertOk();
        $this->assertSame('CLR-0005', $observation->fresh()->plate_number);
        $this->assertSame('HistoricalColorMarker', $observation->fresh()->detection_metadata_json['vehicle_color']);

        $data = ['plate_number' => 'CLR-0005', 'vehicle_type' => 'Van', 'location' => 'gate-1',
            'observed_at' => now()->toDateTimeString(), 'status' => 'reviewed', 'vehicle_color' => ['ignored']];
        $this->actingAs($this->admin)->patch(route('guest-observations.update', $observation), $data)->assertRedirect()->assertSessionHasNoErrors();
        unset($data['vehicle_color']);
        $this->patch(route('guest-observations.verify', $observation), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('HistoricalColorMarker', $observation->fresh()->vehicle_color);
        $this->assertSame('DifferentHistoricalColor', $event->fresh()->vehicle_color);
        $this->assertSame('Van', $event->fresh()->vehicle_type);
    }

    public function test_plate_updates_ignore_color_without_erasing_existing_values_or_accepting_a_new_matching_signal(): void
    {
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.visitor-plates'), $this->capture([
            'plate_number' => 'CLR-0006', 'plate_status' => 'read', 'vehicle_color' => ['ignored'],
            'ocr_details' => json_encode(['vehicle_color' => 'ignored', 'frames_checked' => 2]),
        ]))->assertOk();
        $record = VisitorRecord::query()->sole();
        $this->assertNull($record->vehicle_color);
        $this->assertNull($record->plateProfile->vehicle_color);
        $this->assertSame(['frames_checked' => 2], \Illuminate\Support\Arr::except($record->ocr_details_json, [\App\Services\VehicleTypeEvidenceService::KEY]));
        $record->forceFill(['vehicle_color' => 'HistoricalColorMarker'])->save();
        $this->postJson(route('api.integration.visitor-plates'), $this->capture([
            'plate_number' => 'CLR-0006', 'plate_status' => 'read', 'vehicle_color' => 'different ignored value',
        ]))->assertSuccessful();
        $this->assertSame('HistoricalColorMarker', $record->fresh()->vehicle_color);
    }

    public function test_retired_fields_do_not_bypass_authorization_or_other_validation(): void
    {
        $this->postJson(route('vehicle-events.store'), ['vehicle_color' => ['ignored']])->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->postJson(route('api.guest-observation'), $this->capture(['vehicle_color' => ['ignored']]))->assertUnauthorized();
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.guest-observation'), $this->capture([
            'event_time' => 'invalid', 'camera_id' => -1, 'vehicle_color' => ['ignored'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['event_time', 'camera_id'])->assertJsonMissingPath('errors.vehicle_color');
        $this->assertDatabaseCount('guest_vehicle_observations', 0);
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public function test_crossing_metadata_keeps_direction_and_detector_evidence_without_color(): void
    {
        $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.crossings'), $this->capture([
            'direction' => 'IN', 'vehicle_color' => ['ignored'],
            'detection_metadata' => json_encode(['vehicle_color' => 'ignored', 'confidence' => 0.9, 'rfid_status' => 'no_pass']),
        ]))->assertCreated();
        $crossing = \App\Models\VehicleCrossing::query()->sole();
        $this->assertSame('IN', $crossing->direction);
        $this->assertSame(['confidence' => 0.9, 'rfid_status' => 'no_pass'], $crossing->detection_metadata_json);
        $this->assertSame('IN', VisitorRecord::query()->sole()->direction);
    }

    public function test_new_colorless_approximate_matches_keep_the_existing_conservative_threshold(): void
    {
        // The existing same-day match rule must not depend on the wall clock
        // happening to be less than 20 minutes before midnight.
        $this->travelTo(now()->startOfDay()->addHours(12));
        $this->withHeader('X-Api-Key', 'test-detector-key');
        foreach ([['entry', 'IN', 'ABC-1234'], ['exit', 'OUT', 'A8C-1234']] as [$key, $direction, $plate]) {
            $this->postJson(route('api.integration.crossings'), $this->capture([
                'external_event_key' => $key, 'direction' => $direction,
                'detection_metadata' => ['rfid_status' => 'no_pass'],
            ]))->assertCreated();
            $this->postJson(route('api.integration.visitor-plates'), $this->capture([
                'external_event_key' => $key, 'plate_number' => $plate, 'plate_status' => 'read', 'vehicle_color' => 'White',
            ]))->assertOk();
            $this->travel(20)->minutes();
        }
        $exit = VisitorRecord::query()->where('external_event_key', 'exit')->sole();
        $this->assertSame(PlateMatchService::MEDIUM, $exit->match_confidence);
        $this->assertNotNull($exit->plate_profile_id);
        $this->assertStringNotContainsString('colour', $exit->match_reason);
        $this->assertSame([null, null], VisitorRecord::query()->pluck('vehicle_color')->all());
    }

    public function test_forms_logs_profiles_reports_exports_and_live_feeds_omit_retained_historical_color(): void
    {
        $marker = 'HistoricalColorMarker';
        $event = VehicleEvent::query()->create(['event_type' => 'ENTRY', 'event_status' => 'completed', 'event_origin' => 'manual',
            'plate_text' => 'CLR-0007', 'vehicle_type' => 'Car', 'vehicle_color' => $marker, 'event_time' => now(), 'match_status' => 'open']);
        $profile = PlateProfile::query()->create(['plate_key' => 'CLR0008', 'plate_number' => 'CLR-0008', 'vehicle_type' => 'Van', 'vehicle_color' => $marker]);
        VisitorRecord::query()->create(['external_event_key' => 'historical-color-visitor', 'gate' => 'gate-1', 'direction' => 'IN',
            'seen_at' => now(), 'plate_status' => 'read', 'plate_number' => 'CLR-0008', 'plate_key' => 'CLR0008',
            'plate_profile_id' => $profile->id, 'vehicle_type' => 'Van', 'vehicle_color' => $marker, 'status' => 'active']);
        GuestVehicleObservation::query()->create(['plate_number' => 'CLR-0009', 'vehicle_type' => 'Truck', 'vehicle_color' => $marker,
            'location' => 'gate-1', 'observation_source' => 'cctv', 'status' => 'pending_review', 'observed_at' => now()]);
        $pending = app(EventService::class)->createDetectedEvent($this->capture());
        $pending->forceFill(['vehicle_color' => $marker])->save();

        $this->actingAs($this->admin);
        foreach ([route('vehicle-events.create'), route('vehicle-events.show', $event), route('vehicle-events.show', $pending),
            route('logs.index'), route('logs.print'), route('visitors.index'), route('visitors.index', ['tab' => 'review']),
            route('visitors.profiles.show', $profile)] as $url) {
            $this->get($url)->assertOk()->assertDontSee($marker)->assertDontSee('name="vehicle_color"', false)->assertDontSee('Vehicle / Color');
        }
        $csv = $this->get(route('vehicle-events.export.csv', ['all' => 1]))->assertOk()->streamedContent();
        $rows = array_map(str_getcsv(...), array_filter(explode("\n", trim($csv))));
        $this->assertNotContains('Color', $rows[0]);
        $this->assertStringNotContainsString($marker, $csv);
        foreach ($rows as $row) {
            $this->assertCount(count($rows[0]), $row);
        }
        foreach (['api.recent-guest-logs', 'api.recent-event-logs', 'api.recent-station-logs'] as $route) {
            $response = $this->getJson(route($route))->assertOk()->assertJsonMissingPath('logs.0.vehicle_color');
            $this->assertStringNotContainsString('vehicle_color', $response->getContent());
            $this->assertStringNotContainsString($marker, $response->getContent());
        }
        $this->assertSame($marker, $event->fresh()->vehicle_color);
        $this->assertSame($marker, $profile->fresh()->vehicle_color);
    }
}
