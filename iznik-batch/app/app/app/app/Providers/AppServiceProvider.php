<?php

namespace App\Providers;

use App\Console\FlockEventMutex;
use App\Listeners\SpamCheckListener;
use App\Services\LokiService;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Process\ExecutableFinder;

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

        // Register FlockEventMutex for process-aware scheduler locks.
        // Uses OS-level flock() which auto-releases on process death.
        if (config('cache.lock_store', 'flock') === 'flock') {
            $this->app->singleton(EventMutex::class, function ($app) {
                return new FlockEventMutex();
            });
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->checkRequiredBinaries();
        $this->registerSpamCheckListener();
    }

    /**
     * Register spam check listener for outgoing emails.
     * Only active when SPAM_CHECK_ENABLED=true.
     */
    protected function registerSpamCheckListener(): void
    {
        Event::listen(MessageSending::class, SpamCheckListener::class);
    }

    /**
     * Check that required external binaries are available.
     */
    protected function checkRequiredBinaries(): void
    {
        $finder = new ExecutableFinder();

        // Check for MJML (required for email rendering).
        // Look in common locations including local node_modules.
        $mjmlPaths = [
            base_path('node_modules/.bin'),
            '/usr/local/bin',
            '/usr/bin',
        ];

        $mjmlBinary = $finder->find('mjml', null, $mjmlPaths);

        if (!$mjmlBinary) {
            $message = 'MJML binary not found. Email templates will not render correctly. ' .
                'Install with: npm install mjml (in project root)';
            Log::warning($message);

            // Warning only - plain text emails (Mail::raw) still work without MJML.
            // Only MJML-based email templates will fail to render.
        }
    }
}
