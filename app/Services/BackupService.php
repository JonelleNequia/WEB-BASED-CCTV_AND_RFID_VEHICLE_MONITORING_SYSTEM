<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Windows install kit: one-file backups that can be restored on this PC or
 * on a new install.
 *
 *   storage/backups/PHILCST-backup-YYYYmmdd-HHMMSS.zip
 *     database.sqlite   consistent copy (VACUUM INTO) while the app runs
 *     files/...         snapshots, frames and exports (as in system:reset)
 *     app-key.txt       APP_KEY: camera passwords are encrypted with it
 *     backup.json       when, from which version, what is inside
 *
 * The zip holds the APP_KEY and the camera logins (encrypted with it), so
 * keep backups on this PC or a drive only the school has.
 */
class BackupService
{
    public const PREFIX = 'PHILCST-backup-';

    public function __construct(protected SystemResetService $resetService)
    {
    }

    public function directory(): string
    {
        return storage_path('backups');
    }

    /**
     * Make a backup; returns the zip's path.
     */
    public function create(?string $directory = null, string $label = ''): string
    {
        $directory = rtrim($directory ?: $this->directory(), '/\\');
        File::ensureDirectoryExists($directory);
        $name = self::PREFIX.now()->format('Ymd-His').($label !== '' ? '-'.preg_replace('/[^a-z0-9-]+/i', '-', $label) : '');
        $zipPath = $directory.DIRECTORY_SEPARATOR.$name.'.zip';
        $temp = storage_path('app/backup-'.uniqid());
        File::ensureDirectoryExists($temp);

        try {
            $database = $this->databasePath();
            DB::statement('VACUUM INTO ?', [$temp.'/database.sqlite']);

            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Could not create $zipPath.");
            }
            $zip->addFile($temp.'/database.sqlite', 'database.sqlite');
            $files = 0;
            foreach ($this->resetService->folders() as $label => $path) {
                if (! File::isDirectory($path)) {
                    continue;
                }
                foreach (File::allFiles($path) as $file) {
                    $zip->addFile($file->getPathname(), 'files/'.$label.'/'.str_replace('\\', '/', $file->getRelativePathname()));
                    $files++;
                }
            }
            $zip->addFromString('app-key.txt', (string) config('app.key'));
            $zip->addFromString('backup.json', (string) json_encode([
                'created_at' => now()->toIso8601String(),
                'app' => config('app.name'),
                'version' => $this->version(),
                'database' => basename($database),
                'files' => $files,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();
        } finally {
            File::deleteDirectory($temp);
        }

        return $zipPath;
    }

    /**
     * Backups in a folder, newest first.
     *
     * @return list<array{path: string, name: string, size: int, created_at: int}>
     */
    public function list(?string $directory = null): array
    {
        $directory = $directory ?: $this->directory();
        if (! File::isDirectory($directory)) {
            return [];
        }

        return collect(File::files($directory))
            ->filter(fn ($file) => str_starts_with($file->getFilename(), self::PREFIX) && $file->getExtension() === 'zip')
            ->map(fn ($file) => ['path' => $file->getPathname(), 'name' => $file->getFilename(), 'size' => $file->getSize(), 'created_at' => $file->getMTime()])
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    /**
     * What a backup holds (throws when it is not a backup of this system).
     *
     * @return array<string, mixed>
     */
    public function inspect(string $zipPath): array
    {
        $zip = new ZipArchive;
        if (! is_file($zipPath) || $zip->open($zipPath) !== true) {
            throw new RuntimeException("$zipPath is not a backup file (cannot open it).");
        }
        $info = json_decode((string) $zip->getFromName('backup.json'), true);
        $hasDatabase = $zip->locateName('database.sqlite') !== false;
        $key = (string) $zip->getFromName('app-key.txt');
        $zip->close();

        if (! is_array($info) || ! $hasDatabase) {
            throw new RuntimeException(basename($zipPath).' is not a PHILCST backup (no database.sqlite / backup.json).');
        }

        return [...$info, 'app_key_differs' => $key !== '' && $key !== (string) config('app.key')];
    }

    /**
     * Put a backup back. Stop the services first (deploy/windows/restore.bat
     * does). Returns what was restored.
     *
     * @return array{database: string, files: int, app_key_restored: bool}
     */
    public function restore(string $zipPath): array
    {
        $this->inspect($zipPath);
        $database = $this->databasePath();
        $zip = new ZipArchive;
        $zip->open($zipPath);

        // The database: replace the file (and its WAL files) while nothing has it open.
        DB::disconnect();
        $temp = $database.'.restoring';
        File::put($temp, (string) $zip->getFromName('database.sqlite'));
        foreach (['-wal', '-shm'] as $suffix) {
            File::delete($database.$suffix);
        }
        File::move($temp, $database);

        // Snapshots, frames and exports.
        $files = 0;
        $folders = $this->resetService->folders();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! str_starts_with($name, 'files/') || str_ends_with($name, '/') || str_contains($name, '..')) {
                continue;
            }
            foreach ($folders as $label => $path) {
                $prefix = 'files/'.$label.'/';
                if (str_starts_with($name, $prefix)) {
                    $target = $path.DIRECTORY_SEPARATOR.substr($name, strlen($prefix));
                    File::ensureDirectoryExists(dirname($target));
                    File::put($target, (string) $zip->getFromIndex($i));
                    $files++;
                    break;
                }
            }
        }

        // The key the camera passwords were encrypted with.
        $key = trim((string) $zip->getFromName('app-key.txt'));
        $zip->close();
        $keyRestored = false;
        if ($key !== '' && $key !== (string) config('app.key')) {
            $this->writeAppKey($key);
            $keyRestored = true;
        }

        return ['database' => $database, 'files' => $files, 'app_key_restored' => $keyRestored];
    }

    public function databasePath(): string
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.$connection.database");
        if ($connection !== 'sqlite' || $database === ':memory:') {
            throw new RuntimeException('Backups support the SQLite database file only.');
        }

        return $database;
    }

    protected function writeAppKey(string $key): void
    {
        $env = app()->environmentFilePath();
        $text = File::exists($env) ? File::get($env) : '';
        $line = 'APP_KEY='.$key;
        $text = preg_match('/^APP_KEY=.*$/m', $text)
            ? preg_replace('/^APP_KEY=.*$/m', str_replace('$', '\$', $line), $text)
            : rtrim($text)."\n".$line."\n";
        File::put($env, $text);
    }

    protected function version(): ?string
    {
        $info = dirname(base_path()).DIRECTORY_SEPARATOR.'BUILD-INFO.json';

        return is_file($info) ? (json_decode((string) file_get_contents($info), true)['version'] ?? null) : null;
    }
}
