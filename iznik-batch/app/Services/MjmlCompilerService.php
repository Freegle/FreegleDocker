<?php

namespace App\Services;

use App\Services\WorkerPool\BoundedPool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Mjml\Mjml;

/**
 * Service for compiling MJML templates to HTML.
 *
 * Supports two modes:
 * - HTTP: Uses external MJML server with BoundedPool back pressure
 * - Local: Uses Spatie/MJML package with local Node.js
 *
 * The HTTP mode is preferred for production as it provides:
 * - Back pressure when the compiler is overloaded
 * - Centralized resource management
 * - Better observability
 */
class MjmlCompilerService
{
    private ?BoundedPool $pool = null;

    private string $mode;

    public function __construct()
    {
        $this->mode = config('services.mjml.mode', 'local');

        // Only initialize pool for HTTP mode
        if ($this->mode === 'http') {
            $this->pool = new BoundedPool(
                name: 'mjml',
                maxConcurrency: config('pools.mjml.max', 20),
                timeoutSeconds: config('pools.mjml.timeout', 30),
                sentryThrottleSeconds: config('pools.mjml.sentry_throttle', 300)
            );
            $this->pool->initialize();
        }
    }

    /**
     * Compile MJML to HTML.
     *
     * In HTTP mode, this will block if all workers are busy (back pressure).
     *
     * @throws \RuntimeException If compilation fails
     */
    public function compile(string $mjml): string
    {
        if ($this->mode === 'http') {
            return $this->compileViaHttp($mjml);
        }

        return $this->compileLocally($mjml);
    }

    /**
     * Compile MJML using the HTTP server with back pressure.
     */
    protected function compileViaHttp(string $mjml): string
    {
        return $this->pool->withPermit(function () use ($mjml) {
            $url = config('services.mjml.url');

            $response = Http::timeout(config('services.mjml.http_timeout', 30))
                ->post($url, [
                    'mjml' => $mjml,
                ]);

            if (! $response->successful()) {
                Log::error('MJML HTTP compilation failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException(
                    'MJML HTTP compilation failed: '.$response->body()
                );
            }

            $html = $response->json()['html'] ?? '';

            if (empty(trim($html))) {
                throw new \RuntimeException('MJML compilation returned empty HTML');
            }

            return $html;
        });
    }

    /**
     * Compile MJML locally using the Spatie package.
     */
    protected function compileLocally(string $mjml): string
    {
        return Mjml::new()->toHtml($mjml);
    }

    /**
     * Get pool statistics for monitoring.
     *
     * @return array|null Pool stats or null if using local mode
     */
    public function getPoolStats(): ?array
    {
        return $this->pool?->getStats();
    }

    /**
     * Check if running in HTTP mode with worker pool.
     */
    public function usesWorkerPool(): bool
    {
        return $this->mode === 'http';
    }

    /**
     * Get current compilation mode.
     */
    public function getMode(): string
    {
        return $this->mode;
    }
}
