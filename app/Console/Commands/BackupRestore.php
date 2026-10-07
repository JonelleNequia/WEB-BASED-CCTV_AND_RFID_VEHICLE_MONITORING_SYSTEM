<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Windows install kit: put a backup back (deploy/windows/restore.bat stops
 * the services first and starts them again after). The data as it is now
 * is backed up first, so a wrong restore can be undone.
 *
 *   php artisan backup:restore C:\PHILCST-VMS\app\storage\backups\PHILCST-backup-20261008-020000.zip --force
 */
class BackupRestore extends Command
{
    protected $signature = 'backup:restore {file : The backup zip} {--force : Do not ask} {--no-safety-backup : Do not back up the current data first}';

    protected $description = 'Restore a backup made by backup:run (database, snapshots, APP_KEY)';

    public function handle(BackupService $backups): int
    {
        $file = (string) $this->argument('file');

        try {
            $info = $backups->inspect($file);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Backup from '.($info['created_at'] ?? '?').($info['version'] ? ' (version '.$info['version'].')' : '').', '.($info['files'] ?? 0).' files.');
        if (! $this->option('force') && ! $this->confirm('Replace the current data with this backup?')) {
            return self::FAILURE;
        }

        try {
            if (! $this->option('no-safety-backup')) {
                $this->line('Current data saved first: '.$backups->create(null, 'before-restore'));
            }
            $result = $backups->restore($file);
        } catch (Throwable $exception) {
            $this->error('Restore failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Restored: database and '.$result['files'].' files.');
        if ($result['app_key_restored']) {
            $this->warn('APP_KEY restored from the backup (camera passwords need it). Run "php artisan config:cache" (restore.bat does).');
        }

        return self::SUCCESS;
    }
}
