<?php

namespace Tests\Unit\Queue;

use App\Mail\Newsfeed\ChitchatReportMail;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProcessBackgroundTasksCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the background_tasks table exists in the test database.
        DB::statement('CREATE TABLE IF NOT EXISTS background_tasks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_type VARCHAR(50) NOT NULL,
            data JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at TIMESTAMP NULL,
            failed_at TIMESTAMP NULL,
            error_message TEXT NULL,
            attempts INT UNSIGNED DEFAULT 0,
            INDEX idx_task_type (task_type),
            INDEX idx_pending (processed_at, created_at)
        )');
    }

    protected function tearDown(): void
    {
        // Clean up any tasks created during tests.
        DB::table('background_tasks')->truncate();
        parent::tearDown();
    }

    public function test_processes_push_notify_group_mods_task(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Owner']);

        // Insert a task simulating what Go would insert.
        DB::table('background_tasks')->insert([
            'task_type' => 'push_notify_group_mods',
            'data' => json_encode(['group_id' => $group->id]),
            'created_at' => now(),
        ]);

        // Mock the push service - Firebase won't be configured in test.
        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyGroupMods')
            ->once()
            ->with($group->id)
            ->andReturn(1);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify task was marked as processed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
        $this->assertNull($task->failed_at);
        $this->assertEquals(1, $task->attempts);
    }

    public function test_processes_email_chitchat_report_task(): void
    {
        Mail::fake();

        DB::table('background_tasks')->insert([
            'task_type' => 'email_chitchat_report',
            'data' => json_encode([
                'user_id' => 12345,
                'user_name' => 'Test Reporter',
                'user_email' => 'reporter@test.com',
                'newsfeed_id' => 67890,
                'reason' => 'Inappropriate content',
            ]),
            'created_at' => now(),
        ]);

        // Mock push service (not needed for this test but required by command).
        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify email was sent.
        Mail::assertSent(ChitchatReportMail::class, function (ChitchatReportMail $mail) {
            return $mail->reporterName === 'Test Reporter'
                && $mail->reporterId === 12345
                && $mail->reporterEmail === 'reporter@test.com'
                && $mail->newsfeedId === 67890
                && $mail->reason === 'Inappropriate content';
        });

        // Verify task was marked as processed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_skips_already_processed_tasks(): void
    {
        DB::table('background_tasks')->insert([
            'task_type' => 'push_notify_group_mods',
            'data' => json_encode(['group_id' => 1]),
            'created_at' => now(),
            'processed_at' => now(),  // Already processed.
        ]);

        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldNotReceive('notifyGroupMods');

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();
    }

    public function test_marks_failed_after_max_attempts(): void
    {
        DB::table('background_tasks')->insert([
            'task_type' => 'unknown_task_type',
            'data' => json_encode(['test' => TRUE]),
            'created_at' => now(),
            'attempts' => 2,  // Already tried twice.
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Should be marked as permanently failed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->failed_at);
        $this->assertNotNull($task->error_message);
        $this->assertStringContains('Unknown task type', $task->error_message);
    }

    public function test_handles_missing_required_fields(): void
    {
        // Email report with missing required fields.
        DB::table('background_tasks')->insert([
            'task_type' => 'email_chitchat_report',
            'data' => json_encode(['user_id' => 123]),  // Missing user_name, user_email, etc.
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Should have recorded the error but not permanently failed yet (attempts < 3).
        $task = DB::table('background_tasks')->first();
        $this->assertNull($task->processed_at);
        $this->assertNotNull($task->error_message);
        $this->assertEquals(1, $task->attempts);
    }

    public function test_processes_multiple_tasks_in_order(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();

        // Insert tasks in order.
        DB::table('background_tasks')->insert([
            'task_type' => 'push_notify_group_mods',
            'data' => json_encode(['group_id' => $group->id]),
            'created_at' => now()->subSeconds(2),
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_chitchat_report',
            'data' => json_encode([
                'user_id' => 1,
                'user_name' => 'Reporter',
                'user_email' => 'test@test.com',
                'newsfeed_id' => 100,
                'reason' => 'Test reason',
            ]),
            'created_at' => now(),
        ]);

        $mockPush = $this->mock(PushNotificationService::class);
        $mockPush->shouldReceive('notifyGroupMods')
            ->once()
            ->with($group->id)
            ->andReturn(0);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Both should be processed.
        $tasks = DB::table('background_tasks')->get();
        $this->assertCount(2, $tasks);
        foreach ($tasks as $task) {
            $this->assertNotNull($task->processed_at);
        }
    }

    public function test_exits_after_max_iterations(): void
    {
        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 3,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Command should have exited cleanly after 3 iterations.
    }

    /**
     * Custom assertion for string containment (PHPUnit 10+ compatible).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'."
        );
    }
}
