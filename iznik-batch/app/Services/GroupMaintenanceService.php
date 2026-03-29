<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GroupMaintenanceService
{
    /**
     * Update member and moderator counts for all groups.
     *
     * Migrated from iznik-server/scripts/cron/membercounts.php
     */
    public function updateMemberCounts(): array
    {
        $stats = [
            'groups_updated' => 0,
        ];

        $groups = DB::table('groups')->select('id', 'nameshort')->get();

        foreach ($groups as $group) {
            $memberCount = DB::table('memberships')
                ->where('groupid', $group->id)
                ->count();

            $modCount = DB::table('memberships')
                ->where('groupid', $group->id)
                ->whereIn('role', ['Owner', 'Moderator'])
                ->count();

            DB::table('groups')
                ->where('id', $group->id)
                ->update([
                    'membercount' => $memberCount,
                    'modcount' => $modCount,
                ]);

            $stats['groups_updated']++;
        }

        return $stats;
    }
}
