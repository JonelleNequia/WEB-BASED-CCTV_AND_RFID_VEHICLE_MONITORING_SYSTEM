<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use App\Models\VehicleCrossing;
use App\Support\CameraFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 2 (visitor model): direction from the CCTV.
 */
class Phase2CrossingDirectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    public function test_detector_crossing_is_stored_once_with_gate_direction_time_track_snapshot_and_confidence(): void
    {
        Storage::fake('public');
        $payload = [
            'external_event_key' => 'gate-1-track-7-1000',
            'camera_role' => 'gate-1',
            'direction' => 'OUT',
            'direction_reason' => 'crossed the line',
            'event_time' => now()->toIso8601String(),
            'track_id' => 7,
            'confidence' => 0.83,
            'detected_vehicle_type' => 'Car',
            'detection_metadata' => json_encode(['in_side' => 1, 'rfid_status' => 'no_pass']),
        ];

        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])
            ->post(route('api.integration.crossings'), $payload + ['snapshot' => UploadedFile::fake()->image('crossing.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('crossing.direction', 'OUT');

        $crossing = VehicleCrossing::query()->sole();
        $this->assertSame(['gate-1', 'OUT', 7, 0.83, 'Car'], [$crossing->gate, $crossing->direction, $crossing->track_id, $crossing->confidence, $crossing->vehicle_type]);
        $this->assertSame(Camera::query()->forRole('gate-1')->value('id'), $crossing->camera_id);
        $this->assertSame('no_pass', $crossing->detection_metadata_json['rfid_status']);
        Storage::disk('public')->assertExists($crossing->snapshot_path);

        // A retry with the same key is not a second crossing.
        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])
            ->postJson(route('api.integration.crossings'), $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);
        $this->assertSame(1, VehicleCrossing::query()->count());
    }

    public function test_unknown_direction_is_kept_and_bad_input_is_rejected(): void
    {
        $base = ['camera_role' => 'entrance', 'event_time' => now()->toIso8601String(), 'track_id' => 3];

        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])
            ->postJson(route('api.integration.crossings'), $base + ['external_event_key' => 'k-1', 'direction' => 'unknown', 'direction_reason' => 'track too short'])
            ->assertCreated();
        $crossing = VehicleCrossing::query()->sole();
        $this->assertSame(['gate-1', 'UNKNOWN', 'Direction unknown'], [$crossing->gate, $crossing->direction, $crossing->directionLabel()]);

        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])
            ->postJson(route('api.integration.crossings'), $base + ['external_event_key' => 'k-2', 'direction' => 'SIDEWAYS'])
            ->assertUnprocessable();
        $this->withHeaders(['X-Api-Key' => 'test-detector-key'])
            ->postJson(route('api.integration.crossings'), ['camera_role' => 'gate-9'] + $base + ['external_event_key' => 'k-3', 'direction' => 'IN'])
            ->assertUnprocessable();
        $this->withHeaders(['X-Api-Key' => 'wrong'])
            ->postJson(route('api.integration.crossings'), $base + ['external_event_key' => 'k-4', 'direction' => 'IN'])
            ->assertUnauthorized();
    }

    public function test_calibration_saves_the_in_arrow_and_exports_it_to_the_detector(): void
    {
        $camera = Camera::query()->forRole('gate-1')->firstOrFail();
        $save = fn (array $arrow) => $this->actingAs($this->admin)->putJson(route('calibration.update'), [
            'camera_id' => $camera->id,
            'calibration_mask' => [['x' => 0, 'y' => 0.3], ['x' => 1, 'y' => 0.3], ['x' => 1, 'y' => 1]],
            'calibration_direction' => $arrow,
        ]);

        // ROI work: the IN arrow (tail -> head); turning it around swaps the ends.
        $up = ['x1' => 0.5, 'y1' => 0.9, 'x2' => 0.5, 'y2' => 0.5];
        $save($up)->assertOk()->assertJsonPath('camera.calibration_direction', $up);
        $config = json_decode(File::get(CameraFiles::path('camera_runtime_config.json')), true);
        $this->assertSame($up, $config['cameras']['gate-1']['calibration_direction']);
        $this->assertSame(0.2, $config['cameras']['gate-1']['pass_min_move']);
        $this->assertStringEndsWith('/api/v1/integration/crossings', $config['system_settings']['crossing_url']);

        $save(['x1' => 0.5, 'y1' => 0.5, 'x2' => 0.5])->assertUnprocessable();
        $save(['x1' => 0.5, 'y1' => 0.5, 'x2' => 0.5, 'y2' => 1.4])->assertUnprocessable();
    }

    public function test_the_minimum_movement_is_set_per_gate_under_advanced_timing(): void
    {
        $this->actingAs($this->admin)->put(route('settings.update'), ['section' => 'timing', 'pass_min_move' => ['gate-1' => 30, 'gate-2' => null]])
            ->assertSessionHasNoErrors();
        $config = json_decode(File::get(CameraFiles::path('camera_runtime_config.json')), true);
        $this->assertSame([0.3, 0.2], [$config['cameras']['gate-1']['pass_min_move'], $config['cameras']['gate-2']['pass_min_move']]);

        $this->actingAs($this->admin)->put(route('settings.update'), ['section' => 'timing', 'pass_min_move' => ['gate-1' => 90]])
            ->assertSessionHasErrors('pass_min_move.gate-1');
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'timing']))->assertOk()->assertSee('IN / OUT: minimum movement');

        $this->actingAs($this->admin)->post(route('settings.restore-defaults'), ['section' => 'timing']);
        $this->assertNull(\App\Models\Gate::query()->where('code', 'gate-1')->value('pass_min_move'));
    }

    public function test_migration_turns_a_saved_trigger_line_into_an_arrow_towards_its_in_side(): void
    {
        $migration = require database_path('migrations/2026_10_12_000001_roi_pass_in_arrow.php');
        $line = ['x1' => 0.1, 'y1' => 0.7, 'x2' => 0.9, 'y2' => 0.7];

        // Side +1 of a line drawn left to right is below it: IN = down the picture.
        $this->assertSame(['x1' => 0.5, 'y1' => 0.5, 'x2' => 0.5, 'y2' => 0.9], $migration::arrowFromLine($line + ['in_side' => 1]));
        $this->assertSame(['x1' => 0.5, 'y1' => 0.9, 'x2' => 0.5, 'y2' => 0.5], $migration::arrowFromLine($line + ['in_side' => -1]));
        $this->assertNull($migration::arrowFromLine(null));

        $migration->down();
        DB::table('cameras')->where('camera_role', 'gate-1')->update(['calibration_line_json' => json_encode($line + ['in_side' => -1])]);
        $migration->up();
        $arrow = Camera::query()->forRole('gate-1')->value('calibration_direction_json');
        $this->assertSame(['x1' => 0.5, 'y1' => 0.9, 'x2' => 0.5, 'y2' => 0.5], is_string($arrow) ? json_decode($arrow, true) : $arrow);
    }

    public function test_migration_gives_existing_lines_the_old_in_side(): void
    {
        $migration = require database_path('migrations/2026_10_01_000003_create_vehicle_crossings.php');
        $migration->down();
        DB::table('cameras')->where('camera_role', 'gate-1')
            ->update(['calibration_line_json' => json_encode(['x1' => 0.1, 'y1' => 0.6, 'x2' => 0.9, 'y2' => 0.6])]);

        $migration->up();

        $line = Camera::query()->forRole('gate-1')->value('calibration_line_json');
        $this->assertSame(1, json_decode(is_string($line) ? $line : json_encode($line), true)['in_side']);
    }

    public function test_calibration_page_has_the_flip_button_and_recent_records(): void
    {
        VehicleCrossing::query()->create([
            'gate' => 'gate-1', 'direction' => 'IN', 'direction_reason' => 'moved across the line',
            'crossed_at' => now(), 'track_id' => 42, 'confidence' => 0.91, 'external_event_key' => 'page-1',
        ]);

        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'calibration']))
            ->assertOk()
            ->assertSee('Flip IN arrow')
            ->assertSee('Recent records')
            ->assertSee('track #42');

        $this->actingAs($this->admin)->getJson(route('calibration.heartbeat'))
            ->assertOk()
            ->assertJsonPath('crossings.gate-1.0.direction', 'IN')
            ->assertJsonPath('crossings.gate-1.0.track_id', 42)
            ->assertJsonPath('crossings.gate-2', []);
    }
}
