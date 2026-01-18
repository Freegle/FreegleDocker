<?php

namespace Tests\Feature\Command;

use App\Console\Commands\Deploy\RefreshCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeployRefreshCommandTest extends TestCase
{
    /**
     * Track which artisan commands were called during the test.
     */
    protected array $calledCommands = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Artisan::call() to track calls without actually executing cache operations.
        // This prevents the deploy:refresh command from corrupting pre-compiled views
        // and bootstrap cache files that other tests depend on.
        $this->calledCommands = [];

        Artisan::shouldReceive('call')
            ->andReturnUsing(function ($command, $parameters = []) {
                $this->calledCommands[] = $command;
                return 0; // Success
            });
    }

    public function test_clears_and_optimizes_caches(): void
    {
        $this->artisan('deploy:refresh')
            ->expectsOutput('Refreshing application after deployment...')
            ->expectsOutput('Clearing environment-specific caches...')
            ->assertSuccessful();

        // Verify the correct artisan commands were called
        $this->assertContains('package:discover', $this->calledCommands);
        $this->assertContains('config:clear', $this->calledCommands);
        $this->assertContains('route:clear', $this->calledCommands);
        $this->assertContains('event:clear', $this->calledCommands);
        $this->assertContains('view:cache', $this->calledCommands);
        $this->assertContains('cache:clear', $this->calledCommands);
        $this->assertContains('queue:restart', $this->calledCommands);
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

        // Run deploy:refresh - the mock prevents actual cache operations
        $this->artisan('deploy:refresh')
            ->assertSuccessful();

        // Verify package:discover was called (verifyBootstrapCache behavior)
        $this->assertContains('package:discover', $this->calledCommands);

        // Verify files still exist and have content (weren't corrupted by mock)
        $this->assertFileExists($servicesPath);
        $this->assertFileExists($packagesPath);
        $this->assertGreaterThan(0, filesize($servicesPath), 'services.php should not be empty');
        $this->assertGreaterThan(0, filesize($packagesPath), 'packages.php should not be empty');
    }

    public function test_restarts_queue_workers(): void
    {
        $this->artisan('deploy:refresh')
            ->expectsOutput('Restarting queue workers...')
            ->assertSuccessful();

        $this->assertContains('queue:restart', $this->calledCommands);
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

    public function test_calls_cache_commands_in_correct_order(): void
    {
        $this->artisan('deploy:refresh')
            ->assertSuccessful();

        // Verify package:discover is called first (in verifyBootstrapCache)
        $packageDiscoverIndex = array_search('package:discover', $this->calledCommands);
        $configClearIndex = array_search('config:clear', $this->calledCommands);

        $this->assertNotFalse($packageDiscoverIndex);
        $this->assertNotFalse($configClearIndex);
        $this->assertLessThan($configClearIndex, $packageDiscoverIndex,
            'package:discover should be called before config:clear');
    }
}
