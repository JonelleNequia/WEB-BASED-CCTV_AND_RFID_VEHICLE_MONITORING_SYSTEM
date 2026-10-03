<?php

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\RfidScanLog;
use App\Models\RfidTag;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VisitorRecord;
use App\Services\RfidIngestService;
use App\Support\CameraFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fresh start: `system:reset` and Settings › System Status › Reset activity data.
 */
class SystemResetTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // Never touch the real logs, snapshots or backups.
        $this->storage = sys_get_temp_dir().'/system-reset-test-'.uniqid();
        File::ensureDirectoryExists($this->storage.'/logs');
        $this->app->useStoragePath($this->storage);
        Storage::fake('public');
        Storage::fake('local');

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('email', 'admin@philcst.local')->firstOrFail();
        $this->activity();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    public function test_dry_run_lists_what_would_be_removed_and_changes_nothing(): void
    {
        $this->artisan('system:reset', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN: Reset level: activity')
            ->expectsOutputToContain('Nothing was changed.')
            ->assertSuccessful();

        $this->assertSame(2, RfidScanLog::query()->count());
        $this->assertSame(1, VisitorRecord::query()->count());
        Storage::disk('public')->assertExists('visitor_plates/plate.jpg');
    }

    public function test_activity_reset_removes_activity_keeps_setup_and_registry_and_restarts_ids(): void
    {
        $kept = $this->keptCounts();

        $this->artisan('system:reset', ['--force' => true])
            ->expectsOutputToContain('Backup saved: ')
            ->expectsOutputToContain('Reset done. New records start at ID 1.')
            ->assertSuccessful();

        foreach (['vehicle_events', 'rfid_scan_logs', 'visitor_records', 'plate_profiles', 'guest_vehicle_observations', 'active_sessions', 'event_receive_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame($kept, $this->keptCounts());

        // Registered vehicles back to Outside with clean counters; tags not "last scanned".
        $vehicle = Vehicle::query()->where('plate_number', 'RST 1001')->sole();
        $this->assertSame([Vehicle::STATE_OUTSIDE, 0, null], [$vehicle->current_state, (int) $vehicle->entries_today_count, $vehicle->last_seen_at]);
        $this->assertNull(RfidTag::query()->where('uid', 'RST-TAG')->value('last_scanned_at'));

        // Snapshots, logs and status files gone; backup made.
        Storage::disk('public')->assertMissing('visitor_plates/plate.jpg');
        $this->assertSame('', File::get(storage_path('logs/detector-runtime.log')));
        $this->assertFileDoesNotExist(CameraFiles::statusPath());
        $backups = File::directories(storage_path('backups'));
        $this->assertCount(1, $backups);
        $this->assertFileExists($backups[0].'/snapshots.zip');

        // New records start at 1.
        $this->assertSame(1, app(RfidIngestService::class)->ingest(['tag_uid' => 'RST-TAG', 'scan_location' => 'gate-1'])->scanLog->id);
    }

    public function test_full_reset_also_removes_vehicles_and_tags_but_keeps_users_settings_gates_and_cameras(): void
    {
        $this->artisan('system:reset', ['--full' => true, '--force' => true])->assertSuccessful();

        $this->assertSame([0, 0], [Vehicle::query()->count(), RfidTag::query()->count()]);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(['gate-1', 'gate-2'], Gate::codes());
        $this->assertSame(2, DB::table('cameras')->count());
        $this->assertGreaterThan(0, DB::table('system_settings')->count());
    }

    public function test_devices_option_removes_saved_devices_and_assignments_the_unassign_way(): void
    {
        $camera = DB::table('network_devices')->insertGetId(['device_key' => 'AA:BB:CC:00:00:01', 'mac' => 'AA:BB:CC:00:00:01', 'ip' => '192.168.1.126', 'kind' => 'camera', 'created_at' => now(), 'updated_at' => now()]);
        $reader = DB::table('network_devices')->insertGetId(['device_key' => 'AA:BB:CC:00:00:02', 'mac' => 'AA:BB:CC:00:00:02', 'ip' => '192.168.2.116', 'kind' => 'rfid_reader', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('network_devices')->insert(['device_key' => 'ip:192.168.2.116', 'ip' => '192.168.2.116', 'kind' => 'rfid_reader', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('device_assignments')->insert([
            ['station' => 'gate-1', 'role' => 'camera', 'network_device_id' => $camera, 'created_at' => now(), 'updated_at' => now()],
            ['station' => 'gate-1', 'role' => 'reader', 'network_device_id' => $reader, 'created_at' => now(), 'updated_at' => now()],
        ]);
        Gate::query()->where('code', 'gate-1')->update(['reader_type' => 'uhf_ethernet', 'reader_name' => 'Gate 1 UHF Reader']);
        DB::table('cameras')->where('camera_role', 'gate-1')->update(['source_type' => 'rtsp', 'source_value' => 'rtsp://192.168.1.126:554/stream1']);
        File::ensureDirectoryExists(\App\Support\DeviceFiles::directory());
        File::put(\App\Support\DeviceFiles::scanResultPath(), '{"devices": []}');

        // Without --devices they stay.
        $this->artisan('system:reset', ['--force' => true])->assertSuccessful();
        $this->assertSame([2, 3], [DB::table('device_assignments')->count(), DB::table('network_devices')->count()]);

        $this->artisan('system:reset', ['--devices' => true, '--dry-run' => true])
            ->expectsOutputToContain('network_devices (saved devices)')
            ->assertSuccessful();
        $this->assertSame(3, DB::table('network_devices')->count());

        $this->artisan('system:reset', ['--devices' => true, '--force' => true])
            ->expectsOutputToContain('must be assigned again in Settings > Devices')
            ->assertSuccessful();

        $this->assertSame([0, 0], [DB::table('device_assignments')->count(), DB::table('network_devices')->count()]);
        $this->assertFileDoesNotExist(\App\Support\DeviceFiles::scanResultPath());
        // Gate still a UHF gate (waiting for a reader); camera keeps its last address.
        $this->assertSame('uhf_ethernet', Gate::query()->where('code', 'gate-1')->value('reader_type'));
        $this->assertSame('rtsp://192.168.1.126:554/stream1', DB::table('cameras')->where('camera_role', 'gate-1')->value('source_value'));
        // Vehicles and tags untouched by --devices.
        $this->assertSame(1, Vehicle::query()->count());
    }

    public function test_reset_needs_reset_typed_unless_forced(): void
    {
        $this->artisan('system:reset')
            ->expectsQuestion('Type RESET to remove this data', 'reset')
            ->expectsOutputToContain('Cancelled. Nothing was changed.')
            ->assertFailed();
        $this->assertSame(2, RfidScanLog::query()->count());

        $this->artisan('system:reset')
            ->expectsQuestion('Type RESET to remove this data', 'RESET')
            ->assertSuccessful();
        $this->assertSame(0, RfidScanLog::query()->count());
    }

    public function test_admin_resets_activity_from_system_status_with_reset_typed(): void
    {
        $guard = User::query()->create(['name' => 'Guard', 'email' => 'guard.reset@philcst.local', 'password' => Hash::make('password'), 'role' => 'guard']);
        $this->actingAs($guard)->post(route('settings.system.reset-activity'), ['confirm' => 'RESET'])->assertForbidden();

        $this->actingAs($this->admin)->get(route('settings.index', ['tab' => 'status']))
            ->assertOk()->assertSee('Reset activity data')->assertSee('Type <strong>RESET</strong> to confirm', false);

        $this->actingAs($this->admin)->post(route('settings.system.reset-activity'), ['confirm' => 'reset'])
            ->assertSessionHasErrors('confirm');
        $this->assertSame(2, RfidScanLog::query()->count());

        $this->actingAs($this->admin)->post(route('settings.system.reset-activity'), ['confirm' => 'RESET'])
            ->assertRedirect(route('settings.index', ['tab' => 'status']))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Activity data reset') && str_contains($message, 'Backup: '));
        $this->assertSame(0, RfidScanLog::query()->count());
        $this->assertSame(1, Vehicle::query()->count());
    }

    /**
     * Some activity: a registered vehicle inside, scans, a visitor record,
     * files and logs.
     */
    protected function activity(): void
    {
        $vehicle = Vehicle::query()->create(['plate_number' => 'RST 1001', 'vehicle_owner_name' => 'Owner', 'category' => 'faculty_staff', 'vehicle_type' => 'Car']);
        $tag = RfidTag::query()->create(['uid' => 'RST-TAG', 'status' => RfidTag::STATUS_ASSIGNED, 'vehicle_id' => $vehicle->id, 'assigned_at' => now()]);
        $vehicle->forceFill(['rfid_tag_id' => $tag->id, 'rfid_tag_uid' => $tag->uid])->save();

        app(RfidIngestService::class)->ingest(['tag_uid' => 'RST-TAG', 'scan_location' => 'gate-1']);
        app(RfidIngestService::class)->ingest(['tag_uid' => 'UNKNOWN-RST', 'scan_location' => 'gate-1']);
        VisitorRecord::query()->create(['external_event_key' => 'rst-1', 'gate' => 'gate-1', 'direction' => 'IN', 'seen_at' => now(), 'status' => 'active', 'plate_status' => 'unreadable']);

        Storage::disk('public')->put('visitor_plates/plate.jpg', 'jpg');
        File::put(storage_path('logs/detector-runtime.log'), "old detector log\n");
        File::ensureDirectoryExists(dirname(CameraFiles::statusPath()));
        File::put(CameraFiles::statusPath(), '{"service_running": true}');
    }

    /**
     * @return array<string, int>
     */
    protected function keptCounts(): array
    {
        return collect(['users', 'system_settings', 'gates', 'cameras', 'rois', 'device_assignments', 'network_devices', 'vehicles', 'vehicle_rfid_tags'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }
}
