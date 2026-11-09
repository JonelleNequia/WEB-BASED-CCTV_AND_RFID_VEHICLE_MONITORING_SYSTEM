<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\RealtimeLogController;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VisitorRecord;
use App\Services\DetectorRuntimeService;
use App\Services\PlateEvidenceService;
use App\Services\VisitorRecordService;
use App\Support\VisitorRecordLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class OcrCandidatePresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        $this->travelTo(now()->startOfDay()->addHours(12));
        Storage::fake('occurrences'); Storage::fake('public');
        $this->withHeader('X-Api-Key', 'test-detector-key');
    }

    private function payload(?string $text = '395ISX', float $confidence = .789): array
    {
        return ['external_event_key' => 'candidate-pass', 'camera_role' => 'gate-1',
            'camera_id' => \App\Models\Camera::forRole('gate-1')->value('id'), 'event_time' => now()->toIso8601String(),
            'plate_status' => 'unreadable', 'plate_number' => null, 'best_guess' => $text, 'plate_confidence' => $confidence,
            'ocr_details' => ['reader' => 'plate_detector', 'raw_reads' => $text ? [['text' => $text, 'confidence' => .999, 'engine' => 'plate_ocr']] : [],
                'vote' => ['plate' => $text, 'best_guess' => $text, 'status' => $text ? 'read' : 'unreadable', 'reason' => $text ? '8 of 8 frame reads match exactly; character vote.' : 'No plate found in 0 frame(s).', 'frames_checked' => $text ? 8 : 0],
                'identifier_evidence' => ['kind' => 'uncertain', 'source' => 'unclassified_region']]];
    }

    private function record(array $payload): VisitorRecord
    {
        $this->post(route('api.integration.crossings'), ['external_event_key' => $payload['external_event_key'],
            'camera_role' => 'gate-1', 'camera_id' => $payload['camera_id'], 'event_time' => $payload['event_time'],
            'direction' => 'IN', 'detection_metadata' => ['rfid_status' => 'no_pass'],
            'snapshot' => UploadedFile::fake()->image('vehicle.jpg')])->assertCreated();
        $this->post(route('api.integration.visitor-plates'), array_replace($payload, [
            'ocr_details' => json_encode($payload['ocr_details']), 'plate_image' => UploadedFile::fake()->image('plate.jpg')]))->assertOk();
        return VisitorRecord::where('external_event_key', $payload['external_event_key'])->sole();
    }

    public function test_matching_vote_remains_review_with_candidate_and_the_real_type_blocker_across_views(): void
    {
        $record = $this->record($this->payload());
        $this->assertSame('review', $record->registration_status);
        $this->assertNull($record->plate_number); $this->assertNull($record->plate_key);
        $this->assertNull($record->plate_profile_id); $this->assertDatabaseCount('plate_profiles', 0);
        $this->assertSame('Needs review: 395ISX', $record->plateLabel());
        $row = VisitorRecordLog::fields($record);
        $this->assertNull($row['accepted_plate_number']);
        $this->assertSame('identifier_type_unconfirmed', $row['plate_review_reason_code']);
        $this->assertStringContainsString('Identifier type is unconfirmed', $row['plate_review_reason']);
        $this->assertStringNotContainsString('8 of 8', $row['plate_review_reason']);
        $this->assertSame('79% (OCR evidence)', $row['plate_confidence_label']);
        $this->assertSame('OCR candidate (unconfirmed)', $row['plate_candidate_source']);
        $gate = collect(app(RealtimeLogController::class)->gateLogRows('gate-1'))->firstWhere('record_type', 'visitor_record');
        $this->assertSame($row['plate_number'], $gate['plate_number']);
        $this->assertSame($row['plate_review_reason'], $gate['result_detail']);
        $this->getJson(route('logs.index'))->assertOk()->assertJsonPath('logs.0.plate_candidate', '395ISX')->assertJsonPath('logs.0.status_label', 'Needs review');
        $this->get(route('visitors.index', ['tab' => 'review']))->assertOk()->assertSee('Identifier type is unconfirmed')->assertSee('395ISX')->assertSee('OCR candidate');
        $this->get(route('logs.print', ['autoprint' => 0]))->assertOk()->assertSee('Needs review: 395ISX')->assertSee('Identifier type is unconfirmed');
        $csv = $this->get(route('vehicle-events.export.csv', ['all' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Needs review: 395ISX', $csv); $this->assertStringContainsString('Identifier type is unconfirmed', $csv);
        $this->assertNotNull($record->snapshot_url); $this->assertNotNull($record->plate_image_url);
        $this->assertSame($record->snapshot_url, $gate['image_url']); $this->assertSame($record->plate_image_url, $gate['plate_image_url']);
    }

    public function test_old_stored_review_reason_is_presented_accurately_without_rewriting_the_record(): void
    {
        $record = $this->record($this->payload('AAC-1869'));
        $details = $record->ocr_details_json;
        unset($details[PlateEvidenceService::KEY]['latest'], $details[PlateEvidenceService::KEY]['reason_code']);
        $old = 'OCR result requires review. 8 of 8 frame reads match exactly; character vote. Confirm the text and identifier type from the images.';
        $record->forceFill(['registration_reason' => $old, 'ocr_details_json' => $details])->save();
        $before = $record->getRawOriginal(); $fields = VisitorRecordLog::fields($record);
        $this->assertSame('Needs review: AAC 1869', $fields['plate_number']);
        $this->assertSame('identifier_type_unconfirmed', $fields['plate_review_reason_code']);
        $this->assertSame($before, $record->fresh()->getRawOriginal());
    }

    public function test_empty_ocr_is_unreadable_but_a_later_empty_read_does_not_erase_an_earlier_candidate(): void
    {
        $record = $this->record($this->payload(null, 0));
        $this->assertSame('Plate unreadable', $record->plateLabel());
        $this->assertNull(PlateEvidenceService::candidate($record));
        app(VisitorRecordService::class)->applyPlateReading($this->payload());
        app(VisitorRecordService::class)->applyPlateReading($this->payload(null, 0));
        $record->refresh();
        $this->assertSame('Needs review: 395ISX', $record->plateLabel());
        $this->assertSame('earlier_ocr_candidate', PlateEvidenceService::presentation($record)['reason_code']);
        $this->assertSame(.789, PlateEvidenceService::presentation($record)['confidence']);
        $this->assertSame('Earlier OCR candidate (unconfirmed)', PlateEvidenceService::presentation($record)['source']);
        $this->assertDatabaseCount('vehicle_crossings', 1); $this->assertDatabaseCount('visitor_records', 1);
        $this->assertDatabaseCount('vehicle_events', 0);
    }

    public function test_accepted_legacy_read_displays_the_plate_but_readability_does_not_imply_registration(): void
    {
        $record = VisitorRecord::create(['external_event_key' => 'accepted-legacy', 'gate' => 'gate-1', 'direction' => 'IN',
            'seen_at' => now(), 'plate_status' => 'read', 'plate_number' => '395ISX', 'plate_confidence' => .95,
            'registration_status' => 'guest', 'status' => 'active', 'source' => 'legacy']);
        $this->assertSame('395ISX', $record->plateLabel());
        $this->assertNull(PlateEvidenceService::candidate($record));
        $this->assertSame('Unregistered Guest', VisitorRecordLog::fields($record)['log_type_label']);
        $this->assertDatabaseCount('vehicles', 0);
    }

    public function test_identity_conflict_reason_is_not_replaced_with_a_generic_identifier_reason(): void
    {
        $record = $this->record($this->payload());
        $reason = 'Plate evidence conflicts with the linked RFID identity. Check the plate; RFID identity is retained.';
        $record->forceFill(['registration_reason' => $reason])->save();
        $row = VisitorRecordLog::fields($record);
        $this->assertSame($reason, $row['plate_review_reason']);
        $this->assertSame($reason, $row['alert_reason']);
        $this->assertNull($row['plate_review_reason_code']);
    }

    public static function literalCandidates(): array
    {
        return array_map(fn ($text) => [$text], ['395ISX', 'ISX395', 'AO0123', 'A00123', 'AI0123', 'A10123', '000123']);
    }

    #[DataProvider('literalCandidates')]
    public function test_literal_candidate_order_zeros_and_lookalikes_are_preserved(string $text): void
    {
        $record = $this->record($this->payload($text));
        $this->assertSame(\App\Support\PlateNumber::key($text), \App\Support\PlateNumber::key(PlateEvidenceService::candidate($record)));
        $this->assertNull($record->plate_number); $this->assertDatabaseCount('plate_profiles', 0);
    }

    public function test_registered_without_rfid_and_unmatched_confirmations_keep_identity_images_and_retry_effects(): void
    {
        $vehicle = Vehicle::create(['plate_number' => '395ISX', 'vehicle_owner_name' => 'Fixture Owner', 'vehicle_type' => 'Car',
            'category' => 'faculty_staff', 'status' => 'active', 'created_at' => now()->subDay()]);
        $record = $this->record($this->payload());
        $identity = $record->only(['id', 'external_event_key', 'gate', 'camera_id', 'seen_at', 'direction', 'snapshot_path', 'plate_image_path']);
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'confirm'])->assertOk()->assertJsonPath('registration_status', 'registered');
        $first = $vehicle->fresh()->getRawOriginal();
        $late = $this->payload('ISX395');
        $this->postJson(route('api.integration.visitor-plates'), $late)->assertOk();
        $this->postJson(route('api.integration.visitor-plates'), $late)->assertOk();
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'confirm'])->assertOk();
        $record->refresh();
        $this->assertSame('395ISX', $record->plateLabel()); $this->assertNull(PlateEvidenceService::candidate($record));
        $this->assertEquals($identity, $record->only(array_keys($identity)));
        $this->assertSame($first, $vehicle->fresh()->getRawOriginal());
        $this->assertDatabaseCount('vehicle_events', 1); $this->assertDatabaseCount('rfid_scan_logs', 0);
        $this->assertNull($vehicle->fresh()->rfid_tag_id);
        $record = $this->record(array_replace($this->payload('A123BC'), ['external_event_key' => 'unmatched-pass']));
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'confirm'])->assertOk()->assertJsonPath('registration_status', 'guest');
        $this->assertSame('A123BC', $record->fresh()->plateLabel());
    }

    public function test_insufficient_confidence_and_temporary_evidence_are_not_accepted_plates(): void
    {
        $payload = $this->payload('395ISX', .2);
        $record = $this->record($payload);
        $this->assertSame('ocr_low_confidence', PlateEvidenceService::presentation($record)['reason_code']);
        $this->assertSame('Needs review: 395ISX', $record->plateLabel());
        $this->patchJson(route('visitors.records.review', $record), ['action' => 'temporary_identifier',
            'identifier_type' => 'conduction_sticker', 'identifier_text' => '395ISX'])->assertOk();
        $record->refresh();
        $this->assertSame('Conduction sticker (no actual plate)', $record->plateLabel());
        $this->assertNull(PlateEvidenceService::candidate($record)); $this->assertNull($record->plate_number);
        $this->assertDatabaseCount('plate_profiles', 0);
        $report = app(\App\Services\ListReportService::class)->data('guests', []);
        $this->assertSame('Evidence only', $report['rows']->first()['cells']['plate_result']);
    }

    public function test_no_camera_status_keeps_historical_activity_but_not_checking_or_live_claims(): void
    {
        $this->record($this->payload());
        $this->mock(DetectorRuntimeService::class, function ($mock): void {
            $mock->shouldReceive('markStationViewerActive')->andReturnNull();
            $mock->shouldReceive('ensureRunning')->andReturn(['service_running' => true, 'cameras' => ['gate-1' => [
                'source_type' => 'none', 'camera_running' => false, 'last_error' => 'No camera added for this gate.']]]);
            $mock->shouldReceive('withViewerStreamUrls')->andReturnUsing(fn ($runtime) => $runtime);
            $mock->shouldReceive('defaultStreamUrl')->andReturn('/fixture-stream');
        });
        $response = $this->getJson(route('gates.state'))->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $response->assertJsonPath('detector_running', true)->assertJsonPath('gates.gate-1.source_configured', false)
            ->assertJsonPath('gates.gate-1.camera_running', false)->assertJsonPath('gates.gate-1.checking', [])
            ->assertJsonPath('gates.gate-1.logs.0.plate_candidate', '395ISX');
    }
}
