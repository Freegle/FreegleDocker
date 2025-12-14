<?php

namespace Tests\Unit\Services;

use App\Models\ChatImage;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Models\MessageGroup;
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
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user1, $group);
        $this->createMembership($user2, $group);

        $room = ChatRoom::create([
            'name' => 'Test Room',
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
        ]);

        // Create a spam message older than 7 days.
        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Spam',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subDays(10),
            'reviewrejected' => 1,
        ]);

        // Create a normal message.
        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'message' => 'Normal',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subDays(10),
            'reviewrejected' => 0,
        ]);

        $count = $this->service->purgeSpamChatMessages();

        $this->assertEquals(1, $count);
    }

    public function test_purge_empty_chat_rooms(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user1, $group);
        $this->createMembership($user2, $group);
        $this->createMembership($user3, $group);

        // Create an empty room.
        $emptyRoom = ChatRoom::create([
            'name' => 'Empty Room',
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
        ]);

        // Create a room with messages (different users to avoid unique constraint).
        $roomWithMessages = ChatRoom::create([
            'name' => 'Room With Messages',
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user3->id,
        ]);

        ChatMessage::create([
            'chatid' => $roomWithMessages->id,
            'userid' => $user1->id,
            'message' => 'Hello',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
        ]);

        $count = $this->service->purgeEmptyChatRooms();

        $this->assertEquals(1, $count);
        $this->assertDatabaseMissing('chat_rooms', ['id' => $emptyRoom->id]);
        $this->assertDatabaseHas('chat_rooms', ['id' => $roomWithMessages->id]);
    }

    public function test_purge_orphaned_chat_images(): void
    {
        // Create orphaned image.
        $orphanedImage = ChatImage::create([
            'chatmsgid' => null,
        ]);

        $count = $this->service->purgeOrphanedChatImages();

        $this->assertEquals(1, $count);
        $this->assertDatabaseMissing('chat_images', ['id' => $orphanedImage->id]);
    }

    public function test_purge_old_messages_history(): void
    {
        // Create old history entries.
        DB::table('messages_history')->insert([
            'arrival' => now()->subDays(100),
        ]);

        // Create recent history entry.
        DB::table('messages_history')->insert([
            'arrival' => now()->subDays(10),
        ]);

        $count = $this->service->purgeOldMessagesHistory();

        $this->assertEquals(1, $count);
    }

    public function test_purge_pending_messages(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Set to pending and old.
        MessageGroup::where('msgid', $message->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subDays(100),
            ]);

        $count = $this->service->purgePendingMessages();

        $this->assertEquals(1, $count);
    }

    public function test_purge_old_drafts(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Create old draft entry.
        DB::table('messages_drafts')->insert([
            'msgid' => $message->id,
            'timestamp' => now()->subDays(100),
        ]);

        $count = $this->service->purgeOldDrafts();

        $this->assertEquals(1, $count);
    }

    public function test_purge_deleted_messages(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Mark as deleted.
        $message->update([
            'deleted' => now()->subDays(5),
            'date' => now()->subDays(30),
        ]);

        $count = $this->service->purgeDeletedMessages();

        $this->assertEquals(1, $count);
    }

    public function test_purge_unvalidated_emails(): void
    {
        // Create old unvalidated email.
        DB::table('users_emails')->insert([
            'email' => 'unvalidated@example.com',
            'userid' => null,
            'added' => now()->subDays(10),
        ]);

        // Create recent unvalidated email.
        DB::table('users_emails')->insert([
            'email' => 'recent@example.com',
            'userid' => null,
            'added' => now()->subDays(1),
        ]);

        $count = $this->service->purgeUnvalidatedEmails();

        $this->assertEquals(1, $count);
    }

    public function test_purge_users_nearby(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user1, $group);
        $this->createMembership($user2, $group);

        $message1 = $this->createTestMessage($user1, $group);
        $message2 = $this->createTestMessage($user2, $group);

        // Create old entry.
        DB::table('users_nearby')->insert([
            'userid' => $user1->id,
            'msgid' => $message1->id,
            'timestamp' => now()->subDays(100),
        ]);

        // Create recent entry.
        DB::table('users_nearby')->insert([
            'userid' => $user2->id,
            'msgid' => $message2->id,
            'timestamp' => now()->subDays(10),
        ]);

        $count = $this->service->purgeUsersNearby();

        $this->assertEquals(1, $count);
    }

    public function test_purge_orphaned_isochrones(): void
    {
        // Create orphaned isochrone.
        $isochroneId = DB::table('isochrones')->insertGetId([
            'polygon' => DB::raw("ST_GeomFromText('POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))')"),
        ]);

        $count = $this->service->purgeOrphanedIsochrones();

        $this->assertEquals(1, $count);
        $this->assertDatabaseMissing('isochrones', ['id' => $isochroneId]);
    }

    public function test_run_all(): void
    {
        $results = $this->service->runAll();

        $this->assertIsArray($results);
        $this->assertArrayHasKey('spam_chat_messages', $results);
        $this->assertArrayHasKey('empty_chat_rooms', $results);
        $this->assertArrayHasKey('orphaned_chat_images', $results);
        $this->assertArrayHasKey('messages_history', $results);
        $this->assertArrayHasKey('pending_messages', $results);
        $this->assertArrayHasKey('old_drafts', $results);
        $this->assertArrayHasKey('non_freegle_messages', $results);
        $this->assertArrayHasKey('deleted_messages', $results);
        $this->assertArrayHasKey('stranded_messages', $results);
        $this->assertArrayHasKey('html_body', $results);
        $this->assertArrayHasKey('unvalidated_emails', $results);
        $this->assertArrayHasKey('users_nearby', $results);
        $this->assertArrayHasKey('orphaned_isochrones', $results);
        $this->assertArrayHasKey('completed_admins', $results);
    }

    public function test_purge_html_body(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Set htmlbody and old arrival.
        $message->update([
            'htmlbody' => '<html><body>Test</body></html>',
            'arrival' => now()->subDays(30),
        ]);

        $count = $this->service->purgeHtmlBody();

        $this->assertEquals(1, $count);
        $message->refresh();
        $this->assertNull($message->htmlbody);
    }

    public function test_purge_stranded_messages(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Remove from all groups (making it stranded).
        MessageGroup::where('msgid', $message->id)->delete();

        // Make it old enough.
        $message->update(['arrival' => now()->subDays(5)]);

        $count = $this->service->purgeStrandedMessages();

        $this->assertEquals(1, $count);
    }

    public function test_purge_non_freegle_messages(): void
    {
        $user = $this->createTestUser();

        // Create non-Freegle group.
        $group = $this->createTestGroup();
        $group->update(['type' => 'Reuse']);

        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        // Make it old.
        MessageGroup::where('msgid', $message->id)
            ->update(['arrival' => now()->subDays(100)]);

        $count = $this->service->purgeNonFreegleMessages();

        $this->assertEquals(1, $count);
    }

    public function test_purge_completed_admins(): void
    {
        $user = $this->createTestUser();

        // Create completed admin.
        $adminId = DB::table('admins')->insertGetId([
            'complete' => now()->subDays(100),
            'created' => now()->subDays(200),
        ]);

        // Create admin_users entries.
        DB::table('admins_users')->insert([
            'adminid' => $adminId,
            'userid' => $user->id,
        ]);

        $count = $this->service->purgeCompletedAdmins();

        $this->assertEquals(1, $count);
        $this->assertDatabaseMissing('admins_users', ['adminid' => $adminId]);
    }
}
