<?php

namespace Tests\Unit\Commands\Dedup;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class TnDedupCommandTest extends TestCase
{
    private function createTnTestGroup(string $name): int
    {
        DB::statement(
            "INSERT INTO `groups` (nameshort, type, publish, polyindex) VALUES (?, 'Freegle', 1, ST_GeomFromText('POINT(0 0)'))",
            [$name]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    public function test_merges_duplicate_tn_posts(): void
    {
        $groupA = $this->createTnTestGroup('test-tn-dedup-a');
        $groupB = $this->createTnTestGroup('test-tn-dedup-b');
        $userId = DB::table('users')->insertGetId(['systemrole' => 'User']);

        $msg1 = DB::table('messages')->insertGetId([
            'fromuser' => $userId,
            'subject' => 'OFFER: Test Item',
            'type' => 'Offer',
            'arrival' => now()->subHour(),
            'tnpostid' => 'TN-DEDUP-TEST-123',
        ]);
        $msg2 = DB::table('messages')->insertGetId([
            'fromuser' => $userId,
            'subject' => 'OFFER: Test Item',
            'type' => 'Offer',
            'arrival' => now(),
            'tnpostid' => 'TN-DEDUP-TEST-123',
        ]);

        DB::table('messages_groups')->insert([
            ['msgid' => $msg1, 'groupid' => $groupA, 'collection' => 'Approved', 'arrival' => now()->subHour()],
            ['msgid' => $msg2, 'groupid' => $groupB, 'collection' => 'Approved', 'arrival' => now()],
        ]);

        $this->artisan('dedup:tn')->assertSuccessful();

        // msg1 (older) should now have both groups.
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msg1, 'groupid' => $groupA]);
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msg1, 'groupid' => $groupB]);

        // msg2 should be deleted.
        $this->assertDatabaseMissing('messages_groups', ['msgid' => $msg2]);
        $this->assertNotNull(DB::table('messages')->where('id', $msg2)->value('deleted'));

        // Cleanup.
        DB::table('messages_groups')->whereIn('msgid', [$msg1, $msg2])->delete();
        DB::table('messages')->whereIn('id', [$msg1, $msg2])->delete();
        DB::table('groups')->whereIn('id', [$groupA, $groupB])->delete();
        DB::table('users')->where('id', $userId)->delete();
    }

    public function test_ignores_messages_without_tnpostid(): void
    {
        $groupA = $this->createTnTestGroup('test-tn-nodup-a');
        $userId = DB::table('users')->insertGetId(['systemrole' => 'User']);

        $msg1 = DB::table('messages')->insertGetId([
            'fromuser' => $userId, 'subject' => 'OFFER: Item A', 'type' => 'Offer', 'arrival' => now(),
        ]);
        $msg2 = DB::table('messages')->insertGetId([
            'fromuser' => $userId, 'subject' => 'OFFER: Item B', 'type' => 'Offer', 'arrival' => now(),
        ]);

        DB::table('messages_groups')->insert([
            ['msgid' => $msg1, 'groupid' => $groupA, 'collection' => 'Approved', 'arrival' => now()],
            ['msgid' => $msg2, 'groupid' => $groupA, 'collection' => 'Approved', 'arrival' => now()],
        ]);

        $this->artisan('dedup:tn')->assertSuccessful();

        // Both messages should still exist.
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msg1]);
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msg2]);

        // Cleanup.
        DB::table('messages_groups')->whereIn('msgid', [$msg1, $msg2])->delete();
        DB::table('messages')->whereIn('id', [$msg1, $msg2])->delete();
        DB::table('groups')->where('id', $groupA)->delete();
        DB::table('users')->where('id', $userId)->delete();
    }
}
