<?php

namespace App\Providers;

use App\Services\LokiService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register LokiService as a singleton.
        $this->app->singleton(LokiService::class, function ($app) {
            return new LokiService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
