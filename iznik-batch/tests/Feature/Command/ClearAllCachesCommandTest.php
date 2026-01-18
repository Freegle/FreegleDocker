<?php

namespace Tests\Feature\Command;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ClearAllCachesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock Artisan::call() to prevent actual cache operations.
        // clear:all delegates to deploy:refresh which clears views and caches.
        Artisan::shouldReceive('call')
            ->andReturn(0); // Success
    }

    public function test_shows_deprecation_warning(): void
    {
        $this->artisan('clear:all')
            ->expectsOutput('clear:all is deprecated. Use deploy:refresh instead.')
            ->assertSuccessful();
    }

    public function test_delegates_to_deploy_refresh(): void
    {
        $this->artisan('clear:all')
            ->expectsOutput('Refreshing application after deployment...')
            ->assertSuccessful();
    }
}
