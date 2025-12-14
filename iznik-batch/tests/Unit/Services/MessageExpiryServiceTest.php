<?php

namespace Tests\Unit\Services;

use App\Mail\Message\DeadlineReached;
use App\Models\Message;
use App\Models\MessageOutcome;
use App\Services\MessageExpiryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MessageExpiryServiceTest extends TestCase
{
    protected MessageExpiryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MessageExpiryService();
    }

    public function test_process_deadline_expired_with_no_messages(): void
    {
        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, $stats['emails_sent']);
        $this->assertEquals(0, $stats['errors']);
    }

    public function test_process_deadline_expired_sends_email(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Set deadline to yesterday.
        $message->deadline = now()->subDays(1)->format('Y-m-d');
        $message->save();

        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(1, $stats['processed']);
        $this->assertEquals(1, $stats['emails_sent']);
        $this->assertEquals(0, $stats['errors']);

        Mail::assertSent(DeadlineReached::class);

        // Verify outcome was created.
        $this->assertDatabaseHas('messages_outcomes', [
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_EXPIRED,
        ]);
    }

    public function test_process_deadline_expired_skips_messages_with_outcome(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Set deadline to yesterday and add an outcome.
        $message->deadline = now()->subDays(1)->format('Y-m-d');
        $message->save();

        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(0, $stats['processed']);
        Mail::assertNothingSent();
    }

    public function test_process_deadline_expired_skips_future_deadline(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Set deadline to tomorrow.
        $message->deadline = now()->addDays(1)->format('Y-m-d');
        $message->save();

        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(0, $stats['processed']);
        Mail::assertNothingSent();
    }

    public function test_process_deadline_expired_skips_user_without_email(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Set deadline to yesterday.
        $message->deadline = now()->subDays(1)->format('Y-m-d');
        $message->save();

        // Remove user's email.
        DB::table('users_emails')->where('userid', $user->id)->delete();

        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(1, $stats['processed']);
        // Email should not be sent since user has no email.
        Mail::assertNothingSent();
    }

    public function test_process_expired_from_spatial_index(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Create a spatial index entry.
        DB::table('messages_spatial')->insert([
            'msgid' => $message->id,
            'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
            'successful' => 0,
        ]);

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(1, $count);

        // Verify outcome was created.
        $this->assertDatabaseHas('messages_outcomes', [
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_EXPIRED,
        ]);

        // Verify spatial entry was deleted.
        $this->assertDatabaseMissing('messages_spatial', [
            'msgid' => $message->id,
        ]);
    }

    public function test_process_expired_from_spatial_index_skips_with_existing_outcome(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Create existing outcome.
        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        // Create a spatial index entry.
        DB::table('messages_spatial')->insert([
            'msgid' => $message->id,
            'point' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
            'successful' => 0,
        ]);

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(1, $count);

        // Verify no new expired outcome was created (still just the TAKEN one).
        $this->assertEquals(1, MessageOutcome::where('msgid', $message->id)->count());
        $this->assertEquals(MessageOutcome::OUTCOME_TAKEN, MessageOutcome::where('msgid', $message->id)->first()->outcome);
    }

    public function test_expire_lookback_days_constant(): void
    {
        $this->assertEquals(90, MessageExpiryService::EXPIRE_LOOKBACK_DAYS);
    }
}
