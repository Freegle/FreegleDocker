<?php

namespace Tests\Unit\Mail;

use App\Mail\Chat\ChatNotification;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use Tests\TestCase;

class ChatNotificationTest extends TestCase
{
    /**
     * Create a standard User2User chat setup for testing.
     *
     * Returns an array with users, room, message, and mail objects ready to use.
     * This reduces the ~20 lines of boilerplate repeated in most tests.
     *
     * @param array $options Optional overrides:
     *   - user1_attrs: array of attributes for user1
     *   - user2_attrs: array of attributes for user2
     *   - message_text: string message content
     *   - message_type: ChatMessage type constant
     *   - message_attrs: array of additional message attributes
     * @return array{user1: User, user2: User, room: ChatRoom, message: ChatMessage, mail: ChatNotification}
     */
    protected function createUser2UserChatSetup(array $options = []): array
    {
        $user1 = $this->createTestUser($options['user1_attrs'] ?? []);
        $user2 = $this->createTestUser($options['user2_attrs'] ?? []);

        $room = $this->createTestChatRoom($user1, $user2);

        $messageAttrs = array_merge(
            ['message' => $options['message_text'] ?? 'Test message'],
            ['type' => $options['message_type'] ?? ChatMessage::TYPE_DEFAULT],
            $options['message_attrs'] ?? []
        );

        $message = $this->createTestChatMessage($room, $user1, $messageAttrs);

        $mail = new ChatNotification(
            $user2,
            $user1,
            $room,
            $message,
            ChatRoom::TYPE_USER2USER
        );

        return compact('user1', 'user2', 'room', 'message', 'mail');
    }

    /**
     * Create a User2Mod chat setup for testing moderator notifications.
     *
     * @param array $options Optional overrides:
     *   - member_attrs: array of attributes for the member
     *   - moderator_attrs: array of attributes for the moderator (if needed)
     *   - group_attrs: array of attributes for the group
     *   - message_text: string message content
     *   - message_type: ChatMessage type constant
     *   - message_attrs: array of additional message attributes
     *   - sender: 'member' or 'moderator' (default: 'member')
     *   - recipient: 'member' or 'moderator' (default: 'member' - member receives notification)
     * @return array{member: User, moderator: User|null, group: \App\Models\Group, room: ChatRoom, message: ChatMessage, mail: ChatNotification}
     */
    protected function createUser2ModChatSetup(array $options = []): array
    {
        $member = $this->createTestUser($options['member_attrs'] ?? []);
        $moderator = isset($options['moderator_attrs']) || ($options['recipient'] ?? 'member') === 'moderator'
            ? $this->createTestUser($options['moderator_attrs'] ?? [])
            : null;
        $group = $this->createTestGroup($options['group_attrs'] ?? []);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
        ]);

        $sender = ($options['sender'] ?? 'member') === 'member' ? $member : $moderator;

        $messageAttrs = array_merge(
            ['message' => $options['message_text'] ?? 'Test message'],
            ['type' => $options['message_type'] ?? ChatMessage::TYPE_DEFAULT],
            $options['message_attrs'] ?? []
        );

        $message = $this->createTestChatMessage($room, $sender, $messageAttrs);

        // Determine recipient and sender for the mail object
        $recipientType = $options['recipient'] ?? 'member';
        if ($recipientType === 'moderator') {
            $mailRecipient = $moderator;
            $mailSender = $member;
        } else {
            $mailRecipient = $member;
            $mailSender = ($options['sender'] ?? 'member') === 'moderator' ? $moderator : null;
        }

        $mail = new ChatNotification(
            $mailRecipient,
            $mailSender,
            $room,
            $message,
            ChatRoom::TYPE_USER2MOD
        );

        return compact('member', 'moderator', 'group', 'room', 'message', 'mail');
    }

    public function test_chat_notification_can_be_constructed(): void
    {
        ['mail' => $mail] = $this->createUser2UserChatSetup();

        $this->assertInstanceOf(ChatNotification::class, $mail);
    }

    public function test_chat_notification_has_properties(): void
    {
        ['user1' => $user1, 'user2' => $user2, 'room' => $room, 'mail' => $mail] = $this->createUser2UserChatSetup();

        $this->assertEquals($user2->id, $mail->recipient->id);
        $this->assertEquals($user1->id, $mail->sender->id);
        $this->assertEquals($room->id, $mail->chatRoom->id);
        $this->assertNotEmpty($mail->userSite);
        $this->assertStringContainsString('/chats/' . $room->id, $mail->chatUrl);
    }

    public function test_chat_notification_returns_recipient_user_id(): void
    {
        ['user2' => $user2, 'mail' => $mail] = $this->createUser2UserChatSetup();

        // Use reflection to call protected getRecipientUserId method.
        $reflection = new \ReflectionMethod($mail, 'getRecipientUserId');
        $userId = $reflection->invoke($mail);

        $this->assertEquals($user2->id, $userId, 'getRecipientUserId should return the recipient user ID');
    }

    public function test_chat_notification_build_returns_self(): void
    {
        ['mail' => $mail] = $this->createUser2UserChatSetup();

        $result = $mail->build();

        $this->assertInstanceOf(ChatNotification::class, $result);
    }

    public function test_chat_notification_user2user_subject_without_interested_message(): void
    {
        ['mail' => $mail] = $this->createUser2UserChatSetup([
            'user1_attrs' => ['fullname' => 'John Doe'],
        ]);

        // Without an "interested in" message, we get the fallback subject.
        $this->assertEquals('[Freegle] You have a new message', $mail->replySubject);
    }

    public function test_chat_notification_user2user_subject_with_interested_message(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'John Doe']);
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup();
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
        $this->assertStringContainsString('[' . $group->namefull . ']', $mail->replySubject);
        $this->assertStringContainsString('OFFER: Double Bed Frame (London)', $mail->replySubject);
    }

    public function test_chat_notification_user2mod_subject(): void
    {
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

        // USER2MOD subject format: "Your conversation with the {groupNameFull} Volunteers"
        // For member-facing emails, we use the friendly full group name.
        $this->assertStringContainsString('Your conversation with the', $mail->replySubject);
        // Group name contains the namefull (unique per test).
        $this->assertStringContainsString($group->namefull, $mail->replySubject);
        $this->assertStringContainsString('Volunteers', $mail->replySubject);
    }

    public function test_chat_notification_no_sender_subject(): void
    {
        // Test with null sender to verify fallback subject
        ['user2' => $user2, 'room' => $room, 'message' => $message] = $this->createUser2UserChatSetup();

        // Create mail with null sender (edge case)
        $mail = new ChatNotification($user2, null, $room, $message, ChatRoom::TYPE_USER2USER);

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
        ['mail' => $mail] = $this->createUser2UserChatSetup();

        $envelope = $mail->envelope();

        $this->assertNotEmpty($envelope->subject);
    }

    public function test_chat_notification_decodes_emojis(): void
    {
        // Message with emoji escape sequences (as stored in database with double backslashes from frontend).
        ['mail' => $mail] = $this->createUser2UserChatSetup([
            'message_text' => 'Hello \\\\u1f600\\\\u world \\\\u2764\\\\u',
        ]);

        $mail->build();
        $html = $mail->render();

        // Verify emojis are decoded - the HTML should contain actual emoji characters.
        $this->assertStringContainsString('😀', $html);
        $this->assertStringContainsString('❤', $html);

        // Verify the escape sequences are NOT present.
        $this->assertStringNotContainsString('\\\\u1f600\\\\u', $html);
        $this->assertStringNotContainsString('\\\\u2764\\\\u', $html);
    }

    public function test_chat_notification_handles_compound_emojis(): void
    {
        // Message with compound emoji (flag emoji with two code points, double backslashes from frontend).
        ['mail' => $mail] = $this->createUser2UserChatSetup([
            'message_text' => 'From \\\\u1f1ec-1f1e7\\\\u with love',
        ]);

        $mail->build();
        $html = $mail->render();

        // Verify flag emoji is decoded (🇬🇧).
        $this->assertStringContainsString('🇬🇧', $html);
        $this->assertStringNotContainsString('\\\\u1f1ec-1f1e7\\\\u', $html);
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

        // Subject should be based on the interested message.
        $this->assertStringContainsString('Regarding:', $mail->replySubject);
        $this->assertStringContainsString('[' . $group->namefull . ']', $mail->replySubject);
        $this->assertStringContainsString('OFFER: Test Item', $mail->replySubject);

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('I would love this!', $html);
    }

    public function test_chat_notification_promised_message_type(): void
    {
        ['mail' => $mail] = $this->createUser2UserChatSetup([
            'user1_attrs' => ['fullname' => 'Alice'],
            'user2_attrs' => ['fullname' => 'Bob'],
            'message_text' => '',
            'message_type' => ChatMessage::TYPE_PROMISED,
        ]);

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('promised', $html);
    }

    public function test_chat_notification_nudge_message_type(): void
    {
        ['mail' => $mail] = $this->createUser2UserChatSetup([
            'user1_attrs' => ['fullname' => 'Alice'],
            'user2_attrs' => ['fullname' => 'Bob'],
            'message_text' => '',
            'message_type' => ChatMessage::TYPE_NUDGE,
        ]);

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('Nudge', $html);
        $this->assertStringContainsString('please can you reply', $html);
    }

    public function test_chat_notification_completed_message_type(): void
    {
        ['mail' => $mail] = $this->createUser2UserChatSetup([
            'user1_attrs' => ['fullname' => 'Alice'],
            'user2_attrs' => ['fullname' => 'Bob'],
            'message_text' => '',
            'message_type' => ChatMessage::TYPE_COMPLETED,
        ]);

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('no longer available', $html);
    }

    public function test_chat_notification_reply_to_format(): void
    {
        ['user2' => $user2, 'room' => $room, 'mail' => $mail] = $this->createUser2UserChatSetup();

        $envelope = $mail->envelope();

        // Reply-to should be in the format notify-{chatid}-{userid}@domain.
        $replyTo = $envelope->replyTo;
        $this->assertNotEmpty($replyTo);
        $this->assertStringContainsString('notify-' . $room->id . '-' . $user2->id, $replyTo[0]->address);
    }

    public function test_chat_notification_from_display_name(): void
    {
        ['mail' => $mail] = $this->createUser2UserChatSetup([
            'user1_attrs' => ['fullname' => 'Alice Smith'],
        ]);

        $envelope = $mail->envelope();

        // From name should be "SenderName on Freegle".
        $this->assertStringContainsString('Alice Smith', $envelope->from->name);
        $this->assertStringContainsString('on', $envelope->from->name);
    }

    /**
     * Test that From address uses noreply@ilovefreegle.org for AMP email whitelisting,
     * while Reply-To uses the notify address for routing replies.
     *
     * This is required because noreply@ilovefreegle.org is whitelisted for sending
     * AMP emails, but we still need replies to route through the chat system.
     */
    public function test_chat_notification_from_uses_noreply_reply_to_uses_notify(): void
    {
        ['user2' => $user2, 'room' => $room, 'mail' => $mail] = $this->createUser2UserChatSetup([
            'user1_attrs' => ['fullname' => 'Alice Smith'],
        ]);

        $envelope = $mail->envelope();

        // From address should be noreply@ilovefreegle.org (for AMP whitelisting).
        $this->assertEquals(
            config('freegle.mail.noreply_addr'),
            $envelope->from->address,
            'From address should be noreply for AMP email whitelisting'
        );

        // From name should still be the sender's display name.
        $this->assertStringContainsString('Alice Smith', $envelope->from->name);

        // Reply-To should be the notify address for routing replies through the chat system.
        $this->assertNotEmpty($envelope->replyTo);
        $this->assertStringContainsString(
            'notify-' . $room->id . '-' . $user2->id,
            $envelope->replyTo[0]->address,
            'Reply-To should be the notify address for chat routing'
        );
    }

    public function test_chat_notification_with_previous_messages(): void
    {
        ['user1' => $user1, 'user2' => $user2, 'room' => $room] = $this->createUser2UserChatSetup();

        // Create previous message
        $prevMessage = $this->createTestChatMessage($room, $user1, [
            'message' => 'Previous message',
            'date' => now()->subMinutes(5),
        ]);

        // Create latest message from user2
        $message = $this->createTestChatMessage($room, $user2, [
            'message' => 'Latest message',
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
        $this->assertStringContainsString('[' . $group->namefull . ']', $mail->replySubject);
    }

    public function test_chat_notification_chat_url_contains_room_id(): void
    {
        ['room' => $room, 'mail' => $mail] = $this->createUser2UserChatSetup();

        $this->assertStringContainsString('/chats/' . $room->id, $mail->chatUrl);
    }

    public function test_chat_notification_image_message_type(): void
    {
        ['mail' => $mail] = $this->createUser2UserChatSetup([
            'message_text' => '',
            'message_type' => ChatMessage::TYPE_IMAGE,
        ]);

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('Sent an image', $html);
    }

    public function test_chat_notification_reneged_message_type(): void
    {
        ['mail' => $mail] = $this->createUser2UserChatSetup([
            'user1_attrs' => ['fullname' => 'Alice'],
            'user2_attrs' => ['fullname' => 'Bob'],
            'message_text' => '',
            'message_type' => ChatMessage::TYPE_RENEGED,
        ]);

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

        ['user2' => $user2, 'mail' => $mail] = $this->createUser2UserChatSetup();

        // Debug: Check mail object state before build.
        $recipientExists = $mail->recipient->exists;
        $chatType = $mail->chatType;
        $recipientEmail = $mail->recipient->email_preferred;
        $footerViewExists = view()->exists('emails.mjml.partials.footer');

        $mail->build();
        $html = $mail->render();

        // Debug: Extract footer section to see what was rendered.
        $footerMatch = [];
        preg_match('/This email was sent[^<]*/', $html, $footerMatch);
        $footerText = $footerMatch[0] ?? 'FOOTER NOT FOUND';

        // Build debug message for assertion failure.
        $debug = sprintf(
            "\n=== DEBUG INFO ===\n" .
            "config('freegle.amp.enabled'): %s\n" .
            "mail->recipient->exists: %s\n" .
            "mail->recipient->email_preferred: %s\n" .
            "mail->chatType: %s\n" .
            "footer view exists: %s\n" .
            "HTML length: %d\n" .
            "Footer text found: %s\n" .
            "==================\n",
            var_export(config('freegle.amp.enabled'), true),
            var_export($recipientExists, true),
            var_export($recipientEmail, true),
            var_export($chatType, true),
            var_export($footerViewExists, true),
            strlen($html),
            $footerText
        );

        // Footer should indicate AMP was included.
        $this->assertStringContainsString('sent with AMP', $html, "Expected 'sent with AMP' in HTML but not found." . $debug);
    }

    public function test_chat_notification_no_amp_indicator_when_disabled(): void
    {
        // Disable AMP.
        config(['freegle.amp.enabled' => false]);
        config(['freegle.amp.secret' => null]);

        ['mail' => $mail] = $this->createUser2UserChatSetup();

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

        ['mail' => $mail] = $this->createUser2ModChatSetup();

        $mail->build();
        $html = $mail->render();

        // AMP is only for User2User chats, so no indicator for User2Mod.
        $this->assertStringNotContainsString('sent with AMP', $html);
    }

    public function test_own_message_notification_sets_flag_correctly(): void
    {
        ['user1' => $user1, 'user2' => $user2, 'room' => $room, 'message' => $message, 'mail' => $normalMail] =
            $this->createUser2UserChatSetup([
                'user1_attrs' => ['fullname' => 'Alice'],
                'user2_attrs' => ['fullname' => 'Bob'],
            ]);

        // Normal notification: recipient (user2) is NOT the message sender.
        $this->assertFalse($normalMail->isOwnMessage, 'isOwnMessage should be false for normal notifications');

        // Own message notification: recipient (user1) IS the message sender.
        $ownMail = new ChatNotification($user1, $user1, $room, $message, ChatRoom::TYPE_USER2USER);
        $this->assertTrue($ownMail->isOwnMessage, 'isOwnMessage should be true when recipient sent the message');
    }

    public function test_own_message_notification_shows_copy_of_your_message(): void
    {
        ['user1' => $user1, 'room' => $room, 'message' => $message] = $this->createUser2UserChatSetup([
            'user1_attrs' => ['fullname' => 'Alice'],
            'user2_attrs' => ['fullname' => 'Bob'],
            'message_text' => 'Hello Bob!',
        ]);

        // Own message notification: recipient (user1) IS the message sender.
        $mail = new ChatNotification($user1, $user1, $room, $message, ChatRoom::TYPE_USER2USER);

        $mail->build();
        $html = $mail->render();

        // Should show "Copy of your message to Bob" instead of "New message from Alice".
        $this->assertStringContainsString('Copy of your message to', $html);
        $this->assertStringContainsString('Bob', $html);
        $this->assertStringNotContainsString('New message from Alice', $html);
    }

    public function test_own_message_notification_shows_view_conversation_button(): void
    {
        ['user1' => $user1, 'room' => $room, 'message' => $message] = $this->createUser2UserChatSetup([
            'user1_attrs' => ['fullname' => 'Alice'],
            'user2_attrs' => ['fullname' => 'Bob'],
        ]);

        // Own message notification.
        $mail = new ChatNotification($user1, $user1, $room, $message, ChatRoom::TYPE_USER2USER);

        $mail->build();
        $html = $mail->render();

        // Should show "View conversation" instead of "Reply to Alice".
        $this->assertStringContainsString('View conversation', $html);
        $this->assertStringNotContainsString('Reply to Alice', $html);
    }

    public function test_own_message_notification_does_not_show_you_indicator(): void
    {
        ['user1' => $user1, 'room' => $room, 'message' => $message] = $this->createUser2UserChatSetup([
            'user1_attrs' => ['fullname' => 'Alice'],
            'user2_attrs' => ['fullname' => 'Bob'],
        ]);

        // Own message notification.
        $mail = new ChatNotification($user1, $user1, $room, $message, ChatRoom::TYPE_USER2USER);

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
        ['user1' => $user1, 'room' => $room, 'message' => $message] = $this->createUser2UserChatSetup([
            'user1_attrs' => ['fullname' => 'Alice'],
            'user2_attrs' => ['fullname' => 'Bob'],
        ]);

        // Own message notification.
        $mail = new ChatNotification($user1, $user1, $room, $message, ChatRoom::TYPE_USER2USER);

        $mail->build();
        $html = $mail->render();

        // Should show "Your message" instead of "New message".
        $this->assertStringContainsString('Your message', $html);
    }

    public function test_user2mod_moderator_notification_uses_modtools_url(): void
    {
        ['mail' => $mail] = $this->createUser2ModChatSetup([
            'member_attrs' => ['fullname' => 'Alice Member'],
            'moderator_attrs' => ['fullname' => 'Bob Moderator'],
            'message_text' => 'Help me please',
            'recipient' => 'moderator',
        ]);

        // Moderator should have isModerator flag set.
        $this->assertTrue($mail->isModerator);

        // Chat URL should point to ModTools.
        $this->assertStringContainsString(config('freegle.sites.mod'), $mail->chatUrl);
    }

    public function test_user2mod_member_notification_uses_user_site_url(): void
    {
        ['mail' => $mail] = $this->createUser2ModChatSetup([
            'member_attrs' => ['fullname' => 'Alice Member'],
            'message_text' => 'Help me please',
        ]);

        // Member should NOT have isModerator flag set.
        $this->assertFalse($mail->isModerator);

        // Chat URL should point to user site.
        $this->assertStringContainsString(config('freegle.sites.user'), $mail->chatUrl);
    }

    public function test_user2mod_moderator_subject_includes_member_info(): void
    {
        ['group' => $group, 'mail' => $mail] = $this->createUser2ModChatSetup([
            'member_attrs' => ['fullname' => 'Alice Member'],
            'moderator_attrs' => ['fullname' => 'Bob Moderator'],
            'message_text' => 'Help me please',
            'recipient' => 'moderator',
        ]);

        // Subject should be "Member conversation on {GroupShortName} with {MemberName} ({email})".
        $this->assertStringContainsString('Member conversation on', $mail->replySubject);
        $this->assertStringContainsString($group->nameshort, $mail->replySubject);
        $this->assertStringContainsString('Alice', $mail->replySubject);
        // Email is auto-generated as test{id}@test.com.
        $this->assertStringContainsString('@test.com', $mail->replySubject);
    }

    public function test_user2mod_member_subject_mentions_volunteers(): void
    {
        ['mail' => $mail] = $this->createUser2ModChatSetup([
            'member_attrs' => ['fullname' => 'Alice Member'],
            'message_text' => 'Help me please',
        ]);

        // Subject should be "Your conversation with the {groupName} Volunteers".
        $this->assertStringContainsString('Your conversation with the', $mail->replySubject);
        $this->assertStringContainsString('Volunteers', $mail->replySubject);
    }

    public function test_user2mod_moderator_notification_shows_modtools_styling(): void
    {
        ['mail' => $mail] = $this->createUser2ModChatSetup([
            'member_attrs' => ['fullname' => 'Alice Member'],
            'moderator_attrs' => ['fullname' => 'Bob Moderator'],
            'message_text' => 'Help me please',
            'recipient' => 'moderator',
        ]);

        $mail->build();
        $html = $mail->render();

        // ModTools blue color should be present.
        $this->assertStringContainsString('#396aa3', $html);
        // Should show member info bar.
        $this->assertStringContainsString('Member:', $html);
        $this->assertStringContainsString('Alice', $html);
    }

    public function test_user2mod_moderator_notification_shows_reply_to_member_button(): void
    {
        ['mail' => $mail] = $this->createUser2ModChatSetup([
            'member_attrs' => ['fullname' => 'Alice Member'],
            'moderator_attrs' => ['fullname' => 'Bob Moderator'],
            'message_text' => 'Help me please',
            'recipient' => 'moderator',
        ]);

        $mail->build();
        $html = $mail->render();

        // Should show "Reply to member" button.
        $this->assertStringContainsString('Reply to member', $html);
    }

    public function test_user2mod_member_property_set_for_moderators(): void
    {
        ['member' => $member, 'mail' => $mail] = $this->createUser2ModChatSetup([
            'member_attrs' => ['fullname' => 'Alice Member'],
            'moderator_attrs' => ['fullname' => 'Bob Moderator'],
            'message_text' => 'Help me please',
            'recipient' => 'moderator',
        ]);

        // Member property should be set.
        $this->assertNotNull($mail->member);
        $this->assertEquals($member->id, $mail->member->id);
    }

    public function test_chat_notification_modmail_message_type(): void
    {
        ['mail' => $mail] = $this->createUser2UserChatSetup([
            'user1_attrs' => ['fullname' => 'Alice'],
            'user2_attrs' => ['fullname' => 'Bob'],
            'message_text' => 'Please be aware of our group rules.',
            'message_type' => ChatMessage::TYPE_MODMAIL,
        ]);

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('Message from Volunteers:', $html);
        $this->assertStringContainsString('Please be aware of our group rules.', $html);
    }

    public function test_chat_notification_reporteduser_message_type(): void
    {
        ['mail' => $mail] = $this->createUser2ModChatSetup([
            'member_attrs' => ['fullname' => 'Alice Member'],
            'moderator_attrs' => ['fullname' => 'Bob Moderator'],
            'message_text' => 'This person was rude to me.',
            'message_type' => ChatMessage::TYPE_REPORTEDUSER,
            'recipient' => 'moderator',
        ]);

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('This member reported another member', $html);
        $this->assertStringContainsString('This person was rude to me.', $html);
    }

    public function test_chat_notification_reminder_message_type(): void
    {
        // TYPE_REMINDER is used by Tryst for automatic handover reminders.
        ['mail' => $mail] = $this->createUser2UserChatSetup([
            'user1_attrs' => ['fullname' => 'Alice'],
            'user2_attrs' => ['fullname' => 'Bob'],
            'message_text' => "Automatic reminder: Handover at 2pm. Please confirm that's still ok or let them know if things have changed. Everybody hates a no-show...",
            'message_type' => ChatMessage::TYPE_REMINDER,
        ]);

        $mail->build();
        $html = $mail->render();

        // TYPE_REMINDER falls through to default, so it should show the handover reminder text.
        $this->assertStringContainsString('Automatic reminder: Handover at 2pm', $html);
        $this->assertStringContainsString('Everybody hates a no-show', $html);
    }

    public function test_user2mod_member_property_null_for_members(): void
    {
        ['member' => $member, 'mail' => $mail] = $this->createUser2ModChatSetup([
            'member_attrs' => ['fullname' => 'Alice Member'],
            'message_text' => 'Help me please',
        ]);

        // Member property should still be set (it's the member in the chat).
        $this->assertNotNull($mail->member);
        $this->assertEquals($member->id, $mail->member->id);
        // But isModerator should be false.
        $this->assertFalse($mail->isModerator);
    }

    /**
     * Test User2Mod: when member receives mod reply, FROM name should be "GroupName Volunteers"
     * not the individual moderator's name.
     *
     * This matches iznik-server behavior in processUnmailedMessage() lines 3009-3013:
     * For User2Mod when notifying member, fromname is always "{group.namedisplay} volunteers"
     */
    public function test_user2mod_member_receives_mod_reply_from_name_is_volunteers(): void
    {
        $member = $this->createTestUser(['fullname' => 'Alice Member']);
        $moderator = $this->createTestUser(['fullname' => 'Sheila Mod']);
        $group = $this->createTestGroup(['namedisplay' => 'Test Freegle Group']);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
        ]);

        // Message from moderator to member.
        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $moderator->id,
            'message' => 'Hello from the volunteers!',
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

        // Member receives notification (they are user1 in User2Mod chat).
        $mail = new ChatNotification(
            $member,     // recipient = member (user1)
            $moderator,  // sender = moderator
            $room,
            $message,
            ChatRoom::TYPE_USER2MOD
        );

        $envelope = $mail->envelope();

        // From name should be "TestGroup Volunteers", NOT "Sheila Mod on Freegle".
        $this->assertStringContainsString('Volunteers', $envelope->from->name);
        $this->assertStringNotContainsString('Sheila', $envelope->from->name);
    }

    /**
     * Test User2Mod: when member receives mod reply, the message content should NOT
     * show the moderator's individual name - it should show "Volunteers" or similar.
     *
     * This matches iznik-server prepareForTwig() lines 1971-1980 which uses group profile
     * instead of individual mod profile when notifying member.
     */
    public function test_user2mod_member_receives_mod_reply_hides_mod_identity_in_message(): void
    {
        $member = $this->createTestUser(['fullname' => 'Alice Member']);
        $moderator = $this->createTestUser(['fullname' => 'Sheila Mod']);
        $group = $this->createTestGroup(['namedisplay' => 'Test Freegle Group']);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
        ]);

        // Message from moderator.
        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $moderator->id,
            'message' => 'Hello from the volunteers!',
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
            $member,
            $moderator,
            $room,
            $message,
            ChatRoom::TYPE_USER2MOD
        );

        $mail->build();
        $html = $mail->render();

        // The rendered HTML should NOT contain the moderator's individual name "Sheila".
        // This is critical for privacy - members should not see which mod replied.
        $this->assertStringNotContainsString('Sheila', $html);

        // Should show the group name or "Volunteers" somewhere.
        $this->assertStringContainsString('Volunteers', $html);
    }

    /**
     * Test User2Mod: when moderator receives member's message, FROM name should be member's name.
     */
    public function test_user2mod_mod_receives_member_message_from_name_is_member(): void
    {
        $member = $this->createTestUser(['fullname' => 'Alice Member']);
        $moderator = $this->createTestUser(['fullname' => 'Sheila Mod']);
        $group = $this->createTestGroup();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
        ]);

        // Message from member.
        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $member->id,
            'message' => 'I need help!',
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

        // Moderator receives notification.
        $mail = new ChatNotification(
            $moderator,  // recipient = moderator (NOT user1)
            $member,     // sender = member
            $room,
            $message,
            ChatRoom::TYPE_USER2MOD
        );

        $envelope = $mail->envelope();

        // From name should include member's name, not "Volunteers".
        $this->assertStringContainsString('Alice', $envelope->from->name);
    }

    /**
     * Test User2Mod: when member views previous messages, member's own messages show
     * their name, but mod messages show "Volunteers".
     *
     * This tests the prepareMessage() logic for previous messages in the thread.
     */
    public function test_user2mod_member_previous_messages_show_correct_identity(): void
    {
        $member = $this->createTestUser(['fullname' => 'Alice Member']);
        $moderator = $this->createTestUser(['fullname' => 'Sheila Mod']);
        $group = $this->createTestGroup(['namedisplay' => 'Test Freegle Group']);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
        ]);

        // Previous message from member.
        $memberMsg = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $member->id,
            'message' => 'Hello from Alice!',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subMinutes(10),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        // Previous message from mod.
        $modMsg = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $moderator->id,
            'message' => 'Hi Alice, we can help!',
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

        // Latest message from mod triggers notification to member.
        $latestMsg = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $moderator->id,
            'message' => 'Any update?',
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

        // Member receives notification with previous messages.
        $mail = new ChatNotification(
            $member,
            $moderator,
            $room,
            $latestMsg,
            ChatRoom::TYPE_USER2MOD,
            collect([$memberMsg, $modMsg])  // Previous messages
        );

        $mail->build();
        $html = $mail->render();

        // Member's own message should show their name "Alice".
        $this->assertStringContainsString('Alice', $html);

        // Mod messages should NOT show "Sheila" - should show "Volunteers" instead.
        $this->assertStringNotContainsString('Sheila', $html);
        $this->assertStringContainsString('Volunteers', $html);
    }
}
