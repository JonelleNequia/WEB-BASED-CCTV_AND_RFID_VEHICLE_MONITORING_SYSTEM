<?php

namespace App\Providers;

use App\Services\AlertSummaryService;
use App\View\Composers\NavigationComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private array $lifecycleCommands = [];
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\LifecycleBarrier::class);
        $this->app->singleton(\App\Services\DeliveryMediaJournal::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->booted(function () {
            // Enabled lifecycle controls also apply in isolated CLI fixtures; Laravel
            // normally suppresses Symfony console events when APP_ENV=testing.
            if ($this->app['config']->get('lifecycle.root')) $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->rerouteSymfonyCommandEvents();
        });
        $this->app['events']->listen(\Illuminate\Console\Events\CommandStarting::class, function ($event) {
            $barrier = $this->app->make(\App\Services\LifecycleBarrier::class);
            if (! $barrier->enabled()) { $this->lifecycleCommands[] = false; return; }
            $command = $event->command ?? '';
            if (in_array($command, ['system:delivery-init', 'system:delivery-initializable', 'system:delivery-check', 'list', 'help', 'about', 'route:list'], true)) { $this->lifecycleCommands[] = false; return; }
            if (preg_match('/^(?:migrate|db:|backup:|system:reset|visitors:cleanup|rfid:cleanup|runtime:fetch)/', $command)) {
                throw new \RuntimeException('Uncertified lifecycle operation disabled. Use the authorized D5 subphase.');
            }
            $barrier->acquire(control: in_array($command, ['config:clear', 'config:cache', 'detector:start', 'go2rtc:start'], true));
            $this->lifecycleCommands[] = true;
        });
        $this->app['events']->listen(\Illuminate\Console\Events\CommandFinished::class, function ($event) {
            if (array_pop($this->lifecycleCommands)) $this->app->make(\App\Services\LifecycleBarrier::class)->release();
        });
        if (config('deployment.named_https')) {
            \Illuminate\Support\Facades\URL::forceRootUrl(config('deployment.url'));
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
        // UI Phase 2: sidebar alert badge and system status dots.
        View::composer('layouts.partials.navigation', NavigationComposer::class);
        View::composer('logs.index', fn ($view) => $view->with('alertCounts', app(AlertSummaryService::class)->counts()));
    }
}
