<?php

namespace App\Providers;

use App\Listeners\SpamCheckListener;
use App\Services\LokiService;
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
        if (!$finder->find('mjml')) {
            $message = 'MJML binary not found. Email templates will not render correctly. ' .
                'Install with: npm install -g mjml';
            Log::error($message);

            // Also output to stderr for CLI visibility.
            if ($this->app->runningInConsole()) {
                fwrite(STDERR, "ERROR: {$message}\n");
            }
        }
    }
}
