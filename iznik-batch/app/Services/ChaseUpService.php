<?php

namespace App\Services;

use App\Helpers\MailHelper;
use App\Mail\Message\ChaseUp as ChaseUpMail;
use App\Mail\Message\ChaseUpPromised;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ChaseUpService
{
    /**
     * Only consider messages from the last N days.
     * V1: $mindate = max('2001-01-01', 90 days ago).
     */
    public const LOOKBACK_DAYS = 90;

    /**
     * Default repost settings.
     */
    public const DEFAULT_REPOSTS = [
        'offer' => 3,
        'wanted' => 7,
        'max' => 5,
        'chaseups' => 5,
    ];

    /**
     * Process chase-ups for all active Freegle groups.
     *
     * Matches V1 chaseup.php → Message::chaseUp().
     * Sends chase-up emails for messages with replies but no outcome,
     * after max reposts reached.
     *
     * Multi-group fix: V1 updates lastchaseup globally
     * (WHERE msgid = ?). We update per-group
     * (WHERE msgid = ? AND groupid = ?).
     *
     * V1 side effects included:
     *   - UPDATE messages_groups SET lastchaseup = NOW() (per-group)
     *   - Chase-up email: "What happened to: {subject}" with links to
     *     mark completed/repost/withdraw. Different template if promised.
     */
    public function process(bool $dryRun = false): array
    {
        $stats = [
            'chased' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $mindate = now()->subDays(self::LOOKBACK_DAYS)->format('Y-m-d');

        // V1: SELECT id FROM groups WHERE type = 'Freegle' ORDER BY RAND()
        // Note: V1 doesn't filter onhere for chaseup (unlike autorepost).
        $groups = Group::freegle()->inRandomOrder()->get();

        foreach ($groups as $group) {
            if ($group->isClosed()) {
                continue;
            }

            $reposts = $group->getSetting('reposts', self::DEFAULT_REPOSTS);

            try {
                $groupStats = $this->processGroup($group, $reposts, $mindate, $dryRun);
                $stats['chased'] += $groupStats['chased'];
                $stats['skipped'] += $groupStats['skipped'];
            } catch (\Exception $e) {
                Log::error("Error processing chase-up for group #{$group->id}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Process chase-ups for a single group.
     */
    protected function processGroup(Group $group, array $reposts, string $mindate, bool $dryRun): array
    {
        $stats = ['chased' => 0, 'skipped' => 0];

        $messages = $this->getCandidates($group->id, $mindate);
        $now = time();

        foreach ($messages as $msg) {
            // V1: Mail::ourDomain check.
            if (!MailHelper::isOurDomain($msg->fromaddr)) {
                $stats['skipped']++;
                continue;
            }

            // V1: canChaseup() — max reposts reached AND enough time since last chaseup.
            if (!$this->canChaseup($msg, $reposts)) {
                $stats['skipped']++;
                continue;
            }

            // V1: find last reply time in chat.
            $lastReplyDate = DB::table('chat_messages')
                ->whereIn('chatid', function ($q) use ($msg) {
                    $q->select('chatid')
                        ->from('chat_messages')
                        ->where('refmsgid', $msg->msgid);
                })
                ->max('date');

            if (!$lastReplyDate) {
                $stats['skipped']++;
                continue;
            }

            $replyAgeHours = ($now - strtotime($lastReplyDate)) / 3600;
            $chaseupInterval = $reposts['chaseups'] ?? 2;

            // V1: $age > $interval * 24
            if ($chaseupInterval <= 0 || $replyAgeHours <= $chaseupInterval * 24) {
                $stats['skipped']++;
                continue;
            }

            // V1: canRepost() check — message must be old enough.
            if (!$this->canRepost($msg, $reposts)) {
                $stats['skipped']++;
                continue;
            }

            // Ready to chase up.
            if ($dryRun) {
                $promised = $this->isPromised($msg->msgid);
                Log::info("Dry run: would send chase-up for message #{$msg->msgid} on group #{$group->id}" . ($promised ? ' (promised)' : ''));
                $stats['chased']++;
                continue;
            }

            // Multi-group fix: update per-group, not global.
            // V1: UPDATE messages_groups SET lastchaseup = NOW() WHERE msgid = ?
            DB::table('messages_groups')
                ->where('msgid', $msg->msgid)
                ->where('groupid', $group->id)
                ->update(['lastchaseup' => now()]);

            // V1: "What happened to: {subject}" — different template if promised.
            $user = User::find($msg->fromuser);
            if ($user && $user->email_preferred) {
                $promised = $this->isPromised($msg->msgid);
                $mailable = $promised
                    ? new ChaseUpPromised(
                        messageId: $msg->msgid,
                        messageSubject: $msg->subject ?? '',
                        messageType: $msg->type,
                        userId: $msg->fromuser,
                        userName: $user->displayname,
                        userEmail: $user->email_preferred,
                        groupId: $group->id,
                    )
                    : new ChaseUpMail(
                        messageId: $msg->msgid,
                        messageSubject: $msg->subject ?? '',
                        messageType: $msg->type,
                        userId: $msg->fromuser,
                        userName: $user->displayname,
                        userEmail: $user->email_preferred,
                        groupId: $group->id,
                    );
                Mail::send($mailable);
            }

            Log::info("Chase-up sent for message #{$msg->msgid} on group #{$group->id}");
            $stats['chased']++;
        }

        return $stats;
    }

    /**
     * Get chase-up candidate messages for a specific group.
     *
     * V1: UNION query for messages_related on id1 and id2.
     * Simplified here: exclude messages that have related messages.
     * Messages must have at least one chat reply (INNER JOIN chat_messages).
     */
    protected function getCandidates(int $groupid, string $mindate)
    {
        return DB::table('messages_groups')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->join('memberships', function ($join) {
                $join->on('memberships.userid', '=', 'messages.fromuser')
                    ->on('memberships.groupid', '=', 'messages_groups.groupid');
            })
            ->leftJoin('messages_related AS mr1', 'mr1.id1', '=', 'messages.id')
            ->leftJoin('messages_related AS mr2', 'mr2.id2', '=', 'messages.id')
            ->leftJoin('messages_outcomes', 'messages.id', '=', 'messages_outcomes.msgid')
            ->join('chat_messages', 'messages.id', '=', 'chat_messages.refmsgid')
            ->select(
                'messages_groups.msgid',
                'messages_groups.groupid',
                'messages_groups.lastchaseup',
                'messages_groups.autoreposts',
                'messages.type',
                'messages.subject',
                'messages.fromaddr',
                'messages.fromuser',
                DB::raw('TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) AS hoursago')
            )
            ->where('messages_groups.arrival', '>', $mindate)
            ->where('messages_groups.groupid', $groupid)
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->whereNull('mr1.id1')
            ->whereNull('mr2.id2')
            ->whereNull('messages_outcomes.msgid')
            ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->where('messages.source', Message::SOURCE_PLATFORM)
            ->whereNull('messages.deleted')
            ->groupBy(
                'messages_groups.msgid',
                'messages_groups.groupid',
                'messages_groups.lastchaseup',
                'messages_groups.autoreposts',
                'messages.type',
                'messages.subject',
                'messages.fromaddr',
                'messages.fromuser',
                'messages_groups.arrival'
            )
            ->get();
    }

    /**
     * Check if a message can be chased up on this specific group.
     *
     * V1 canChaseup(): queries ALL groups, returns TRUE if ANY passes.
     * Multi-group fix: we check only the current group. V1's cross-group
     * leak (chasing up on group A because group B hit max reposts) is not
     * reproduced — each group is evaluated independently.
     */
    protected function canChaseup(object $msg, array $reposts): bool
    {
        $maxreposts = $reposts['max'] ?? 5;

        // Must have reached max reposts (or reposts disabled).
        if ($maxreposts > 0 && $msg->autoreposts < $maxreposts) {
            return false;
        }

        // V1 had a unit-mixing bug here: max(days, chaseups * 24) compared days
        // to hours, making repeat chaseups fire after ~120 days instead of 5.
        // Fixed: both values are now in days.
        $typeIntervalDays = $msg->type === Message::TYPE_OFFER
            ? ($reposts['offer'] ?? 3)
            : ($reposts['wanted'] ?? 7);
        $chaseupDays = $reposts['chaseups'] ?? 2;
        $intervalDays = max($typeIntervalDays, $chaseupDays);

        // If last chaseup exists, must be older than interval.
        if ($msg->lastchaseup) {
            $ageHours = (time() - strtotime($msg->lastchaseup)) / 3600;
            return $ageHours > $intervalDays * 24;
        }

        return true;
    }

    /**
     * Check if a message is old enough to be reposted on this group.
     *
     * V1 canRepost(): queries ALL groups, returns TRUE if ANY passes.
     * Multi-group fix: we check only the current group's arrival time.
     */
    protected function canRepost(object $msg, array $reposts): bool
    {
        $interval = $msg->type === Message::TYPE_OFFER
            ? ($reposts['offer'] ?? 3)
            : ($reposts['wanted'] ?? 7);

        return $msg->hoursago > $interval * 24;
    }

    /**
     * Check if a message has promises.
     *
     * V1: $m->promiseCount()
     */
    protected function isPromised(int $msgid): bool
    {
        return DB::table('messages_promises')
            ->where('msgid', $msgid)
            ->exists();
    }

}
