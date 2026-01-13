<?php

namespace App\Traits;

trait GracefulShutdown
{
    protected bool $shouldStop = FALSE;
    protected ?string $abortFilePath = NULL;

    /**
     * Set up signal handlers for graceful shutdown.
     * Skipped in testing environment to avoid interfering with PHPUnit's output capture.
     */
    protected function setupSignalHandlers(): void
    {
        // Skip signal handlers in testing to avoid PHPUnit risky test warnings
        // and interference with expectsOutputToContain() assertions.
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
     * Check if we're running in a testing environment.
     *
     * PHPUnit sets APP_ENV=testing in $_ENV/$_SERVER via phpunit.xml,
     * but the container's OS environment may have APP_ENV=local.
     * We check ALL sources to handle both scenarios.
     */
    protected function isTestingEnvironment(): bool
    {
        // Check PHPUNIT_RUNNING constant (if defined by test bootstrap).
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            return true;
        }

        // Check APP_ENV from multiple sources.
        if (getenv('APP_ENV') === 'testing') {
            return true;
        }

        if (($_ENV['APP_ENV'] ?? null) === 'testing') {
            return true;
        }

        if (($_SERVER['APP_ENV'] ?? null) === 'testing') {
            return true;
        }

        return false;
    }

    /**
     * Register shutdown handlers (alias for setupSignalHandlers).
     */
    protected function registerShutdownHandlers(): void
    {
        $this->setupSignalHandlers();
    }

    /**
     * Check if we should stop processing.
     */
    protected function shouldStop(): bool
    {
        if ($this->shouldStop) {
            return TRUE;
        }

        if ($this->abortFilePath && file_exists($this->abortFilePath)) {
            $this->shouldStop = TRUE;
            return TRUE;
        }

        return FALSE;
    }

    /**
     * Check if we should abort processing (alias for shouldStop).
     */
    protected function shouldAbort(): bool
    {
        return $this->shouldStop();
    }

    /**
     * Set the abort file path for this script.
     */
    protected function setAbortFile(string $scriptName): self
    {
        $this->abortFilePath = "/tmp/iznik.{$scriptName}.abort";
        return $this;
    }

    /**
     * Create the abort file to signal shutdown.
     */
    protected function createAbortFile(): void
    {
        if ($this->abortFilePath) {
            touch($this->abortFilePath);
        }
    }

    /**
     * Remove the abort file after processing.
     */
    protected function removeAbortFile(): void
    {
        if ($this->abortFilePath && file_exists($this->abortFilePath)) {
            unlink($this->abortFilePath);
        }
    }

    /**
     * Log shutdown message if stopping.
     */
    protected function logShutdownIfStopping(): bool
    {
        if ($this->shouldStop()) {
            if (method_exists($this, 'info')) {
                $this->info('Graceful shutdown requested, stopping...');
            }
            return TRUE;
        }
        return FALSE;
    }
}
