<?php

namespace App\Console\Commands;

use App\Services\SystemResetService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fresh start (see SystemResetService for what each level removes and keeps).
 *
 *   php artisan system:reset --dry-run          what would be removed
 *   php artisan system:reset                    activity data (default)
 *   php artisan system:reset --full             activity data + vehicles and tags
 *   ... --devices                               also saved devices and assignments
 *   ... --force                                 no "type RESET" question
 */
class SystemReset extends Command
{
    protected $signature = 'system:reset
        {--activity : Remove activity data (the default)}
        {--full : Also remove registered vehicles and RFID tags}
        {--devices : Also remove saved devices and the gates\' camera / reader assignments (scan and assign again)}
        {--dry-run : Show what would be removed, change nothing}
        {--force : Do not ask to type RESET}';

    protected $description = 'Remove activity data (or with --full also the Registry) after a backup, for a fresh start';

    public function handle(SystemResetService $resetService): int
    {
        if ($this->option('full') && $this->option('activity')) {
            $this->error('Choose --activity or --full, not both.');

            return self::INVALID;
        }

        $level = $this->option('full') ? SystemResetService::LEVEL_FULL : SystemResetService::LEVEL_ACTIVITY;
        $devices = (bool) $this->option('devices');
        $plan = $resetService->plan($level, $devices);

        $this->info(($this->option('dry-run') ? 'DRY RUN: ' : '').'Reset level: '.$level.($level === SystemResetService::LEVEL_FULL ? ' (activity data + registered vehicles and RFID tags)' : ' (activity data)'));
        $this->table(['What', 'Count', 'Action'], array_map(fn (array $row): array => [$row['item'], $row['count'].' '.$row['kind'], $row['action']], $plan));
        $this->line('Kept: users, settings, gates, calibration, camera credentials'
            .($devices ? '' : ', device assignments, discovered devices')
            .($level === SystemResetService::LEVEL_ACTIVITY ? ', registered vehicles and RFID tags.' : '.'));
        if ($devices) {
            $this->warn('Devices: the camera and reader must be assigned again in Settings > Devices after the next scan.');
        }

        if ($this->option('dry-run')) {
            $this->info('Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Type RESET to confirm (run it in a terminal), or add --force.');

                return self::FAILURE;
            }

            if ($this->ask('Type RESET to remove this data') !== 'RESET') {
                $this->warn('Cancelled. Nothing was changed.');

                return self::FAILURE;
            }
        }

        try {
            $backup = $resetService->backup();
            $this->info('Backup saved: '.$backup.' (database.sqlite, snapshots.zip)');
            $resetService->reset($level);
            if ($devices) {
                $resetService->resetDevices();
            }
        } catch (Throwable $exception) {
            $this->error('Reset stopped: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['What', 'Now'], array_map(fn (array $row): array => [$row['item'], match ($row['action']) {
            'empty' => 'emptied',
            'reset to Outside' => $row['count'].' rows, all Outside',
            default => $row['count'].' '.$row['kind'],
        }], $resetService->plan($level, $devices)));
        $this->info('Reset done. New records start at ID 1.');

        return self::SUCCESS;
    }
}
