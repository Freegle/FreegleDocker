<?php

namespace Tests\Feature\Console;

use App\Models\EmailQueueItem;
use App\Services\EmailSpoolerService;
use Tests\TestCase;

class ProcessEmailQueueCommandTest extends TestCase
{
    protected string $spoolDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a unique spool directory for each test.
        $this->spoolDir = storage_path('spool/mail-queue-test-' . uniqid());

        $spooler = new EmailSpoolerService();
        $reflection = new \ReflectionClass($spooler);

        foreach (['spoolDir' => '', 'pendingDir' => '/pending', 'sendingDir' => '/sending', 'failedDir' => '/failed', 'sentDir' => '/sent'] as $prop => $suffix) {
            $property = $reflection->getProperty($prop);
            $property->setAccessible(TRUE);
            $property->setValue($spooler, $this->spoolDir . $suffix);
        }

        $ensureMethod = $reflection->getMethod('ensureDirectoriesExist');
        $ensureMethod->setAccessible(TRUE);
        $ensureMethod->invoke($spooler);

        $this->app->instance(EmailSpoolerService::class, $spooler);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->spoolDir)) {
            $this->recursiveDelete($this->spoolDir);
        }

        parent::tearDown();
    }

    protected function recursiveDelete(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function test_processes_welcome_email_from_queue(): void
    {
        $user = $this->createTestUser(['fullname' => 'Welcome Test User']);

        EmailQueueItem::create([
            'email_type' => 'welcome',
            'user_id' => $user->id,
        ]);

        $this->artisan('mail:queue:process')
            ->assertExitCode(0);

        // Verify the item was marked as processed.
        $item = EmailQueueItem::where('user_id', $user->id)
            ->where('email_type', 'welcome')
            ->first();
        $this->assertNotNull($item->processed_at, 'Item should be marked as processed');
        $this->assertNull($item->failed_at, 'Item should not have failed');
    }

    public function test_marks_unknown_type_as_failed(): void
    {
        $user = $this->createTestUser(['fullname' => 'Unknown Type User']);

        EmailQueueItem::create([
            'email_type' => 'nonexistent_type',
            'user_id' => $user->id,
        ]);

        $this->artisan('mail:queue:process')
            ->assertExitCode(1);

        $item = EmailQueueItem::where('user_id', $user->id)
            ->where('email_type', 'nonexistent_type')
            ->first();
        $this->assertNotNull($item->failed_at, 'Item should be marked as failed');
        $this->assertStringContainsString('Unknown email type', $item->error_message);
    }

    public function test_marks_missing_user_as_failed(): void
    {
        EmailQueueItem::create([
            'email_type' => 'welcome',
            'user_id' => 999999999,
        ]);

        $this->artisan('mail:queue:process')
            ->assertExitCode(1);

        $item = EmailQueueItem::where('user_id', 999999999)
            ->where('email_type', 'welcome')
            ->first();
        $this->assertNotNull($item->failed_at);
        $this->assertStringContainsString('not found', $item->error_message);
    }

    public function test_skips_already_processed_items(): void
    {
        $user = $this->createTestUser(['fullname' => 'Already Processed User']);

        EmailQueueItem::create([
            'email_type' => 'welcome',
            'user_id' => $user->id,
            'processed_at' => now(),
        ]);

        $this->artisan('mail:queue:process')
            ->expectsOutputToContain('No pending')
            ->assertExitCode(0);
    }

    public function test_skips_already_failed_items(): void
    {
        $user = $this->createTestUser(['fullname' => 'Already Failed User']);

        EmailQueueItem::create([
            'email_type' => 'welcome',
            'user_id' => $user->id,
            'failed_at' => now(),
            'error_message' => 'Previous failure',
        ]);

        $this->artisan('mail:queue:process')
            ->expectsOutputToContain('No pending')
            ->assertExitCode(0);
    }

    public function test_stats_option_shows_counts(): void
    {
        $user = $this->createTestUser(['fullname' => 'Stats User']);

        // Create items in different states.
        EmailQueueItem::create([
            'email_type' => 'welcome',
            'user_id' => $user->id,
        ]);
        EmailQueueItem::create([
            'email_type' => 'welcome',
            'user_id' => $user->id,
            'processed_at' => now(),
        ]);

        $this->artisan('mail:queue:process', ['--stats' => TRUE])
            ->assertExitCode(0);
    }

    public function test_processes_items_in_order(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'Order User 1']);
        $user2 = $this->createTestUser(['fullname' => 'Order User 2']);

        // Create items - user2 first (older), user1 second (newer).
        EmailQueueItem::create([
            'email_type' => 'welcome',
            'user_id' => $user2->id,
            'created_at' => now()->subMinutes(5),
        ]);
        EmailQueueItem::create([
            'email_type' => 'welcome',
            'user_id' => $user1->id,
            'created_at' => now(),
        ]);

        $this->artisan('mail:queue:process')
            ->assertExitCode(0);

        // Both should be processed.
        $this->assertNotNull(
            EmailQueueItem::where('user_id', $user2->id)->first()->processed_at
        );
        $this->assertNotNull(
            EmailQueueItem::where('user_id', $user1->id)->first()->processed_at
        );
    }

    public function test_respects_limit_option(): void
    {
        $user = $this->createTestUser(['fullname' => 'Limit User']);

        // Create 3 pending items.
        for ($i = 0; $i < 3; $i++) {
            EmailQueueItem::create([
                'email_type' => 'welcome',
                'user_id' => $user->id,
            ]);
        }

        // Process only 1.
        $this->artisan('mail:queue:process', ['--limit' => 1])
            ->assertExitCode(0);

        $processedCount = EmailQueueItem::where('user_id', $user->id)
            ->whereNotNull('processed_at')
            ->count();
        $pendingCount = EmailQueueItem::where('user_id', $user->id)
            ->pending()
            ->count();

        $this->assertEquals(1, $processedCount, 'Should process exactly 1 item');
        $this->assertEquals(2, $pendingCount, 'Should leave 2 items pending');
    }

    public function test_not_yet_implemented_types_fail_gracefully(): void
    {
        $user = $this->createTestUser(['fullname' => 'Not Impl User']);
        $email = $this->uniqueEmail('notimpl');

        \App\Models\UserEmail::where('userid', $user->id)->update(['email' => $email]);

        EmailQueueItem::create([
            'email_type' => 'forgot_password',
            'user_id' => $user->id,
            'extra_data' => json_encode(['email' => $email]),
        ]);

        $this->artisan('mail:queue:process')
            ->assertExitCode(1);

        $item = EmailQueueItem::where('user_id', $user->id)->first();
        $this->assertNotNull($item->failed_at);
        $this->assertStringContainsString('not yet implemented', $item->error_message);
    }
}
