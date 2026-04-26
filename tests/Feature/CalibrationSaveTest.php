<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalibrationSaveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure one camera's browser calibration can be saved through the calibration endpoint.
     */
    public function test_calibration_endpoint_saves_mask_and_line_for_a_camera(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $camera = Camera::query()->forRole('entrance')->firstOrFail();

        $this->actingAs($user)
            ->putJson(route('calibration.update'), [
                'camera_id' => $camera->id,
                'browser_device_id' => 'device-entrance-001',
                'browser_label' => 'Built-in Webcam',
                'last_connection_status' => 'connected',
                'last_connection_message' => 'Browser preview connected.',
                'calibration_mask' => [
                    'x' => 0.15,
                    'y' => 0.20,
                    'width' => 0.50,
                    'height' => 0.35,
                ],
                'calibration_line' => [
                    'x1' => 0.10,
                    'y1' => 0.80,
                    'x2' => 0.90,
                    'y2' => 0.80,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('camera.camera_role', 'entrance');

        $camera->refresh();

        $this->assertSame('device-entrance-001', $camera->browser_device_id);
        $this->assertSame('Built-in Webcam', $camera->browser_label);
        $this->assertSame('connected', $camera->last_connection_status);
        $this->assertSame([
            'x' => 0.15,
            'y' => 0.20,
            'width' => 0.50,
            'height' => 0.35,
        ], $camera->calibration_mask_json);
        $this->assertSame([
            'x1' => 0.10,
            'y1' => 0.80,
            'x2' => 0.90,
            'y2' => 0.80,
        ], $camera->calibration_line_json);
        $this->assertNotNull($camera->last_connected_at);
    }
}
