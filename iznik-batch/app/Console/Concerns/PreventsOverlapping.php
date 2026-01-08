<?php

namespace App\Console\Concerns;

/**
 * Trait for preventing overlapping command execution using file locks.
 *
 * Uses flock() system calls which are automatically released when:
 * - The process exits normally
 * - The process crashes
 * - The process is killed
 *
 * This is more reliable than TTL-based locks which can leave stale locks
 * after crashes.
 */
trait PreventsOverlapping
{
    protected ?string $lockFile = null;

    /** @var resource|null */
    protected $lockHandle = null;

    /**
     * Try to acquire an exclusive lock. Returns false if already running.
     */
    protected function acquireLock(): bool
    {
        $lockPath = storage_path('framework/command-locks');
        if (!is_dir($lockPath)) {
            mkdir($lockPath, 0755, true);
        }

        $lockName = str_replace([':', '\\'], '-', static::class);
        $this->lockFile = "{$lockPath}/{$lockName}.lock";

        $this->lockHandle = fopen($this->lockFile, 'c');
        if (!$this->lockHandle) {
            return false;
        }

        // Non-blocking exclusive lock.
        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;
            return false;
        }

        // Write PID for debugging.
        ftruncate($this->lockHandle, 0);
        fwrite($this->lockHandle, (string) getmypid());
        fflush($this->lockHandle);

        return true;
    }

    /**
     * Release the lock (also released automatically on process death).
     */
    protected function releaseLock(): void
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }
}
