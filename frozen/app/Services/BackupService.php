<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/** Read-only legacy archive listing/metadata; coherent recovery belongs to D5B.
 * No browser/PHP request delegates to a privileged OS controller.
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
     * Retained API refuses the uncertified legacy writer.
     */
    public function create(?string $directory = null, string $label = ''): string
    {
        throw new RuntimeException('Legacy backup:run cannot establish a consistent recovery point. Use the installed D5B recovery.py backup command.');
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

        return [...$info, 'legacy_unverified' => true, 'app_key_differs' => $key !== '' && $key !== (string) config('app.key')];
    }

    /**
     * Retained API refuses in-place database/media/key replacement.
     *
     * @return array{database: string, files: int, app_key_restored: bool}
     */
    public function restore(string $zipPath): array
    {
        throw new RuntimeException('Legacy backup:restore is disabled. Inspect and confirm a complete D5B recovery plan with the installed recovery.py controller.');
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


    protected function version(): ?string
    {
        $info = dirname(base_path()).DIRECTORY_SEPARATOR.'BUILD-INFO.json';

        return is_file($info) ? (json_decode((string) file_get_contents($info), true)['version'] ?? null) : null;
    }
}
