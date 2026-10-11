<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\Message;
use App\Models\MessageAutomod;
use App\Models\MessageGroup;
use App\Services\Automod\AutomodMode;
use App\Services\Automod\QualitySample;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-approve content-check-clean posts from NULL-posting-status ("auto-moderated")
 * members after a configurable delay (default 20 minutes).
 *
 * Members whose memberships.ourPostingStatus is NULL have never been given an explicit
 * posting status. Historically NULL was treated as MODERATED, so their posts sat in
 * Pending until a moderator acted or the 48-hour AutoApproveService fallback fired.
 *
 * This service reinterprets NULL as "auto-moderated": their posts are held in Pending for
 * a short window — giving moderators and microvolunteers a chance to intervene — and are
 * then released automatically. A configurable percentage is held back as a manual
 * quality-check sample.
 *
 * Posts only become eligible once the automod chart (messages:automod, AutomodService)
 * has reviewed them and recorded an approve verdict on messages_automod — see
 * plans/active/automod-flowchart.md. That chart is what now applies the group-eligibility
 * and member-danger-signal checks that used to live here directly (groupAllowsAutoApprove()
 * and hasDangerSignals() were ported verbatim to AutomodFactsService::groupDisallows() and
 * ::memberVeto()); this service no longer duplicates them. A row goes stale — and this
 * service stops treating it as clean — the moment the post is edited after the chart last
 * saw it (messages.editedat past messages_automod.created), or the content check runs again
 * after it.
 *
 * Trusted members (DEFAULT/UNMODERATED) are unaffected — contentcheck already approves
 * their clean posts immediately. Explicit MODERATED/PROHIBITED members are unaffected too.
 *
 * Every publish is written to Loki through LokiService (the batch container's own log file
 * does not reach Loki), with how long the post waited and how old its decision was.
 */
class AutoApproveCleanService
{
    public function __construct(private readonly ?LokiService $loki = null)
    {
    }

    /** How long a clean post waits in Pending before it publishes itself: the same for every community. */
    public function delayMinutes(): int
    {
        return (int) config('freegle.autoapprove.delay_minutes', 20);
    }

    /** Share of otherwise-clean posts held back for a moderator's verdict: the same for every community. */
    public function qualityCheckPercent(): int
    {
        return QualitySample::percent();
    }

    /**
     * Rollout gate for the clean-path auto-approve.
     *
     * @return int[]|null null  = enabled everywhere (subject to per-group checks);
     *                    []    = fully off (the default — deploying this code is a no-op);
     *                    [ids] = phased trial: enabled only for these groups.
     *
     * A community in the shadow list never publishes whatever this says: AutomodMode is the
     * place that combines the two lists, and AutomodMode::approveModeSql() the SQL form of it.
     */
    public function enabledGroupIds(): ?array
    {
        if (config('freegle.autoapprove.enabled', false)) {
            return null;
        }

        $csv = trim((string) config('freegle.autoapprove.trial_group_ids', ''));
        if ($csv === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $csv))));
    }

    /**
     * Process all eligible pending messages.
     *
     * @return array{approved:int, held_quality:int, skipped:int, errors:int}
     */
    public function process(bool $dryRun = false): array
    {
        $stats = ['approved' => 0, 'held_quality' => 0, 'skipped' => 0, 'errors' => 0];

        if ($this->enabledGroupIds() === []) {
            // Feature dark (default): posts follow the pre-existing behaviour — Pending
            // until a moderator acts or the 48h AutoApproveService fallback fires.
            return $stats;
        }

        // One site-wide delay for every community (config freegle.autoapprove.delay_minutes).
        // There is deliberately no per-community override: members get the same wait
        // everywhere, and the Go countdown in autoapproveat.go assumes the same figure.
        $candidates = DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->join('users as u', 'u.id', '=', 'm.fromuser')
            ->join('memberships as mem', function ($j) {
                $j->on('mem.userid', '=', 'm.fromuser')->on('mem.groupid', '=', 'mg.groupid');
            })
            ->join('groups as g', 'g.id', '=', 'mg.groupid')
            ->select(
                'mg.msgid', 'mg.groupid', 'mg.arrival', 'm.fromuser', 'm.spamtype', 'm.subject',
                DB::raw('m.type as msgtype'),
                // keep-raw: the decision's time from the one row the unique (msgid, groupid) key
                // guarantees, for the published event; a correlated scalar the builder cannot render.
                DB::raw('(SELECT ma2.created FROM messages_automod ma2 WHERE ma2.msgid = mg.msgid AND ma2.groupid = mg.groupid) AS decided_at')
            )
            ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
            ->whereNull('mg.heldby')
            ->whereNull('m.heldby')
            // A copy a moderator pulled back (Back to Pending, or Reject from the Check
            // queue) waits for a moderator's own Approve, which is the only thing that
            // clears the flag: Release alone must not let the clock publish it again.
            ->where('mg.needs_moderator', 0)
            ->whereNull('mg.spamreason')
            ->whereNull('m.spamreason')
            // Never auto-approve a message that is in the Spam collection on ANY
            // group (Discourse #9654). Spam-collection messages surface in the
            // Pending review queue but must be actioned by a human — mirroring
            // the identical guard in AutoApproveService.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_groups as spam_mg')
                    ->whereColumn('spam_mg.msgid', 'mg.msgid')
                    ->where('spam_mg.collection', MessageGroup::COLLECTION_SPAM)
                    ->where('spam_mg.deleted', 0);
            })
            ->where('mg.deleted', 0)
            ->whereNull('m.deleted')
            ->whereNull('u.deleted')
            ->whereNull('mem.ourPostingStatus')           // the auto-moderated tier
            ->whereNotNull('mg.contentcheck_checked_at')   // content check has run ...
            // ... and the automod chart (messages:automod) has since reviewed it and recorded
            // an approve verdict that is still fresh — i.e. not superseded by a later edit or
            // a later content check. This replaces the old direct contentCleanSql()/
            // groupAllowsAutoApprove()/hasDangerSignals() checks, which now live in
            // AutomodFactsService instead (plans/active/automod-flowchart.md).
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_automod as ma')
                    ->whereColumn('ma.msgid', 'mg.msgid')
                    ->whereColumn('ma.groupid', 'mg.groupid')
                    ->where('ma.verdict', MessageAutomod::VERDICT_APPROVE)
                    // keep-raw: COALESCE against a nullable column (m.editedat) compared to
                    // another column (ma.created) isn't expressible with whereColumn/where.
                    ->whereRaw('ma.created >= COALESCE(m.editedat, ?)', ['1970-01-01 00:00:00'])
                    // The decision must postdate the content check it drew its facts from.
                    ->whereColumn('ma.created', '>=', 'mg.contentcheck_checked_at')
                    // The wait counts from the decision as well as from arrival. A verdict
                    // that lands late (service down, backlog, UNAVAILABLE retried) must not
                    // publish a post the minute it is written: moderators had no countdown
                    // in the meantime and would get no window at all. The Go countdown
                    // (autoapproveat.go) shows the same max(arrival, decided) + delay.
                    // keep-raw: NOW() - INTERVAL keeps the comparison on the database clock.
                    ->whereRaw('ma.created <= (NOW() - INTERVAL ? MINUTE)', [$this->delayMinutes()]);
            })
            ->where('mg.quality_sample', 0)               // already-sampled rows are excluded entirely
            ->where('mg.rippled_in', 0)                   // rippled-in rows belong to AutoApproveService (carries the Taken/Received + rippled_in_pending_hours + recentLogs-bypass guards)
            // keep-raw: NOW() - INTERVAL keeps the comparison on the database clock, like the hold check below.
            ->whereRaw('mg.arrival <= (NOW() - INTERVAL ? MINUTE)', [$this->delayMinutes()])
            ->whereRaw(
                '(mg.autoapprove_hold_until IS NULL OR mg.autoapprove_hold_until <= NOW())'
            )
            // Approve-mode communities only: the trial list while the master switch is off,
            // and never a community in the shadow list.
            // keep-raw: a dynamic id list from config, or always/never, which the builder cannot express.
            ->whereRaw(AutomodMode::approveModeSql('mg.groupid'))
            ->orderBy('mg.msgid')
            ->orderBy('mg.groupid')
            // Bound the per-tick batch like the sibling every-minute crons (ripple:expand
            // etc.): a post-outage backlog drains at 500/minute instead of one huge run
            // outliving its overlap mutex. Oldest msgids first, so the backlog is FIFO.
            ->limit(500)
            ->get();

        foreach ($candidates as $row) {
            try {
                if ($this->isQualitySampled((int) $row->msgid)) {
                    // Normally stamped when the chart recorded its approve (AutomodService);
                    // this catches a sample rate raised since, so the stamp is never missed.
                    if (!$dryRun) {
                        DB::table('messages_groups')
                            ->where('msgid', $row->msgid)
                            ->where('groupid', $row->groupid)
                            ->where('quality_sample', 0)
                            ->update(['quality_sample' => 1]);
                        $stats['held_quality']++;
                    }
                    continue;
                }

                if ($dryRun) {
                    $stats['approved']++;
                    Log::info("Dry run: would auto-approve clean message #{$row->msgid} on group #{$row->groupid}");
                    continue;
                }

                if ($this->approve($row)) {
                    $stats['approved']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Exception $e) {
                Log::error("AutoApproveClean: error processing message #{$row->msgid}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Deterministically hold a site-wide percentage of otherwise-eligible posts in Pending so a
     * moderator spot-checks the auto-approval quality (QualitySample, shared with the chart's
     * decision and the Go countdown).
     */
    protected function isQualitySampled(int $msgid): bool
    {
        return QualitySample::sampled($msgid);
    }

    /**
     * Approve a message on a group. Mirrors AutoApproveService::approveOnGroup side effects
     * (NULL approvedby, reset arrival, Autoapproved log, Ham recording) and additionally
     * queues a freebie-alert task for Offers, matching the immediate contentcheck approval.
     *
     * Candidates are selected once and written one by one, and a moderator can act in the
     * gap: the write re-checks that the copy is still an unheld, un-pulled-back Pending row
     * with no live hold, and does nothing at all otherwise. A Reject, a Hold or a moderator
     * opening the queue (which bumps the hold) in that window therefore stands.
     *
     * @return bool whether the post was published here.
     */
    protected function approve(object $row): bool
    {
        $published = DB::transaction(function () use ($row) {
            $updated = DB::table('messages_groups')
                ->where('msgid', $row->msgid)
                ->where('groupid', $row->groupid)
                ->where('collection', MessageGroup::COLLECTION_PENDING)
                ->whereNull('heldby')
                ->where('needs_moderator', 0)
                // keep-raw: NOW() keeps the hold comparison on the database clock.
                ->whereRaw('(autoapprove_hold_until IS NULL OR autoapprove_hold_until <= NOW())')
                ->update([
                    'collection' => MessageGroup::COLLECTION_APPROVED,
                    'approvedby' => null,            // NULL marks an auto-approval
                    'approvedat' => now(),
                    'arrival'    => now(),           // so the digest picks it up as new
                ]);

            if ($updated === 0) {
                return false;
            }

            DB::table('logs')->insert([
                'timestamp' => now(),
                'type'      => 'Message',
                'subtype'   => 'Autoapproved',
                'msgid'     => $row->msgid,
                'groupid'   => $row->groupid,
                'user'      => $row->fromuser,
                // Which automatic path: the 48h sweep writes none, so the two can be told apart.
                'text'      => 'automod',
            ]);

            if ($row->msgtype === Message::TYPE_OFFER) {
                DB::table('background_tasks')->insert([
                    'task_type' => BackgroundTask::TASK_FREEBIE_ALERTS_ADD,
                    'data'      => json_encode(['msgid' => (int) $row->msgid]),
                ]);
            }

            // Mirror the Go manual-approve path (addApprovedMessageToSpatialIndex): seed
            // messages_spatial now so the post appears in browse/search — and becomes
            // visible to the rippling engine — immediately, instead of waiting up to
            // 5 minutes for the spatial reconciler cron. Re-checks Approved so it is a
            // no-op if anything above did not land.
            // keep-raw: INSERT ... SELECT with ST_GeomFromText and ON DUPLICATE KEY UPDATE; the builder's insertUsing cannot render the upsert clause.
            DB::statement(
                "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
                 SELECT m.id, ST_GeomFromText(CONCAT('POINT(', m.lng, ' ', m.lat, ')'), 3857),
                        mg.groupid, m.type, mg.arrival
                 FROM messages m
                 INNER JOIN messages_groups mg ON mg.msgid = m.id
                 LEFT JOIN messages_outcomes mo ON mo.msgid = m.id
                 WHERE m.id = ? AND mg.groupid = ? AND mg.collection = 'Approved'
                   AND mg.deleted = 0 AND m.deleted IS NULL
                   AND m.lat IS NOT NULL AND m.lng IS NOT NULL
                   AND NOT (m.lat = 0 AND m.lng = 0)
                   AND mo.id IS NULL
                 ON DUPLICATE KEY UPDATE point = VALUES(point), msgtype = VALUES(msgtype),
                                         arrival = VALUES(arrival)",
                [$row->msgid, $row->groupid]
            );

            return true;
        });

        if (!$published) {
            Log::info("AutoApproveClean: message #{$row->msgid} on group #{$row->groupid} changed before the write (a moderator acted, or a hold was bumped); not published");
            $this->loki()->logEvent('autoapprove', 'skipped', [
                'msgid' => (int) $row->msgid,
                'groupid' => (int) $row->groupid,
                'reason' => 'changed before write',
            ]);

            return false;
        }

        // notSpam parity: whitelist the subject when it was flagged for cross-group reuse.
        if ($row->spamtype === 'SubjectUsedForDifferentGroups' && $row->subject) {
            DB::table('spam_whitelist_subjects')->insertOrIgnore([
                'subject' => AutoApproveService::getPrunedSubject($row->subject),
                'comment' => 'Marked as not spam',
            ]);
        }

        // notSpam parity: record HAM when the message had been marked spam.
        if ($row->spamtype) {
            DB::table('messages_spamham')->upsert(
                ['msgid' => $row->msgid, 'spamham' => 'Ham'],
                ['msgid'],
                ['spamham']
            );
        }

        $minutesWaited = !empty($row->arrival) ? (int) now()->diffInMinutes(Carbon::parse($row->arrival), true) : null;
        $decisionAge = !empty($row->decided_at) ? (int) now()->diffInSeconds(Carbon::parse($row->decided_at), true) : null;

        Log::info("AutoApproveClean: approved message #{$row->msgid} on group #{$row->groupid} after {$minutesWaited} minutes (decision {$decisionAge}s old)");
        $this->loki()->logEvent('autoapprove', 'published', [
            'msgid' => (int) $row->msgid,
            'groupid' => (int) $row->groupid,
            'minutes_waited' => $minutesWaited,
            'decision_age_seconds' => $decisionAge,
        ]);

        return true;
    }

    private function loki(): LokiService
    {
        return $this->loki ?? app(LokiService::class);
    }
}
