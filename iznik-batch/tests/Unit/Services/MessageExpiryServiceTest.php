<?php

namespace Tests\Unit\Services;

use App\Mail\Message\DeadlineReached;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\MessageOutcome;
use App\Services\MessageExpiryService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MessageExpiryServiceTest extends TestCase
{
    protected MessageExpiryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MessageExpiryService();
        Mail::fake();
    }

    public function test_process_deadline_expired_with_no_messages(): void
    {
        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, $stats['emails_sent']);
        $this->assertEquals(0, $stats['errors']);
        Mail::assertNothingSent();
    }

    public function test_process_deadline_expired_marks_message(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        // Create message with expired deadline.
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Test Item (TestLocation)',
            'textbody' => 'Test message',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'deadline' => now()->subDay(), // Expired yesterday.
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);

        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(1, $stats['processed']);

        // Check outcome was created.
        $outcome = MessageOutcome::where('msgid', $message->id)->first();
        $this->assertNotNull($outcome);
        $this->assertEquals(MessageOutcome::OUTCOME_EXPIRED, $outcome->outcome);
    }

    public function test_process_deadline_expired_sends_email(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Test Item (TestLocation)',
            'textbody' => 'Test message',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'deadline' => now()->subDay(),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);

        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(1, $stats['emails_sent']);
        Mail::assertSent(DeadlineReached::class);
    }

    public function test_skips_messages_with_existing_outcome(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Test Item (TestLocation)',
            'textbody' => 'Test message',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'deadline' => now()->subDay(),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);

        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        // Add existing outcome.
        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $stats = $this->service->processDeadlineExpired();

        // Should not process because there's already an outcome.
        $this->assertEquals(0, $stats['processed']);
    }

    public function test_skips_messages_with_future_deadline(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Test Item (TestLocation)',
            'textbody' => 'Test message',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'deadline' => now()->addDay(), // Future deadline.
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);

        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(0, $stats['processed']);
        Mail::assertNothingSent();
    }

    public function test_skips_messages_without_deadline(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        // Create message without deadline.
        $this->createTestMessage($user, $group);

        $stats = $this->service->processDeadlineExpired();

        $this->assertEquals(0, $stats['processed']);
        Mail::assertNothingSent();
    }
}
