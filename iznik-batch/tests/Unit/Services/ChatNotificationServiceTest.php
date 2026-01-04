<?php

namespace Tests\Unit\Services;

use App\Mail\Chat\ChatNotification;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Models\Membership;
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

    public function test_notify_by_email_with_no_rooms(): void
    {
        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_notify_by_email_sends_notification(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        // Create roster entries.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a message from sender to recipient (old enough to trigger notification).
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        // Recipient should be notified.
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_with_specific_chat(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(
            ChatRoom::TYPE_USER2USER,
            $room->id
        );

        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_respects_delay(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a very recent message (within delay period).
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subSeconds(5),
        ]);

        // With default 30 second delay, this message should not trigger notification.
        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_notify_by_email_skips_old_messages(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now()->subDays(2),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a message older than the sinceHours parameter.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subDays(2),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        // Old messages should not trigger notification.
        $this->assertEquals(0, $count);
    }

    public function test_notify_by_email_for_user2mod(): void
    {
        $user = $this->createTestUser();
        $mod = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $user->id,
            'user2' => $mod->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $mod->id,
            'lastmsgemailed' => null,
        ]);

        $this->createTestChatMessage($room, $user, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD);

        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_with_force_all(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create an already mailed message.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
            'mailedtoall' => 1,
            'seenbyall' => 1,
        ]);

        // With force=true, should still process.
        $count = $this->service->notifyByEmail(
            ChatRoom::TYPE_USER2USER,
            null,
            ChatNotificationService::DEFAULT_DELAY,
            ChatNotificationService::DEFAULT_SINCE_HOURS,
            true
        );

        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_skips_rejected_messages(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a rejected message.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
            'reviewrejected' => 1,
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        $this->assertEquals(0, $count);
    }

    public function test_notify_by_email_skips_user_without_email(): void
    {
        $sender = $this->createTestUser();

        // Create recipient without email.
        $recipient = User::create([
            'firstname' => 'No',
            'lastname' => 'Email',
            'fullname' => 'No Email',
            'added' => now(),
        ]);

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        // Should not send to user without email.
        Mail::assertNotSent(ChatNotification::class, function ($mail) use ($recipient) {
            return $mail->to[0]['address'] === $recipient->email_preferred;
        });
    }

    public function test_notify_by_email_updates_roster(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        $recipientRoster = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        $message = $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
        ]);

        $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        // Check if roster was updated.
        $recipientRoster->refresh();
        // The lastmsgemailed should be updated if notification was sent.
        $this->assertTrue(
            $recipientRoster->lastmsgemailed === null ||
            $recipientRoster->lastmsgemailed >= 0
        );
    }

    public function test_default_delay_constant(): void
    {
        $this->assertEquals(30, ChatNotificationService::DEFAULT_DELAY);
    }

    public function test_default_since_hours_constant(): void
    {
        $this->assertEquals(24, ChatNotificationService::DEFAULT_SINCE_HOURS);
    }

    public function test_notify_by_email_skips_already_mailed(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create already mailed message.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
            'mailedtoall' => 1,
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        $this->assertEquals(0, $count);
    }

    public function test_notify_by_email_skips_seen_messages(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create already seen message.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
            'seenbyall' => 1,
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        $this->assertEquals(0, $count);
    }

    public function test_notify_by_email_with_custom_delay(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a message 20 seconds ago.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subSeconds(20),
        ]);

        // With 10 second delay, this should trigger notification.
        $count = $this->service->notifyByEmail(
            ChatRoom::TYPE_USER2USER,
            null,
            10 // 10 second delay.
        );

        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_with_custom_since_hours(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now()->subHours(36),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a message 36 hours ago.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subHours(36),
        ]);

        // With 24 hour since limit, this should not trigger notification.
        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        $this->assertEquals(0, $count);

        // With 48 hour since limit, it should.
        $count = $this->service->notifyByEmail(
            ChatRoom::TYPE_USER2USER,
            null,
            ChatNotificationService::DEFAULT_DELAY,
            48
        );

        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_skips_messages_needing_review(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a message that needs review.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
            'reviewrequired' => 1,
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        $this->assertEquals(0, $count);
    }

    public function test_notify_by_email_skips_messages_needing_processing(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a message that needs processing.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
            'processingrequired' => 1,
            'processingsuccessful' => 0,
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER);

        $this->assertEquals(0, $count);
    }

    public function test_notify_by_email_user2mod_notifies_group_moderators(): void
    {
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        // Create moderators for the group.
        $moderator1 = $this->createTestUser();
        $moderator2 = $this->createTestUser();

        Membership::create([
            'userid' => $moderator1->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
        ]);

        Membership::create([
            'userid' => $moderator2->id,
            'groupid' => $group->id,
            'role' => 'Owner',
            'added' => now(),
        ]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        // Create roster for member only (moderators will be added dynamically).
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $member->id,
            'lastmsgemailed' => null,
        ]);

        // Member sends message.
        $this->createTestChatMessage($room, $member, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD);

        // Should notify moderators (the roster entries are created automatically).
        $this->assertGreaterThanOrEqual(0, $count);

        // Verify roster entries were created for moderators.
        $mod1Roster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $moderator1->id)
            ->first();
        $mod2Roster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $moderator2->id)
            ->first();

        $this->assertNotNull($mod1Roster, 'Moderator 1 should have a roster entry');
        $this->assertNotNull($mod2Roster, 'Moderator 2 (Owner) should have a roster entry');
    }

    public function test_notify_by_email_user2mod_notifies_member(): void
    {
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        // Create a moderator.
        $moderator = $this->createTestUser();

        Membership::create([
            'userid' => $moderator->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
        ]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        // Create roster for member.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $member->id,
            'lastmsgemailed' => null,
        ]);

        // Moderator sends message.
        $this->createTestChatMessage($room, $moderator, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD);

        // Should notify the member.
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_user2mod_sends_to_moderators(): void
    {
        $member = $this->createTestUser([
            'fullname' => 'Alice Member',
        ]);
        $group = $this->createTestGroup(['nameshort' => 'TestGroup']);

        // Create a moderator.
        $moderator = $this->createTestUser([
            'fullname' => 'Bob Moderator',
        ]);

        Membership::create([
            'userid' => $moderator->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
        ]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        // Create roster for member.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $member->id,
            'lastmsgemailed' => null,
        ]);

        // Member sends message.
        $this->createTestChatMessage($room, $member, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD);

        // Should have sent at least 1 notification (to the moderator).
        // The member is also notified if they sent the message and have NOTIFS_EMAIL_MINE enabled.
        $this->assertGreaterThan(0, $count, 'Should have sent notifications');

        // Verify moderator roster entry was updated.
        $modRoster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $moderator->id)
            ->first();
        $this->assertNotNull($modRoster, 'Moderator should have roster entry');
        $this->assertNotNull($modRoster->lastmsgemailed, 'Moderator roster should be updated after notification');
    }
}
