<?php

namespace Tests\Feature;

use App\Models\VehicleCrossing;
use App\Services\BackupService;
use App\Support\CameraFiles;
use Database\Seeders\InstallSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Windows install kit, Phase 3: backup:run / backup:restore on a real
 * SQLite file (VACUUM INTO and the file swap need one; no RefreshDatabase).
 */
class BackupRestoreTest extends TestCase
{
    protected string $work;

    protected function setUp(): void
    {
        parent::setUp();

        $this->work = sys_get_temp_dir().'/vms-backup-test-'.uniqid();
        File::ensureDirectoryExists($this->work);
        touch($this->work.'/database.sqlite');
        config(['database.connections.sqlite.database' => $this->work.'/database.sqlite']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->seed(InstallSeeder::class);
        // Restoring the APP_KEY writes .env: this test's own, never the project's.
        File::put($this->work.'/.env', "APP_NAME=x\nAPP_KEY=base64:current\n");
        $this->app->useEnvironmentPath($this->work);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        File::deleteDirectory(CameraFiles::path('frames'));

        parent::tearDown();
    }

    public function test_a_backup_restores_the_database_snapshots_and_app_key(): void
    {
        $work = $this->work;
        VehicleCrossing::query()->create(['gate' => 'gate-1', 'direction' => 'IN', 'crossed_at' => now(), 'external_event_key' => 'kept-1']);
        File::ensureDirectoryExists(CameraFiles::path('frames'));
        File::put(CameraFiles::path('frames/gate-1_latest_frame.jpg'), 'picture');
        config(['app.key' => 'base64:from-the-backup']);

        $this->artisan('backup:run', ['--to' => $work.'/backups'])->assertSuccessful();
        $zip = app(BackupService::class)->list($work.'/backups')[0]['path'];
        $this->assertMatchesRegularExpression('/PHILCST-backup-\d{8}-\d{6}\.zip$/', $zip);

        // Things change (or a new PC with a new key), then the backup comes back.
        VehicleCrossing::query()->delete();
        File::put(CameraFiles::path('frames/gate-1_latest_frame.jpg'), 'changed');
        config(['app.key' => 'base64:current']);

        $this->artisan('backup:restore', ['file' => $zip, '--force' => true, '--no-safety-backup' => true])
            ->expectsOutputToContain('APP_KEY restored')
            ->assertSuccessful();

        DB::purge('sqlite');
        $this->assertSame(['kept-1'], VehicleCrossing::query()->pluck('external_event_key')->all());
        $this->assertSame('picture', File::get(CameraFiles::path('frames/gate-1_latest_frame.jpg')));
        $this->assertStringContainsString('APP_KEY=base64:from-the-backup', File::get($work.'/.env'));
        $this->assertStringContainsString('APP_NAME=x', File::get($work.'/.env'));

        $this->artisan('backup:restore', ['file' => __FILE__, '--force' => true])->assertFailed();
    }

}
