<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\UserEmail;
use App\Services\UserManagementService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserManagementServiceTest extends TestCase
{
    protected UserManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserManagementService();
    }

    public function test_process_bounced_emails_marks_invalid(): void
    {
        $user = $this->createTestUser();

        // Update the user's email to have bounced (timestamp set) and be validated.
        UserEmail::where('userid', $user->id)
            ->update(['bounced' => now(), 'validated' => now()]);

        $stats = $this->service->processBouncedEmails();

        $this->assertEquals(1, $stats['marked_invalid']);

        // Verify email is now invalid (validated set to NULL).
        $email = UserEmail::where('userid', $user->id)->first();
        $this->assertNull($email->validated);
    }

    public function test_process_bounced_emails_ignores_non_bounced(): void
    {
        $user = $this->createTestUser();

        // Update the user's email to be validated but not bounced.
        UserEmail::where('userid', $user->id)
            ->update(['bounced' => NULL, 'validated' => now()]);

        $stats = $this->service->processBouncedEmails();

        $this->assertEquals(0, $stats['marked_invalid']);
    }

    public function test_retention_stats_counts_active_users(): void
    {
        // Create active user.
        $activeUser = User::create([
            'firstname' => 'Active',
            'lastname' => 'User',
            'fullname' => 'Active User',
            'added' => now()->subDays(60),
            'lastaccess' => now()->subDays(5),
        ]);

        // Create inactive user.
        $inactiveUser = User::create([
            'firstname' => 'Inactive',
            'lastname' => 'User',
            'fullname' => 'Inactive User',
            'added' => now()->subDays(120),
            'lastaccess' => now()->subDays(100),
        ]);

        $stats = $this->service->updateRetentionStats();

        $this->assertGreaterThanOrEqual(1, $stats['active_users_30d']);
        $this->assertArrayHasKey('active_users_90d', $stats);
        $this->assertArrayHasKey('new_users_30d', $stats);
        $this->assertArrayHasKey('churned_users', $stats);
    }

    public function test_retention_stats_counts_new_users(): void
    {
        // Create new user.
        User::create([
            'firstname' => 'New',
            'lastname' => 'User',
            'fullname' => 'New User',
            'added' => now()->subDays(5),
            'lastaccess' => now(),
        ]);

        $stats = $this->service->updateRetentionStats();

        $this->assertGreaterThanOrEqual(1, $stats['new_users_30d']);
    }

    public function test_cleanup_inactive_users_anonymizes_old_users(): void
    {
        // Create old inactive user.
        $oldUser = User::create([
            'firstname' => 'Old',
            'lastname' => 'Inactive',
            'fullname' => 'Old Inactive User',
            'added' => now()->subYears(5),
            'lastaccess' => now()->subYears(4),
        ]);

        UserEmail::create([
            'userid' => $oldUser->id,
            'email' => 'old@test.com',
            'preferred' => 1,
            'added' => now()->subYears(5),
        ]);

        $cleaned = $this->service->cleanupInactiveUsers(3);

        $this->assertEquals(1, $cleaned);

        // Verify user is anonymized.
        $oldUser->refresh();
        $this->assertEquals('Deleted', $oldUser->firstname);
        $this->assertNotNull($oldUser->deleted);

        // Verify email is removed.
        $this->assertDatabaseMissing('users_emails', ['userid' => $oldUser->id]);
    }

    public function test_cleanup_inactive_users_ignores_recent_users(): void
    {
        // Create recent user.
        $recentUser = User::create([
            'firstname' => 'Recent',
            'lastname' => 'User',
            'fullname' => 'Recent User',
            'added' => now()->subYears(1),
            'lastaccess' => now()->subMonths(6),
        ]);

        $cleaned = $this->service->cleanupInactiveUsers(3);

        $this->assertEquals(0, $cleaned);

        // Verify user is not anonymized.
        $recentUser->refresh();
        $this->assertEquals('Recent', $recentUser->firstname);
        $this->assertNull($recentUser->deleted);
    }

    public function test_merge_duplicates_with_no_duplicates(): void
    {
        $user = $this->createTestUser();

        $stats = $this->service->mergeDuplicates();

        $this->assertEquals(0, $stats['duplicates_found']);
    }
}
