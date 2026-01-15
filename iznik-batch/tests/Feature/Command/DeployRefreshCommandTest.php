<?php

namespace Tests\Feature\Command;

use App\Console\Commands\Deploy\RefreshCommand;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeployRefreshCommandTest extends TestCase
{
    public function test_clears_and_optimizes_caches(): void
    {
        $this->artisan('deploy:refresh')
            ->expectsOutput('Refreshing application after deployment...')
            ->expectsOutput('Clearing and rebuilding caches...')
            ->assertSuccessful();
    }

    public function test_restarts_queue_workers(): void
    {
        $this->artisan('deploy:refresh')
            ->expectsOutput('Restarting queue workers...')
            ->assertSuccessful();
    }

    public function test_attempts_supervisor_restart(): void
    {
        $this->artisan('deploy:refresh')
            ->expectsOutput('Restarting supervisor programs...')
            ->assertSuccessful();
    }

    public function test_records_deployed_version(): void
    {
        // Ensure version file exists.
        $versionFile = base_path('version.txt');
        $this->assertFileExists($versionFile);

        // Clear any cached version.
        Cache::forget(RefreshCommand::VERSION_CACHE_KEY);

        $this->artisan('deploy:refresh')
            ->assertSuccessful();

        // Check that version was cached.
        $cachedVersion = Cache::get(RefreshCommand::VERSION_CACHE_KEY);
        $this->assertNotNull($cachedVersion);
    }

    public function test_get_current_version_returns_file_content(): void
    {
        $version = RefreshCommand::getCurrentVersion();
        $this->assertNotNull($version);
        $this->assertStringContainsString('0', $version); // Initial version is 0
    }
}
