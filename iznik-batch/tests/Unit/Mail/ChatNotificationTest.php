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

    public function test_chat_notification_returns_recipient_user_id(): void
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

        // Use reflection to call protected getRecipientUserId method.
        $reflection = new \ReflectionMethod($mail, 'getRecipientUserId');
        $userId = $reflection->invoke($mail);

        $this->assertEquals($user2->id, $userId, 'getRecipientUserId should return the recipient user ID');
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

    public function test_chat_notification_user2user_subject_without_interested_message(): void
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

        // Without an "interested in" message, we get the fallback subject.
        $this->assertEquals('[Freegle] You have a new message', $mail->replySubject);
    }

    public function test_chat_notification_user2user_subject_with_interested_message(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'John Doe']);
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup(['nameshort' => 'TestGroup', 'namefull' => 'Test Freegle Group']);
        $this->createMembership($user1, $group);

        $refMessage = $this->createTestMessage($user1, $group, [
            'subject' => 'OFFER: Double Bed Frame (London)',
        ]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        // Create an "interested in" message first - this is what determines the subject.
        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'message' => 'Is this still available?',
            'type' => ChatMessage::TYPE_INTERESTED,
            'date' => now()->subMinutes(5),
            'refmsgid' => $refMessage->id,
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        // Now create a follow-up message.
        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Yes it is!',
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

        // Subject should be based on the "interested in" message's referenced item.
        $this->assertStringStartsWith('Regarding:', $mail->replySubject);
        $this->assertStringContainsString('[Test Freegle Group]', $mail->replySubject);
        $this->assertStringContainsString('OFFER: Double Bed Frame (London)', $mail->replySubject);
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

        // USER2MOD subject format: "Your conversation with the {groupName} volunteers"
        $this->assertStringContainsString('Your conversation with the', $mail->replySubject);
        $this->assertStringContainsString('TestGroup', $mail->replySubject);
        $this->assertStringContainsString('volunteers', $mail->replySubject);
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

        // Without an interested message, subject is the fallback.
        $this->assertEquals('[Freegle] You have a new message', $mail->replySubject);
    }

    public function test_chat_notification_with_ref_message_but_no_interested(): void
    {
        // This test verifies that having a refmsgid on the current message is NOT enough -
        // we need an actual TYPE_INTERESTED message in the chat to use the item subject.
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

        // This is a DEFAULT message with a refmsgid, but NOT a TYPE_INTERESTED message.
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

        // Without a TYPE_INTERESTED message in the chat, we get the fallback subject.
        $this->assertEquals('[Freegle] You have a new message', $mail->replySubject);
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
        $group = $this->createTestGroup(['nameshort' => 'TestGroup', 'namefull' => 'Test Freegle Group']);
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

        // Subject should be based on the interested message.
        $this->assertStringContainsString('Regarding:', $mail->replySubject);
        $this->assertStringContainsString('[Test Freegle Group]', $mail->replySubject);
        $this->assertStringContainsString('OFFER: Test Item', $mail->replySubject);

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
        $group = $this->createTestGroup(['nameshort' => 'TestGroup', 'namefull' => 'Test Freegle Group']);
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

        // Create a TYPE_INTERESTED message - this is required for the "Regarding:" subject.
        $chatMessage = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'message' => 'Interested!',
            'type' => ChatMessage::TYPE_INTERESTED,
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
        // Also verify group name is included.
        $this->assertStringContainsString('[Test Freegle Group]', $mail->replySubject);
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

    public function test_chat_notification_shows_amp_indicator_when_enabled(): void
    {
        // Set up AMP config.
        config(['freegle.amp.enabled' => true]);
        config(['freegle.amp.secret' => 'test-secret-key']);

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

        $mail->build();
        $html = $mail->render();

        // Footer should indicate AMP was included.
        $this->assertStringContainsString('sent with AMP', $html);
    }

    public function test_chat_notification_no_amp_indicator_when_disabled(): void
    {
        // Disable AMP.
        config(['freegle.amp.enabled' => false]);
        config(['freegle.amp.secret' => null]);

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

        $mail->build();
        $html = $mail->render();

        // Footer should NOT indicate AMP.
        $this->assertStringNotContainsString('sent with AMP', $html);
    }

    public function test_chat_notification_no_amp_indicator_for_user2mod(): void
    {
        // Enable AMP.
        config(['freegle.amp.enabled' => true]);
        config(['freegle.amp.secret' => 'test-secret-key']);

        $user = $this->createTestUser();
        $group = $this->createTestGroup();

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

        $mail->build();
        $html = $mail->render();

        // AMP is only for User2User chats, so no indicator for User2Mod.
        $this->assertStringNotContainsString('sent with AMP', $html);
    }

    public function test_own_message_notification_sets_flag_correctly(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'Alice']);
        $user2 = $this->createTestUser(['fullname' => 'Bob']);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        // Message sent by user1.
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

        // Normal notification: recipient (user2) is NOT the message sender.
        $normalMail = new ChatNotification(
            $user2,
            $user1,
            $room,
            $message,
            ChatRoom::TYPE_USER2USER
        );
        $this->assertFalse($normalMail->isOwnMessage, 'isOwnMessage should be false for normal notifications');

        // Own message notification: recipient (user1) IS the message sender.
        $ownMail = new ChatNotification(
            $user1,
            $user1,
            $room,
            $message,
            ChatRoom::TYPE_USER2USER
        );
        $this->assertTrue($ownMail->isOwnMessage, 'isOwnMessage should be true when recipient sent the message');
    }

    public function test_own_message_notification_shows_copy_of_your_message(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'Alice']);
        $user2 = $this->createTestUser(['fullname' => 'Bob']);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        // Message sent by user1.
        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Hello Bob!',
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

        // Own message notification: recipient (user1) IS the message sender.
        $mail = new ChatNotification(
            $user1,
            $user1,
            $room,
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $mail->build();
        $html = $mail->render();

        // Should show "Copy of your message to Bob" instead of "New message from Alice".
        $this->assertStringContainsString('Copy of your message to', $html);
        $this->assertStringContainsString('Bob', $html);
        $this->assertStringNotContainsString('New message from Alice', $html);
    }

    public function test_own_message_notification_shows_view_conversation_button(): void
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

        // Own message notification.
        $mail = new ChatNotification(
            $user1,
            $user1,
            $room,
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $mail->build();
        $html = $mail->render();

        // Should show "View conversation" instead of "Reply to Alice".
        $this->assertStringContainsString('View conversation', $html);
        $this->assertStringNotContainsString('Reply to Alice', $html);
    }

    public function test_own_message_notification_does_not_show_you_indicator(): void
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

        // Own message notification.
        $mail = new ChatNotification(
            $user1,
            $user1,
            $room,
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $mail->build();
        $html = $mail->render();

        // Should NOT show "(you)" indicator since the whole email is about your own message.
        $this->assertStringNotContainsString('(you)', $html);
    }

    // Note: test_own_message_notification_hides_about_sender_section is not included
    // because aboutme is stored in a separate users_aboutme table that's complex to
    // set up in tests. The template conditional for hiding "About sender" section
    // when isOwnMessage is true is validated by the isOwnMessage flag tests above.

    public function test_own_message_notification_shows_your_message_label(): void
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

        // Own message notification.
        $mail = new ChatNotification(
            $user1,
            $user1,
            $room,
            $message,
            ChatRoom::TYPE_USER2USER
        );

        $mail->build();
        $html = $mail->render();

        // Should show "Your message" instead of "New message".
        $this->assertStringContainsString('Your message', $html);
    }
}
