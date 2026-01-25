<?php

namespace App\Services\WorkerPool;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * A bounded worker pool using Redis BLPOP for back pressure.
 *
 * This implements a semaphore pattern where:
 * - Pool is initialized with N permits (tokens in a Redis list)
 * - acquire() uses BLPOP - blocks until a permit is available
 * - release() uses RPUSH - returns the permit to the pool
 *
 * When all permits are taken, new requests block at acquire(),
 * providing natural back pressure to upstream producers.
 */
class BoundedPool
{
    private string $permitsKey;

    private string $statsKey;

    public function __construct(
        private string $name,
        private int $maxConcurrency,
        private int $timeoutSeconds = 0, // 0 = block forever
        private int $sentryThrottleSeconds = 300
    ) {
        $this->permitsKey = "pool:{$name}:permits";
        $this->statsKey = "pool:{$name}:stats";
    }

    /**
     * Initialize the pool with permits.
     * Safe to call multiple times - only adds missing permits.
     */
    public function initialize(): void
    {
        $current = Redis::llen($this->permitsKey);
        $needed = $this->maxConcurrency - $current;

        if ($needed > 0) {
            for ($i = 0; $i < $needed; $i++) {
                Redis::rpush($this->permitsKey, '1');
            }
            Log::info("BoundedPool[{$this->name}]: Initialized {$needed} permits (total: {$this->maxConcurrency})");
        }
    }

    /**
     * Acquire a permit. Blocks until one is available.
     *
     * @return bool True if permit acquired, false if timeout exceeded
     */
    public function acquire(): bool
    {
        // BLPOP returns [key, value] on success, null on timeout
        $result = Redis::blpop($this->permitsKey, $this->timeoutSeconds);

        if ($result === null || $result === []) {
            $this->recordTimeout();

            return false;
        }

        $this->recordAcquire();

        return true;
    }

    /**
     * Release a permit back to the pool.
     */
    public function release(): void
    {
        Redis::rpush($this->permitsKey, '1');
    }

    /**
     * Execute callback with a permit, ensuring release on completion.
     *
     * @throws PoolTimeoutException If permit cannot be acquired within timeout
     */
    public function withPermit(callable $callback): mixed
    {
        if (! $this->acquire()) {
            throw new PoolTimeoutException(
                "Could not acquire permit for pool: {$this->name}"
            );
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    /**
     * Get current pool statistics.
     */
    public function getStats(): array
    {
        $available = Redis::llen($this->permitsKey);

        return [
            'name' => $this->name,
            'max_concurrency' => $this->maxConcurrency,
            'available' => $available,
            'in_use' => $this->maxConcurrency - $available,
            'timeouts' => (int) (Redis::hget($this->statsKey, 'timeouts') ?? 0),
            'total_acquired' => (int) (Redis::hget($this->statsKey, 'acquired') ?? 0),
        ];
    }

    /**
     * Check if pool is at capacity (no permits available).
     */
    public function isAtCapacity(): bool
    {
        return Redis::llen($this->permitsKey) === 0;
    }

    /**
     * Get the pool name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the maximum concurrency.
     */
    public function getMaxConcurrency(): int
    {
        return $this->maxConcurrency;
    }

    /**
     * Reset the pool - removes all permits and reinitializes.
     * Use with caution - may leave work in progress without permits.
     */
    public function reset(): void
    {
        Redis::del($this->permitsKey);
        Redis::del($this->statsKey);
        $this->initialize();
    }

    private function recordAcquire(): void
    {
        Redis::hincrby($this->statsKey, 'acquired', 1);
    }

    private function recordTimeout(): void
    {
        $count = Redis::hincrby($this->statsKey, 'timeouts', 1);

        // Throttled Sentry alert - only alert every N seconds
        $lastAlertKey = "pool:{$this->name}:last_alert";
        $lastAlert = Redis::get($lastAlertKey);

        if (! $lastAlert || (time() - (int) $lastAlert) > $this->sentryThrottleSeconds) {
            Redis::setex($lastAlertKey, $this->sentryThrottleSeconds, time());

            Log::error("BoundedPool[{$this->name}] at max capacity", [
                'pool' => $this->name,
                'max_concurrency' => $this->maxConcurrency,
                'total_timeouts' => $count,
            ]);

            // Report to Sentry via Laravel's error handler
            report(new PoolCapacityException(
                "Pool '{$this->name}' at maximum capacity ({$this->maxConcurrency})"
            ));
        }
    }
}
