<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Windows install kit (Phase 2): every bundled runtime and model is pinned,
 * and development fetches the big files instead of keeping them in git.
 */
class WindowsKitBundleTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function manifest(): array
    {
        return json_decode((string) File::get(base_path('build/windows/runtimes.json')), true);
    }

    public function test_every_download_is_pinned_and_go2rtc_matches_the_live_view_config(): void
    {
        $manifest = $this->manifest();

        foreach (['runtimes', 'models', 'development'] as $group) {
            foreach ($manifest[$group] as $name => $entry) {
                $this->assertNotEmpty($entry['urls'], $name);
                foreach ($entry['urls'] as $url) {
                    $this->assertStringStartsWith('https://', $url, $name);
                }
                $this->assertTrue(
                    (isset($entry['sha256']) && preg_match('/^[0-9a-f]{64}$/', $entry['sha256']) === 1) || filled($entry['signed_by'] ?? null),
                    "$name needs a SHA-256 or a signer"
                );
            }
        }

        $this->assertSame(config('monitoring.live.bundles.win64.sha256'), $manifest['runtimes']['go2rtc']['sha256']);
        $this->assertSame(config('monitoring.live.bundles.mac_arm64.sha256'), $manifest['development']['go2rtc_mac_arm64']['sha256']);
        $this->assertSame(['php', 'caddy', 'python', 'go2rtc', 'nssm', 'vc_redist'], array_keys($manifest['runtimes']));
    }

    public function test_the_kit_templates_have_the_extensions_and_the_php_workers(): void
    {
        $ini = File::get(base_path('build/windows/templates/php.ini.template'));
        foreach (['curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3', 'zip'] as $extension) {
            $this->assertStringContainsString("extension = $extension", $ini);
        }
        $this->assertStringContainsString('display_errors = Off', $ini);
        $this->assertStringContainsString('date.timezone = Asia/Manila', $ini);

        $caddy = File::get(base_path('build/windows/templates/Caddyfile.template'));
        $this->assertStringContainsString('php_fastcgi 127.0.0.1:9001 127.0.0.1:9002 127.0.0.1:9003 127.0.0.1:9004', $caddy);
        $this->assertStringContainsString(':{{WEB_PORT}}', $caddy);
        $this->assertStringContainsString('root * "{{INSTALL_DIR}}/app/public"', $caddy);
    }

    public function test_runtime_fetch_checks_the_hash_and_refuses_a_wrong_file(): void
    {
        $root = sys_get_temp_dir().'/runtime-fetch-test-'.uniqid();
        File::ensureDirectoryExists($root.'/build/windows');
        $good = 'model bytes';
        File::put($root.'/build/windows/runtimes.json', json_encode([
            'models' => [
                'good.pt' => ['urls' => ['https://example.test/good.pt'], 'sha256' => hash('sha256', $good), 'type' => 'file', 'target' => 'models/good.pt'],
                'bad.pt' => ['urls' => ['https://example.test/bad.pt'], 'sha256' => hash('sha256', 'something else'), 'type' => 'file', 'target' => 'models/bad.pt'],
            ],
            'development' => [],
        ]));
        $this->app->setBasePath($root);
        $this->app->useStoragePath($root.'/storage');
        Http::fake(fn ($request) => Http::response(str_contains((string) $request->url(), 'good') ? $good : 'tampered'));

        $this->artisan('runtime:fetch')
            ->expectsOutputToContain('downloaded and checked')
            ->expectsOutputToContain('does not match runtimes.json')
            ->assertFailed();

        $this->assertSame($good, File::get($root.'/models/good.pt'));
        $this->assertFileDoesNotExist($root.'/models/bad.pt');
        $this->artisan('runtime:fetch')->expectsOutputToContain('already here');

        File::deleteDirectory($root);
    }
}
