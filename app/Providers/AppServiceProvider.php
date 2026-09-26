<?php

namespace App\Providers;

use App\Services\AlertSummaryService;
use App\View\Composers\NavigationComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // UI Phase 2: sidebar alert badge and system status dots.
        View::composer('layouts.partials.navigation', NavigationComposer::class);
        View::composer('logs.index', fn ($view) => $view->with('alertCounts', app(AlertSummaryService::class)->counts()));
    }
}
