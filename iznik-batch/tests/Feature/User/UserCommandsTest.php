<?php

namespace Tests\Feature\User;

use App\Models\User;
use App\Models\UserEmail;
use Tests\TestCase;

class UserCommandsTest extends TestCase
{
    public function test_process_bounced_command_runs_successfully(): void
    {
        $this->artisan('mail:bounced')
            ->assertExitCode(0);
    }

    public function test_process_bounced_displays_stats(): void
    {
        $this->artisan('mail:bounced')
            ->expectsOutputToContain('Processing bounced emails')
            ->expectsOutputToContain('Processed:')
            ->expectsOutputToContain('Marked invalid:')
            ->assertExitCode(0);
    }

    public function test_process_bounced_with_bounced_email(): void
    {
        $user = $this->createTestUser();

        // Add a bounced email.
        UserEmail::create([
            'userid' => $user->id,
            'email' => $this->uniqueEmail('bounced'),
            'bounced' => now()->subDays(1),
            'added' => now()->subDays(30),
        ]);

        $this->artisan('mail:bounced')
            ->assertExitCode(0);
    }

    public function test_retention_stats_command_runs_successfully(): void
    {
        $this->artisan('users:retention-stats')
            ->assertExitCode(0);
    }

    public function test_retention_stats_displays_table(): void
    {
        $this->artisan('users:retention-stats')
            ->expectsOutputToContain('Calculating user retention statistics')
            ->expectsOutputToContain('Active users (30 days)')
            ->expectsOutputToContain('Active users (90 days)')
            ->expectsOutputToContain('New users (30 days)')
            ->expectsOutputToContain('Churned users (90-180 days)')
            ->assertExitCode(0);
    }

    public function test_retention_stats_with_active_users(): void
    {
        // Create recently active user.
        $user = User::create([
            'firstname' => 'Active',
            'lastname' => 'User',
            'fullname' => 'Active User',
            'added' => now()->subDays(60),
            'lastaccess' => now()->subDays(5),
        ]);

        UserEmail::create([
            'userid' => $user->id,
            'email' => $this->uniqueEmail('active'),
            'preferred' => 1,
            'added' => now(),
        ]);

        $this->artisan('users:retention-stats')
            ->assertExitCode(0);
    }

    public function test_update_kudos_command_runs_successfully(): void
    {
        $this->artisan('users:update-kudos')
            ->assertExitCode(0);
    }

    public function test_update_kudos_displays_stats(): void
    {
        $this->artisan('users:update-kudos')
            ->expectsOutputToContain('Updating user kudos')
            ->expectsOutputToContain('Updated kudos for')
            ->assertExitCode(0);
    }

    public function test_update_kudos_with_users(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Create some messages to generate kudos.
        for ($i = 0; $i < 3; $i++) {
            $this->createTestMessage($user, $group);
        }

        $this->artisan('users:update-kudos')
            ->assertExitCode(0);
    }
}
