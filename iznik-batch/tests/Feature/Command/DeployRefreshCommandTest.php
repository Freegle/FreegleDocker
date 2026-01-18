<?php

namespace Tests\Feature\Command;

use App\Console\Commands\Deploy\RefreshCommand;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeployRefreshCommandTest extends TestCase
{
    /**
     * Test that deploy:refresh runs successfully and produces expected output.
     *
     * Note: We verify behavior via output assertions rather than mocking Artisan::call(),
     * which causes issues with Laravel's error handling (694 "risky" tests in CI).
     * The actual cache operations run during this test, which is safe because:
     * 1. Config/route/event caches are cleared then rebuilt by subsequent tests
     * 2. view:cache precompiles views (doesn't corrupt them)
     * 3. Bootstrap cache files (services.php, packages.php) are verified to exist
     */
    public function test_clears_and_optimizes_caches(): void
    {
        $this->artisan('deploy:refresh')
            ->expectsOutput('Refreshing application after deployment...')
            ->expectsOutput('Clearing environment-specific caches...')
            ->assertSuccessful();
    }

    public function test_verifies_bootstrap_cache_files(): void
    {
        // Ensure bootstrap cache files exist (they should be committed to git)
        $servicesPath = base_path('bootstrap/cache/services.php');
        $packagesPath = base_path('bootstrap/cache/packages.php');

        $this->assertFileExists($servicesPath, 'services.php should be committed to git');
        $this->assertFileExists($packagesPath, 'packages.php should be committed to git');
        $this->assertGreaterThan(0, filesize($servicesPath), 'services.php should not be empty');
        $this->assertGreaterThan(0, filesize($packagesPath), 'packages.php should not be empty');

        // Run deploy:refresh
        $this->artisan('deploy:refresh')
            ->assertSuccessful();

        // Verify files still exist and have content after the command ran
        clearstatcache();
        $this->assertFileExists($servicesPath);
        $this->assertFileExists($packagesPath);
        $this->assertGreaterThan(0, filesize($servicesPath), 'services.php should not be empty after refresh');
        $this->assertGreaterThan(0, filesize($packagesPath), 'packages.php should not be empty after refresh');
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
