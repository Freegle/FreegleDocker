<?php

namespace Tests;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /**
     * Create a test user with an email address.
     */
    protected function createTestUser(array $attributes = []): User
    {
        $user = User::create(array_merge([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ], $attributes));

        // Create email for user.
        $email = 'test' . $user->id . '@test.com';
        UserEmail::create([
            'userid' => $user->id,
            'email' => $email,
            'preferred' => 1,
            'added' => now(),
        ]);

        return $user->fresh();
    }

    /**
     * Create a test Freegle group.
     */
    protected function createTestGroup(array $attributes = []): Group
    {
        $uniqueId = uniqid();
        return Group::create(array_merge([
            'nameshort' => 'TestGroup' . $uniqueId,
            'namefull' => 'Test Freegle Group ' . $uniqueId,
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5074,
            'lng' => -0.1278,
            'onhere' => 1,
            'publish' => 1,
        ], $attributes));
    }

    /**
     * Create a membership for a user in a group.
     */
    protected function createMembership(User $user, Group $group, array $attributes = []): Membership
    {
        return Membership::create(array_merge([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'added' => now(),
        ], $attributes));
    }

    /**
     * Create a test message (offer/wanted).
     */
    protected function createTestMessage(User $user, Group $group, array $attributes = []): Message
    {
        $message = Message::create(array_merge([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Test Item (TestLocation)',
            'textbody' => 'This is a test offer message.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ], $attributes));

        // Create messages_groups entry.
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        return $message->fresh();
    }

    /**
     * Create multiple test messages.
     */
    protected function createTestMessages(User $user, Group $group, int $count = 3): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = $this->createTestMessage($user, $group, [
                'subject' => 'OFFER: Test Item ' . ($i + 1) . ' (TestLocation)',
                'type' => $i % 2 === 0 ? Message::TYPE_OFFER : Message::TYPE_WANTED,
            ]);
        }
        return $messages;
    }

    /**
     * Create a test chat room between two users.
     */
    protected function createTestChatRoom(User $user1, User $user2, array $attributes = []): ChatRoom
    {
        return ChatRoom::create(array_merge([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ], $attributes));
    }

    /**
     * Create a test chat message.
     */
    protected function createTestChatMessage(ChatRoom $room, User $user, array $attributes = []): ChatMessage
    {
        return ChatMessage::create(array_merge([
            'chatid' => $room->id,
            'userid' => $user->id,
            'message' => 'Test message',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ], $attributes));
    }
}
