<?php

namespace App\Console\Commands\Monitor;

use App\Models\MessageGroup;
use App\Services\AutoApproveCleanService;
use App\Services\Automod\AutomodMode;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Watches automated review (messages:automod) the way monitor:email-health watches mail.
 *
 * Fails, and escalates to Sentry, when either:
 *
 * - UNAVAILABLE: a Pending post on a shadow or approve-mode community has had its content
 *   check for a quarter of an hour and automated review still cannot decide it. A failed
 *   decision is retried every five minutes and the retry rewrites the row's timestamp, so
 *   the row's own age says nothing; the age of the content check it is waiting behind does.
 * - UNDECIDED: a post on an approve-mode community from an auto-moderated member is older
 *   than the publish delay and has no decision at all (or only one that predates a later
 *   content check). The countdown shows nothing for such a post and nothing will publish
 *   it, so moderators see a post that looks unmoderated and is going nowhere.
 *
 * Both are quiet while automated review is dark (neither list set), so the schedule costs
 * nothing until a community is named. Failures show as red badges in the cron jobs
 * sysadmin tab, like the other monitors.
 */
#[AsCommand(name: 'monitor:automod-health')]
class AutomodHealthCommand extends Command
{
    protected $signature = 'monitor:automod-health';

    protected $description = 'Fails when automated review has been unavailable for 15 minutes or a trial post is overdue with no decision';

    private const UNAVAILABLE_MINUTES = 15;

    public function handle(): int
    {
        $eligible = AutomodMode::eligibleGroupIds();
        if ($eligible === []) {
            $this->info('Automated review is dark; nothing to check.');

            return Command::SUCCESS;
        }

        $failures = [];

        // --- Reviews that have been failing for a quarter of an hour ---
        $unavailable = DB::table('messages_automod as ma')
            ->join('messages_groups as mg', function ($j) {
                $j->on('mg.msgid', '=', 'ma.msgid')->on('mg.groupid', '=', 'ma.groupid');
            })
            ->where('ma.end_node', 'UNAVAILABLE')
            ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
            ->where('mg.deleted', 0)
            ->where('mg.contentcheck_checked_at', '<=', now()->subMinutes(self::UNAVAILABLE_MINUTES))
            ->when($eligible !== null, fn ($q) => $q->whereIn('mg.groupid', $eligible))
            ->count();

        if ($unavailable > 0) {
            $failures[] = "UNAVAILABLE: {$unavailable} post(s) automated review has failed to decide for "
                . self::UNAVAILABLE_MINUTES . ' minutes (is the automod service up, and answering within AUTOMOD_TIMEOUT?)';
        }

        $this->info('Unavailable for ' . self::UNAVAILABLE_MINUTES . "+ minutes: {$unavailable}");

        // --- Approve-mode posts past the delay with nothing decided ---
        $delay = (new AutoApproveCleanService())->delayMinutes();

        $undecidedQuery = DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->join('memberships as mem', function ($j) {
                $j->on('mem.userid', '=', 'm.fromuser')->on('mem.groupid', '=', 'mg.groupid');
            })
            ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
            ->where('mg.deleted', 0)
            ->whereNull('m.deleted')
            ->where('mg.rippled_in', 0)
            ->whereNull('mg.heldby')
            ->where('mg.needs_moderator', 0)
            ->whereNull('mem.ourPostingStatus')
            ->whereNotNull('mg.contentcheck_checked_at')
            ->where('mg.contentcheck_checked_at', '<=', now()->subMinutes($delay))
            // keep-raw: the approve-mode set is a dynamic id list from config, or always/never.
            ->whereRaw(AutomodMode::approveModeSql('mg.groupid'))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_automod as ma')
                    ->whereColumn('ma.msgid', 'mg.msgid')
                    ->whereColumn('ma.groupid', 'mg.groupid')
                    ->whereColumn('ma.created', '>=', 'mg.contentcheck_checked_at');
            });

        $undecided = (clone $undecidedQuery)->count();
        if ($undecided > 0) {
            $oldest = $undecidedQuery->min('mg.contentcheck_checked_at');
            $minutes = (int) now()->diffInMinutes(Carbon::parse($oldest), true);
            $failures[] = "UNDECIDED: {$undecided} approve-mode post(s) past the {$delay}-minute delay with no decision "
                . "(oldest waiting {$minutes} minutes; is messages:automod running and keeping up?)";
        }

        $this->info("Undecided past the delay: {$undecided}");

        if ($failures !== []) {
            foreach ($failures as $f) {
                $this->error($f);
                Log::warning("[AutomodHealth] {$f}");
            }

            // Same escalation as monitor:email-health: the team watch Sentry.
            if (function_exists('\Sentry\captureMessage')) {
                \Sentry\captureMessage('[AutomodHealth] ' . implode('; ', $failures));
            }

            return Command::FAILURE;
        }

        $this->info('Automated review health OK.');

        return Command::SUCCESS;
    }
}
