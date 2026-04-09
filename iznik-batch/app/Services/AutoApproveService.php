<?php

namespace App\Services;

use App\Models\Group;
use App\Models\MessageGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoApproveService
{
    /**
     * Messages must be pending for this many hours before auto-approval.
     */
    public const PENDING_HOURS = 48;

    /**
     * User must be a member for this many hours before their messages auto-approve.
     */
    public const MEMBERSHIP_HOURS = 48;

    /**
     * Auto-approve pending messages that meet all criteria.
     *
     * Matches V1 autoapprove.php → Message::autoapprove().
     * Processes per (msgid, groupid) pair — multi-group safe.
     *
     * V1 side effects included:
     *   - notSpam(): records HAM in messages_spamham
     *   - Log APPROVED entry (from approve())
     *   - SQL UPDATE messages_groups (collection, approvedby, approvedat, arrival)
     *   - Log AUTOAPPROVED entry (from autoapprove())
     *
     * V1 side effects NOT included (handled elsewhere):
     *   - release(): query already filters heldby IS NULL
     *   - notifyGroupMods(): Go API handles push notifications
     *   - maybeMail(): not called in autoapprove (no subject/body passed)
     *   - addToSpatialIndex(): handled by message_spatial.php cron
     *   - index(): handled by message_unindexed.php cron
     */
    public function process(bool $dryRun = false): array
    {
        $stats = [
            'approved' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        // V1 query: SELECT msgid, groupid, TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS ago
        // FROM messages_groups INNER JOIN messages ON messages.id = messages_groups.msgid
        // WHERE collection = 'Pending' AND heldby IS NULL HAVING ago > 48
        $candidates = DB::table('messages_groups')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->select(
                'messages_groups.msgid',
                'messages_groups.groupid',
                'messages.fromuser',
                'messages.spamtype',
                DB::raw('TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS hours_pending')
            )
            ->where('messages_groups.collection', MessageGroup::COLLECTION_PENDING)
            ->whereNull('messages.heldby')
            ->whereRaw('TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) > ?', [self::PENDING_HOURS])
            ->get();

        foreach ($candidates as $candidate) {
            try {
                // V1: check for recent logs referencing this message (avoids auto-approving
                // messages that were recently held/unheld).
                $recentLogs = DB::table('logs')
                    ->where('msgid', $candidate->msgid)
                    ->where('timestamp', '>', now()->subHours(self::PENDING_HOURS))
                    ->exists();

                if ($recentLogs) {
                    $stats['skipped']++;
                    continue;
                }

                // V1: iterates all groups for the message, checks each independently.
                $groups = DB::table('messages_groups')
                    ->where('msgid', $candidate->msgid)
                    ->where('collection', MessageGroup::COLLECTION_PENDING)
                    ->pluck('groupid');

                foreach ($groups as $groupid) {
                    if ($this->shouldApproveOnGroup($candidate, $groupid)) {
                        if ($dryRun) {
                            Log::info("Dry run: would auto-approve message #{$candidate->msgid} on group #{$groupid}");
                            $stats['approved']++;
                        } else {
                            $this->approveOnGroup($candidate, $groupid);
                            $stats['approved']++;
                        }
                    } else {
                        $stats['skipped']++;
                    }
                }
            } catch (\Exception $e) {
                Log::error("Error auto-approving message #{$candidate->msgid}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Check whether a message should be auto-approved on a specific group.
     *
     * V1: $g->getSetting('publish', TRUE) && !$g->getSetting('closed', FALSE)
     *     && !$g->getPrivate('autofunctionoverride')
     *     && membership added > 48 hours ago
     */
    protected function shouldApproveOnGroup(object $candidate, int $groupid): bool
    {
        $group = Group::find($groupid);
        if (!$group) {
            return false;
        }

        if (!$group->getSetting('publish', true)) {
            return false;
        }

        if ($group->isClosed()) {
            return false;
        }

        if ($group->getAttribute('autofunctionoverride')) {
            return false;
        }

        // V1: $joined = $u->getMembershipAtt($gid, 'added');
        // $hoursago = round((time() - strtotime($joined)) / 3600);
        $membership = DB::table('memberships')
            ->where('userid', $candidate->fromuser)
            ->where('groupid', $groupid)
            ->first();

        if (!$membership || !$membership->added) {
            return false;
        }

        $memberHours = (int) round((time() - strtotime($membership->added)) / 3600);
        if ($memberHours <= self::MEMBERSHIP_HOURS) {
            return false;
        }

        return true;
    }

    /**
     * Approve a message on a specific group.
     *
     * Matches V1 Message::approve() + Message::autoapprove() side effects.
     */
    protected function approveOnGroup(object $candidate, int $groupid): void
    {
        // V1 notSpam(): record HAM in messages_spamham if message was marked spam.
        if ($candidate->spamtype) {
            DB::table('messages_spamham')->upsert(
                ['msgid' => $candidate->msgid, 'spamham' => 'Ham'],
                ['msgid'],
                ['spamham']
            );
        }

        // V1 approve() log: type=Message, subtype=Approved.
        // V1 uses byuser=0 for system actions; we use NULL (FK constraint prevents user 0).
        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'Message',
            'subtype' => 'Approved',
            'msgid' => $candidate->msgid,
            'groupid' => $groupid,
            'user' => $candidate->fromuser,
            'byuser' => null,
        ]);

        // V1 approve(): UPDATE messages_groups SET collection='Approved', approvedby=0,
        // approvedat=NOW(), arrival=NOW() WHERE msgid=? AND groupid=? AND collection!='Approved'
        // V1 uses approvedby=0; we use NULL (FK constraint prevents user 0).
        DB::table('messages_groups')
            ->where('msgid', $candidate->msgid)
            ->where('groupid', $groupid)
            ->where('collection', '!=', MessageGroup::COLLECTION_APPROVED)
            ->update([
                'collection' => MessageGroup::COLLECTION_APPROVED,
                'approvedby' => null,
                'approvedat' => now(),
                'arrival' => now(),
            ]);

        // V1 autoapprove() log: type=Message, subtype=Autoapproved.
        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'Message',
            'subtype' => 'Autoapproved',
            'msgid' => $candidate->msgid,
            'groupid' => $groupid,
            'user' => $candidate->fromuser,
        ]);

        Log::info("Auto-approved message #{$candidate->msgid} on group #{$groupid}");
    }
}
