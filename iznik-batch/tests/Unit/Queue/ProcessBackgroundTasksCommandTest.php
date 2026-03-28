<?php

namespace Tests\Unit\Queue;

use App\Mail\Donation\DonateExternalMail;
use App\Mail\Newsfeed\ChitchatReportMail;
use App\Mail\Session\ForgotPasswordMail;
use App\Mail\Session\UnsubscribeConfirmMail;
use App\Mail\Message\ModStdMessageMail;
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

    public function test_processes_email_donate_external_task(): void
    {
        Mail::fake();

        DB::table('background_tasks')->insert([
            'task_type' => 'email_donate_external',
            'data' => json_encode([
                'user_id' => 54321,
                'user_name' => 'Generous Donor',
                'user_email' => 'donor@test.com',
                'amount' => 25.50,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify email was sent with correct data.
        Mail::assertSent(DonateExternalMail::class, function (DonateExternalMail $mail) {
            return $mail->userName === 'Generous Donor'
                && $mail->userId === 54321
                && $mail->userEmail === 'donor@test.com'
                && $mail->amount === 25.50;
        });

        // Verify task was marked as processed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_email_donate_external_fails_with_missing_fields(): void
    {
        DB::table('background_tasks')->insert([
            'task_type' => 'email_donate_external',
            'data' => json_encode(['user_id' => 123]),  // Missing user_name, user_email, amount.
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Should have recorded the error.
        $task = DB::table('background_tasks')->first();
        $this->assertNull($task->processed_at);
        $this->assertNotNull($task->error_message);
        $this->assertStringContains('email_donate_external requires', $task->error_message);
    }

    public function test_processes_email_forgot_password_task(): void
    {
        Mail::fake();

        DB::table('background_tasks')->insert([
            'task_type' => 'email_forgot_password',
            'data' => json_encode([
                'user_id' => 11111,
                'email' => 'forgetful@test.com',
                'reset_url' => 'https://www.ilovefreegle.org/settings?u=11111&k=abc123&src=forgotpass',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify email was sent with correct data.
        Mail::assertSent(ForgotPasswordMail::class, function (ForgotPasswordMail $mail) {
            return $mail->userId === 11111
                && $mail->email === 'forgetful@test.com'
                && $mail->resetUrl === 'https://www.ilovefreegle.org/settings?u=11111&k=abc123&src=forgotpass';
        });

        // Verify task was marked as processed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_processes_email_unsubscribe_task(): void
    {
        Mail::fake();

        DB::table('background_tasks')->insert([
            'task_type' => 'email_unsubscribe',
            'data' => json_encode([
                'user_id' => 22222,
                'email' => 'leaving@test.com',
                'unsub_url' => 'https://www.ilovefreegle.org/unsubscribe/22222?u=22222&k=def456&confirm=1',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify email was sent with correct data.
        Mail::assertSent(UnsubscribeConfirmMail::class, function (UnsubscribeConfirmMail $mail) {
            return $mail->userId === 22222
                && $mail->email === 'leaving@test.com'
                && $mail->unsubUrl === 'https://www.ilovefreegle.org/unsubscribe/22222?u=22222&k=def456&confirm=1';
        });

        // Verify task was marked as processed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
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

    public function test_mod_stdmsg_reject_sends_email_from_group_volunteers(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $posterEmail = $this->createTestUserEmail($poster, ['preferred' => 1]);
        $mod = $this->createTestUser(['fullname' => 'Wendy Moderator']);

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'WANTED: Something (Test AB1)',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Rejected',
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_message_rejected',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'MODERATOR MESSAGE -: WANTED: Something (Test AB1)',
                'body' => 'Dear member, please repost with more detail.',
                'stdmsgid' => 0,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify email was sent with correct from address and content.
        Mail::assertSent(ModStdMessageMail::class, function (ModStdMessageMail $mail) use ($group, $mod) {
            $this->assertEquals('Wendy Moderator', $mail->modName);
            $this->assertEquals($group->nameshort, $mail->groupNameShort);
            $this->assertEquals('MODERATOR MESSAGE -: WANTED: Something (Test AB1)', $mail->stdSubject);
            $this->assertEquals('Dear member, please repost with more detail.', $mail->stdBody);
            return TRUE;
        });

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_mod_stdmsg_approve_without_content_skips_email(): void
    {
        Mail::fake();

        $poster = $this->createTestUser();
        $mod = $this->createTestUser();

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Test item',
            'date' => now(),
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_message_approved',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $mod->id,
                'subject' => '',
                'body' => '',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // No email should be sent for empty stdmsg.
        Mail::assertNothingSent();

        // But the task should be marked as processed (not failed).
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_mod_stdmsg_falls_back_to_messages_groups_for_groupid(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $posterEmail = $this->createTestUserEmail($poster, ['preferred' => 1]);
        $mod = $this->createTestUser(['fullname' => 'Test Mod']);

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'WANTED: Widget',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        // Queue task WITHOUT groupid (old Go API format).
        DB::table('background_tasks')->insert([
            'task_type' => 'email_message_reply',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $mod->id,
                'subject' => 'Re: WANTED: Widget',
                'body' => 'Thanks for posting!',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        Mail::assertSent(ModStdMessageMail::class, function (ModStdMessageMail $mail) use ($group) {
            $this->assertEquals($group->nameshort, $mail->groupNameShort);
            return TRUE;
        });

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_mod_stdmsg_creates_chat_room_and_message(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $posterEmail = $this->createTestUserEmail($poster, ['preferred' => 1]);
        $mod = $this->createTestUser(['fullname' => 'Chat Mod']);

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Chat test',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Rejected',
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_message_rejected',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'Rejection notice',
                'body' => 'Please repost.',
                'stdmsgid' => 0,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify a User2Mod chat room was created.
        $chatRoom = DB::table('chat_rooms')
            ->where('user1', $poster->id)
            ->where('groupid', $group->id)
            ->where('chattype', 'User2Mod')
            ->first();
        $this->assertNotNull($chatRoom, 'User2Mod chat room should be created');

        // Verify a chat message was created.
        $chatMsg = DB::table('chat_messages')
            ->where('chatid', $chatRoom->id)
            ->where('userid', $mod->id)
            ->where('type', 'ModMail')
            ->first();
        $this->assertNotNull($chatMsg, 'ModMail chat message should be created');
        $this->assertEquals($msgId, $chatMsg->refmsgid);
        $this->assertStringContains('Rejection notice', $chatMsg->message);
        $this->assertStringContains('Please repost.', $chatMsg->message);
    }

    public function test_message_outcome_logs_and_notifies_interested_users(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $replier = $this->createTestUser();

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Test item',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        // Create a User2User chat room where the replier expressed interest.
        $chatRoomId = DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User',
            'user1' => $poster->id,
            'user2' => $replier->id,
        ]);
        DB::table('chat_messages')->insert([
            'chatid' => $chatRoomId,
            'userid' => $replier->id,
            'message' => 'Is this still available?',
            'type' => 'Interested',
            'refmsgid' => $msgId,
            'date' => now(),
            'reviewrequired' => 0,
            'reviewrejected' => 0,
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'message_outcome',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $poster->id,
                'outcome' => 'Taken',
                'happiness' => 'Happy',
                'comment' => 'Great experience',
                'userid' => 0,
                'message' => 'Sorry, this has now been taken.',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify log entry was created.
        $log = DB::table('logs')
            ->where('msgid', $msgId)
            ->where('type', 'Message')
            ->where('subtype', 'Outcome')
            ->first();
        $this->assertNotNull($log, 'Outcome log entry should be created');
        $this->assertEquals($poster->id, $log->user);
        $this->assertEquals($group->id, $log->groupid);
        $this->assertStringContains('Taken', $log->text);

        // Verify the interested replier got a Completed chat message.
        $completedMsg = DB::table('chat_messages')
            ->where('chatid', $chatRoomId)
            ->where('type', 'Completed')
            ->first();
        $this->assertNotNull($completedMsg, 'Completed chat message should be created');
        $this->assertEquals($poster->id, $completedMsg->userid);
        $this->assertEquals($msgId, $completedMsg->refmsgid);
        $this->assertEquals('Sorry, this has now been taken.', $completedMsg->message);

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }

    public function test_message_outcome_skips_message_for_unpromised_chats(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $replier = $this->createTestUser();

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Test item',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        $chatRoomId = DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User',
            'user1' => $poster->id,
            'user2' => $replier->id,
        ]);
        // Interested message.
        DB::table('chat_messages')->insert([
            'chatid' => $chatRoomId,
            'userid' => $replier->id,
            'message' => 'I want this',
            'type' => 'Interested',
            'refmsgid' => $msgId,
            'date' => now(),
            'reviewrequired' => 0,
            'reviewrejected' => 0,
        ]);
        // Reneged (unpromised) message — poster changed their mind.
        DB::table('chat_messages')->insert([
            'chatid' => $chatRoomId,
            'userid' => $poster->id,
            'message' => null,
            'type' => 'Reneged',
            'refmsgid' => $msgId,
            'date' => now(),
            'reviewrequired' => 0,
            'reviewrejected' => 0,
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'message_outcome',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $poster->id,
                'outcome' => 'Taken',
                'happiness' => '',
                'comment' => '',
                'userid' => 0,
                'message' => 'Sorry, this has been taken.',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // The Completed message should have null message body (unpromised).
        $completedMsg = DB::table('chat_messages')
            ->where('chatid', $chatRoomId)
            ->where('type', 'Completed')
            ->first();
        $this->assertNotNull($completedMsg, 'Completed chat message should be created');
        $this->assertNull($completedMsg->message, 'Message should be null for unpromised chat');
    }

    public function test_message_outcome_excludes_user_who_got_item(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $taker = $this->createTestUser();

        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $poster->id,
            'subject' => 'OFFER: Test item',
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
        ]);

        $chatRoomId = DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User',
            'user1' => $poster->id,
            'user2' => $taker->id,
        ]);
        DB::table('chat_messages')->insert([
            'chatid' => $chatRoomId,
            'userid' => $taker->id,
            'message' => 'I want this',
            'type' => 'Interested',
            'refmsgid' => $msgId,
            'date' => now(),
            'reviewrequired' => 0,
            'reviewrejected' => 0,
        ]);

        // Record that this user actually got the item.
        DB::table('messages_by')->insert([
            'msgid' => $msgId,
            'userid' => $taker->id,
            'count' => 1,
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'message_outcome',
            'data' => json_encode([
                'msgid' => $msgId,
                'byuser' => $poster->id,
                'outcome' => 'Taken',
                'happiness' => '',
                'comment' => '',
                'userid' => $taker->id,
                'message' => 'Sorry, taken.',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // No Completed message should be created — the taker already got it.
        $completedMsg = DB::table('chat_messages')
            ->where('chatid', $chatRoomId)
            ->where('type', 'Completed')
            ->first();
        $this->assertNull($completedMsg, 'Should not notify user who got the item');

        // But the log should still be there.
        $log = DB::table('logs')
            ->where('msgid', $msgId)
            ->where('type', 'Message')
            ->where('subtype', 'Outcome')
            ->first();
        $this->assertNotNull($log);
    }

    public function test_mod_stdmsg_for_member_sends_email_and_creates_chat(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $member = $this->createTestUser();
        $memberEmail = $this->createTestUserEmail($member, ['preferred' => 1]);
        $mod = $this->createTestUser(['fullname' => 'Test Moderator']);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_mod_stdmsg',
            'data' => json_encode([
                'userid' => $member->id,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => 'Location Required please',
                'body' => 'Hello, can you please advise your postcode?',
                'action' => 'Leave Approved Member',
                'stdmsgid' => 0,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        // Verify email was sent.
        Mail::assertSent(ModStdMessageMail::class, function (ModStdMessageMail $mail) use ($mod) {
            $this->assertEquals('Test Moderator', $mail->modName);
            $this->assertEquals('Location Required please', $mail->stdSubject);
            $this->assertEquals('Hello, can you please advise your postcode?', $mail->stdBody);
            return TRUE;
        });

        // Verify task was processed.
        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);

        // Verify chat room and message were created.
        $chatRoom = DB::table('chat_rooms')
            ->where('user1', $member->id)
            ->where('groupid', $group->id)
            ->where('chattype', 'User2Mod')
            ->first();
        $this->assertNotNull($chatRoom, 'Chat room should be created');

        $chatMsg = DB::table('chat_messages')
            ->where('chatid', $chatRoom->id)
            ->where('userid', $mod->id)
            ->where('type', 'ModMail')
            ->first();
        $this->assertNotNull($chatMsg, 'Chat message should be created');
        $this->assertStringContains('Location Required please', $chatMsg->message);
    }

    public function test_mod_stdmsg_for_member_without_content_skips_email(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('background_tasks')->insert([
            'task_type' => 'email_mod_stdmsg',
            'data' => json_encode([
                'userid' => $member->id,
                'byuser' => $mod->id,
                'groupid' => $group->id,
                'subject' => '',
                'body' => '',
                'action' => 'Leave Approved Member',
                'stdmsgid' => 0,
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', [
            '--max-iterations' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        Mail::assertNothingSent();

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
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
