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

    public function test_process_expired_from_spatial_index_with_no_messages(): void
    {
        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(0, $count);
    }

    public function test_process_expired_from_spatial_index_processes_messages(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Insert into messages_spatial table.
        \DB::statement("INSERT INTO messages_spatial (msgid, point, successful) VALUES ({$message->id}, ST_GeomFromText('POINT(0 0)', 3857), 0)");

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(1, $count);

        // Check outcome was created.
        $outcome = MessageOutcome::where('msgid', $message->id)->first();
        $this->assertNotNull($outcome);
        $this->assertEquals(MessageOutcome::OUTCOME_EXPIRED, $outcome->outcome);

        // Check entry removed from spatial index.
        $this->assertDatabaseMissing('messages_spatial', ['msgid' => $message->id]);
    }

    public function test_process_expired_from_spatial_index_skips_successful(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Insert into messages_spatial table with successful=1.
        \DB::statement("INSERT INTO messages_spatial (msgid, point, successful) VALUES ({$message->id}, ST_GeomFromText('POINT(0 0)', 3857), 1)");

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(0, $count);
    }

    public function test_process_expired_from_spatial_skips_messages_with_outcome(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Create existing outcome.
        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        // Insert into messages_spatial table.
        \DB::statement("INSERT INTO messages_spatial (msgid, point, successful) VALUES ({$message->id}, ST_GeomFromText('POINT(0 0)', 3857), 0)");

        $count = $this->service->processExpiredFromSpatialIndex();

        $this->assertEquals(1, $count);

        // Check no new outcome was created (only the existing TAKEN).
        $outcomes = MessageOutcome::where('msgid', $message->id)->get();
        $this->assertEquals(1, $outcomes->count());
        $this->assertEquals(MessageOutcome::OUTCOME_TAKEN, $outcomes->first()->outcome);
    }

    public function test_expire_lookback_days_constant(): void
    {
        $this->assertEquals(90, MessageExpiryService::EXPIRE_LOOKBACK_DAYS);
    }
}
