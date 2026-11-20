<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/** Deprecated CLI; installed D5B backups use the owned external controller. */
class BackupRun extends Command
{
    protected $signature = 'backup:run {--to= : Folder for the backup (default storage/backups)} {--label= : Added to the file name}';

    protected $description = 'Explain the required D5B consistent-backup workflow (legacy writer disabled)';

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
