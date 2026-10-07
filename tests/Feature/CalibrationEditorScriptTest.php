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
}
