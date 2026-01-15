<?php

namespace App\Traits;

/**
 * Trait for graceful shutdown of long-running commands.
 *
 * Registers signal handlers for SIGTERM/SIGINT that set a flag.
 * The command's loop should check shouldStop() at safe points
 * (between database operations, etc.) and exit gracefully.
 *
 * This works with supervisor, systemd, docker stop, and Ctrl+C -
 * all of which send SIGTERM first.
 */
trait GracefulShutdown
{
    protected bool $shouldStop = FALSE;

    /**
     * Register signal handlers for graceful shutdown.
     * Call this at the start of your daemon loop.
     */
    protected function registerShutdownHandlers(): void
    {
        // Skip in testing to avoid PHPUnit issues.
        if ($this->isTestingEnvironment()) {
            return;
        }

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(TRUE);
            pcntl_signal(SIGTERM, fn() => $this->shouldStop = TRUE);
            pcntl_signal(SIGINT, fn() => $this->shouldStop = TRUE);
        }
    }

    /**
     * Alias for registerShutdownHandlers.
     */
    protected function setupSignalHandlers(): void
    {
        $this->registerShutdownHandlers();
    }

    /**
     * Check if we should stop processing.
     * Call this at safe points in your loop.
     */
    protected function shouldStop(): bool
    {
        return $this->shouldStop;
    }

    /**
     * Alias for shouldStop.
     */
    protected function shouldAbort(): bool
    {
        return $this->shouldStop();
    }

    protected function isTestingEnvironment(): bool
    {
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            return TRUE;
        }

        return in_array(
            'testing',
            [getenv('APP_ENV'), $_ENV['APP_ENV'] ?? NULL, $_SERVER['APP_ENV'] ?? NULL],
            TRUE
        );
    }
}
