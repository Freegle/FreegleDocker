<?php

namespace Tests\Feature\Monitor;

use App\Models\MessageAutomod;
use App\Models\MessageGroup;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * monitor:automod-health: fails (and so escalates) when automated review has been failing
 * for a quarter of an hour, or when a post on an approve-mode community has waited longer
 * than the publish delay without any decision at all.
 */
class AutomodHealthCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'freegle.autoapprove.enabled' => true,
            'freegle.autoapprove.trial_group_ids' => '',
            'freegle.automod.shadow_group_ids' => '',
            'freegle.autoapprove.delay_minutes' => 20,
        ]);
    }

    /**
     * A Pending post from an auto-moderated member whose content check ran at $checkedAt.
     *
     * @return array{0: int, 1: int} msgid, groupid
     */
    private function waitingPost(\Carbon\Carbon $checkedAt): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)->update([
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => $checkedAt->copy()->subMinute(),
            'contentcheck_checked_at' => $checkedAt,
        ]);

        return [(int) $message->id, (int) $group->id];
    }

    private function unavailableRow(int $msgid, int $groupid): void
    {
        DB::table('messages_automod')->insert([
            'msgid' => $msgid, 'groupid' => $groupid, 'mode' => MessageAutomod::MODE_APPROVE,
            'chart_version' => '', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end_node' => 'UNAVAILABLE',
            'reason' => 'Automated review unavailable', 'path' => '[]', 'created' => now()->subMinute(),
        ]);
    }

    public function test_ok_when_nothing_is_waiting_and_nothing_failed(): void
    {
        $this->artisan('monitor:automod-health')->assertExitCode(0);
    }

    public function test_fails_when_a_review_has_been_unavailable_for_fifteen_minutes(): void
    {
        [$msgid, $groupid] = $this->waitingPost(now()->subMinutes(20));
        $this->unavailableRow($msgid, $groupid);

        $this->artisan('monitor:automod-health')
            ->expectsOutputToContain('UNAVAILABLE')
            ->assertExitCode(1);
    }

    public function test_a_review_that_only_just_failed_is_not_an_alert(): void
    {
        [$msgid, $groupid] = $this->waitingPost(now()->subMinutes(5));
        $this->unavailableRow($msgid, $groupid);

        $this->artisan('monitor:automod-health')->assertExitCode(0);
    }

    public function test_fails_when_a_trial_post_is_older_than_the_delay_with_no_decision(): void
    {
        $this->waitingPost(now()->subMinutes(30));

        $this->artisan('monitor:automod-health')
            ->expectsOutputToContain('UNDECIDED')
            ->assertExitCode(1);
    }

    public function test_a_decided_post_is_not_an_alert(): void
    {
        [$msgid, $groupid] = $this->waitingPost(now()->subMinutes(30));
        DB::table('messages_automod')->insert([
            'msgid' => $msgid, 'groupid' => $groupid, 'mode' => MessageAutomod::MODE_APPROVE,
            'chart_version' => '5', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end_node' => 'HOLD_MONEY',
            'reason' => 'Asks for money', 'path' => '[]', 'created' => now()->subMinutes(29),
        ]);

        $this->artisan('monitor:automod-health')->assertExitCode(0);
    }

    public function test_a_post_still_within_the_delay_is_not_an_alert(): void
    {
        $this->waitingPost(now()->subMinutes(10));

        $this->artisan('monitor:automod-health')->assertExitCode(0);
    }

    public function test_quiet_when_automated_review_is_dark(): void
    {
        config(['freegle.autoapprove.enabled' => false]);
        $this->waitingPost(now()->subMinutes(30));

        $this->artisan('monitor:automod-health')->assertExitCode(0);
    }
}
