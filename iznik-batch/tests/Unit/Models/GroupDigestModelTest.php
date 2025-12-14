<?php

namespace Tests\Unit\Models;

use App\Models\GroupDigest;
use Tests\TestCase;

class GroupDigestModelTest extends TestCase
{
    public function test_group_digest_can_be_created(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $digest = GroupDigest::create([
            'groupid' => $group->id,
            'frequency' => 1,
            'msgid' => $message->id,
            'msgdate' => now(),
            'started' => now(),
            'ended' => now(),
        ]);

        $this->assertDatabaseHas('groups_digests', ['id' => $digest->id]);
    }

    public function test_group_relationship(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $digest = GroupDigest::create([
            'groupid' => $group->id,
            'frequency' => 1,
            'msgid' => $message->id,
            'msgdate' => now(),
            'started' => now(),
            'ended' => now(),
        ]);

        $this->assertEquals($group->id, $digest->group->id);
    }

    public function test_last_message_relationship(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $digest = GroupDigest::create([
            'groupid' => $group->id,
            'frequency' => 1,
            'msgid' => $message->id,
            'msgdate' => now(),
            'started' => now(),
            'ended' => now(),
        ]);

        $this->assertEquals($message->id, $digest->lastMessage->id);
    }
}
