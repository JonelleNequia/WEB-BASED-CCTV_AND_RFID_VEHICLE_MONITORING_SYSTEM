<?php

namespace App\Services;

use App\Models\DeviceAssignment;
use App\Models\Vehicle;
use App\Support\CameraFiles;
use App\Support\DeviceFiles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Fresh start: removes the activity data (and with "full", the Registry)
 * after a dated backup of the database and the snapshots.
 *
 * - activity: vehicle logs, RFID scans, crossings, Unregistered Visitor
 *   records, plate profiles, alerts and old guest / guest pass data,
 *   sessions, received-event logs, snapshots and frames, detector and
 *   device-service logs, cached status files. Registered vehicles go back
 *   to Outside with their counters and "last seen" cleared; tags lose their
 *   "last scanned".
 * - full: activity + registered vehicles and RFID tags.
 *
 * - devices (added to either level): saved devices and the camera / reader
 *   assignments of the gates; the cameras keep their last address as a
 *   manual source, the gates stay UHF gates waiting for a reader. Scan and
 *   assign again in Settings › Devices.
 *
 * Always kept: users, settings, gates, calibration (cameras, zones) and
 * camera credentials (and without "devices", the devices and assignments).
 * IDs of the emptied tables start at 1 again.
 */
class SystemResetService
{
    public const LEVEL_ACTIVITY = 'activity';

    public const LEVEL_FULL = 'full';

    /** Activity tables, emptied in this order. */
    protected const ACTIVITY_TABLES = [
        'active_sessions',
        'visitor_records',
        'plate_profiles',
        'guest_vehicle_observations',
        'vehicle_crossings',
        'vehicle_events',
        'rfid_scan_logs',
        'guest_visits',
        'event_receive_logs',
    ];

    /** Registry tables, emptied too with "full". */
    protected const REGISTRY_TABLES = [
        'legacy_vehicle_categories',
        'vehicles',
        'vehicle_rfid_tags',
    ];

    public function __construct(protected LocalStorageService $localStorageService)
    {
    }

    /**
     * What a reset would remove: rows per table, files per folder.
     *
     * @return list<array{item: string, count: int, kind: string, action: string}>
     */
    public function plan(string $level = self::LEVEL_ACTIVITY, bool $devices = false): array
    {
        $rows = [];

        if ($devices) {
            $rows[] = ['item' => 'device_assignments (camera / reader per gate)', 'count' => (int) DB::table('device_assignments')->count(), 'kind' => 'rows', 'action' => 'unassign'];
            $rows[] = ['item' => 'network_devices (saved devices)', 'count' => (int) DB::table('network_devices')->count(), 'kind' => 'rows', 'action' => 'delete'];
            $rows[] = ['item' => 'device scan result file', 'count' => File::exists(DeviceFiles::scanResultPath()) ? 1 : 0, 'kind' => 'files', 'action' => 'delete'];
        }

        foreach ($this->tables($level) as $table) {
            $rows[] = ['item' => $table, 'count' => (int) DB::table($table)->count(), 'kind' => 'rows', 'action' => 'delete'];
        }

        if ($level === self::LEVEL_ACTIVITY) {
            $rows[] = ['item' => 'vehicles (state, counters, last seen)', 'count' => (int) DB::table('vehicles')->count(), 'kind' => 'rows', 'action' => 'reset to Outside'];
            $rows[] = ['item' => 'vehicle_rfid_tags (last scanned)', 'count' => (int) DB::table('vehicle_rfid_tags')->whereNotNull('last_scanned_at')->count(), 'kind' => 'rows', 'action' => 'clear'];
        }

        foreach ($this->folders() as $label => $path) {
            $rows[] = ['item' => $label, 'count' => $this->fileCount($path), 'kind' => 'files', 'action' => 'delete'];
        }

        foreach ($this->files() as $label => $paths) {
            $rows[] = ['item' => $label, 'count' => collect($paths)->filter(fn (string $path): bool => File::exists($path))->count(), 'kind' => 'files', 'action' => str_contains($label, 'logs') ? 'empty' : 'delete'];
        }

        $rows[] = ['item' => 'cache (RFID claims, launch state)', 'count' => Schema::hasTable('cache') ? (int) DB::table('cache')->count() : 0, 'kind' => 'rows', 'action' => 'clear'];

        return $rows;
    }

    /**
     * Copy the database file and zip the snapshot folders into
     * storage/backups/reset-YYYYmmdd-HHMMSS/. Returns that folder.
     */
    public function backup(): string
    {
        $folder = storage_path('backups/reset-'.now()->format('Ymd-His'));
        File::ensureDirectoryExists($folder);

        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (config('database.default') === 'sqlite' && $database !== ':memory:' && File::exists($database)) {
            // A consistent copy even while the app is writing (VACUUM INTO).
            DB::statement('VACUUM INTO ?', [$folder.'/database.sqlite']);
        } elseif (config('database.default') !== 'sqlite') {
            throw new RuntimeException('Automatic backup supports the SQLite database only; back up the database first.');
        }

        $zip = new ZipArchive;
        if ($zip->open($folder.'/snapshots.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the snapshots backup in '.$folder.'.');
        }

        foreach ($this->folders() as $label => $path) {
            if (! File::isDirectory($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                $zip->addFile($file->getPathname(), $label.'/'.$file->getRelativePathname());
            }
        }

        $zip->addFromString('README.txt', 'Snapshots and frames saved before `system:reset` on '.now()->toDateTimeString().".\n");
        $zip->close();

        return $folder;
    }

    /**
     * Remove the data. Returns what was removed (same shape as plan()).
     *
     * @return list<array{item: string, count: int, kind: string, action: string}>
     */
    public function reset(string $level = self::LEVEL_ACTIVITY): array
    {
        $done = $this->plan($level);
        $tables = $this->tables($level);

        // SQLite only changes foreign key checks outside a transaction. The
        // emptied tables point at each other (scan <-> crossing <-> event).
        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($tables, $level): void {
                foreach ($tables as $table) {
                    DB::table($table)->delete();
                }

                if ($level === self::LEVEL_ACTIVITY) {
                    DB::table('vehicles')->update([
                        'current_state' => Vehicle::STATE_OUTSIDE,
                        'daily_count_date' => null,
                        'entries_today_count' => 0,
                        'exits_today_count' => 0,
                        'first_entry_today_at' => null,
                        'last_exit_today_at' => null,
                        'last_entry_at' => null,
                        'last_exit_at' => null,
                        'last_seen_at' => null,
                    ]);
                    DB::table('vehicle_rfid_tags')->update(['last_scanned_at' => null]);
                }

                $this->restartIds($tables);
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        foreach ($this->folders() as $path) {
            if (File::isDirectory($path)) {
                File::cleanDirectory($path);
            }
        }

        foreach ($this->files() as $paths) {
            foreach ($paths as $path) {
                if (! File::exists($path)) {
                    continue;
                }

                // Logs are emptied, not deleted: a running service keeps writing to them.
                str_ends_with($path, '.log') ? File::put($path, '') : File::delete($path);
            }
        }

        Cache::flush();
        $this->localStorageService->ensureBaseDirectories();

        return $done;
    }

    /**
     * Remove the saved devices and the gates' assignments, the same way as
     * "Unassign" in Settings › Devices (cameras keep their last address as a
     * manual source), then export the device and camera runtime files.
     *
     * @return list<array{item: string, count: int, kind: string, action: string}>
     */
    public function resetDevices(): array
    {
        $done = array_slice($this->plan(self::LEVEL_ACTIVITY, true), 0, 3);
        $registry = app(DeviceRegistryService::class);

        foreach (DeviceAssignment::query()->get(['station', 'role']) as $assignment) {
            $registry->unassign($assignment->station, $assignment->role);
        }

        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function (): void {
                DB::table('device_assignments')->delete();
                DB::table('network_devices')->delete();
                $this->restartIds(['device_assignments', 'network_devices']);
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // Otherwise the last scan would be read back into the device list.
        File::delete(DeviceFiles::scanResultPath());
        $registry->exportRuntimeConfig();

        return $done;
    }

    /**
     * @return list<string>
     */
    protected function tables(string $level): array
    {
        $tables = $level === self::LEVEL_FULL
            ? [...self::ACTIVITY_TABLES, ...self::REGISTRY_TABLES]
            : self::ACTIVITY_TABLES;

        return array_values(array_filter($tables, fn (string $table): bool => Schema::hasTable($table)));
    }

    /**
     * @param  list<string>  $tables
     */
    protected function restartIds(array $tables): void
    {
        if (DB::getDriverName() === 'sqlite') {
            if (DB::table('sqlite_master')->where('name', 'sqlite_sequence')->exists()) {
                DB::table('sqlite_sequence')->whereIn('name', $tables)->delete();
            }

            return;
        }

        if (DB::getDriverName() === 'mysql') {
            foreach ($tables as $table) {
                DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
            }
        }
    }

    /**
     * Snapshot and frame folders (contents removed, folders kept).
     *
     * @return array<string, string>
     */
    protected function folders(): array
    {
        $media = Storage::disk($this->localStorageService->mediaDisk());
        $archive = Storage::disk($this->localStorageService->archiveDisk());

        $folders = [];
        foreach (['vehicle_images', 'plate_images', 'detected_vehicle_images'] as $key) {
            $folders['snapshots/'.$this->localStorageService->mediaDirectory($key)] = $media->path($this->localStorageService->mediaDirectory($key));
        }
        foreach (['guest_snapshots', 'crossing_snapshots', 'visitor_plates', 'visitor_snapshots'] as $directory) {
            $folders['snapshots/'.$directory] = $media->path($directory);
        }
        $folders['exports/'.$this->localStorageService->archiveDirectory('rfid_exports')] = $archive->path($this->localStorageService->archiveDirectory('rfid_exports'));
        $folders['camera/frames'] = CameraFiles::path('frames');
        $folders['camera/snapshots'] = CameraFiles::path('snapshots');

        return $folders;
    }

    /**
     * Logs and cached status files.
     *
     * @return array<string, list<string>>
     */
    protected function files(): array
    {
        return [
            'detector logs' => [
                ...File::glob(storage_path('logs/detector-runtime.log*')),
                storage_path('logs/camera_service.log'),
            ],
            'device-service logs' => [
                ...File::glob(storage_path('logs/device-service*.log*')),
                DeviceFiles::captureLogPath(),
                DeviceFiles::directory().DIRECTORY_SEPARATOR.'raw_tap.log',
            ],
            'cached status files' => [
                CameraFiles::statusPath(),
                CameraFiles::path('station_activity.json'),
                CameraFiles::path('detector_launch_state.json'),
                // The device-discovery result (last_scan.json) is kept: device detection.
                DeviceFiles::statusPath(),
            ],
        ];
    }

    protected function fileCount(string $path): int
    {
        return File::isDirectory($path) ? count(File::allFiles($path)) : 0;
    }
}
