<?php

namespace Tests\Feature\Command;

use Tests\TestCase;

class ClearAllCachesCommandTest extends TestCase
{
    public function test_clears_caches(): void
    {
        $this->artisan('clear:all')
            ->expectsOutput('Clearing all caches and restarting services...')
            ->expectsOutput('Clearing caches...')
            ->assertSuccessful();
    }

    public function test_restarts_queue_workers(): void
    {
        $this->artisan('clear:all')
            ->expectsOutput('Restarting queue workers...')
            ->assertSuccessful();
    }

    public function test_attempts_supervisor_restart(): void
    {
        $this->artisan('clear:all')
            ->expectsOutput('Restarting supervisor programs...')
            ->assertSuccessful();
    }
}
