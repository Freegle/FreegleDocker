<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\MessageOutcome;
use App\Services\AutoApproveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AutoApproveServiceTest extends TestCase
{
    protected AutoApproveService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AutoApproveService();
    }

    public function test_stats_structure(): void
    {
        $stats = $this->service->process();

        $this->assertArrayHasKey('approved', $stats);
        $this->assertArrayHasKey('skipped', $stats);
        $this->assertArrayHasKey('errors', $stats);
    }

    public function test_approves_message_pending_over_48_hours(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        // Membership added 72 hours ago (exceeds 48h threshold).
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        // Set message to pending and arrival 49 hours ago.
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
            ]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['approved']);

        // Verify messages_groups updated to Approved.
        $mg = DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->first();
        $this->assertEquals(MessageGroup::COLLECTION_APPROVED, $mg->collection);
        $this->assertNull($mg->approvedby);

        // Verify dual log entries (Approved + Autoapproved).
        $this->assertDatabaseHas('logs', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'type' => 'Message',
            'subtype' => 'Approved',
        ]);
        $this->assertDatabaseHas('logs', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'type' => 'Message',
            'subtype' => 'Autoapproved',
        ]);
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
            ]);

        $stats = $this->service->process(dryRun: true);

        $this->assertGreaterThanOrEqual(1, $stats['approved']);

        // Message should still be pending.
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);

        // No log entries should exist.
        $this->assertDatabaseMissing('logs', [
            'msgid' => $message->id,
            'type' => 'Message',
            'subtype' => 'Autoapproved',
        ]);
    }

    public function test_skips_message_not_pending_long_enough(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        // Set message to pending but only 24 hours ago (under 48h threshold).
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(24),
            ]);

        $this->service->process();

        // Message should still be pending.
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_message_with_recent_logs(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
            ]);

        // Add a recent log entry (within 48 hours).
        DB::table('logs')->insert([
            'timestamp' => now()->subHours(1),
            'type' => 'Message',
            'subtype' => 'Hold',
            'msgid' => $message->id,
        ]);

        $this->service->process();

        // Message should still be pending (skipped due to recent logs).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_closed_group(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup([
            'settings' => ['closed' => true],
        ]);
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
            ]);

        $this->service->process();

        // Message should still be pending (group is closed).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_group_with_publish_false(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup([
            'settings' => ['publish' => false],
        ]);
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
            ]);

        $this->service->process();

        // Message should still be pending (group has publish=false).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_group_with_autofunctionoverride(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup([
            'autofunctionoverride' => 1,
        ]);
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
            ]);

        $this->service->process();

        // Message should still be pending (autofunctionoverride).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_skips_new_member_under_48_hours(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        // Membership added only 24 hours ago.
        $this->createMembership($user, $group, [
            'added' => now()->subHours(24),
        ]);

        $message = $this->createTestMessage($user, $group);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
            ]);

        $this->service->process();

        // Message should still be pending (member too new).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_records_ham_for_spam_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        // Mark as spam type.
        DB::table('messages')->where('id', $message->id)->update(['spamtype' => 'Spam']);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
            ]);

        $stats = $this->service->process();

        $this->assertGreaterThanOrEqual(1, $stats['approved']);

        // Verify Ham was recorded in messages_spamham.
        $this->assertDatabaseHas('messages_spamham', [
            'msgid' => $message->id,
            'spamham' => 'Ham',
        ]);
    }

    public function test_skips_held_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'added' => now()->subHours(72),
        ]);

        $message = $this->createTestMessage($user, $group);

        // Mark as held by the user.
        DB::table('messages')->where('id', $message->id)->update(['heldby' => $user->id]);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subHours(49),
            ]);

        $this->service->process();

        // Message should still be pending (held by a mod).
        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
        ]);
    }

    public function test_constants(): void
    {
        $this->assertEquals(48, AutoApproveService::PENDING_HOURS);
        $this->assertEquals(48, AutoApproveService::MEMBERSHIP_HOURS);
    }
}
