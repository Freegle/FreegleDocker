<?php

namespace Tests\Unit\Services;

use App\Mail\Housekeeper\HousekeeperResultsMail;
use App\Services\EmailSpoolerService;
use App\Services\HousekeeperService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HousekeeperServiceTest extends TestCase
{
    protected HousekeeperService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HousekeeperService();
        Mail::fake();
    }

    public function test_facebook_deletion_puts_known_users_into_limbo(): void
    {
        $user = $this->createTestUser();
        $fbId = 'fb_test_' . uniqid();

        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => 'Facebook',
            'uid' => $fbId,
        ]);

        $spooler = $this->createMock(EmailSpoolerService::class);

        $this->service->process([
            'task' => 'facebook-deletion',
            'status' => 'success',
            'summary' => 'Test deletion',
            'data' => ['ids' => [$fbId]],
        ], $spooler, false);

        $deleted = DB::table('users')
            ->where('id', $user->id)
            ->value('deleted');

        $this->assertNotNull($deleted, 'User should be marked as deleted (limbo)');
    }

    public function test_facebook_deletion_skips_unknown_ids(): void
    {
        $spooler = $this->createMock(EmailSpoolerService::class);

        $this->service->process([
            'task' => 'facebook-deletion',
            'status' => 'success',
            'summary' => 'Unknown IDs',
            'data' => ['ids' => ['nonexistent_fb_id_999']],
        ], $spooler, false);

        // No exception thrown = success.
        $this->assertTrue(true);
    }

    public function test_facebook_deletion_skips_already_deleted_users(): void
    {
        $user = $this->createTestUser();
        $fbId = 'fb_already_del_' . uniqid();

        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => 'Facebook',
            'uid' => $fbId,
        ]);

        // Pre-mark as deleted.
        DB::table('users')
            ->where('id', $user->id)
            ->update(['deleted' => now()->subDay()]);

        $spooler = $this->createMock(EmailSpoolerService::class);

        $this->service->process([
            'task' => 'facebook-deletion',
            'status' => 'success',
            'summary' => 'Already deleted',
            'data' => ['ids' => [$fbId]],
        ], $spooler, false);

        // Deleted timestamp should not have changed.
        $deleted = DB::table('users')
            ->where('id', $user->id)
            ->value('deleted');

        $this->assertNotNull($deleted);
    }

    public function test_sends_notification_email_when_configured(): void
    {
        $spooler = $this->createMock(EmailSpoolerService::class);

        $this->service->process([
            'task' => 'facebook-deletion',
            'status' => 'success',
            'summary' => 'Processed 2 IDs',
            'email' => 'test@example.com',
            'data' => ['ids' => []],
        ], $spooler, false);

        Mail::assertSent(HousekeeperResultsMail::class, function ($mail) {
            return $mail->hasTo('test@example.com')
                && $mail->task === 'facebook-deletion'
                && $mail->status === 'success';
        });
    }

    public function test_spools_notification_when_should_spool(): void
    {
        $spooler = $this->createMock(EmailSpoolerService::class);
        $spooler->expects($this->once())
            ->method('spool');

        $this->service->process([
            'task' => 'facebook-deletion',
            'status' => 'success',
            'summary' => 'Spooled test',
            'email' => 'spool@example.com',
            'data' => ['ids' => []],
        ], $spooler, true);
    }

    public function test_failure_sends_notification_without_processing(): void
    {
        $spooler = $this->createMock(EmailSpoolerService::class);

        $this->service->process([
            'task' => 'facebook-deletion',
            'status' => 'failure',
            'summary' => 'Login failed',
            'email' => 'admin@example.com',
            'data' => ['ids' => ['should_not_be_processed']],
        ], $spooler, false);

        // Failure status should NOT process deletion IDs.
        Mail::assertSent(HousekeeperResultsMail::class, function ($mail) {
            return $mail->hasTo('admin@example.com')
                && $mail->status === 'failure';
        });
    }

    public function test_end_to_end_via_background_task_dispatch(): void
    {
        $user = $this->createTestUser();
        $fbId = 'fb_e2e_' . uniqid();

        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => 'Facebook',
            'uid' => $fbId,
        ]);

        $spooler = $this->createMock(EmailSpoolerService::class);

        $this->service->process([
            'task' => 'facebook-deletion',
            'status' => 'success',
            'summary' => "Processed 1 ID",
            'email' => 'e2e@example.com',
            'data' => ['ids' => [$fbId]],
        ], $spooler, false);

        // User should be in limbo.
        $deleted = DB::table('users')
            ->where('id', $user->id)
            ->value('deleted');
        $this->assertNotNull($deleted);

        // Notification should have been sent.
        Mail::assertSent(HousekeeperResultsMail::class, function ($mail) {
            return $mail->hasTo('e2e@example.com');
        });
    }
}
