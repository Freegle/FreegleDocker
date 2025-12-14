<?php

namespace Tests\Unit\Mail;

use App\Mail\Chat\ChatNotification;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use Tests\TestCase;

class ChatNotificationTest extends TestCase
{
    public function test_chat_notification_can_be_constructed(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
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
        ]);

        $mail = new ChatNotification(
            $user2,
            $user1,
            $room,
            collect([$message]),
            ChatRoom::TYPE_USER2USER
        );

        $this->assertInstanceOf(ChatNotification::class, $mail);
    }

    public function test_chat_notification_has_properties(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
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
        ]);

        $mail = new ChatNotification(
            $user2,
            $user1,
            $room,
            collect([$message]),
            ChatRoom::TYPE_USER2USER
        );

        $this->assertEquals($user2->id, $mail->recipient->id);
        $this->assertEquals($user1->id, $mail->sender->id);
        $this->assertEquals($room->id, $mail->chatRoom->id);
        $this->assertNotEmpty($mail->userSite);
        $this->assertStringContainsString('/chats/' . $room->id, $mail->chatUrl);
    }

    public function test_chat_notification_build_returns_self(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
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
        ]);

        $mail = new ChatNotification(
            $user2,
            $user1,
            $room,
            collect([$message]),
            ChatRoom::TYPE_USER2USER
        );

        $result = $mail->build();

        $this->assertInstanceOf(ChatNotification::class, $result);
    }

    public function test_chat_notification_user2user_subject(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'John Doe']);
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
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
        ]);

        $mail = new ChatNotification(
            $user2,
            $user1,
            $room,
            collect([$message]),
            ChatRoom::TYPE_USER2USER
        );

        $this->assertStringContainsString('John Doe', $mail->replySubject);
        $this->assertStringContainsString('sent you a message', $mail->replySubject);
    }

    public function test_chat_notification_user2mod_subject(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup(['nameshort' => 'TestGroup']);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $user->id,
            'groupid' => $group->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
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
        ]);

        $mail = new ChatNotification(
            $user,
            null,
            $room,
            collect([$message]),
            ChatRoom::TYPE_USER2MOD
        );

        $this->assertStringContainsString('Message from', $mail->replySubject);
        $this->assertStringContainsString('TestGroup', $mail->replySubject);
    }

    public function test_chat_notification_no_sender_subject(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
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
        ]);

        $mail = new ChatNotification(
            $user2,
            null,
            $room,
            collect([$message]),
            ChatRoom::TYPE_USER2USER
        );

        $this->assertStringContainsString('Someone', $mail->replySubject);
    }

    public function test_chat_notification_with_ref_message(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user1, $group);

        $refMessage = $this->createTestMessage($user1, $group, [
            'subject' => 'OFFER: Test Item (Location)',
        ]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $chatMessage = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
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
            'refmsgid' => $refMessage->id,
        ]);

        $mail = new ChatNotification(
            $user2,
            $user1,
            $room,
            collect([$chatMessage]),
            ChatRoom::TYPE_USER2USER
        );

        $this->assertStringContainsString('Re:', $mail->replySubject);
        $this->assertStringContainsString('OFFER: Test Item', $mail->replySubject);
    }

    public function test_chat_notification_envelope_has_subject(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
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
        ]);

        $mail = new ChatNotification(
            $user2,
            $user1,
            $room,
            collect([$message]),
            ChatRoom::TYPE_USER2USER
        );

        $envelope = $mail->envelope();

        $this->assertNotEmpty($envelope->subject);
    }
}
