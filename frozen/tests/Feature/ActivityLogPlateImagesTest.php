<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\GuestVehicleObservation;
use App\Models\RfidScanLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\VisitorRecord;
use App\Services\VisitorRecordService;
use App\Support\OccurrenceMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\fixtures\PortalSeeder;
use Tests\TestCase;

class ActivityLogPlateImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PortalSeeder::class);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
        Storage::fake('public');
        Storage::fake('occurrences');
    }

    private function image(): string
    {
        $file = Str::uuid().'.jpg';
        Storage::disk('occurrences')->put($file, UploadedFile::fake()->image('evidence.jpg', 160, 50)->getContent());

        return OccurrenceMedia::PREFIX.$file;
    }

    public function test_list_json_and_print_keep_each_occurrences_two_images_separate(): void
    {
        $expected = [];
        foreach (['vehicle_event', 'visitor_record'] as $source) {
            foreach ([[true, true], [true, false], [false, true], [false, false]] as $index => [$snapshot, $crop]) {
                $plate = ($source === 'vehicle_event' ? 'EVT' : 'GST').(1000 + $index);
                $photoPath = $snapshot ? $this->image() : null;
                $cropPath = $crop ? $this->image() : null;
                if ($source === 'vehicle_event') {
                    $record = VehicleEvent::create(['event_type' => 'ENTRY', 'event_status' => 'completed', 'event_origin' => 'manual',
                        'plate_text' => $plate, 'event_time' => now(), 'vehicle_image_path' => $photoPath, 'plate_image_path' => $cropPath]);
                } else {
                    $record = VisitorRecord::create(['external_event_key' => $plate, 'gate' => 'gate-1', 'direction' => 'IN',
                        'seen_at' => now(), 'plate_number' => $plate, 'plate_status' => 'read', 'registration_status' => 'guest',
                        'snapshot_path' => $photoPath, 'plate_image_path' => $cropPath]);
                }
                $expected[$source.':'.$record->id] = ['plate' => $plate, 'image_url' => OccurrenceMedia::url($photoPath),
                    'plate_image_url' => OccurrenceMedia::url($cropPath)];
            }
        }
        $logs = $this->getJson(route('logs.index'))->assertOk()->json('logs');
        $this->assertCount(8, $logs);
        foreach ($logs as $log) {
            $images = $expected[$log['record_type'].':'.$log['id']];
            $this->assertSame($images['image_url'], $log['image_url']);
            $this->assertSame($images['plate_image_url'], $log['plate_image_url']);
        }
        $html = $this->get(route('logs.index'))->assertOk()->assertSee('No plate image');
        $print = $this->get(route('logs.print', ['snapshots' => 1]))->assertOk()->assertSee('Include images')->assertSee('No plate image');
        foreach ($print->viewData('rows') as $row) {
            $images = collect($expected)->firstWhere('plate', $row['plate']);
            $this->assertSame($images['image_url'], $row['image_url']);
            $this->assertSame($images['plate_image_url'], $row['plate_image_url']);
            if ($row['plate_image_url']) {
                $html->assertSee('data-snapshot="'.$row['plate_image_url'].'"', false);
                $print->assertSee('src="'.$row['plate_image_url'].'"', false);
                $this->get($row['plate_image_url'])->assertOk();
            }
        }
        $without = $this->get(route('logs.print'))->assertOk();
        $without->assertDontSee('<img ', false)->assertDontSee('No plate image');
    }

    public function test_absent_missing_legacy_and_scan_crops_never_borrow_a_vehicle_image(): void
    {
        $crop = $this->image();
        $first = VehicleEvent::create(['event_type' => 'ENTRY', 'event_status' => 'completed', 'event_origin' => 'manual',
            'plate_text' => '395ISX', 'event_time' => now()->subMinute(), 'plate_image_path' => $crop]);
        $missing = VehicleEvent::create(['event_type' => 'EXIT', 'event_status' => 'completed', 'event_origin' => 'manual',
            'plate_text' => '395ISX', 'event_time' => now(), 'plate_image_path' => OccurrenceMedia::PREFIX.Str::uuid().'.jpg']);
        GuestVehicleObservation::create(['plate_number' => '395ISX', 'observed_at' => now(), 'location' => 'gate-1']);
        RfidScanLog::create(['tag_uid' => 'NO-CROP', 'scan_time' => now(), 'scan_location' => 'gate-1', 'verification_status' => 'unknown_tag']);
        $logs = $this->getJson(route('logs.index'))->assertOk()->json('logs');
        foreach ($logs as $log) {
            $this->assertSame($log['record_type'] === 'vehicle_event' && $log['id'] === $first->id ? OccurrenceMedia::url($crop) : null, $log['plate_image_url']);
        }
        $this->assertNotNull($missing->plate_image_url); // The model's generic placeholder must not become evidence.
        $this->get(route('logs.index'))->assertSee('No plate image')->assertDontSee('plate-placeholder.svg');
    }

    public function test_staff_correction_retry_and_successive_movements_retain_their_own_crops(): void
    {
        Vehicle::create(['plate_number' => '395ISX', 'category' => 'faculty_staff', 'vehicle_type' => 'Car', 'status' => 'active'])
            ->forceFill(['created_at' => now()->subDay()])->save();
        $service = app(VisitorRecordService::class);
        $identity = [];
        foreach (['IN', 'OUT'] as $index => $direction) {
            $at = now()->subMinutes(5 - $index);
            $key = 'crop-correction-'.$index;
            $this->withHeader('X-Api-Key', 'test-detector-key')->postJson(route('api.integration.crossings'), [
                'external_event_key' => $key, 'camera_role' => 'gate-1', 'event_time' => $at->toIso8601String(),
                'direction' => $direction, 'snapshot' => UploadedFile::fake()->image('vehicle.jpg'),
            ])->assertCreated();
            $reading = ['external_event_key' => $key, 'camera_role' => 'gate-1', 'event_time' => $at->toIso8601String(),
                'plate_status' => 'read', 'plate_number' => '3951SX', 'plate_confidence' => .95];
            $service->applyPlateReading($reading, UploadedFile::fake()->image('crop.jpg'));
            $record = VisitorRecord::where('external_event_key', $key)->sole();
            $path = $record->plate_image_path;
            $url = route('visitors.records.review', $record);
            $this->patchJson($url, ['action' => 'correct_plate', 'plate' => '395ISX'])->assertOk()->assertJsonPath('registration_status', 'registered');
            $event = $record->fresh()->movementEvent;
            $this->assertNotNull($event);
            $before = $event->only(['id', 'event_time', 'event_type', 'camera_id', 'vehicle_image_path', 'plate_image_path']);
            $this->assertNotNull($before['vehicle_image_path']);
            $this->assertSame($path, $before['plate_image_path']);
            $this->patchJson($url, ['action' => 'correct_plate', 'plate' => '395ISX'])->assertOk();
            $service->applyPlateReading($reading);
            $this->assertEquals($before, $event->fresh()->only(array_keys($before)));
            $this->assertSame($path, $record->fresh()->plate_image_path);
            $identity[$event->id] = OccurrenceMedia::url($path);
        }
        $this->assertDatabaseCount('vehicle_events', 2);
        $logs = $this->getJson(route('logs.index', ['plate_text' => '395ISX']))->assertOk()->json('logs');
        $this->assertCount(2, $logs);
        $this->assertCount(2, array_unique(array_values($identity)));
        foreach ($logs as $log) {
            $this->assertSame('vehicle_event', $log['record_type']);
            $this->assertSame('395ISX', $log['plate_number']);
            $this->assertSame($identity[$log['id']], $log['plate_image_url']);
        }
        $rows = $this->get(route('logs.print', ['plate_text' => '395ISX', 'snapshots' => 1]))->assertOk()->viewData('rows');
        $this->assertEqualsCanonicalizing(array_values($identity), $rows->pluck('plate_image_url')->all());
    }

    public function test_existing_public_crop_paths_remain_usable_without_rewriting_media(): void
    {
        $path = 'legacy/plate-395ISX.jpg';
        Storage::disk('public')->put($path, UploadedFile::fake()->image('legacy.jpg')->getContent());
        $event = VehicleEvent::create(['event_type' => 'ENTRY', 'event_status' => 'completed', 'event_origin' => 'manual',
            'plate_text' => '395ISX', 'event_time' => now(), 'plate_image_path' => $path]);
        $url = Storage::disk('public')->url($path);
        $this->getJson(route('logs.index'))->assertOk()->assertJsonPath('logs.0.plate_image_url', $url);
        $this->get(route('logs.print', ['snapshots' => 1]))->assertOk()->assertSee('src="'.$url.'"', false);
        $this->assertSame($path, $event->fresh()->plate_image_path);
    }

    public function test_images_follow_the_full_filtered_report_without_changing_csv_or_permissions(): void
    {
        $crop = $this->image();
        foreach (range(1, 18) as $index) {
            VehicleEvent::create(['event_type' => 'ENTRY', 'event_status' => 'completed', 'event_origin' => 'manual',
                'plate_text' => 'IMG'.str_pad((string) $index, 4, '0', STR_PAD_LEFT), 'camera_id' => Camera::forRole('gate-1')->value('id'),
                'event_time' => '2026-11-03 08:30:00', 'plate_image_path' => $crop]);
        }
        $filters = ['date_from' => '2026-11-03', 'date_to' => '2026-11-03', 'time_from' => '08:00', 'time_to' => '09:00', 'gate' => 'gate-1', 'plate_text' => 'IMG', 'page' => 2];
        $this->getJson(route('logs.index', $filters))->assertJsonCount(3, 'logs')->assertJsonPath('total', 18);
        $print = $this->get(route('logs.print', $filters + ['snapshots' => 1]))->assertOk();
        $this->assertCount(18, $print->viewData('rows'));
        $this->assertSame(array_fill(0, 18, OccurrenceMedia::url($crop)), $print->viewData('rows')->pluck('plate_image_url')->all());
        $csv = $this->get(route('vehicle-events.export.csv', $filters))->assertOk()->streamedContent();
        $lines = array_map(str_getcsv(...), array_filter(explode("\n", trim($csv))));
        $this->assertCount(19, $lines);
        $this->assertCount(14, $lines[0]);
        $this->assertStringNotContainsString('/evidence/', $csv);
        $this->assertEqualsCanonicalizing($print->viewData('rows')->pluck('plate')->all(), array_column(array_slice($lines, 1), 3));
        $this->actingAs(User::factory()->create(['role' => 'guard']));
        $this->getJson(route('logs.index'))->assertForbidden();
        $this->getJson(route('logs.print', ['snapshots' => 1]))->assertForbidden();
        auth()->forgetGuards();
        $this->app['session']->flush();
        $this->getJson(OccurrenceMedia::url($crop))->assertUnauthorized();
    }
}
