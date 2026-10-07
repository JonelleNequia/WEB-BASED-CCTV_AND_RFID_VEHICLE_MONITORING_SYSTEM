<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Windows install kit: a one-file backup (database, snapshots, APP_KEY).
 * Task Scheduler runs it every day; deploy/windows/backup-now.bat on demand.
 *
 *   php artisan backup:run [--to=D:\Backups] [--label=before-update]
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run {--to= : Folder for the backup (default storage/backups)} {--label= : Added to the file name}';

    protected $description = 'Back up the database, snapshots and APP_KEY into one zip file';

    public function handle(BackupService $backups): int
    {
        try {
            $path = $backups->create($this->option('to') ?: null, (string) $this->option('label'));
        } catch (Throwable $exception) {
            $this->error('Backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup saved: '.$path.' ('.round(filesize($path) / 1048576, 1).' MB)');

        return self::SUCCESS;
    }
}
