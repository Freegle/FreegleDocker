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
            ->expectsOutputToContain('Processing bounce suspensions')
            ->expectsOutputToContain('Suspended (permanent bounces >= 3):')
            ->expectsOutputToContain('Suspended (total bounces >= 50):')
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

    public function test_cleanup_command_runs_successfully(): void
    {
        $this->artisan('users:cleanup')
            ->assertExitCode(0);
    }

    public function test_cleanup_displays_table(): void
    {
        $this->artisan('users:cleanup')
            ->expectsOutputToContain('Running user cleanup')
            ->expectsOutputToContain('Delete Yahoo Groups users')
            ->expectsOutputToContain('Forget inactive users')
            ->expectsOutputToContain('Process GDPR forgets')
            ->expectsOutputToContain('Delete fully forgotten users')
            ->assertExitCode(0);
    }

    public function test_cleanup_dry_run(): void
    {
        $this->artisan('users:cleanup --dry-run')
            ->expectsOutputToContain('DRY RUN')
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

        // Set lastaccess to recent so user is selected (V1: lastaccess > 2 days ago).
        $user->update(['lastaccess' => now()]);

        // Create some messages to generate kudos.
        for ($i = 0; $i < 3; $i++) {
            $this->createTestMessage($user, $group);
        }

        $this->artisan('users:update-kudos')
            ->assertExitCode(0);
    }
}
