<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\Gate;
use App\Models\User;
use App\Services\DetectorRuntimeService;
use App\Support\CameraFiles;
use App\Support\PythonLauncher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Startup checks: System status says when the detector runs older code than
 * the code on this PC (it never loaded Phase 1-3 because an old process kept
 * running), and when a gate's calibration cannot be used.
 */
class StartupChecksTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
    }

    protected function tearDown(): void
    {
        File::delete(CameraFiles::statusPath());

        parent::tearDown();
    }

    public function test_laravel_and_the_detector_work_out_the_same_code_version(): void
    {
        $python = PythonLauncher::directory().'/.venv/bin/python';
        if (! is_file($python)) {
            $this->markTestSkipped('The detector\'s Python environment is not installed.');
        }

        $process = new Process([$python, '-c', 'import code_version; print(code_version.code_version())'], PythonLauncher::directory());
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame(trim($process->getOutput()), app(DetectorRuntimeService::class)->codeVersion());
    }

    public function test_old_code_is_shown_and_current_code_is_ok(): void
    {
        $runtime = app(DetectorRuntimeService::class);
        $current = $runtime->codeVersion();

        $this->detectorStatus(['code_version' => null]);                  // a detector from before versions were reported
        $this->assertSame('old', $runtime->codeCheck($runtime->readStatus())['state']);
        $this->statusPage()->assertSee('Old detector code is running')->assertSee('a version from before this check');

        $this->detectorStatus(['code_version' => 'abcdef123456']);
        $this->statusPage()->assertSee('Old detector code is running')->assertSee('abcdef123456')->assertSee((string) $current);

        $this->detectorStatus(['code_version' => $current, 'started_at' => now()->toIso8601String()]);
        $this->assertSame('current', $runtime->codeCheck($runtime->readStatus())['state']);
        $this->statusPage()->assertSee('Detector code is current')->assertDontSee('Old detector code');

        $this->detectorStatus(['code_version' => $current, 'updated_at' => now()->subMinutes(5)->toIso8601String()]);
        $this->assertSame('unknown', $runtime->codeCheck($runtime->readStatus())['state']);
    }

    public function test_an_unusable_zone_and_a_missing_in_out_setting_are_shown(): void
    {
        Gate::query()->where('code', 'gate-1')->update(['away_direction' => null]);
        Gate::query()->where('code', 'gate-2')->update(['away_direction' => 'OUT']);
        $this->detectorStatus(['code_version' => app(DetectorRuntimeService::class)->codeVersion()], [
            'gate-1' => ['detection_status' => ['code' => 'zone_invalid', 'label' => 'Zone invalid', 'message' => 'The saved zone has points outside the picture (x=120, y=80; points must be 0-1).', 'next_step' => 'Draw the zone again in Settings › Calibration.']],
        ]);

        $this->statusPage()
            ->assertSee('Gate 1: Zone invalid')
            ->assertSee('points must be 0-1')
            ->assertSee('Gate 1: IN / OUT not set')
            ->assertSee('still detected and recorded, as &quot;Direction unknown&quot;', false)
            ->assertDontSee('Gate 2: IN / OUT not set');
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $cameras
     */
    protected function detectorStatus(array $values, array $cameras = []): void
    {
        File::ensureDirectoryExists(dirname(CameraFiles::statusPath()));
        File::put(CameraFiles::statusPath(), json_encode([
            'service_running' => true,
            'updated_at' => now()->toIso8601String(),
            'pid' => 999999,
            'cameras' => array_replace_recursive(['gate-1' => ['camera_role' => 'gate-1', 'camera_running' => true]], $cameras),
            ...$values,
        ]));
    }

    protected function statusPage(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'status']))->assertOk();
    }
}
