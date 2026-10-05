<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CameraFiles;
use App\Support\DetectionStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A1 (detection): one line per gate saying what detection is doing, why,
 * and what to do; the detector restarts by itself without an open page.
 */
class DetectionPipelineStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::delete(CameraFiles::statusPath());

        parent::tearDown();
    }

    public function test_system_status_explains_each_gate_with_a_next_step(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();

        File::ensureDirectoryExists(CameraFiles::directory());
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'cameras' => [
                'gate-1' => ['camera_running' => true, 'detection_status' => [
                    'code' => 'running', 'label' => 'Running', 'message' => 'Watching for vehicles (7.6 checks per second).', 'next_step' => '', 'retry_in' => null,
                ]],
                'gate-2' => ['camera_running' => false, 'retry_count' => 4, 'offline_since' => now()->subMinutes(3)->toIso8601String(), 'detection_status' => [
                    'code' => 'camera_offline', 'label' => 'Camera offline', 'message' => 'The camera is not reachable from this PC (Host is down).',
                    'next_step' => "Check the camera's LAN cable and power.", 'retry_in' => 16,
                ]],
            ],
        ]));

        $html = $this->actingAs($admin)->get(route('settings.index', ['tab' => 'status']))->assertOk()->getContent();

        $this->assertStringContainsString('data-gate-detection="running"', $html);
        $this->assertStringContainsString('Watching for vehicles (7.6 checks per second).', $html);
        $this->assertStringContainsString('data-gate-detection="camera_offline"', $html);
        $this->assertStringContainsString('Next try in 16 s.', $html);
        $this->assertStringContainsString('→ Check the camera&#039;s LAN cable and power.', $html);
        $this->assertStringContainsString('Reconnect attempts', $html);
        $this->assertStringNotContainsString('Detection Ready', $html);
        $this->assertStringNotContainsString('Not running</strong>', $html);
    }

    public function test_detector_off_and_older_status_files_still_get_a_line(): void
    {
        $this->assertSame('detector_off', DetectionStatus::forGate(['service_running' => false], 'gate-1')['code']);
        $this->assertSame(['running', 'success'], array_values(array_intersect_key(DetectionStatus::forGate([
            'service_running' => true,
            'cameras' => ['gate-1' => ['camera_running' => true, 'detection' => ['detection_fps' => 7.8]]],
        ], 'gate-1'), ['code' => 0, 'tone' => 0])));
        $this->assertSame('critical', DetectionStatus::forGate([
            'service_running' => true,
            'cameras' => ['gate-1' => ['camera_running' => false, 'last_error' => 'The camera did not answer in time. Check it.']],
        ], 'gate-1')['tone']);
    }

    public function test_the_detector_is_restarted_every_minute_without_an_open_page(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command.' '.$event->expression);

        $this->assertTrue($events->contains(fn (string $line) => str_contains($line, 'detector:start') && str_ends_with($line, '* * * * *')));
    }
}
