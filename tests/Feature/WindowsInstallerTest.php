<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use App\Services\DetectorRuntimeService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InstallSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Windows install kit, Phase 3: installer, scripts and what the app adds for
 * them (backups, first admin, gates list, signed-in detector stream proxy).
 * Addresses from RFC 5737.
 */
class WindowsInstallerTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_the_proxy_every_page_uses_the_same_origin_detector_path(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        config(['monitoring.stream.proxy' => true]);
        $runtime = app(DetectorRuntimeService::class);

        $this->assertSame('/detector/stream/gate-1', $runtime->streamUrlForRole('gate-1', ['cameras' => ['gate-1' => ['stream_url' => 'http://127.0.0.1:8765/stream/gate-1']]], '198.51.100.10'));
        $this->assertSame('/detector/stream/gate-2', $runtime->defaultStreamUrl('gate-2'));

        Camera::query()->forRole('gate-1')->update(['source_type' => 'rtsp', 'source_value' => 'rtsp://198.51.100.20:554/stream2']);
        foreach ([route('gates.kiosk', 'gate-1'), route('settings.index', ['tab' => 'calibration', 'gate' => 'gate-1'])] as $page) {
            $this->actingAs($admin)->get($page)->assertOk()->assertSee('data-mjpeg-url="/detector/stream/gate-1"', false);
        }

        // Without the kit (development): the detector's own port, as before.
        config(['monitoring.stream.proxy' => false]);
        $this->assertStringEndsWith(':8765/stream/gate-1', $runtime->defaultStreamUrl('gate-1'));
    }

    public function test_the_web_server_lets_only_signed_in_users_reach_the_detector(): void
    {
        $this->get(route('live.proxy-auth'))->assertRedirect(route('login'));

        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $this->actingAs($admin)->get(route('live.proxy-auth'))->assertNoContent();

        $caddy = File::get(base_path('build/windows/templates/Caddyfile.template'));
        $this->assertStringContainsString('handle /detector/* {', $caddy);
        $this->assertStringContainsString('uri /live/proxy-auth', $caddy);
        $this->assertStringContainsString('uri strip_prefix /detector', $caddy);
        $this->assertStringContainsString('reverse_proxy 127.0.0.1:8765', $caddy);
    }

    public function test_a_new_install_gets_gates_and_settings_but_no_demo_admin(): void
    {
        $this->seed(InstallSeeder::class);

        $this->assertSame(0, User::query()->count());
        $this->assertTrue(Camera::query()->forRole('gate-1')->exists());

        $this->artisan('system:first-admin', ['--email' => 'admin@school.test', '--json' => true])->assertSuccessful();
        $admin = User::query()->sole();
        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertFalse(Hash::check('password', $admin->password));

        Artisan::call('system:first-admin', ['--json' => true]);
        $this->assertSame(['created' => false, 'email' => 'admin@school.test'], json_decode(trim(Artisan::output()), true));
        $this->assertSame(1, User::query()->count());
    }

    public function test_the_first_admin_password_is_random_and_printed_once(): void
    {
        Artisan::call('system:first-admin', ['--json' => true]);
        $result = json_decode(trim(Artisan::output()), true);

        $this->assertTrue($result['created']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/', $result['password']);
        $this->assertTrue(Hash::check($result['password'], User::query()->sole()->password));
    }

    public function test_gates_list_gives_the_kiosk_page_of_each_gate(): void
    {
        $this->seed(DatabaseSeeder::class);
        Artisan::call('gates:list', ['--json' => true]);
        $gates = json_decode(trim(Artisan::output()), true);

        $this->assertSame(['code' => 'gate-1', 'name' => 'Gate 1', 'kiosk_path' => '/gates/gate-1/kiosk'], $gates[0]);
    }

    public function test_the_installer_runs_the_setup_script_and_its_uninstaller_asks_about_the_data(): void
    {
        $iss = File::get(base_path('build/windows/installer/PHILCST-VMS.iss'));

        foreach (['PrivilegesRequired=admin', 'DefaultDirName=C:\PHILCST-VMS', 'ArchitecturesInstallIn64BitMode=x64compatible', 'MinVersion=10.0',
            'OutputBaseFilename=PHILCST-VMS-Setup-{#AppVersion}', '\app\deploy\windows\install.ps1', '\app\deploy\windows\uninstall.ps1',
            '{param:REMOVEDATA|no}', 'MB_DEFBUTTON2', "' -ComputerName PHILCST-VMS'", "' -SetNetworkPrivate'", 'install-result.txt'] as $part) {
            $this->assertStringContainsString($part, $iss);
        }

        // Every Start Menu entry is a script that exists, and each .bat runs its .ps1.
        preg_match_all('#\\\\app\\\\deploy\\\\windows\\\\([a-z-]+)\.bat#', $iss, $matches);
        $this->assertCount(9, array_unique($matches[1]));
        foreach (array_unique($matches[1]) as $script) {
            $this->assertFileExists(base_path("deploy/windows/$script.ps1"));
            $this->assertStringContainsString("%~dp0$script.ps1", File::get(base_path("deploy/windows/$script.bat")));
        }
    }

    public function test_the_scripts_ship_in_the_release_with_windows_line_ends_and_plain_ascii(): void
    {
        $attributes = File::get(base_path('.gitattributes'));
        $this->assertStringNotContainsString('/deploy export-ignore', $attributes);
        $this->assertStringContainsString('*.ps1 text eol=crlf', $attributes);
        $this->assertStringContainsString('*.bat text eol=crlf', $attributes);
        $this->assertStringContainsString('*.cmd text eol=crlf', $attributes);

        foreach (File::allFiles(base_path('deploy/windows')) as $file) {
            // Windows PowerShell 5.1 reads scripts without a BOM as ANSI.
            $this->assertSame(1, preg_match('//u', $file->getContents()) && ! preg_match('/[^\x00-\x7F]/', $file->getContents()) ? 1 : 0, $file->getRelativePathname().' is not plain ASCII');
        }
    }

    public function test_no_script_calls_a_program_with_stderr_redirected_outside_the_helpers(): void
    {
        // Windows PowerShell 5.1: "& prog 2>&1" under ErrorActionPreference
        // 'Stop' turns the program's first stderr line into a fatal error
        // (the first real install stopped on "nssm stop" of a stopped service).
        foreach (File::allFiles(base_path('deploy/windows')) as $file) {
            if ($file->getExtension() !== 'ps1' || $file->getFilename() === 'common.ps1') {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/2>&1/', $file->getContents(), $file->getFilename().': use Invoke-VmsNative or Invoke-VmsQuiet');
        }
    }

    public function test_the_powershell_functions_pass_their_tests(): void
    {
        $pwsh = (new ExecutableFinder)->find('pwsh');
        if ($pwsh === null) {
            $this->markTestSkipped('PowerShell 7 (pwsh) is not installed.');
        }

        // Every script parses.
        $parse = new Process([$pwsh, '-NoProfile', '-Command', '$bad = 0; Get-ChildItem deploy/windows -Recurse -Filter *.ps1 | ForEach-Object { $t = $null; $e = $null; [System.Management.Automation.Language.Parser]::ParseFile($_.FullName, [ref]$t, [ref]$e) | Out-Null; if ($e) { $bad++; Write-Output "$($_.Name): $($e[0].Message)" } }; exit $bad'], base_path());
        $parse->setTimeout(60)->run();
        $this->assertTrue($parse->isSuccessful(), $parse->getOutput());

        $tests = new Process([$pwsh, '-NoProfile', '-File', 'tests/windows/common.tests.ps1'], base_path());
        $tests->setTimeout(60)->run();
        $this->assertTrue($tests->isSuccessful(), $tests->getOutput().$tests->getErrorOutput());
    }
}
