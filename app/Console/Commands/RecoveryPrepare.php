<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Regenerate disposable configuration using installed code after validated promotion. */
class RecoveryPrepare extends Command
{
    protected $signature = 'system:recovery-prepare';
    protected $description = 'Rebuild local runtime configuration during an owned D5B recovery resume';

    public function handle(): int
    {
        $barrier = app(\App\Services\LifecycleBarrier::class);
        if (! $barrier->enabled()) throw new \RuntimeException('Installed owned recovery is required.');
        $state = $barrier->metadata('maintenance.json');
        if (($state['phase'] ?? null) !== 'resuming' || ! filled(getenv('PHILCST_LIFECYCLE_CONTROL'))
            || ! hash_equals((string) $state['operation'], (string) getenv('PHILCST_LIFECYCLE_CONTROL'))) {
            throw new \RuntimeException('Only the exact owned recovery resume may rebuild configuration.');
        }
        app(\App\Services\SettingsService::class)->exportCameraRuntimeConfig(syncLive: false);
        app(\App\Services\Go2rtcService::class)->writeConfig();
        $this->info('Installed runtime configuration regenerated.');

        return self::SUCCESS;
    }
}
