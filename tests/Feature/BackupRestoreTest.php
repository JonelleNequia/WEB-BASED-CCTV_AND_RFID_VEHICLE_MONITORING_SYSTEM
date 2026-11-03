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

    public function test_backups_retain_deprecated_color_columns_and_historical_relationships(): void
    {
        $event = \App\Models\VehicleEvent::query()->create([
            'event_type' => 'ENTRY', 'event_time' => now(), 'vehicle_type' => 'Car', 'vehicle_color' => 'Legacy event color',
            'detection_metadata_json' => ['vehicle_color' => 'Legacy metadata color'],
        ]);
        $session = \App\Models\ActiveSession::query()->create([
            'entry_event_id' => $event->id, 'entry_time' => now(), 'status' => 'open', 'vehicle_color' => 'Legacy session color',
        ]);
        $profile = \App\Models\PlateProfile::query()->create([
            'plate_key' => 'LEG1234', 'plate_number' => 'LEG 1234', 'vehicle_color' => 'Legacy profile color',
        ]);
        $record = \App\Models\VisitorRecord::query()->create([
            'external_event_key' => 'legacy-color-backup', 'gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now(),
            'plate_profile_id' => $profile->id, 'vehicle_color' => 'Legacy visitor color',
        ]);
        $observation = \App\Models\GuestVehicleObservation::query()->create([
            'location' => 'gate-1', 'observed_at' => now(), 'visitor_record_id' => $record->id,
            'vehicle_color' => 'Legacy observation color',
        ]);
        $models = [$event, $session, $profile, $record, $observation];
        $colors = array_map(fn ($model) => $model->vehicle_color, $models);
        $zip = app(BackupService::class)->create($this->work.'/backups');
        foreach ($models as $model) {
            $model->forceFill(['vehicle_color' => null])->save();
        }
        app(BackupService::class)->restore($zip);
        DB::purge('sqlite');
        foreach ($models as $index => $model) {
            $this->assertSame($colors[$index], $model->fresh()->vehicle_color);
        }
        $this->assertSame(['vehicle_color' => 'Legacy metadata color'], $event->fresh()->detection_metadata_json);
        $this->assertSame($event->id, $session->fresh()->entryEvent->id);
        $this->assertSame($profile->id, $record->fresh()->plateProfile->id);
        $this->assertSame($record->id, $observation->fresh()->visitor_record_id);
    }

}
