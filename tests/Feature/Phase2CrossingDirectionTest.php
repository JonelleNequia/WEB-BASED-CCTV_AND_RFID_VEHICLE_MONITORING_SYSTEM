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

    public function test_calibration_saves_the_gate_direction_and_exports_it_to_the_detector(): void
    {
        $camera = Camera::query()->forRole('gate-1')->firstOrFail();
        $save = fn (array $data) => $this->actingAs($this->admin)->putJson(route('calibration.update'), [
            'camera_id' => $camera->id,
            'calibration_mask' => [['x' => 0, 'y' => 0.3], ['x' => 1, 'y' => 0.3], ['x' => 1, 'y' => 1]],
        ] + $data);
        $exported = fn () => json_decode(File::get(CameraFiles::path('camera_runtime_config.json')), true)['cameras']['gate-1'];

        // ROI work: only the zone is drawn; a vehicle moving away from the camera is IN or OUT here.
        $save(['away_direction' => 'OUT'])->assertOk()->assertJsonPath('camera.away_direction', 'OUT');
        $this->assertSame(['OUT', 0.15], [$exported()['away_direction'], $exported()['pass_min_move']]);
        $this->assertArrayNotHasKey('calibration_direction', $exported());
        $this->assertStringEndsWith('/api/v1/integration/crossings', json_decode(File::get(CameraFiles::path('camera_runtime_config.json')), true)['system_settings']['crossing_url']);

        $save([])->assertOk()->assertJsonPath('camera.away_direction', 'OUT');     // the zone only: the direction stays
        $save(['away_direction' => null])->assertOk()->assertJsonPath('camera.away_direction', null);
        $this->assertNull($exported()['away_direction']);
        $save(['away_direction' => 'UP'])->assertUnprocessable();
    }

    public function test_a_gate_without_its_direction_warns_on_its_card(): void
    {
        Camera::query()->forRole('gate-1')->update(['calibration_mask_json' => json_encode([['x' => 0, 'y' => 0.3], ['x' => 1, 'y' => 0.3], ['x' => 1, 'y' => 1]])]);
        \App\Models\Gate::query()->where('code', 'gate-1')->update(['away_direction' => null]);

        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))
            ->assertOk()
            ->assertSee('Zone set · IN / OUT not set')
            ->assertSee('Vehicles are still Detected and Recorded, but as &quot;Direction unknown&quot; (registered ones use their state) until IN / OUT is set.', false)
            ->assertSee('data-away-warning', false);

        \App\Models\Gate::query()->where('code', 'gate-1')->update(['away_direction' => 'IN']);
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'gates']))
            // Gate direction work, Phase A: the mapping on the card.
            ->assertOk()->assertSee('Zone set · Papalayo = IN · Papalapit = OUT');
    }

    public function test_the_minimum_movement_is_set_per_gate_under_advanced_timing(): void
    {
        $this->actingAs($this->admin)->put(route('settings.update'), ['section' => 'timing', 'pass_min_move' => ['gate-1' => 30, 'gate-2' => null]])
            ->assertSessionHasNoErrors();
        $config = json_decode(File::get(CameraFiles::path('camera_runtime_config.json')), true);
        $this->assertSame([0.3, 0.15], [$config['cameras']['gate-1']['pass_min_move'], $config['cameras']['gate-2']['pass_min_move']]);

        $this->actingAs($this->admin)->put(route('settings.update'), ['section' => 'timing', 'pass_min_move' => ['gate-1' => 90]])
            ->assertSessionHasErrors('pass_min_move.gate-1');
        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'timing']))->assertOk()->assertSee('IN / OUT: minimum movement');

        $this->actingAs($this->admin)->post(route('settings.restore-defaults'), ['section' => 'timing']);
        $this->assertNull(\App\Models\Gate::query()->where('code', 'gate-1')->value('pass_min_move'));
    }

    public function test_migration_turns_the_saved_arrow_or_line_into_the_gate_setting_and_removes_them(): void
    {
        $migration = require database_path('migrations/2026_10_12_000002_gate_away_direction.php');

        // The IN arrow pointing down the picture (toward the camera): away from it is OUT.
        $this->assertSame('OUT', $migration::awayFromArrow(['x1' => 0.5, 'y1' => 0.2, 'x2' => 0.5, 'y2' => 0.6]));
        $this->assertSame('IN', $migration::awayFromArrow(['x1' => 0.5, 'y1' => 0.86, 'x2' => 0.53, 'y2' => 0.46]));
        $this->assertNull($migration::awayFromArrow(['x1' => 0.1, 'y1' => 0.5, 'x2' => 0.9, 'y2' => 0.52]));   // sideways
        // An older trigger line: its IN side (+1 = below a line drawn left to right).
        $line = ['x1' => 0.1, 'y1' => 0.7, 'x2' => 0.9, 'y2' => 0.7];
        $this->assertSame('OUT', $migration::awayFromSaved((object) ['calibration_line_json' => json_encode($line + ['in_side' => 1])]));
        $this->assertSame('IN', $migration::awayFromSaved((object) ['calibration_line_json' => json_encode($line + ['in_side' => -1])]));
        $this->assertNull($migration::awayFromSaved((object) []));

        $migration->down();
        DB::table('cameras')->where('camera_role', 'gate-1')->update(['calibration_direction_json' => json_encode(['x1' => 0.5, 'y1' => 0.86, 'x2' => 0.53, 'y2' => 0.46])]);
        DB::table('cameras')->where('camera_role', 'gate-2')->update(['calibration_line_json' => json_encode($line + ['in_side' => 1])]);
        $migration->up();

        $this->assertSame(['IN', 'OUT'], [
            \App\Models\Gate::query()->where('code', 'gate-1')->value('away_direction'),
            \App\Models\Gate::query()->where('code', 'gate-2')->value('away_direction'),
        ]);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('cameras', 'calibration_line_json'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('cameras', 'calibration_direction_json'));
    }

    public function test_calibration_page_has_the_direction_setting_and_recent_records(): void
    {
        Camera::query()->forRole('gate-1')->update(['source_type' => 'rtsp', 'source_value' => 'rtsp://198.51.100.20:554/stream2']);
        VehicleCrossing::query()->create([
            'gate' => 'gate-1', 'direction' => 'IN', 'direction_reason' => 'moved across the line',
            'crossed_at' => now(), 'track_id' => 42, 'confidence' => 0.91, 'external_event_key' => 'page-1',
        ]);

        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'calibration']))
            ->assertOk()
            ->assertSee('Kapag papalayo ang sasakyan sa camera, ito ay:')
            ->assertSee('Papasok sa campus')
            ->assertSee('Palabas ng campus')
            ->assertSee('Parehong IN at OUT ay naitatala sa gate na ito.')
            ->assertSee('calibration-away-diagram', false)
            ->assertDontSee('Flip IN')
            ->assertSee('Recent records')
            ->assertSee('track #42');

        $this->actingAs($this->admin)->getJson(route('calibration.heartbeat'))
            ->assertOk()
            ->assertJsonPath('crossings.gate-1.0.direction', 'IN')
            ->assertJsonPath('crossings.gate-1.0.track_id', 42)
            ->assertJsonPath('crossings.gate-2', []);
    }
}
