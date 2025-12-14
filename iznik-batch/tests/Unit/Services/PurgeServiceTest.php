<?php

namespace Tests\Unit\Services;

use App\Models\ChatImage;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\UserEmail;
use App\Services\PurgeService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeServiceTest extends TestCase
{
    protected PurgeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PurgeService();
    }

    public function test_purge_spam_chat_messages(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $sender->id,
            'user2' => $recipient->id,
            'created' => now(),
        ]);

        // Create old spam message (should be purged).
        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'message' => 'Spam message',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subDays(10),
            'reviewrejected' => 1,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        // Create recent spam message (should not be purged).
        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'message' => 'Recent spam',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subDays(3),
            'reviewrejected' => 1,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        $purged = $this->service->purgeSpamChatMessages(7);

        $this->assertEquals(1, $purged);
        $this->assertEquals(1, ChatMessage::where('chatid', $room->id)->count());
    }

    public function test_purge_empty_chat_rooms(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        // Create empty room.
        ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        // Create room with message (different user pair to avoid unique constraint).
        $roomWithMessage = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user3->id,
            'created' => now(),
        ]);

        ChatMessage::create([
            'chatid' => $roomWithMessage->id,
            'userid' => $user1->id,
            'message' => 'Test',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        $purged = $this->service->purgeEmptyChatRooms();

        $this->assertEquals(1, $purged);
        $this->assertEquals(1, ChatRoom::count());
    }

    public function test_purge_orphaned_chat_images(): void
    {
        $user = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user->id,
            'user2' => $user->id,
            'created' => now(),
        ]);

        // Create orphaned image.
        ChatImage::create([
            'chatid' => $room->id,
            'chatmsgid' => null,
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user->id,
            'message' => 'Test',
            'type' => ChatMessage::TYPE_IMAGE,
            'date' => now(),
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        // Create linked image.
        ChatImage::create([
            'chatid' => $room->id,
            'chatmsgid' => $message->id,
        ]);

        $purged = $this->service->purgeOrphanedChatImages();

        $this->assertEquals(1, $purged);
        $this->assertEquals(1, ChatImage::count());
    }

    public function test_purge_pending_messages(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();

        // Create old pending message.
        $oldMessage = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'Old pending',
            'source' => 'Platform',
            'date' => now()->subDays(100),
            'arrival' => now()->subDays(100),
        ]);

        MessageGroup::create([
            'msgid' => $oldMessage->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subDays(100),
        ]);

        // Create recent pending message.
        $recentMessage = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'Recent pending',
            'source' => 'Platform',
            'date' => now()->subDays(10),
            'arrival' => now()->subDays(10),
        ]);

        MessageGroup::create([
            'msgid' => $recentMessage->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->subDays(10),
        ]);

        $purged = $this->service->purgePendingMessages(90);

        $this->assertEquals(1, $purged);
        $this->assertNull(Message::find($oldMessage->id));
        $this->assertNotNull(Message::find($recentMessage->id));
    }

    public function test_purge_deleted_messages(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();

        // Create old deleted message.
        Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'Old deleted',
            'source' => 'Platform',
            'date' => now()->subDays(10),
            'arrival' => now()->subDays(10),
            'deleted' => now()->subDays(5), // Deleted 5 days ago.
        ]);

        // Create recently deleted message.
        Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'Recently deleted',
            'source' => 'Platform',
            'date' => now()->subDays(10),
            'arrival' => now()->subDays(10),
            'deleted' => now()->subDay(), // Deleted yesterday.
        ]);

        $purged = $this->service->purgeDeletedMessages(2);

        $this->assertEquals(1, $purged);
    }

    public function test_purge_unvalidated_emails(): void
    {
        // Create old unvalidated email.
        UserEmail::create([
            'userid' => null,
            'email' => 'old@test.com',
            'added' => now()->subDays(10),
        ]);

        // Create recent unvalidated email.
        UserEmail::create([
            'userid' => null,
            'email' => 'recent@test.com',
            'added' => now()->subDays(3),
        ]);

        // Create validated email.
        $user = $this->createTestUser();
        // User already has an email created in createTestUser.

        $purged = $this->service->purgeUnvalidatedEmails(7);

        $this->assertEquals(1, $purged);
        $this->assertDatabaseHas('users_emails', ['email' => 'recent@test.com']);
    }

    public function test_purge_html_body(): void
    {
        $user = $this->createTestUser();

        // Create old message with HTML body.
        Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'Test',
            'source' => 'Platform',
            'date' => now()->subDays(10),
            'arrival' => now()->subDays(10),
            'htmlbody' => '<html>Test</html>',
        ]);

        // Create recent message with HTML body.
        Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'Recent',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'htmlbody' => '<html>Recent</html>',
        ]);

        $purged = $this->service->purgeHtmlBody(2);

        $this->assertEquals(1, $purged);
    }

    public function test_run_all_returns_results(): void
    {
        $results = $this->service->runAll();

        $this->assertIsArray($results);
        $this->assertArrayHasKey('spam_chat_messages', $results);
        $this->assertArrayHasKey('empty_chat_rooms', $results);
        $this->assertArrayHasKey('orphaned_chat_images', $results);
        $this->assertArrayHasKey('messages_history', $results);
        $this->assertArrayHasKey('pending_messages', $results);
        $this->assertArrayHasKey('old_drafts', $results);
        $this->assertArrayHasKey('deleted_messages', $results);
        $this->assertArrayHasKey('unvalidated_emails', $results);
    }

    public function test_purge_old_messages_history(): void
    {
        // Insert old messages_history record.
        DB::table('messages_history')->insert([
            'msgid' => 1,
            'arrival' => now()->subDays(100),
        ]);

        // Insert recent messages_history record.
        DB::table('messages_history')->insert([
            'msgid' => 2,
            'arrival' => now()->subDays(10),
        ]);

        $purged = $this->service->purgeOldMessagesHistory(90);

        $this->assertEquals(1, $purged);
        $this->assertEquals(1, DB::table('messages_history')->count());
    }

    public function test_purge_old_drafts(): void
    {
        $user = $this->createTestUser();

        // Create old draft.
        $oldMessage = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'Old draft',
            'source' => 'Platform',
            'date' => now()->subDays(100),
            'arrival' => now()->subDays(100),
        ]);

        DB::table('messages_drafts')->insert([
            'msgid' => $oldMessage->id,
            'timestamp' => now()->subDays(100),
        ]);

        // Create recent draft.
        $recentMessage = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'Recent draft',
            'source' => 'Platform',
            'date' => now()->subDays(10),
            'arrival' => now()->subDays(10),
        ]);

        DB::table('messages_drafts')->insert([
            'msgid' => $recentMessage->id,
            'timestamp' => now()->subDays(10),
        ]);

        $purged = $this->service->purgeOldDrafts(90);

        $this->assertEquals(1, $purged);
        $this->assertNotNull(Message::find($recentMessage->id));
    }

    public function test_purge_users_nearby(): void
    {
        $group = $this->createTestGroup();
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $this->createMembership($user1, $group);
        $this->createMembership($user2, $group);

        $message1 = $this->createTestMessage($user1, $group);
        $message2 = $this->createTestMessage($user2, $group);

        // Insert old users_nearby record.
        DB::table('users_nearby')->insert([
            'userid' => $user1->id,
            'msgid' => $message1->id,
            'timestamp' => now()->subDays(100),
        ]);

        // Insert recent users_nearby record.
        DB::table('users_nearby')->insert([
            'userid' => $user2->id,
            'msgid' => $message2->id,
            'timestamp' => now()->subDays(10),
        ]);

        $purged = $this->service->purgeUsersNearby(90);

        $this->assertEquals(1, $purged);
        $this->assertEquals(1, DB::table('users_nearby')->count());
    }

    public function test_purge_stranded_messages(): void
    {
        $user = $this->createTestUser();

        // Create stranded message (not on any group, no chat refs, no drafts).
        Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'Stranded',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
        ]);

        // Create message on a group (should not be purged).
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $this->createTestMessage($user, $group);

        $purged = $this->service->purgeStrandedMessages(2);

        $this->assertEquals(1, $purged);
    }

    public function test_purge_orphaned_isochrones(): void
    {
        // Insert orphaned isochrone (not linked to any user) with NULL locationid.
        DB::statement("INSERT INTO isochrones (transport, minutes, locationid, polygon) VALUES ('Walk', 30, NULL, ST_GeomFromText('POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))'))");

        $purged = $this->service->purgeOrphanedIsochrones();

        $this->assertEquals(1, $purged);
        $this->assertEquals(0, DB::table('isochrones')->count());
    }

    public function test_purge_non_freegle_messages(): void
    {
        $user = $this->createTestUser();

        // Create non-Freegle group.
        $nonFreegleGroup = \App\Models\Group::create([
            'nameshort' => 'NonFreegleGroup',
            'namefull' => 'Non Freegle Group',
            'type' => 'Other',
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
        ]);

        // Create old message on non-Freegle group.
        $oldMessage = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'Non-Freegle message',
            'source' => 'Platform',
            'date' => now()->subDays(100),
            'arrival' => now()->subDays(100),
        ]);

        MessageGroup::create([
            'msgid' => $oldMessage->id,
            'groupid' => $nonFreegleGroup->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(100),
        ]);

        $purged = $this->service->purgeNonFreegleMessages(90);

        $this->assertEquals(1, $purged);
    }

    public function test_purge_spam_chat_messages_with_no_spam(): void
    {
        $purged = $this->service->purgeSpamChatMessages();

        $this->assertEquals(0, $purged);
    }

    public function test_purge_empty_chat_rooms_with_no_empty(): void
    {
        $purged = $this->service->purgeEmptyChatRooms();

        $this->assertEquals(0, $purged);
    }

    public function test_purge_orphaned_chat_images_with_none(): void
    {
        $purged = $this->service->purgeOrphanedChatImages();

        $this->assertEquals(0, $purged);
    }
}
