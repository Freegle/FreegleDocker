<?php

namespace Tests\Feature\Purge;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeSessionsCommandTest extends TestCase
{
    public function test_purge_sessions_command_runs_successfully(): void
    {
        $this->artisan('purge:sessions')
            ->assertExitCode(0);
    }

    public function test_purge_sessions_displays_stats(): void
    {
        $this->artisan('purge:sessions')
            ->expectsOutputToContain('Purging session data')
            ->expectsOutputToContain('Purging old sessions')
            ->expectsOutputToContain('Purging old login links')
            ->expectsOutputToContain('Session purge complete')
            ->assertExitCode(0);
    }

    public function test_purge_sessions_with_custom_days(): void
    {
        $this->artisan('purge:sessions', ['--days' => 7])
            ->assertExitCode(0);
    }

    public function test_purge_sessions_purges_old_data(): void
    {
        $user = $this->createTestUser();

        // Create an old session.
        DB::table('sessions')->insert([
            'userid' => $user->id,
            'series' => 1,
            'token' => 'old-' . uniqid(),
            'date' => now()->subDays(40),
            'lastactive' => now()->subDays(40),
        ]);

        $this->artisan('purge:sessions', ['--days' => 31])
            ->assertExitCode(0);

        $this->assertEquals(0, DB::table('sessions')
            ->where('userid', $user->id)
            ->where('series', 1)
            ->count());
    }
}
