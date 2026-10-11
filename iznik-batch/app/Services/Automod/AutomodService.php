<?php

namespace App\Services\Automod;

use App\Models\MessageAutomod;
use App\Models\MessageGroup;
use App\Services\LokiService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a Pending post's facts and text to the automod Node service (automod/, POST /review),
 * walks it through the chart (automod/chart.json) and stores the verdict on messages_automod
 * (plans/active/automod-flowchart.md). One row per (msgid, groupid); a rerun (edit, or the
 * messages:automod command picking it up again) replaces the row rather than adding another.
 *
 * The service call can fail (network, timeout, backend down) without that being a moderation
 * decision: on failure this records end_node=UNAVAILABLE and leaves the post to ordinary
 * moderation rather than guessing hold or approve. A reply that is not the shape the chart
 * produces is treated the same way, never as an approve.
 *
 * Every decision, and every failure to decide, also goes to Loki through LokiService: the batch
 * container's ordinary log file does not reach Loki, and these are the events that answer "why
 * did this post publish itself?" and "why is nothing publishing?". Each run of process() adds
 * one line with the backlog it left behind.
 */
class AutomodService
{
    /**
     * How long one run of process() may keep taking candidates. The command runs every minute
     * under withoutOverlapping(15); a run that outlived that would let a second instance start
     * on the same head of the queue (doubling the model calls and racing on the row), so a run
     * stops well inside a minute and the next minute carries on from where it left off.
     */
    public const RUN_BUDGET_SECONDS = 50.0;

    /** The service's own per-run figures, kept on the row as `detail` for cost and quality work. */
    private const DETAIL_KEYS = ['features', 'ms', 'calls', 'model', 'cost_usd', 'tokens', 'prompt_sha', 'reply_sha'];

    public function __construct(
        private readonly ?AutomodFactsService $factsService = null,
        private readonly ?LokiService $loki = null
    ) {
    }

    /**
     * @param bool $persist whether to write the messages_automod row. False for
     *                       automod:evaluate's live-compare mode, where the sampled posts are
     *                       already decided (Approved/Rejected, not Pending) and writing a
     *                       verdict for them would be noise rather than a moderation record.
     * @return array<string, mixed>|null the decoded /review response on success, or null if
     *                                    the service could not be reached or did not answer in
     *                                    the chart's shape (a row is still written either way,
     *                                    when $persist is true).
     */
    public function review(int $msgid, int $groupid, string $mode, ?string $backend = null, bool $persist = true): ?array
    {
        // The decision is dated from the moment the text is read, not from when the reply lands
        // 10 to 20 seconds later: an edit made while the model is answering must count as later
        // than the decision, or the row would look current for text it never saw.
        $readAt = now();
        $startedAt = microtime(true);

        $message = DB::table('messages')
            ->where('id', $msgid)
            ->first(['subject', 'textbody', 'type']);

        if (!$message) {
            Log::warning('AutomodService: message not found', ['msgid' => $msgid, 'groupid' => $groupid]);

            return null;
        }

        $facts = $this->factsService()->facts($msgid, $groupid);
        $rules = $this->factsService()->rules($groupid);
        $otherPosts = $facts['other_posts'] ?? [];
        unset($facts['other_posts']);

        // A community that has moderators check every post gets no automated decision: the
        // answer is the community's setting, recorded once so the countdown and the line show it.
        if (!empty($facts['group_disallows'])) {
            $decoded = [
                'chart' => 'freegle-automod',
                'version' => '',
                'verdict' => MessageAutomod::VERDICT_HOLD,
                'end' => 'GROUP_MODERATES_ALL',
                'reason' => ($facts['group_disallows_detail'] ?? 'This community has moderators check every post') . '.',
                'path' => [],
            ];
            if ($persist) {
                $this->record($msgid, $groupid, $mode, $decoded, $readAt, $startedAt);
            }

            return $decoded;
        }

        $timeout = (int) config('freegle.automod.timeout', 20);

        $payload = [
            'msgid' => $msgid,
            'groupid' => $groupid,
            'subject' => $message->subject ?? '',
            'body' => $message->textbody ?? '',
            'type' => $message->type ?? '',
            // Objects, not arrays: an empty PHP array encodes as [] and the service wants {}.
            'facts' => (object) $facts,
            'rules' => (object) $rules,
            'other_posts' => $otherPosts,
            // The service stops asking and answers "hold" at the deadline, a little before this
            // side's HTTP timeout would throw the whole reply away.
            'deadline_ms' => max(1000, ($timeout - 2) * 1000),
        ];

        if ($backend) {
            $payload['backend'] = $backend;
        }

        $url = rtrim((string) config('freegle.automod.url'), '/') . '/review';

        try {
            $response = Http::timeout($timeout)->post($url, $payload);

            if (!$response->successful()) {
                $error = 'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 300);
                Log::warning('AutomodService: /review returned an error', [
                    'msgid' => $msgid,
                    'groupid' => $groupid,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                ]);
                $this->unavailable($msgid, $groupid, $mode, $persist, $error, $readAt);

                return null;
            }

            $decoded = $response->json();
            if (!is_array($decoded)) {
                Log::warning('AutomodService: /review returned an unparseable body', [
                    'msgid' => $msgid,
                    'groupid' => $groupid,
                ]);
                $this->unavailable($msgid, $groupid, $mode, $persist, 'unparseable body', $readAt);

                return null;
            }

            if (!self::wellFormed($decoded)) {
                $error = 'malformed decision: ' . substr(json_encode([
                    'verdict' => $decoded['verdict'] ?? null,
                    'end' => $decoded['end'] ?? null,
                    'path' => gettype($decoded['path'] ?? []),
                ]), 0, 300);
                Log::warning('AutomodService: /review answered in a shape the chart does not produce', [
                    'msgid' => $msgid,
                    'groupid' => $groupid,
                    'error' => $error,
                ]);
                $this->unavailable($msgid, $groupid, $mode, $persist, $error, $readAt);

                return null;
            }

            if ($persist) {
                $this->record($msgid, $groupid, $mode, $decoded, $readAt, $startedAt);
                $this->stampQualitySample($msgid, $groupid, $mode, $decoded);
            }

            return $decoded;
        } catch (\Exception $e) {
            Log::warning('AutomodService: /review call failed', [
                'msgid' => $msgid,
                'groupid' => $groupid,
                'error' => $e->getMessage(),
            ]);
            $this->unavailable($msgid, $groupid, $mode, $persist, $e->getMessage(), $readAt);

            return null;
        }
    }

    /**
     * A decision the chart could have produced: a verdict this side knows, a named end node,
     * and a path that is a list (it may be empty). Anything else is not trusted, and an
     * "approve" least of all.
     *
     * @param array<string, mixed> $decoded
     */
    public static function wellFormed(array $decoded): bool
    {
        $end = $decoded['end'] ?? null;
        $path = $decoded['path'] ?? [];

        return in_array($decoded['verdict'] ?? null, [MessageAutomod::VERDICT_APPROVE, MessageAutomod::VERDICT_HOLD], true)
            && is_string($end) && $end !== ''
            && is_array($path);
    }

    /**
     * Picks up Pending posts in shadow/approve groups once their content check has run, and
     * runs review() for any that have no messages_automod row yet, were edited since their last
     * one, or whose last one predates their latest content check (mirrors
     * AutoApproveCleanService::process()'s candidate query/loop shape).
     *
     * @param float $budgetSeconds how long to keep taking candidates (see RUN_BUDGET_SECONDS).
     * @return array{reviewed: int, unavailable: int, errors: int, timeboxed: int}
     */
    public function process(int $limit = 200, float $budgetSeconds = self::RUN_BUDGET_SECONDS): array
    {
        $stats = ['reviewed' => 0, 'unavailable' => 0, 'errors' => 0, 'timeboxed' => 0];

        $eligibleGroupIds = AutomodMode::eligibleGroupIds();
        if ($eligibleGroupIds === []) {
            // Automod is dark everywhere - nothing to do.
            return $stats;
        }

        $candidates = $this->candidates($eligibleGroupIds)
            ->orderBy('mg.msgid')
            ->orderBy('mg.groupid')
            ->limit($limit)
            ->get(['mg.msgid', 'mg.groupid']);

        $started = $this->runClock();
        $durationsMs = [];

        foreach ($candidates as $i => $row) {
            if ($this->runClock() - $started >= $budgetSeconds) {
                $stats['timeboxed'] = count($candidates) - $i;
                Log::info("AutomodService: run budget spent after {$i} posts; {$stats['timeboxed']} left for the next run");
                break;
            }

            $msgid = (int) $row->msgid;
            $groupid = (int) $row->groupid;

            $mode = AutomodMode::for($groupid);
            if ($mode === null) {
                // Config changed between the query and this row being processed - skip it,
                // the next run will pick it up correctly (or not at all).
                continue;
            }

            try {
                $callStarted = microtime(true);
                $result = $this->review($msgid, $groupid, $mode);
                $durationsMs[] = (int) round((microtime(true) - $callStarted) * 1000);
                if ($result === null) {
                    $stats['unavailable']++;
                }
                $stats['reviewed']++;
            } catch (\Exception $e) {
                Log::error("AutomodService: error reviewing message #{$msgid} group #{$groupid}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        $this->logRun($eligibleGroupIds, $stats, $durationsMs);

        return $stats;
    }

    /** The run clock, in seconds. Overridable so a test can spend the budget at will. */
    protected function runClock(): float
    {
        return microtime(true);
    }

    /**
     * The Pending copies the chart has yet to decide, or decided on text or findings it has
     * since been superseded on. A copy the clean path could never publish (held, flagged as
     * spam, deleted, or from a deleted member) is not reviewed: the model call would be wasted.
     *
     * @param int[]|null $eligibleGroupIds
     */
    private function candidates(?array $eligibleGroupIds): Builder
    {
        return DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->join('users as u', 'u.id', '=', 'm.fromuser')
            ->leftJoin('messages_automod as ma', function ($join) {
                $join->on('ma.msgid', '=', 'mg.msgid')
                    ->on('ma.groupid', '=', 'mg.groupid');
            })
            ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
            ->where('mg.deleted', 0)
            ->where('mg.rippled_in', 0)
            ->whereNull('mg.heldby')
            ->whereNull('m.heldby')
            ->whereNull('mg.spamreason')
            ->whereNull('m.spamreason')
            ->whereNull('m.deleted')
            ->whereNull('u.deleted')
            ->whereNotNull('mg.contentcheck_checked_at')
            // The chart's facts come from the content check's findings, so the check must have
            // seen the latest edit before the chart decides on it; the check and this command run
            // in the same minute, in no fixed order.
            ->where(function ($query) {
                $query->whereNull('m.editedat')
                    ->orWhereColumn('mg.contentcheck_checked_at', '>=', 'm.editedat');
            })
            ->where(function ($query) {
                // No automod row yet, the post was edited after the row was last written, the
                // content check has run again since the row was written, or the review could not
                // answer last time and it is worth another try.
                $query->whereNull('ma.id')
                    ->orWhereColumn('m.editedat', '>', 'ma.created')
                    ->orWhereColumn('ma.created', '<', 'mg.contentcheck_checked_at')
                    ->orWhere(function ($q) {
                        $q->where('ma.end_node', 'UNAVAILABLE')
                            ->where('ma.created', '<', now()->subMinutes(5));
                    });
            })
            ->when(
                $eligibleGroupIds !== null,
                fn ($query) => $query->whereIn('mg.groupid', $eligibleGroupIds)
            );
    }

    /**
     * One line per run, to Loki and the log: what this run did and what it left behind, so a
     * backlog, an outage or a slow model is visible without reading the database.
     *
     * @param int[]|null $eligibleGroupIds
     * @param array{reviewed: int, unavailable: int, errors: int, timeboxed: int} $stats
     * @param int[] $durationsMs
     */
    private function logRun(?array $eligibleGroupIds, array $stats, array $durationsMs): void
    {
        $waiting = $this->candidates($eligibleGroupIds);
        $candidatesWaiting = (clone $waiting)->count();
        $oldest = $candidatesWaiting > 0 ? $waiting->min('mg.contentcheck_checked_at') : null;

        $unavailableRows = DB::table('messages_automod as ma')
            ->join('messages_groups as mg', function ($join) {
                $join->on('mg.msgid', '=', 'ma.msgid')->on('mg.groupid', '=', 'ma.groupid');
            })
            ->where('ma.end_node', 'UNAVAILABLE')
            ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
            ->where('mg.deleted', 0)
            ->when($eligibleGroupIds !== null, fn ($q) => $q->whereIn('mg.groupid', $eligibleGroupIds))
            ->count();

        sort($durationsMs);
        $context = array_merge($stats, [
            'candidates_waiting' => $candidatesWaiting,
            'oldest_wait_minutes' => $oldest ? (int) now()->diffInMinutes(Carbon::parse($oldest), true) : null,
            'unavailable_rows' => $unavailableRows,
            'ms_p50' => self::percentile($durationsMs, 0.5),
            'ms_p95' => self::percentile($durationsMs, 0.95),
        ]);

        Log::info('Automod run', $context);
        $this->loki()->logBatchJob('messages:automod', 'run', $context);
    }

    /** @param int[] $sorted ascending */
    private static function percentile(array $sorted, float $p): ?int
    {
        if ($sorted === []) {
            return null;
        }

        return $sorted[(int) min(count($sorted) - 1, floor($p * count($sorted)))];
    }

    private function factsService(): AutomodFactsService
    {
        return $this->factsService ?? new AutomodFactsService();
    }

    private function loki(): LokiService
    {
        return $this->loki ?? app(LokiService::class);
    }

    /**
     * @param array<string, mixed> $decoded the /review response body.
     */
    private function record(int $msgid, int $groupid, string $mode, array $decoded, Carbon $readAt, float $startedAt): void
    {
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $detail = [];
        foreach (self::DETAIL_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                $detail[$key] = $decoded[$key];
            }
        }
        $detail['elapsed_ms'] = $elapsedMs;

        MessageAutomod::updateOrCreate(
            ['msgid' => $msgid, 'groupid' => $groupid],
            [
                'mode' => $mode,
                'chart_version' => (string) ($decoded['version'] ?? ''),
                'verdict' => $decoded['verdict'] ?? MessageAutomod::VERDICT_HOLD,
                'end_node' => $decoded['end'] ?? 'UNKNOWN',
                'reason' => $decoded['reason'] ?? null,
                'path' => $decoded['path'] ?? [],
                'detail' => $detail,
                'created' => $readAt,
            ]
        );

        $context = [
            'msgid' => $msgid,
            'groupid' => $groupid,
            'mode' => $mode,
            'verdict' => $decoded['verdict'] ?? MessageAutomod::VERDICT_HOLD,
            'end_node' => $decoded['end'] ?? 'UNKNOWN',
            'chart_version' => (string) ($decoded['version'] ?? ''),
            'model' => $decoded['model'] ?? null,
            'ms' => $decoded['ms'] ?? $elapsedMs,
            'cost_usd' => $decoded['cost_usd'] ?? null,
            'elapsed_ms' => $elapsedMs,
        ];
        Log::info("AutomodService: {$context['verdict']} ({$context['end_node']}) for message #{$msgid} on group #{$groupid} in {$elapsedMs}ms", $context);
        $this->loki()->logEvent('automod', 'decision', $context);
    }

    private function unavailable(int $msgid, int $groupid, string $mode, bool $persist, string $error, Carbon $readAt): void
    {
        if (!$persist) {
            return;
        }

        MessageAutomod::updateOrCreate(
            ['msgid' => $msgid, 'groupid' => $groupid],
            [
                'mode' => $mode,
                'chart_version' => '',
                'verdict' => MessageAutomod::VERDICT_HOLD,
                'end_node' => 'UNAVAILABLE',
                'reason' => 'Automated review unavailable',
                'path' => [],
                'detail' => ['error' => $error],
                'created' => $readAt,
            ]
        );

        $this->loki()->logEvent('automod', 'unavailable', [
            'msgid' => $msgid,
            'groupid' => $groupid,
            'mode' => $mode,
            'error' => $error,
        ]);
    }

    /**
     * The quality sample is decided with the verdict, not when the wait runs out: an approve on
     * an approve-mode community that falls in the sample is stamped now, so the Pending card can
     * say so and the countdown never promises a publish that the sample will keep back.
     *
     * @param array<string, mixed> $decoded
     */
    private function stampQualitySample(int $msgid, int $groupid, string $mode, array $decoded): void
    {
        if ($mode !== MessageAutomod::MODE_APPROVE
            || ($decoded['verdict'] ?? null) !== MessageAutomod::VERDICT_APPROVE
            || !QualitySample::sampled($msgid)) {
            return;
        }

        $stamped = DB::table('messages_groups')
            ->where('msgid', $msgid)
            ->where('groupid', $groupid)
            ->where('quality_sample', 0)
            ->update(['quality_sample' => 1]);

        if ($stamped > 0) {
            Log::info("AutomodService: message #{$msgid} on group #{$groupid} held back as a quality sample");
            $this->loki()->logEvent('automod', 'quality_sample', ['msgid' => $msgid, 'groupid' => $groupid]);
        }
    }
}
