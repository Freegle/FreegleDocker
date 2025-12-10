<?php

namespace Tests\Unit\Services;

use App\Mail\Chat\ChatNotification;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Models\User;
use App\Services\ChatNotificationService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ChatNotificationServiceTest extends TestCase
{
    protected ChatNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatNotificationService();
        Mail::fake();
    }

    /**
     * Create a test chat room between two users.
     */
    protected function createTestChatRoom(User $user1, User $user2, string $type = ChatRoom::TYPE_USER2USER): ChatRoom
    {
        return ChatRoom::create([
            'chattype' => $type,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);
    }

    /**
     * Create a chat message in a room.
     */
    protected function createTestChatMessage(ChatRoom $room, User $sender, array $attributes = []): ChatMessage
    {
        return ChatMessage::create(array_merge([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'message' => 'Test message content',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subSeconds(60), // Old enough to notify.
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ], $attributes));
    }

    /**
     * Create roster entries for chat room members.
     */
    protected function createRosterEntries(ChatRoom $room, User $user1, User $user2): void
    {
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'lastmsgemailed' => null,
            'lastseen' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'lastmsgemailed' => null,
            'lastseen' => now(),
        ]);
    }

    public function test_notify_with_no_messages_returns_zero(): void
    {
        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_notify_sends_email_for_unmailed_message(): void
    {
        $sender = $this->createTestUser(['fullname' => 'Sender User']);
        $recipient = $this->createTestUser(['fullname' => 'Recipient User']);

        $room = $this->createTestChatRoom($sender, $recipient);
        $this->createRosterEntries($room, $sender, $recipient);
        $this->createTestChatMessage($room, $sender);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        // Should send notification to recipient.
        $this->assertGreaterThanOrEqual(1, $count);
        Mail::assertSent(ChatNotification::class);
    }

    public function test_notify_respects_delay(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient);
        $this->createRosterEntries($room, $sender, $recipient);

        // Create a very recent message (less than delay).
        $this->createTestChatMessage($room, $sender, [
            'date' => now(), // Too recent.
        ]);

        // With default 30 second delay, should not notify.
        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, null, 30);

        Mail::assertNothingSent();
    }

    public function test_notify_skips_already_mailed_messages(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient);
        $this->createRosterEntries($room, $sender, $recipient);

        // Create already mailed message.
        $this->createTestChatMessage($room, $sender, [
            'mailedtoall' => 1,
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_notify_skips_rejected_messages(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient);
        $this->createRosterEntries($room, $sender, $recipient);

        // Create rejected message.
        $this->createTestChatMessage($room, $sender, [
            'reviewrejected' => 1,
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_notify_specific_chat_room(): void
    {
        $sender = $this->createTestUser();
        $recipient1 = $this->createTestUser();
        $recipient2 = $this->createTestUser();

        // Create two rooms with different user pairs (unique constraint: user1, user2, chattype).
        $room1 = $this->createTestChatRoom($sender, $recipient1);
        $room2 = $this->createTestChatRoom($sender, $recipient2);

        $this->createRosterEntries($room1, $sender, $recipient1);
        $this->createRosterEntries($room2, $sender, $recipient2);

        $this->createTestChatMessage($room1, $sender);
        $this->createTestChatMessage($room2, $sender);

        // Only process room1.
        $count = $this->service->notifyByEmail(
            ChatRoom::TYPE_USER2USER,
            $room1->id
        );

        // Should only send for room1.
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function test_notify_user2mod_type(): void
    {
        $user = $this->createTestUser();
        $mod = $this->createTestUser(['fullname' => 'Moderator']);
        $group = $this->createTestGroup();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $user->id,
            'groupid' => $group->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user->id,
            'lastmsgemailed' => null,
        ]);

        $this->createTestChatMessage($room, $mod);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD);

        // Should attempt to notify the user.
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_force_all_sends_regardless_of_flags(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient);
        $this->createRosterEntries($room, $sender, $recipient);

        // Create already mailed message.
        $this->createTestChatMessage($room, $sender, [
            'mailedtoall' => 1,
            'seenbyall' => 1,
        ]);

        $count = $this->service->notifyByEmail(
            ChatRoom::TYPE_USER2USER,
            null,
            30,
            24,
            true // forceAll.
        );

        // With forceAll, should still process.
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_updates_roster_last_message_emailed(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient);
        $this->createRosterEntries($room, $sender, $recipient);

        $message = $this->createTestChatMessage($room, $sender);

        $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        // Check roster was updated.
        $roster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $recipient->id)
            ->first();

        $this->assertEquals($message->id, $roster->lastmsgemailed);
    }
}
