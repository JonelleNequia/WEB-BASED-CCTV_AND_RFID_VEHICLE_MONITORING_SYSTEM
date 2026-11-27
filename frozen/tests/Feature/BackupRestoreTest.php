<?php

namespace Tests\Feature;

use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Legacy APIs must not bypass the external owned D5B controller. */
class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_commands_refuse_even_force_without_mutating_records_or_environment(): void
    {
        $path = sys_get_temp_dir().'/d5b-legacy-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', 'SYNTHETIC_SECRET=retained');
        $this->app->useEnvironmentPath($path);
        try {
            $before = DB::table('migrations')->get()->toJson();
            $this->artisan('backup:run', ['--to' => $path])->expectsOutputToContain('D5B')->assertFailed();
            $this->artisan('backup:restore', ['file' => $path.'/incoming.zip', '--force' => true, '--no-safety-backup' => true])
                ->expectsOutputToContain('D5B')->assertFailed();
            $this->assertSame($before, DB::table('migrations')->get()->toJson());
            $this->assertSame('SYNTHETIC_SECRET=retained', File::get($path.'/.env'));
            $this->assertSame(['.env'], array_map(fn ($file) => $file->getFilename(), File::allFiles($path, true)));
        } finally { File::deleteDirectory($path); }
    }

    public function test_direct_service_call_cannot_run_the_old_restore_or_backup(): void
    {
        foreach (['create', 'restore'] as $method) {
            try {
                app(BackupService::class)->$method('/never-used-d5b-path');
                $this->fail('Legacy privileged mutation accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('D5B', $exception->getMessage());
            }
        }
    }

    public function test_owned_regeneration_writes_configuration_without_starting_or_probing_live_services(): void
    {
        $this->seed(\Database\Seeders\InstallSeeder::class);
        $path = sys_get_temp_dir().'/d5b-controlled-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($path.'/coordination');
        $installation = (string) \Illuminate\Support\Str::uuid(); $generation = (string) \Illuminate\Support\Str::uuid();
        $operation = (string) \Illuminate\Support\Str::uuid(); $set = '00000000-0000-0000-0000-000000000000';
        File::put($path.'/identity.json', json_encode(['schema'=>1,'installation'=>$installation,'generation'=>$generation]));
        File::put($path.'/maintenance.json', json_encode(['schema'=>1,'phase'=>'resuming','operation'=>$operation,'recovery_required'=>true]));
        File::put($path.'/recovery-guard.json', json_encode(['schema'=>1,'status'=>'validated']));
        File::put($path.'/recovery-security.json', json_encode(['schema'=>'philcst.recovery-security.v1','installation'=>$installation,'generation'=>$generation,
            'epoch'=>0,'active_set'=>$set,'blocked_by_set'=>[$set=>[]],'lost_uids'=>[],'revoked_update_keys'=>[],'update_generation'=>0]));
        $pdo = new \PDO('sqlite:'.$path.'/coordination/barrier.sqlite'); $pdo->exec('CREATE TABLE barrier(id INTEGER PRIMARY KEY)'); unset($pdo);
        DB::table('delivery_installations')->insert(['id'=>1,'installation'=>$installation,'generation'=>$generation]);
        config(['lifecycle.root'=>$path]); putenv('PHILCST_LIFECYCLE_CONTROL='.$operation);
        $this->mock(\App\Services\Go2rtcService::class, function ($mock): void {
            $mock->shouldNotReceive('ensureRunning');
            $mock->shouldReceive('writeConfig')->once()->andReturn(true);
        });
        try {
            $this->artisan('system:recovery-prepare')->assertSuccessful();
            $this->assertDatabaseCount('vehicle_events', 0);
            $this->assertDatabaseCount('users', 0);
        } finally {
            app(\App\Services\LifecycleBarrier::class)->release(); config(['lifecycle.root'=>null]);
            putenv('PHILCST_LIFECYCLE_CONTROL'); File::deleteDirectory($path);
        }
    }

    public function test_runtime_regeneration_requires_exact_owned_recovery(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Installed owned recovery');
        $this->artisan('system:recovery-prepare');
    }
}
