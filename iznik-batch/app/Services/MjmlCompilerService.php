<?php

namespace App\Services;

use App\Services\WorkerPool\BoundedPool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for compiling MJML templates to HTML.
 *
 * Uses the freegle-mjml container with BoundedPool for back pressure
 * when the compiler is under load.
 */
class MjmlCompilerService
{
    private BoundedPool $pool;

    public function __construct()
    {
        $this->pool = new BoundedPool(
            name: 'mjml',
            maxConcurrency: config('pools.mjml.max', 20),
            timeoutSeconds: config('pools.mjml.timeout', 30),
            sentryThrottleSeconds: config('pools.mjml.sentry_throttle', 300)
        );
        $this->pool->initialize();
    }

    /**
     * Compile MJML to HTML.
     *
     * Blocks if all workers are busy (back pressure).
     *
     * @throws \RuntimeException If compilation fails
     */
    public function compile(string $mjml): string
    {
        return $this->pool->withPermit(function () use ($mjml) {
            $url = config('services.mjml.url');

            // adrianrudnik/mjml-server expects raw MJML text with Content-Type: text/plain
            // and returns raw HTML (not JSON)
            $response = Http::timeout(config('services.mjml.http_timeout', 30))
                ->withBody($mjml, 'text/plain')
                ->post($url);

            if (! $response->successful()) {
                Log::error('MJML compilation failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException(
                    'MJML compilation failed: '.$response->body()
                );
            }

            $html = $response->body();

            if (empty(trim($html))) {
                throw new \RuntimeException('MJML compilation returned empty HTML');
            }

            return $html;
        });
    }

    /**
     * Get pool statistics for monitoring.
     */
    public function getPoolStats(): array
    {
        return $this->pool->getStats();
    }
}
