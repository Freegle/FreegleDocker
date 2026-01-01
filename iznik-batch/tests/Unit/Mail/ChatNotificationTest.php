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
            $message,
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
            $message,
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
            $message,
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
            $message,
            ChatRoom::TYPE_USER2USER
        );

        // With a message snippet, format is "SenderName: snippet".
        $this->assertStringContainsString('John Doe', $mail->replySubject);
        $this->assertStringContainsString('Test message', $mail->replySubject);
        $this->assertEquals('John Doe: Test message', $mail->replySubject);
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
            $message,
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
            $message,
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
            $chatMessage,
            ChatRoom::TYPE_USER2USER
        );

        $this->assertStringContainsString('Regarding:', $mail->replySubject);
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
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $envelope = $mail->envelope();

        $this->assertNotEmpty($envelope->subject);
    }

    public function test_chat_notification_decodes_emojis(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        // Message with emoji escape sequences (as stored in database).
        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Hello \\u1f600\\u world \\u2764\\u',
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
            $message,
            ChatRoom::TYPE_USER2USER
        );

        // Build the email to trigger message preparation.
        $mail->build();

        // Get the rendered HTML content.
        $html = $mail->render();

        // Verify emojis are decoded - the HTML should contain actual emoji characters.
        $this->assertStringContainsString('😀', $html);
        $this->assertStringContainsString('❤', $html);

        // Verify the escape sequences are NOT present.
        $this->assertStringNotContainsString('\\u1f600\\u', $html);
        $this->assertStringNotContainsString('\\u2764\\u', $html);
    }

    public function test_chat_notification_handles_compound_emojis(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        // Message with compound emoji (flag emoji with two code points).
        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'From \\u1f1ec-1f1e7\\u with love',
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
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $mail->build();
        $html = $mail->render();

        // Verify flag emoji is decoded (🇬🇧).
        $this->assertStringContainsString('🇬🇧', $html);
        $this->assertStringNotContainsString('\\u1f1ec-1f1e7\\u', $html);
    }

    public function test_chat_notification_interested_message_type(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'Alice']);
        $user2 = $this->createTestUser(['fullname' => 'Bob']);
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

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'message' => 'I would love this!',
            'type' => ChatMessage::TYPE_INTERESTED,
            'date' => now(),
            'refmsgid' => $refMessage->id,
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $mail = new ChatNotification(
            $user1,
            $user2,
            $room,
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('I would love this!', $html);
    }

    public function test_chat_notification_promised_message_type(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'Alice']);
        $user2 = $this->createTestUser(['fullname' => 'Bob']);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => '',
            'type' => ChatMessage::TYPE_PROMISED,
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
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('promised', $html);
    }

    public function test_chat_notification_nudge_message_type(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'Alice']);
        $user2 = $this->createTestUser(['fullname' => 'Bob']);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => '',
            'type' => ChatMessage::TYPE_NUDGE,
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
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('Nudge', $html);
        $this->assertStringContainsString('please can you reply', $html);
    }

    public function test_chat_notification_completed_message_type(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'Alice']);
        $user2 = $this->createTestUser(['fullname' => 'Bob']);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => '',
            'type' => ChatMessage::TYPE_COMPLETED,
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
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('no longer available', $html);
    }

    public function test_chat_notification_reply_to_format(): void
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
            'message' => 'Test',
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
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $envelope = $mail->envelope();

        // Reply-to should be in the format notify-{chatid}-{userid}@domain.
        $replyTo = $envelope->replyTo;
        $this->assertNotEmpty($replyTo);
        $this->assertStringContainsString('notify-' . $room->id . '-' . $user2->id, $replyTo[0]->address);
    }

    public function test_chat_notification_from_display_name(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'Alice Smith']);
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
            'message' => 'Test',
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
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $envelope = $mail->envelope();

        // From name should be "SenderName on Freegle".
        $this->assertStringContainsString('Alice Smith', $envelope->from->name);
        $this->assertStringContainsString('on', $envelope->from->name);
    }

    public function test_chat_notification_with_previous_messages(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $prevMessage = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Previous message',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subMinutes(5),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'message' => 'Latest message',
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
            $user1,
            $user2,
            $room,
            $message,
            ChatRoom::TYPE_USER2USER,
            collect([$prevMessage])
        );

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('Latest message', $html);
        $this->assertStringContainsString('Previous message', $html);
    }

    public function test_chat_notification_uses_regarding_instead_of_re(): void
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
            'userid' => $user2->id,
            'message' => 'Interested!',
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
            $user1,
            $user2,
            $room,
            $chatMessage,
            ChatRoom::TYPE_USER2USER
        );

        // Verify "Regarding:" is used instead of "Re:".
        $this->assertStringStartsWith('Regarding:', $mail->replySubject);
        $this->assertStringNotContainsString('Re:', $mail->replySubject);
    }

    public function test_chat_notification_chat_url_contains_room_id(): void
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
            'message' => 'Test',
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
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $this->assertStringContainsString('/chats/' . $room->id, $mail->chatUrl);
    }

    public function test_chat_notification_image_message_type(): void
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
            'message' => '',
            'type' => ChatMessage::TYPE_IMAGE,
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
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('Sent an image', $html);
    }

    public function test_chat_notification_schedule_message_type(): void
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
            'message' => '',
            'type' => ChatMessage::TYPE_SCHEDULE,
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
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('Suggested a collection time', $html);
    }

    public function test_chat_notification_reneged_message_type(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'Alice']);
        $user2 = $this->createTestUser(['fullname' => 'Bob']);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => '',
            'type' => ChatMessage::TYPE_RENEGED,
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
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('cancelled', $html);
        $this->assertStringContainsString('promise', $html);
    }
}
