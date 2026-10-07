<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Calibration editor: its JavaScript tests (tests/js, node --test) run with
 * the PHP suite, so "php artisan test" checks dragging, adding and removing
 * points, undo / redo and the warnings too. Skipped where Node is missing.
 */
class CalibrationEditorScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_editor_javascript_tests_pass(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node.js is not installed.');
        }

        $process = new Process([$node, '--test', 'tests/js/calibration-editor.test.mjs'], base_path());
        $process->setTimeout(60)->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
    }

    public function test_the_page_loads_the_editor_before_the_page_script(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $admin = \App\Models\User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        \App\Models\Camera::query()->forRole('gate-1')->update(['source_type' => 'rtsp', 'source_value' => 'rtsp://198.51.100.20:554/stream2']);

        $html = $this->actingAs($admin)->get(route('settings.index', ['tab' => 'calibration', 'gate' => 'gate-1']))->assertOk()->getContent();

        $editor = strpos($html, 'js/calibration-editor.js?v=');
        $page = strpos($html, 'js/calibration-page.js?v=');
        $this->assertNotFalse($editor);
        $this->assertGreaterThan($editor, $page);
        foreach (['data-tool="mask"', 'data-tool="line"', 'data-done', 'data-problems', 'data-point-menu', 'Remove point'] as $part) {
            $this->assertStringContainsString($part, $html);
        }
    }

    public function test_the_page_has_the_controls_steps_and_detection_toggle(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $admin = \App\Models\User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        \App\Models\Camera::query()->forRole('gate-1')->update(['source_type' => 'rtsp', 'source_value' => 'rtsp://198.51.100.20:554/stream2']);
        \App\Models\VehicleCrossing::query()->create([
            'gate' => 'gate-1', 'direction' => 'IN', 'crossed_at' => now(), 'track_id' => 7, 'vehicle_type' => 'motorcycle', 'external_event_key' => 'editor-1',
        ]);

        $html = $this->actingAs($admin)->get(route('settings.index', ['tab' => 'calibration', 'gate' => 'gate-1']))->assertOk()->getContent();

        foreach (['data-undo', 'data-redo', 'data-reset', 'data-discard', 'data-unsaved', 'Reset to saved', 'Discard changes',
            'class="calibration-steps"', 'data-overlay-toggle data-overlay-key="calibration.overlay" data-overlay-default="off"'] as $part) {
            $this->assertStringContainsString($part, $html);
        }
        // Recent crossings right under the video, with the vehicle type.
        $this->assertLessThan(strpos($html, 'calibration-detail-grid'), strpos($html, 'data-crossings'));
        $this->assertGreaterThan(strpos($html, 'data-calibration-canvas'), strpos($html, 'data-crossings'));
        $this->assertStringContainsString('Motorcycle', $html);

        $this->actingAs($admin)->getJson(route('calibration.heartbeat'))
            ->assertJsonPath('crossings.gate-1.0.type_label', 'Motorcycle')
            ->assertJsonPath('gates.gate-1.calibration.mask', null);
    }

    public function test_the_heartbeat_has_the_saved_calibration_for_reset_to_saved(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $admin = \App\Models\User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $camera = \App\Models\Camera::query()->forRole('gate-1')->firstOrFail();
        $mask = [['x' => 0.1, 'y' => 0.1], ['x' => 0.9, 'y' => 0.1], ['x' => 0.5, 'y' => 0.9]];

        $this->actingAs($admin)->putJson(route('calibration.update'), [
            'camera_id' => $camera->id, 'calibration_mask' => $mask,
            'calibration_line' => ['x1' => 0.2, 'y1' => 0.5, 'x2' => 0.8, 'y2' => 0.5, 'in_side' => -1],
        ])->assertOk();

        $this->actingAs($admin)->getJson(route('calibration.heartbeat'))
            ->assertJsonPath('gates.gate-1.calibration.mask', $mask)
            ->assertJsonPath('gates.gate-1.calibration.line.in_side', -1);
        // Saving also writes the detector's runtime file right away (it re-reads it every frame).
        $runtime = json_decode((string) \Illuminate\Support\Facades\File::get(app(\App\Services\SettingsService::class)->cameraRuntimeConfigPath()), true);
        $this->assertSame($mask, $runtime['cameras']['gate-1']['calibration_mask']);
        $this->assertSame(-1, $runtime['cameras']['gate-1']['calibration_line']['in_side']);
    }
}
