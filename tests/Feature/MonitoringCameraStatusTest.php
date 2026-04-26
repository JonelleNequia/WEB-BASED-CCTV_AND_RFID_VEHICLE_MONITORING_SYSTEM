<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringCameraStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure the monitoring page renders the dual browser-camera layout.
     */
    public function test_monitoring_page_displays_dual_camera_panels(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        $this->actingAs($user)
            ->get(route('monitoring.index'))
            ->assertOk()
            ->assertSee('Entrance Camera')
            ->assertSee('Exit Camera')
            ->assertSee('Camera Source')
            ->assertSee('Not connected');
    }

    /**
     * Ensure browser connection state can be synced back into the camera record.
     */
    public function test_monitoring_state_sync_route_updates_camera_connection_state(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $camera = Camera::query()->forRole('exit')->firstOrFail();

        $this->actingAs($user)
            ->putJson(route('camera-browser.state'), [
                'camera_id' => $camera->id,
                'browser_device_id' => 'device-exit-001',
                'browser_label' => 'External Webcam',
                'last_connection_status' => 'not_connected',
                'last_connection_message' => 'Exit webcam is unplugged.',
            ])
            ->assertOk()
            ->assertJsonPath('camera.camera_role', 'exit')
            ->assertJsonPath('camera.last_connection_status', 'not_connected');

        $camera->refresh();

        $this->assertSame('device-exit-001', $camera->browser_device_id);
        $this->assertSame('External Webcam', $camera->browser_label);
        $this->assertSame('not_connected', $camera->last_connection_status);
        $this->assertSame('Exit webcam is unplugged.', $camera->last_connection_message);
    }
}
