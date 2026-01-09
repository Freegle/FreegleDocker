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
        if (app()->environment('testing')) {
            return;
        }

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(TRUE);
            pcntl_signal(SIGTERM, fn() => $this->shouldStop = TRUE);
            pcntl_signal(SIGINT, fn() => $this->shouldStop = TRUE);
        }
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
