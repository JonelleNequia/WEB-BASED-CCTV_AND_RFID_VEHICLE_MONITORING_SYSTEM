<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/** Deprecated CLI: never delegates an in-process request to privileged recovery. */
class BackupRestore extends Command
{
    protected $signature = 'backup:restore {file : The backup zip} {--force : Do not ask} {--no-safety-backup : Do not back up the current data first}';

    protected $description = 'Explain the required installed D5B recovery workflow (legacy restore disabled)';

    public function handle(BackupService $backups): int
    {
        $this->error('Legacy restore is disabled. Use installed D5B recovery.py inspect/plan, then an explicitly confirmed restore.');

        return self::FAILURE;
    }
}
