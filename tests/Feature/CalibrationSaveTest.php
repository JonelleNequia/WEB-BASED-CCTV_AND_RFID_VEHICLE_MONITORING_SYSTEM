<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CalibrationSaveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One camera's zone and line are saved; calibration work: no browser camera details.
     */
    public function test_calibration_endpoint_saves_mask_and_line_for_a_camera(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $camera = Camera::query()->forRole('gate-1')->firstOrFail();

        $this->actingAs($user)
            ->putJson(route('calibration.update'), [
                'camera_id' => $camera->id,
                'calibration_mask' => [
                    ['x' => 0.15, 'y' => 0.20],
                    ['x' => 0.65, 'y' => 0.20],
                    ['x' => 0.72, 'y' => 0.55],
                    ['x' => 0.10, 'y' => 0.55],
                ],
                'calibration_line' => [
                    'x1' => 0.10,
                    'y1' => 0.80,
                    'x2' => 0.90,
                    'y2' => 0.80,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('camera.camera_role', 'gate-1');

        $camera->refresh();

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('cameras', 'browser_device_id'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('cameras', 'browser_label'));
        $this->assertSame([
            ['x' => 0.15, 'y' => 0.20],
            ['x' => 0.65, 'y' => 0.20],
            ['x' => 0.72, 'y' => 0.55],
            ['x' => 0.10, 'y' => 0.55],
        ], $camera->calibration_mask_json);
        $this->assertSame([
            'x1' => 0.10,
            'y1' => 0.80,
            'x2' => 0.90,
            'y2' => 0.80,
            'in_side' => 1, // Phase 2: IN side, not flipped
        ], $camera->calibration_line_json);
    }

    public function test_calibration_heartbeat_keeps_detector_camera_runtime_active(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        File::delete(app(\App\Services\DetectorRuntimeService::class)->stationActivityPath());

        $this->actingAs($user)
            ->getJson(route('calibration.heartbeat'))
            ->assertOk()
            ->assertJsonPath('runtime.camera_power_mode', 'active');

        $activity = json_decode((string) File::get(app(\App\Services\DetectorRuntimeService::class)->stationActivityPath()), true);

        $this->assertArrayHasKey('gate-1', $activity['locations']);
        $this->assertArrayHasKey('gate-2', $activity['locations']);
    }
}
