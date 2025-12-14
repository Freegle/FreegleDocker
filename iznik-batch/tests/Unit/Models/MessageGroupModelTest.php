<?php

namespace Tests\Unit\Models;

use App\Models\MessageGroup;
use Tests\TestCase;

class MessageGroupModelTest extends TestCase
{
    public function test_message_group_can_be_created(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        // The createTestMessage already creates a MessageGroup record.
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
        ]);
    }

    public function test_message_relationship(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $messageGroup = MessageGroup::where('msgid', $message->id)->first();

        $this->assertEquals($message->id, $messageGroup->message->id);
    }

    public function test_group_relationship(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $messageGroup = MessageGroup::where('msgid', $message->id)->first();

        $this->assertEquals($group->id, $messageGroup->group->id);
    }

    public function test_approved_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $approved = MessageGroup::approved()->get();

        $this->assertTrue($approved->contains('msgid', $message->id));
    }

    public function test_pending_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Create a pending message.
        $message = \App\Models\Message::create([
            'type' => \App\Models\Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Pending (Location)',
            'textbody' => 'Pending message',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
        ]);

        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now(),
        ]);

        $pending = MessageGroup::pending()->get();

        $this->assertTrue($pending->contains('msgid', $message->id));
    }

    public function test_not_deleted_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $notDeleted = MessageGroup::notDeleted()->get();

        $this->assertTrue($notDeleted->contains('msgid', $message->id));
    }

    public function test_is_approved(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $messageGroup = MessageGroup::where('msgid', $message->id)->first();

        $this->assertTrue($messageGroup->isApproved());
    }

    public function test_collection_constants(): void
    {
        $this->assertEquals('Incoming', MessageGroup::COLLECTION_INCOMING);
        $this->assertEquals('Pending', MessageGroup::COLLECTION_PENDING);
        $this->assertEquals('Approved', MessageGroup::COLLECTION_APPROVED);
        $this->assertEquals('Spam', MessageGroup::COLLECTION_SPAM);
        $this->assertEquals('QueuedYahooUser', MessageGroup::COLLECTION_QUEUED_YAHOO);
        $this->assertEquals('Rejected', MessageGroup::COLLECTION_REJECTED);
        $this->assertEquals('QueuedUser', MessageGroup::COLLECTION_QUEUED_USER);
    }

    public function test_approved_by_relationship_type(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $messageGroup = MessageGroup::where('msgid', $message->id)->first();

        // Just test that the relationship is defined correctly.
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $messageGroup->approvedBy());
    }
}
