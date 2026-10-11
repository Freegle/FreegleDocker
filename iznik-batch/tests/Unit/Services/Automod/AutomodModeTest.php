<?php

namespace Tests\Unit\Services\Automod;

use App\Services\Automod\AutomodMode;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Which of the two rollout lists a community is in, and how that set is expressed as SQL.
 * Shadow always wins: the global approve switch must never silently promote a community
 * that is still being watched.
 */
class AutomodModeTest extends TestCase
{
    public function test_the_two_lists_name_the_two_modes(): void
    {
        config([
            'freegle.autoapprove.enabled' => false,
            'freegle.autoapprove.trial_group_ids' => '7, 8',
            'freegle.automod.shadow_group_ids' => '5',
        ]);

        $this->assertSame(AutomodMode::MODE_APPROVE, AutomodMode::for(7));
        $this->assertSame(AutomodMode::MODE_APPROVE, AutomodMode::for(8));
        $this->assertSame(AutomodMode::MODE_SHADOW, AutomodMode::for(5));
        $this->assertNull(AutomodMode::for(9));
        $this->assertTrue(AutomodMode::isApproveMode(7));
        $this->assertFalse(AutomodMode::isApproveMode(5));

        $eligible = AutomodMode::eligibleGroupIds();
        sort($eligible);
        $this->assertSame([5, 7, 8], $eligible);
    }

    public function test_shadow_wins_when_approve_is_on_everywhere(): void
    {
        config([
            'freegle.autoapprove.enabled' => true,
            'freegle.autoapprove.trial_group_ids' => '',
            'freegle.automod.shadow_group_ids' => '5',
        ]);

        $this->assertSame(AutomodMode::MODE_SHADOW, AutomodMode::for(5));
        $this->assertSame(AutomodMode::MODE_APPROVE, AutomodMode::for(6));
        $this->assertFalse(AutomodMode::isApproveMode(5));
        $this->assertTrue(AutomodMode::isApproveMode(6));
        $this->assertNull(AutomodMode::eligibleGroupIds(), 'every community runs the chart in one mode or the other');
    }

    public function test_shadow_wins_over_the_approve_list_too(): void
    {
        config([
            'freegle.autoapprove.enabled' => false,
            'freegle.autoapprove.trial_group_ids' => '5,7',
            'freegle.automod.shadow_group_ids' => '5',
        ]);

        $this->assertSame(AutomodMode::MODE_SHADOW, AutomodMode::for(5));
        $this->assertSame(AutomodMode::MODE_APPROVE, AutomodMode::for(7));
    }

    /** The one SQL form of "an approve-mode community", used by every candidate query. */
    public function test_the_approve_scope_as_sql(): void
    {
        $idsMatching = function (): array {
            $rows = DB::select(
                'SELECT id FROM (SELECT 7 AS id UNION SELECT 8 UNION SELECT 9) t WHERE '
                . AutomodMode::approveModeSql('t.id') . ' ORDER BY id'
            );

            return array_map(fn ($r) => (int) $r->id, $rows);
        };

        config(['freegle.autoapprove.enabled' => false, 'freegle.autoapprove.trial_group_ids' => '7,8', 'freegle.automod.shadow_group_ids' => '7']);
        $this->assertSame([8], $idsMatching());

        config(['freegle.autoapprove.enabled' => true, 'freegle.autoapprove.trial_group_ids' => '', 'freegle.automod.shadow_group_ids' => '7']);
        $this->assertSame([8, 9], $idsMatching());

        config(['freegle.autoapprove.enabled' => false, 'freegle.autoapprove.trial_group_ids' => '', 'freegle.automod.shadow_group_ids' => '7']);
        $this->assertSame([], $idsMatching(), 'nothing is in approve mode while both switches are off');

        config(['freegle.autoapprove.enabled' => true, 'freegle.autoapprove.trial_group_ids' => '', 'freegle.automod.shadow_group_ids' => '']);
        $this->assertSame([7, 8, 9], $idsMatching());
    }
}
