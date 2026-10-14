<?php

namespace App\Services\Ux;

use App\Services\LokiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The automated UX experiment loop's live half: start an experiment, read it from Loki every
 * hour by arm, move traffic towards the arm that is winning (a bandit, not a fixed split), and
 * decide within 24 hours. docs/developers/reference/ux-telemetry.md section 8.
 *
 * An experiment is a switch in the client (useExperiment('<name>')) plus a row here. Which arm a
 * member lands in is crc32 bucketing over the ranges in `arms`, published to the client through
 * the `config` table key `ux_experiments`. Every flow event carries the arm, so reading an
 * experiment is one Loki metric query by arm, flow and event over the window since it started.
 *
 * The primary measure is the flow's completion rate (flow_complete over flow_start), end to end,
 * never one step: a step fixed can push the loss later. Because the split moves every hour and the
 * traffic mix moves with the clock, the comparison is made within strata (six-hour block times
 * logged in) and weighted back together, so a change in who was arriving cannot pass for an
 * effect of the change. Guardrails are read the same way: submit failures, validation failures,
 * help sought, and the OTHER flows the change touches, by the same arm, so a change to Give that
 * hurts Reply is caught. A guardrail trips only when the arm is worse by more than a margin, so a
 * null change read 24 times does not trip one by chance.
 *
 * Only flows that started at least a settling time ago are counted, but everything they did is
 * counted up to now: a flow started in the last three quarters of an hour has not had time to
 * finish, and the arm getting more traffic would otherwise look worse for having more unfinished
 * starts. Every flow event carries when its flow started (started_ms), so the cut is made on the
 * flow, and a flow that started before the cut and finished after it still counts as a completion.
 * Strata use whether the member was signed in when the flow STARTED (start_logged_in), because
 * signing in during the flow is common and an event-time stratum would put the starts and the
 * completes of one flow in different strata.
 *
 * Two safety properties matter more than the statistics:
 *
 *  - One writer at a time. start, stop, close and each experiment's step in the tick take a
 *    database lock (GET_LOCK on the write node), every status change is an UPDATE that names the
 *    status it expects and checks it changed a row, and the tick re-reads the row after its slow
 *    Loki read and before it finishes it. So a human stop that lands while a read is in flight
 *    wins, and two starts cannot both pass the cap.
 *  - The client config says when to stop believing it. Each running entry carries an `until` (its
 *    end plus an hour), so a batch that dies leaves members on control within a day, not forever.
 *
 * After adoption 10% of members keep the old version (the holdout) until the cleanup closes the
 * experiment. For 14 days the tick compares them to the adopted arm once a day, and takes the
 * change off again if the holdout is doing better: a gain that decays (novelty) is not a gain.
 *
 * Thresholds, caps and the bandit band are config('freegle.ux.experiments'), not code.
 */
class UxExperimentService
{
    public const CONFIG_KEY = 'ux_experiments';

    public const CONTROL = 'control';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RUNNING = 'running';

    public const STATUS_ADOPTED = 'adopted';

    public const STATUS_REVERTED = 'reverted';

    public const STATUS_INCONCLUSIVE = 'inconclusive';

    public const STATUS_STOPPED = 'stopped';

    /** Once adopted and cleaned up in code, the row is closed and leaves the config key. */
    public const STATUS_CLOSED = 'closed';

    public const ACTIVE = [self::STATUS_RUNNING];

    /**
     * What a member can say about a change. The last two are answers too, not refusals, and are worded
     * so neither reads as a charge against the member. The client swaps the first two for members
     * with an odd user id and keeps the last two where they are; the keys do not change, so the
     * counts need no unpicking.
     */
    public const EXPERIMENT_OPTIONS = [
        ['key' => 'better', 'label' => 'Better'],
        ['key' => 'worse', 'label' => 'Worse'],
        ['key' => 'dont_care', 'label' => 'Makes no difference to me'],
        ['key' => 'dont_understand', 'label' => "I'm not sure what it's for"],
    ];

    /** Events read per arm and flow. */
    private const EVENTS = ['flow_start', 'flow_complete', 'flow_submit_result', 'validation_failed', 'help_sought'];

    /** Hours in a stratum block: four blocks a day, so night and day are compared like with like. */
    private const BLOCK_HOURS = 6;

    private const LOCK = 'ux_experiments';

    private const NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** What a status can move on from, for start(). */
    private const STARTABLE = [self::STATUS_DRAFT, self::STATUS_STOPPED, self::STATUS_INCONCLUSIVE, self::STATUS_REVERTED];

    /** Re-entrant depth of the lock held by this object. */
    private int $lockDepth = 0;

    public function __construct(private LokiService $loki)
    {
    }

    // ------------------------------------------------------------------ settings

    public static function settings(): array
    {
        $s = (array) config('freegle.ux.experiments', []);

        return $s + [
            'max_concurrent' => 5,
            'max_hours' => 24,
            'settle_minutes' => 45,
            'min_starts_per_arm' => 50,
            'early_min_starts_per_arm' => 300,
            'adopt_probability' => 0.80,
            'max_expected_loss_points' => 0.5,
            'early_adopt_probability' => 0.99,
            'early_revert_probability' => 0.02,
            'guardrail_margin_points' => 2.0,
            // The chance of a false alarm across all of an experiment's guardrails together, split
            // between them (Bonferroni): with k guardrails each needs 1 - error / k.
            'guardrail_error' => 0.10,
            'early_guardrail_error' => 0.03,
            'guardrail_min_events' => 100,
            'bandit' => true,
            'min_control_share' => 35,
            'max_variant_share' => 65,
            'cleanup_days' => 7,
            // Refuse a start whose expected effect a day of this flow could rarely show. 0 disables.
            'min_power' => 0.30,
            'default_expected_points' => 5.0,
            // A read older than this is never used to adopt; after the end the tick waits this
            // long for Loki before it gives up and marks the experiment inconclusive.
            'stale_read_hours' => 2,
            'stale_wait_hours' => 6,
            // After adoption this share of members keeps the old version, and for this many days
            // it is compared once a day with the adopted arm.
            'holdout_share' => 10,
            'holdout_days' => 14,
            // High, and needed on holdout_revert_looks looks in a row: there are up to 14 daily looks,
            // so a loose bar would undo good changes by chance.
            'holdout_revert_probability' => 0.99,
            'holdout_revert_looks' => 2,
            // A change that was inconclusive goes back to the backlog this many times at most.
            'idea_max_attempts' => 2,
            // How long a writer waits for the experiments lock before giving up.
            'lock_wait_seconds' => 10,
        ];
    }

    // ------------------------------------------------------------------ lifecycle

    /**
     * A new draft experiment. $a has flow, name, title, hypothesis, change, expected and
     * optionally arms, pr_url, image_control, image_variant, idea_id and design.
     *
     * design is {touches: [flows the change can affect], expected_points: effect expected}. Its
     * guardrails read only the flows it touches, so a change to Give is not stopped by noise in
     * a flow it cannot reach. touches defaults to the experiment's own flow and login.
     */
    public function create(array $a): int
    {
        $name = (string) ($a['name'] ?? '');
        if (! preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException('name must be 1 to 64 letters, digits, _ or -');
        }
        if (! in_array($a['flow'] ?? '', UxRollupService::FLOWS, true)) {
            throw new \InvalidArgumentException('flow must be one of '.implode(', ', UxRollupService::FLOWS));
        }
        foreach (['title', 'hypothesis', 'change', 'expected'] as $k) {
            if (trim((string) ($a[$k] ?? '')) === '') {
                throw new \InvalidArgumentException("$k is required");
            }
        }
        $arms = self::normaliseArms($a['arms'] ?? null);
        $design = self::normaliseDesign($a['design'] ?? null, $a['flow']);

        $now = Carbon::now('UTC');

        return DB::table('ux_experiments')->insertGetId([
            'flow' => $a['flow'],
            'name' => $name,
            'title' => mb_substr(trim($a['title']), 0, 160),
            'hypothesis' => trim($a['hypothesis']),
            'change_description' => trim($a['change']),
            'expected' => trim($a['expected']),
            'arms' => json_encode($arms),
            'status' => self::STATUS_DRAFT,
            'pr_url' => $a['pr_url'] ?? null,
            'image_control' => $a['image_control'] ?? null,
            'image_variant' => $a['image_variant'] ?? null,
            'idea_id' => $a['idea_id'] ?? null,
            'design' => $design ? json_encode($design) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * The design object: only the keys we know, with touches limited to real flows. Anything else
     * a caller sends (the idea generator's free text, say) is dropped rather than stored.
     *
     * @return array<string, mixed>
     */
    public static function normaliseDesign(mixed $design, string $flow): array
    {
        if (is_string($design)) {
            $design = json_decode($design, true);
        }
        if (! is_array($design)) {
            return [];
        }
        $out = [];
        if (isset($design['touches'])) {
            if (! is_array($design['touches'])) {
                throw new \InvalidArgumentException('design.touches must be a list of flows');
            }
            foreach ($design['touches'] as $t) {
                if (! in_array($t, UxRollupService::FLOWS, true)) {
                    throw new \InvalidArgumentException('design.touches holds an unknown flow: '.json_encode($t));
                }
            }
            $out['touches'] = array_values(array_unique(array_map('strval', $design['touches'])));
        }
        if (isset($design['expected_points'])) {
            if (! is_numeric($design['expected_points']) || (float) $design['expected_points'] <= 0 || (float) $design['expected_points'] > 100) {
                throw new \InvalidArgumentException('design.expected_points must be a positive number of points');
            }
            $out['expected_points'] = (float) $design['expected_points'];
        }

        return $out;
    }

    /**
     * Arms as {name: [from, to]} over 0..99, control included and ranges not overlapping. A
     * missing value means a 50/50 split with one variant, b.
     */
    public static function normaliseArms(mixed $arms): array
    {
        if (is_string($arms)) {
            $arms = json_decode($arms, true);
        }
        if (! is_array($arms) || ! $arms) {
            return [self::CONTROL => [0, 49], 'b' => [50, 99]];
        }
        $out = [];
        $covered = [];
        foreach ($arms as $arm => $range) {
            if (! preg_match('/^[A-Za-z0-9_]{1,16}$/', (string) $arm) || ! is_array($range) || count($range) !== 2) {
                throw new \InvalidArgumentException("arm $arm must be a name and a [from, to] range");
            }
            [$from, $to] = [(int) $range[0], (int) $range[1]];
            if ($from < 0 || $to > 99 || $from > $to) {
                throw new \InvalidArgumentException("arm $arm range must lie within 0 to 99");
            }
            for ($i = $from; $i <= $to; $i++) {
                if (isset($covered[$i])) {
                    throw new \InvalidArgumentException("arm $arm overlaps another arm at $i");
                }
                $covered[$i] = true;
            }
            $out[$arm] = [$from, $to];
        }
        if (! isset($out[self::CONTROL])) {
            throw new \InvalidArgumentException('arms must include control');
        }
        if (count($out) < 2) {
            throw new \InvalidArgumentException('arms must include at least one variant');
        }

        return $out;
    }

    /**
     * Equal contiguous ranges over 0..99, control first then the variants by name; the remainder
     * goes to control, the safe side.
     *
     * @param string[] $names
     * @return array<string, array{0: int, 1: int}>
     */
    public static function evenArms(array $names): array
    {
        $names = array_values(array_unique(array_merge([self::CONTROL], $names)));
        usort($names, fn ($a, $b) => ($a === self::CONTROL ? 0 : 1) <=> ($b === self::CONTROL ? 0 : 1) ?: strcmp($a, $b));
        $each = intdiv(100, count($names));
        $out = [];
        $from = 0;
        foreach ($names as $i => $arm) {
            $n = $each + ($i === 0 ? 100 - $each * count($names) : 0);
            $out[$arm] = [$from, $from + $n - 1];
            $from += $n;
        }

        return $out;
    }

    /** One row, read from the write node: a lifecycle decision must never rest on a lagging replica. */
    public function find(int|string $idOrName): ?object
    {
        $q = DB::table('ux_experiments')->useWritePdo();
        $row = is_numeric($idOrName) ? $q->where('id', (int) $idOrName)->first() : $q->where('name', $idOrName)->first();

        return $row ?: null;
    }

    public function runningCount(): int
    {
        return DB::table('ux_experiments')->useWritePdo()->whereIn('status', self::ACTIVE)->count();
    }

    /**
     * Run $work holding the experiments lock, so two writers (the tick, a stop from the tab, the
     * command line) never interleave. GET_LOCK is held by the connection, is re-entrant for it,
     * and is taken on the write node, which is the only node the writes go to.
     */
    private function locked(callable $work): mixed
    {
        if ($this->lockDepth === 0) {
            // keep-raw: GET_LOCK and RELEASE_LOCK are MySQL functions, not queries on a table.
            $got = DB::selectOne('SELECT GET_LOCK(?, ?) AS got', [self::LOCK, (int) self::settings()['lock_wait_seconds']], false);
            if (! $got || (int) $got->got !== 1) {
                throw new \RuntimeException('Another change to the experiments is in progress; try again in a moment.');
            }
        }
        $this->lockDepth++;
        try {
            return $work();
        } finally {
            $this->lockDepth--;
            if ($this->lockDepth === 0) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK], false);
            }
        }
    }

    /**
     * Start a draft (or a finished) experiment: it runs from now for at most max_hours, within the
     * concurrency cap and with no other experiment running on the same flow (two changes to one
     * flow at once cannot be told apart), and its arms are published. A prompt for microvolunteers
     * is opened from its description and images.
     *
     * Before it starts, the power of a day of this flow at the expected effect is worked out under
     * the adopt rule and stored in design.power. A test that would almost never reach a decision
     * is refused, with advice, rather than run for a day to say "inconclusive". The result starts
     * with what a day of this flow can show, so a reader knows what could ever be seen.
     */
    public function start(int|string $idOrName, ?Carbon $now = null): object
    {
        $now = $now ?: Carbon::now('UTC');

        return $this->locked(function () use ($idOrName, $now) {
            $exp = $this->find($idOrName);
            if (! $exp) {
                throw new \RuntimeException("No experiment $idOrName");
            }
            if (! in_array($exp->status, self::STARTABLE, true)) {
                throw new \RuntimeException("Experiment {$exp->name} is {$exp->status}, not startable");
            }
            $s = self::settings();
            if ($this->runningCount() >= (int) $s['max_concurrent']) {
                throw new \RuntimeException('Already '.$s['max_concurrent'].' experiments running; that is the cap');
            }
            $sameFlow = DB::table('ux_experiments')->useWritePdo()->where('flow', $exp->flow)->whereIn('status', self::ACTIVE)->value('name');
            if ($sameFlow !== null) {
                throw new \RuntimeException("Experiment $sameFlow is already running on the {$exp->flow} flow; only one at a time per flow, so the results can be told apart");
            }

            $design = $this->designOf($exp);
            $design['touches'] = $this->touches($exp);
            $design['expected_points'] ??= $this->ideaExpectedPoints($exp) ?? (float) $s['default_expected_points'];
            $volume = $this->flowVolume($exp->flow, $now);
            $mde = $volume ? $this->mdeFromVolume($volume) : null;
            $power = null;
            if ($volume) {
                $power = round(BetaBinomial::adoptPower($volume['rate'], $volume['per_arm_per_day'], $design['expected_points'] / 100,
                    (float) $s['adopt_probability'], (float) $s['max_expected_loss_points'] / 100), 3);
                $design['power'] = $power;
                if ((float) $s['min_power'] > 0 && $power < (float) $s['min_power']) {
                    throw new \RuntimeException(sprintf(
                        'A day of the %s flow (about %s starts on each arm) would adopt a change of %s points only %d%% of the time, below the %d%% minimum. '
                        .'Merge this idea with another to make a bigger change, or make the change bigger.',
                        $exp->flow, number_format($volume['per_arm_per_day']), rtrim(rtrim(number_format($design['expected_points'], 1), '0'), '.'),
                        round($power * 100), round((float) $s['min_power'] * 100)));
                }
            }

            // An experiment that has run before may have been left by the bandit at 65/35 or worse,
            // with no data behind that now. A second attempt starts from an even split like the
            // first; a draft keeps the arms it was created with.
            $arms = json_decode($exp->arms, true) ?: [];
            if ($exp->status !== self::STATUS_DRAFT) {
                $arms = self::evenArms(array_keys($arms));
            }

            $n = DB::table('ux_experiments')->where('id', $exp->id)->whereIn('status', self::STARTABLE)->update([
                'arms' => json_encode($arms),
                'status' => self::STATUS_RUNNING,
                'started_at' => $now,
                'ends_at' => $now->copy()->addHours((int) $s['max_hours']),
                'decided_at' => null,
                'decision' => null,
                'result' => json_encode($mde === null ? [] : ['mde_points' => $mde]),
                'design' => json_encode($design),
                'updated_at' => $now,
            ]);
            if ($n !== 1) {
                throw new \RuntimeException("Experiment {$exp->name} changed while it was being started; nothing was done");
            }
            if ($exp->idea_id) {
                DB::table('ux_ideas')->where('id', $exp->idea_id)->update(['status' => 'running', 'experiment_id' => $exp->id, 'updated_at' => $now]);
            }
            $this->openPrompt($exp, $now);
            $this->rebuildConfig();

            return $this->find($exp->id);
        });
    }

    /** Stop a running experiment by hand: everyone goes back to control. */
    public function stop(int|string $idOrName, string $reason = 'Stopped by hand.', ?Carbon $now = null): object
    {
        $now = $now ?: Carbon::now('UTC');

        return $this->locked(function () use ($idOrName, $reason, $now) {
            $exp = $this->find($idOrName);
            if (! $exp) {
                throw new \RuntimeException("No experiment $idOrName");
            }
            if ($exp->status !== self::STATUS_RUNNING) {
                throw new \RuntimeException("Experiment {$exp->name} is {$exp->status}, not running");
            }
            if (! $this->finish($exp, self::STATUS_STOPPED, $reason, null, $now)) {
                throw new \RuntimeException("Experiment {$exp->name} is no longer running");
            }

            return $this->find($exp->id);
        });
    }

    /**
     * Mark an adopted experiment's code cleanup done: the switch is gone, so the row leaves the
     * config. Only an adopted experiment can be closed; closing a running one would drop it from
     * the config with no decision.
     */
    public function close(int|string $idOrName, ?string $cleanupPr = null, ?Carbon $now = null): object
    {
        $now = $now ?: Carbon::now('UTC');

        return $this->locked(function () use ($idOrName, $cleanupPr, $now) {
            $exp = $this->find($idOrName);
            if (! $exp) {
                throw new \RuntimeException("No experiment $idOrName");
            }
            if ($exp->status !== self::STATUS_ADOPTED) {
                throw new \RuntimeException("Experiment {$exp->name} is {$exp->status}; only an adopted experiment can be closed");
            }
            $n = DB::table('ux_experiments')->where('id', $exp->id)->where('status', self::STATUS_ADOPTED)->update([
                'status' => self::STATUS_CLOSED,
                'cleanup_pr_url' => $cleanupPr ?: $exp->cleanup_pr_url,
                'updated_at' => $now,
            ]);
            if ($n !== 1) {
                throw new \RuntimeException("Experiment {$exp->name} changed while it was being closed");
            }
            $this->rebuildConfig();

            return $this->find($exp->id);
        });
    }

    /**
     * The hourly tick: read every running experiment, record the read, move traffic, and decide
     * when the time is up or the answer is already clear; then, once a day, look at the holdout of
     * each recently adopted experiment. Returns one line per experiment.
     *
     * @return string[]
     */
    public function tick(?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now('UTC');
        $lines = [];
        foreach (DB::table('ux_experiments')->useWritePdo()->whereIn('status', self::ACTIVE)->orderBy('id')->get() as $exp) {
            try {
                $lines[] = $this->tickOne($exp, $now);
            } catch (\Throwable $e) {
                // One experiment's trouble must not stop the others being decided on time.
                Log::error("ux:experiments-tick: {$exp->name}: ".$e->getMessage());
                $lines[] = "{$exp->name}: error, left alone ({$e->getMessage()})";
            }
        }
        foreach ($this->checkAdopted($now) as $line) {
            $lines[] = $line;
        }
        $this->locked(fn () => $this->rebuildConfig());

        return $lines;
    }

    /**
     * One running experiment. The Loki read is slow and is done outside the lock; everything that
     * writes is done inside it, after the row has been read again: a stop that arrived while the
     * read was in flight wins, and nothing here can overwrite it.
     */
    private function tickOne(object $exp, Carbon $now): string
    {
        $s = self::settings();
        $stale = false;
        try {
            $read = $this->read($exp, $now);
        } catch (UxLokiUnavailable $e) {
            Log::warning("ux:experiments-tick: Loki unavailable for {$exp->name}: ".$e->getMessage());
            $read = $this->lastRead($exp, $now);
            if ($read === null) {
                return $this->locked(function () use ($exp, $now, $s) {
                    $fresh = $this->find($exp->id);
                    if (! $fresh || $fresh->status !== self::STATUS_RUNNING) {
                        return "{$exp->name}: no longer running, left alone";
                    }
                    $ends = Carbon::parse($fresh->ends_at);
                    if ($now->lt($ends)) {
                        return "{$exp->name}: Loki unavailable, no read this hour";
                    }
                    if ($now->lt($ends->copy()->addHours((int) $s['stale_wait_hours']))) {
                        return "{$exp->name}: time is up but Loki is unavailable, waiting for it";
                    }
                    $done = $this->finish($fresh, self::STATUS_INCONCLUSIVE, 'Time was up and the logs could not be read for hours, so the change was taken off and nothing can be said about it.', null, $now);

                    return $done ? "{$exp->name}: inconclusive (Loki unavailable past the end)" : "{$exp->name}: no longer running, left alone";
                });
            }
            $read['stale'] = true;
            $stale = true;
        }

        return $this->locked(function () use ($exp, $read, $now, $stale) {
            $fresh = $this->find($exp->id);
            if (! $fresh || $fresh->status !== self::STATUS_RUNNING) {
                return "{$exp->name}: no longer running, left alone";
            }
            $hours = $this->hours($fresh, $now);
            $summary = $this->summary($fresh, $read, $hours);
            if (! $stale) {
                DB::table('ux_experiment_reads')->insert([
                    'experiment_id' => $fresh->id,
                    'read_at' => $now,
                    'hours' => $hours,
                    'metrics' => json_encode($read),
                    'summary' => $summary,
                ]);
            }
            DB::table('ux_experiments')->where('id', $fresh->id)->where('status', self::STATUS_RUNNING)
                ->update(['result' => json_encode($this->keepStartFacts($fresh, $read)), 'updated_at' => $now]);

            $decision = $this->decide($fresh, $read, $now, $stale);
            if ($decision) {
                if (! $this->finish($fresh, $decision['status'], $decision['text'], $read, $now)) {
                    return "{$exp->name}: no longer running, left alone";
                }

                return "{$exp->name}: {$decision['status']}";
            }

            if (self::settings()['bandit'] && ! $stale) {
                $arms = $this->allocate($read, json_decode($fresh->arms, true));
                // MySQL stores JSON object keys in its own order, so compare by value, not order.
                if ($arms != json_decode($fresh->arms, true)) {
                    DB::table('ux_experiments')->where('id', $fresh->id)->where('status', self::STATUS_RUNNING)
                        ->update(['arms' => json_encode($arms), 'updated_at' => $now]);
                }
            }

            return "{$fresh->name}: ".round($hours, 1).'h, '.$this->oneLine($read);
        });
    }

    /**
     * The holdout check. An adopted experiment keeps serving a tenth of members the old version.
     * Once a day for holdout_days after adoption, the people on the adopted arm are compared with
     * that holdout over the whole time since adoption. The adoption is reversed only when the
     * holdout is doing better with holdout_revert_probability on holdout_revert_looks looks in a
     * row, and both sides have early_min_starts_per_arm starts. Thirteen daily looks at a loose
     * bar would reverse a good change by chance, and the holdout is the small side, so its noise
     * is the biggest; a high bar and a repeat are what keep this for a real reversal.
     *
     * One experiment's trouble (a lock timeout, bad JSON in its row) is logged and left; it must
     * not stop the others being checked or the config being rebuilt.
     *
     * @return string[]
     */
    public function checkAdopted(Carbon $now): array
    {
        $s = self::settings();
        if ((int) $s['holdout_share'] <= 0) {
            return [];
        }
        $lines = [];
        $adopted = DB::table('ux_experiments')->useWritePdo()->where('status', self::STATUS_ADOPTED)
            ->where('decided_at', '>=', $now->copy()->subDays((int) $s['holdout_days']))->orderBy('id')->get();
        foreach ($adopted as $exp) {
            try {
                $line = $this->checkOneAdopted($exp, $now, $s);
            } catch (\Throwable $e) {
                Log::error("ux:experiments-tick: holdout of {$exp->name}: ".$e->getMessage());
                $line = "{$exp->name}: holdout check failed, left alone ({$e->getMessage()})";
            }
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function checkOneAdopted(object $exp, Carbon $now, array $s): ?string
    {
        // The first look is a day after the decision, then once a day: an hour of data after
        // adoption says nothing, and the daily rhythm is the same one the novelty effect has.
        if (Carbon::parse($exp->decided_at)->gt($now->copy()->subHours(23))) {
            return null;
        }
        $result = json_decode($exp->result ?? '', true) ?: [];
        $last = $result['holdout_check']['at'] ?? null;
        if ($last && Carbon::parse($last)->gt($now->copy()->subHours(23))) {
            return null;
        }
        $winner = $result['winner'] ?? $this->firstVariant(json_decode($exp->arms, true) ?: []);
        try {
            $read = $this->read($exp, $now, Carbon::parse($exp->decided_at));
        } catch (UxLokiUnavailable $e) {
            Log::warning("ux:experiments-tick: Loki unavailable for the holdout of {$exp->name}: ".$e->getMessage());

            return null;
        } catch (\InvalidArgumentException $e) {
            Log::error("ux:experiments-tick: {$exp->name}: ".$e->getMessage());

            return null;
        }
        $p = $read['primary'][$winner] ?? null;

        return $this->locked(function () use ($exp, $read, $p, $winner, $now, $s) {
            $fresh = $this->find($exp->id);
            if (! $fresh || $fresh->status !== self::STATUS_ADOPTED) {
                return "{$exp->name}: no longer adopted, holdout left alone";
            }
            $days = round(Carbon::parse($fresh->decided_at)->diffInSeconds($now, false) / 86400, 1);
            $min = (int) $s['early_min_starts_per_arm'];
            $old = json_decode($fresh->result ?? '', true) ?: [];
            $adoptedStarts = $read['arms'][$winner]['starts'] ?? 0;
            $holdoutStarts = $read['arms'][self::CONTROL]['starts'] ?? 0;
            // A look counts against the adoption only when it is clear and has enough people; a
            // look that is not clear starts the count again.
            $against = $p && $adoptedStarts >= $min && $holdoutStarts >= $min
                && $p['p_better'] <= 1 - (float) $s['holdout_revert_probability'];
            $strikes = $against ? ((int) ($old['holdout_check']['strikes'] ?? 0)) + 1 : 0;
            $old['holdout_check'] = ['at' => $now->toIso8601String(), 'days' => $days, 'p_better' => $p['p_better'] ?? null,
                'adopted_starts' => $adoptedStarts, 'holdout_starts' => $holdoutStarts, 'strikes' => $strikes];
            DB::table('ux_experiments')->where('id', $fresh->id)->where('status', self::STATUS_ADOPTED)
                ->update(['result' => json_encode($old), 'updated_at' => $now]);

            if ($against && $strikes >= (int) $s['holdout_revert_looks']) {
                $text = sprintf('Reverted after adoption: the people who kept the old version did better over %s, on %d looks in a row (%s finished with the old version against %s with the change; probability the old version is better %s). The gain seen in the test did not show up afterwards.',
                    $this->dayWord($days), $strikes, $this->pct($p['control_rate']), $this->pct($p['variant_rate']), $this->pct(1 - $p['p_better']));
                $read['decayed'] = true;
                if ($this->finish($fresh, self::STATUS_REVERTED, $text, $read, $now, self::STATUS_ADOPTED)) {
                    return "{$exp->name}: reverted after adoption (the holdout did better)";
                }
            }

            return "{$exp->name}: holdout checked after ".$this->dayWord($days).', still adopted';
        });
    }

    // ------------------------------------------------------------------ reading

    /**
     * Counts by arm since the experiment started (or since $since, for an adopted experiment's
     * holdout), up to the settling time before now (or before the end, once the time is up), and
     * the statistics over them.
     *
     * The counts come hour by hour, ending exactly at the end of the window so the newest data is
     * never dropped, and only whole hours inside the window are kept: an hour that began before
     * the experiment did could hold a previous run of the same name.
     *
     * @throws UxLokiUnavailable
     * @throws \InvalidArgumentException when the name or flow could not be put safely into LogQL
     */
    public function read(object $exp, Carbon $now, ?Carbon $since = null): array
    {
        // These reach a LogQL string, so they are checked here as well as when the row is made:
        // the row could have been written by anything.
        $name = (string) $exp->name;
        if (! preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException('experiment name is not safe to read: '.substr($name, 0, 80));
        }
        if (! in_array($exp->flow, UxRollupService::FLOWS, true)) {
            throw new \InvalidArgumentException('experiment flow is not a known flow: '.substr((string) $exp->flow, 0, 80));
        }
        $touches = $this->touches($exp);

        $s = self::settings();
        $start = $since ?: Carbon::parse($exp->started_at);
        $limit = $since ? $now->copy() : $now->copy()->min(Carbon::parse($exp->ends_at));
        // Flows that began after this have had no time to finish and are left out, completes and
        // all; flows that began before it are counted up to the limit.
        $cut = $limit->copy()->subMinutes((int) $s['settle_minutes']);
        if ($cut->lte($start)) {
            $cut = $start->copy()->addMinute();
        }
        $end = $limit;
        $minutes = max(1, (int) ceil($start->diffInSeconds($end, false) / 60));
        $selector = '{app="freegle", source="client", event_type="flow"}';
        // The arm is the token after "<name>:" inside the variant field, which holds every
        // experiment the member is in as name:arm pairs, comma separated. The name must start the
        // field or follow a comma, or "cta" would also match "login_cta:b". Loki label filters
        // match the whole value; regexp runs over the raw line. Names are letters, digits, _ and
        // -, none of which RE2 treats specially outside a class.
        $armStage = sprintf('| variant=~"(.*,)?%s:.*" | regexp "\\"variant\\":\\"(?:[^\\"]*,)?%s:(?P<arm>[A-Za-z0-9_]+)"', $name, $name);
        $flows = implode('|', array_unique(array_merge([$exp->flow], $touches)));

        // Hour by hour, with logged in at the start of the flow, so the primary comparison can be
        // made within strata. The line filter drops events from a browser that does not stamp
        // started_ms (an old cached app), from both arms alike: without it the numeric filter
        // would raise an error on those lines rather than skip them. logged_in is read too, as the
        // fallback stratum for a line that has no start_logged_in.
        $hourly = $this->hourly(sprintf(
            'sum by (arm, flow, action_name, logged_in, start_logged_in, ok) (count_over_time(%s |= "started_ms" | json flow, action_name, variant, logged_in, start_logged_in, started_ms, ok | __error__="" | started_ms <= %d | flow=~"%s" %s | action_name=~"%s" [1h]))',
            $selector, $cut->getTimestamp() * 1000, $flows, $armStage, implode('|', self::EVENTS)
        ), $start, $end);

        $arms = [];
        foreach (array_keys(json_decode($exp->arms, true)) as $arm) {
            $arms[$arm] = ['starts' => 0, 'completes' => 0, 'submitted' => 0, 'submit_ok' => 0, 'submit_failed' => 0,
                'validation_failed' => 0, 'help_sought' => 0, 'other_flows' => [], 'strata' => []];
        }
        $field = ['flow_start' => 'starts', 'flow_complete' => 'completes', 'flow_submit_result' => 'submitted',
            'validation_failed' => 'validation_failed', 'help_sought' => 'help_sought'];

        foreach ($hourly as [$labels, $points]) {
            $arm = $labels['arm'] ?? '';
            if (! isset($arms[$arm])) {
                continue;
            }
            $flow = $labels['flow'] ?? '';
            $event = $labels['action_name'] ?? '';
            // Signed in when the flow started, not when this event was sent: logging in is often
            // part of the flow, and by the end the member is a different stratum from the start.
            $loggedIn = (($labels['start_logged_in'] ?? '') !== '' ? $labels['start_logged_in'] : ($labels['logged_in'] ?? '')) === 'true' ? 'in' : 'out';
            foreach ($points as $ts => $value) {
                $n = (int) round($value);
                if ($n === 0) {
                    continue;
                }
                if ($flow === $exp->flow) {
                    if (isset($field[$event])) {
                        $arms[$arm][$field[$event]] += $n;
                    }
                    if ($event === 'flow_submit_result' && ($labels['ok'] ?? '') === 'true') {
                        $arms[$arm]['submit_ok'] += $n;
                    }
                    if ($event === 'flow_start' || $event === 'flow_complete') {
                        // The point is the end of the hour it counts; use the middle of that hour.
                        $stratum = $loggedIn.'|'.intdiv((int) gmdate('G', (int) $ts - 1800), self::BLOCK_HOURS);
                        $arms[$arm]['strata'][$stratum] ??= ['starts' => 0, 'completes' => 0];
                        $arms[$arm]['strata'][$stratum][$event === 'flow_start' ? 'starts' : 'completes'] += $n;
                    }
                } elseif (in_array($flow, $touches, true) && ($event === 'flow_start' || $event === 'flow_complete')) {
                    $arms[$arm]['other_flows'][$flow] ??= ['starts' => 0, 'completes' => 0];
                    $arms[$arm]['other_flows'][$flow][$event === 'flow_start' ? 'starts' : 'completes'] += $n;
                }
            }
        }
        foreach ($arms as &$a) {
            $a['submit_failed'] = max(0, $a['submitted'] - $a['submit_ok']);
        }
        unset($a);

        return $this->statistics($exp, $arms, $minutes, $now);
    }

    /**
     * The statistics over per-arm counts: the primary comparison (within strata, weighted back
     * together) and the guardrails.
     *
     * A guardrail is the chance the arm is worse than control by MORE than a margin, so a null
     * change read every hour does not trip one by chance. With k guardrails the bars in decide()
     * are split k ways, and each guardrail carries its k. Only the flows the experiment declares
     * it touches are guardrails.
     */
    public function statistics(object $exp, array $arms, int $minutes, Carbon $now): array
    {
        $s = self::settings();
        $margin = (float) $s['guardrail_margin_points'] / 100;
        $control = $arms[self::CONTROL] ?? ['starts' => 0, 'completes' => 0, 'strata' => [], 'other_flows' => []];
        $primary = [];
        $guardrails = [];
        $totalStarts = array_sum(array_map(fn ($a) => $a['starts'], $arms));
        $others = array_values(array_diff($this->touches($exp), [$exp->flow]));
        $k = 3 + count($others);

        foreach ($arms as $arm => $a) {
            if ($arm === self::CONTROL) {
                continue;
            }
            $strata = [];
            foreach (array_unique(array_merge(array_keys($control['strata'] ?? []), array_keys($a['strata'] ?? []))) as $key) {
                $c = $control['strata'][$key] ?? ['starts' => 0, 'completes' => 0];
                $v = $a['strata'][$key] ?? ['starts' => 0, 'completes' => 0];
                $strata[] = ['sa' => $c['completes'], 'fa' => max(0, $c['starts'] - $c['completes']),
                    'sb' => $v['completes'], 'fb' => max(0, $v['starts'] - $v['completes'])];
            }
            if (! $strata) {
                $strata[] = ['sa' => $control['completes'], 'fa' => max(0, $control['starts'] - $control['completes']),
                    'sb' => $a['completes'], 'fb' => max(0, $a['starts'] - $a['completes'])];
            }
            $st = BetaBinomial::stratifiedDiff($strata);
            $primary[$arm] = [
                'control_rate' => $control['starts'] ? $control['completes'] / $control['starts'] : null,
                'variant_rate' => $a['starts'] ? $a['completes'] / $a['starts'] : null,
                'diff_points' => ($control['starts'] && $a['starts'])
                    ? round(($a['completes'] / $a['starts'] - $control['completes'] / $control['starts']) * 100, 1) : null,
                'p_better' => round($st['p_better'], 4),
                'ci90_points' => [round($st['lo'] * 100, 1), round($st['hi'] * 100, 1)],
                'expected_loss_points' => round($st['expected_loss'] * 100, 2),
                'strata' => count($strata),
                'enough' => $control['starts'] >= $s['min_starts_per_arm'] && $a['starts'] >= $s['min_starts_per_arm'],
                'observed_share' => $totalStarts ? round($a['starts'] / $totalStarts * 100) : null,
            ];

            // Guardrails: a higher rate is worse for the first three. Each is the chance the arm
            // is worse by more than the margin, not merely worse. validation_failed and
            // help_sought can repeat within one start, so a per-start rate is clamped at 1 and the
            // comparison is only read while the rates are well below that.
            $worseIfHigher = [
                'submit_failed' => ['bad' => 'submit_failed', 'base' => 'submitted', 'label' => 'submissions that failed'],
                'validation_failed' => ['bad' => 'validation_failed', 'base' => 'starts', 'label' => 'validation failures per start'],
                'help_sought' => ['bad' => 'help_sought', 'base' => 'starts', 'label' => 'people who went looking for help'],
            ];
            foreach ($worseIfHigher as $key => $g) {
                $cb = $control[$g['base']] ?? 0;
                $vb = $a[$g['base']] ?? 0;
                if ($cb + $vb < $s['guardrail_min_events']) {
                    continue;
                }
                $cBad = min($control[$g['bad']] ?? 0, $cb);
                $vBad = min($a[$g['bad']] ?? 0, $vb);
                $pWorse = BetaBinomial::probabilityDiffExceeds($cBad, $cb - $cBad, $vBad, $vb - $vBad, $margin);
                $guardrails[] = ['arm' => $arm, 'name' => $key, 'label' => $g['label'],
                    'control_rate' => $cb ? $cBad / $cb : null, 'variant_rate' => $vb ? $vBad / $vb : null,
                    'p_worse' => round($pWorse, 4), 'k' => $k];
            }
            foreach ($others as $flow) {
                $v = $a['other_flows'][$flow] ?? ['starts' => 0, 'completes' => 0];
                $c = $control['other_flows'][$flow] ?? ['starts' => 0, 'completes' => 0];
                if ($c['starts'] + $v['starts'] < $s['guardrail_min_events']) {
                    continue;
                }
                // Worse means completing less often, by more than the margin: P(control - variant > margin).
                $pWorse = BetaBinomial::probabilityDiffExceeds(
                    $v['completes'], max(0, $v['starts'] - $v['completes']),
                    $c['completes'], max(0, $c['starts'] - $c['completes']), $margin);
                $guardrails[] = ['arm' => $arm, 'name' => "flow:$flow", 'label' => "$flow completion",
                    'control_rate' => $c['starts'] ? $c['completes'] / $c['starts'] : null,
                    'variant_rate' => $v['starts'] ? $v['completes'] / $v['starts'] : null,
                    'p_worse' => round($pWorse, 4), 'k' => $k];
            }
        }

        return [
            'read_at' => $now->toIso8601String(),
            'window_minutes' => $minutes,
            'arms' => $arms,
            'primary' => $primary,
            'guardrails' => $guardrails,
            'allocation' => self::sharesFromArms(json_decode($exp->arms, true)),
        ];
    }

    // ------------------------------------------------------------------ deciding

    /**
     * Null while the experiment should carry on; otherwise the status and the plain-English
     * decision. Reads the best variant against control. Adoption needs both a high chance of
     * being better AND a small expected loss if it is not; an early stop needs many more people
     * through than a decision at the end, because every hourly look is another chance to be
     * fooled by noise, and none of the early paths is open to a stale read.
     *
     * Guardrails: the bars are split between the k guardrails (block at the end at 1 - 0.10/k,
     * revert early at 1 - 0.03/k), and an early revert also waits for early_min_starts_per_arm.
     */
    public function decide(object $exp, array $read, Carbon $now, bool $stale = false): ?array
    {
        $s = self::settings();
        $timeUp = $now->gte(Carbon::parse($exp->ends_at));
        $best = $this->bestArm($read);
        $harm = $this->worstGuardrail($read, $best);
        $figures = $best ? $this->figures($read, $best) : 'no reads yet';
        $p = $best ? $read['primary'][$best]['p_better'] : null;
        $loss = $best ? ($read['primary'][$best]['expected_loss_points'] ?? 0) : null;
        $lossOk = $loss !== null && $loss <= (float) $s['max_expected_loss_points'];
        $k = max(1, (int) ($harm['k'] ?? 1));
        $harmBlocks = $harm && $harm['p_worse'] >= 1 - (float) $s['guardrail_error'] / $k;
        $harmReverts = $harm && $harm['p_worse'] >= 1 - (float) $s['early_guardrail_error'] / $k;
        $early = $best && $this->bothArmsAtLeast($read, $best, (int) $s['early_min_starts_per_arm']);

        if ($harmReverts && $early) {
            return ['status' => self::STATUS_REVERTED, 'text' => "Reverted early: {$harm['label']} got worse with the change "
                .'('.$this->rate($harm['variant_rate']).' against '.$this->rate($harm['control_rate'])." without it; probability it is worse by more than "
                .$s['guardrail_margin_points'].' points '.$this->pct($harm['p_worse']).').'];
        }

        if ($early) {
            if (! $stale && $p >= $s['early_adopt_probability'] && $lossOk && ! $harmBlocks) {
                return ['status' => self::STATUS_ADOPTED, 'text' => "Adopted early: the change was clearly better. $figures"];
            }
            if ($p <= $s['early_revert_probability']) {
                return ['status' => self::STATUS_REVERTED, 'text' => "Reverted early: the change was clearly worse. $figures"];
            }
        }

        if (! $timeUp) {
            return null;
        }

        if (! $best || ! $read['primary'][$best]['enough']) {
            return ['status' => self::STATUS_INCONCLUSIVE, 'text' => 'Time was up before enough people had been through each arm to tell, so the change was taken off. '.$figures];
        }
        if ($harmBlocks) {
            return ['status' => self::STATUS_REVERTED, 'text' => "Reverted at the end: {$harm['label']} was probably worse with the change (".$this->pct($harm['p_worse'])."), whatever the main result. $figures"];
        }
        if ($p >= $s['adopt_probability'] && $lossOk) {
            return ['status' => self::STATUS_ADOPTED, 'text' => "Adopted at the end: the change was probably better. $figures"];
        }
        if ($p >= $s['adopt_probability'] && ! $lossOk) {
            return ['status' => self::STATUS_INCONCLUSIVE, 'text' => 'Inconclusive at the end, so the change was taken off: it was probably better, but if it were not we would lose more than '
                .$s['max_expected_loss_points'].' points, and a day was not enough to rule that out. '.$figures];
        }
        if ($p <= 1 - $s['adopt_probability']) {
            return ['status' => self::STATUS_REVERTED, 'text' => "Reverted at the end: the change was probably worse. $figures"];
        }

        return ['status' => self::STATUS_INCONCLUSIVE, 'text' => "Inconclusive at the end, so the change was taken off: the result could have gone either way. $figures"];
    }

    /**
     * The split for the next hour: each variant's share is the chance it is better than control
     * (probability matching, which is what Thompson sampling gives for two arms), taken from the
     * same stratified comparison decide() uses, so the split and the decision cannot disagree
     * about who is ahead. Every arm, control and variants alike, is held inside a band: for two
     * arms 35 to 65 (min_control_share to max_variant_share), for k arms a floor of 2/k of
     * that. So no arm is ever starved or given an empty range, and the comparison keeps enough
     * people on both sides to be read. A variant that has an unlucky first hours can recover.
     * Ranges are rewritten as contiguous blocks over 0..99, control first, so moving share only
     * ever moves the buckets at the shared edge.
     *
     * Nothing moves until every arm has early_min_starts_per_arm starts: before that the
     * posteriors are too wide, and a split that chases noise would also move members between arms
     * on no evidence.
     */
    public function allocate(array $read, array $arms): array
    {
        $s = self::settings();
        // Control first, then the variants by name: the ranges are laid out in this order, and
        // MySQL returns JSON keys in its own order, so fix it here.
        $names = array_keys($arms);
        usort($names, fn ($a, $b) => ($a === self::CONTROL ? 0 : 1) <=> ($b === self::CONTROL ? 0 : 1) ?: strcmp($a, $b));
        $counts = $read['arms'] ?? [];
        foreach ($names as $arm) {
            if (($counts[$arm]['starts'] ?? 0) < $s['early_min_starts_per_arm']) {
                return $arms; // Not enough on every arm yet to move anything.
            }
            if ($arm !== self::CONTROL && ! isset($read['primary'][$arm]['p_better'])) {
                return $arms; // No comparison to go on.
            }
        }

        $k = count($names);
        $floor = max(1, (int) floor(min((int) $s['min_control_share'], 2 * (int) $s['min_control_share'] / $k)));
        $cap = max($floor, min((int) $s['max_variant_share'], 100 - ($k - 1) * $floor));
        $pool = 100 - $k * $floor;

        $weights = [];
        foreach ($names as $arm) {
            $weights[$arm] = $arm === self::CONTROL ? 0.0 : max(0.0, min(1.0, (float) $read['primary'][$arm]['p_better']));
        }
        // Control's weight is the chance that no variant is better than it.
        $weights[self::CONTROL] = 1 - max(array_diff_key($weights, [self::CONTROL => 1]) ?: [0.0]);
        $total = array_sum($weights) ?: 1.0;

        $shares = [];
        $remainders = [];
        foreach ($names as $arm) {
            $exact = $pool * $weights[$arm] / $total;
            $shares[$arm] = $floor + (int) floor($exact);
            $remainders[$arm] = $exact - floor($exact);
        }
        // Hand out what rounding left, to the arms with the biggest remainders.
        arsort($remainders);
        $left = 100 - array_sum($shares);
        foreach (array_keys($remainders) as $arm) {
            if ($left <= 0) {
                break;
            }
            $shares[$arm]++;
            $left--;
        }
        // The cap only bites when max_variant_share is set below the band's own top.
        $excess = 0;
        foreach ($names as $arm) {
            if ($shares[$arm] > $cap) {
                $excess += $shares[$arm] - $cap;
                $shares[$arm] = $cap;
            }
        }
        foreach ($names as $arm) {
            $room = min($excess, $cap - $shares[$arm]);
            $shares[$arm] += $room;
            $excess -= $room;
        }

        $out = [];
        $from = 0;
        foreach ($names as $arm) {
            $out[$arm] = [$from, $from + $shares[$arm] - 1];
            $from += $shares[$arm];
        }

        return $out;
    }

    // ------------------------------------------------------------------ wording

    /** The paragraph stored with every read and shown on the tab. */
    public function summary(object $exp, array $read, float $hours): string
    {
        $best = $this->bestArm($read);
        $h = round($hours, 1);
        $after = 'After '.$h.($h == 1.0 ? ' hour' : ' hours');
        if (! $best) {
            return "$after: nothing recorded yet.";
        }
        $p = $read['primary'][$best];
        $c = $read['arms'][self::CONTROL];
        $v = $read['arms'][$best];
        $text = "$after: ".number_format($v['starts']).' people started '.$this->flowName($exp->flow).' with the change and '
            .number_format($c['starts']).' without. ';
        if ($p['variant_rate'] !== null && $p['control_rate'] !== null) {
            $text .= $this->pct($p['variant_rate']).' finished with the change, '.$this->pct($p['control_rate']).' without ('
                .$this->points($p['diff_points']).'). Probability the change is better: '.$this->pct($p['p_better']).'. ';
            if (isset($p['expected_loss_points'])) {
                $text .= 'If we kept it and it were really no better, we would expect to lose about '.$p['expected_loss_points'].' points. ';
            }
        }
        $harm = $this->worstGuardrail($read, $best);
        if ($harm && $harm['p_worse'] >= 0.5) {
            $text .= 'Watch: '.$harm['label'].' looks worse with the change ('.$this->rate($harm['variant_rate']).' against '
                .$this->rate($harm['control_rate']).', probability of being worse by more than '.self::settings()['guardrail_margin_points'].' points '.$this->pct($harm['p_worse']).'). ';
        } elseif ($read['guardrails']) {
            $text .= 'Guardrails: nothing worse. ';
        }
        $served = $read['allocation'][$best] ?? null;
        if ($served !== null) {
            $text .= "Traffic on the change: {$served}% served";
            if (isset($p['observed_share'])) {
                $text .= ", {$p['observed_share']}% of starts seen";
            }
            $text .= '. ';
        }
        $mde = json_decode($exp->result ?? '', true)['mde_points'] ?? null;
        if ($mde !== null) {
            $text .= "A day of this flow can show a change of about {$mde} points. ";
        }
        $text .= $p['enough'] ? 'Enough people have been through to read it.' : 'Too few people so far to read it.';

        return $text;
    }

    private function oneLine(array $read): string
    {
        $best = $this->bestArm($read);
        if (! $best) {
            return 'no data';
        }
        $p = $read['primary'][$best];

        return sprintf('%s vs %s starts, %s vs %s done, p_better %s, expected loss %s pts',
            $read['arms'][$best]['starts'], $read['arms'][self::CONTROL]['starts'],
            $read['arms'][$best]['completes'], $read['arms'][self::CONTROL]['completes'], $p['p_better'], $p['expected_loss_points'] ?? '?');
    }

    private function figures(array $read, string $arm): string
    {
        $p = $read['primary'][$arm];
        $c = $read['arms'][self::CONTROL];
        $v = $read['arms'][$arm];
        $out = number_format($v['starts']).' started with the change and '.number_format($c['starts']).' without; ';
        if ($p['variant_rate'] !== null && $p['control_rate'] !== null) {
            $out .= $this->pct($p['variant_rate']).' finished against '.$this->pct($p['control_rate']).' ('.$this->points($p['diff_points']).'); ';
        }

        return $out.'probability the change is better '.$this->pct($p['p_better']).'.';
    }

    private function flowName(string $flow): string
    {
        return match ($flow) {
            'give' => 'an offer', 'ask' => 'a request', 'reply' => 'a reply', 'login' => 'signing in or up',
            'chat_promise' => 'a promise', 'chat_outcome' => 'marking an outcome', 'settings' => 'settings',
            default => $flow,
        };
    }

    private function dayWord(float $days): string
    {
        return $days == 1.0 ? '1 day' : $days.' days';
    }

    private function pct(?float $v): string
    {
        return $v === null ? 'unknown' : round($v * 100).'%';
    }

    private function rate(?float $v): string
    {
        return $v === null ? 'unknown' : round($v * 100, 1).'%';
    }

    private function points(?float $v): string
    {
        return $v === null ? 'no difference measurable' : ($v > 0 ? '+' : '').$v.' points';
    }

    // ------------------------------------------------------------------ the config key

    /**
     * The JSON the client reads, one entry per experiment. A running entry is
     * {"arms": {...}, "until": <unix seconds>}: the client treats it as control once `until`
     * (the end plus an hour) has passed, so a batch that stops running cannot leave a half-served
     * experiment on for ever. An adopted entry serves the winner to most members and keeps the
     * holdout on control: {"arms": {"b": [0,89], "control": [90,99]}, "adopted": true, "until": ...}.
     * Its `until` is a fortnight past the decision, past the holdout check and the cleanup window.
     */
    public function rebuildConfig(): string
    {
        $s = self::settings();
        $out = [];
        foreach (DB::table('ux_experiments')->useWritePdo()->whereIn('status', [self::STATUS_RUNNING, self::STATUS_ADOPTED])->orderBy('id')->get() as $exp) {
            $arms = json_decode($exp->arms, true) ?: [];
            if ($exp->status === self::STATUS_ADOPTED) {
                $winner = json_decode($exp->result ?? '', true)['winner'] ?? $this->firstVariant($arms);
                $holdout = max(0, min(50, (int) $s['holdout_share']));
                $arms = $holdout > 0
                    ? [$winner => [0, 99 - $holdout], self::CONTROL => [100 - $holdout, 99]]
                    : [$winner => [0, 99]];
                $out[$exp->name] = ['arms' => $arms, 'adopted' => true,
                    'until' => Carbon::parse($exp->decided_at ?? $exp->updated_at, 'UTC')->addDays(15)->getTimestamp()];

                continue;
            }
            // Never publish an empty range: the client would have to drop the entry for it.
            $arms = array_filter($arms, fn ($r) => is_array($r) && count($r) === 2 && $r[0] <= $r[1]);
            $out[$exp->name] = ['arms' => $arms, 'until' => Carbon::parse($exp->ends_at, 'UTC')->getTimestamp() + 3600];
        }
        $json = json_encode($out ?: new \stdClass());
        DB::table('config')->upsert([['key' => self::CONFIG_KEY, 'value' => $json]], ['key'], ['value']);

        return $json;
    }

    // ------------------------------------------------------------------ prompts

    /** Open the microvolunteer prompt for an experiment, if it has nothing open yet. */
    public function openPrompt(object $exp, Carbon $now): ?int
    {
        if (DB::table('ux_prompts')->where('experiment_id', $exp->id)->where('status', 'open')->exists()) {
            return null;
        }
        $images = [];
        if ($exp->image_control) {
            $images[] = ['url' => $exp->image_control, 'caption' => 'How it is now'];
        }
        if ($exp->image_variant) {
            $images[] = ['url' => $exp->image_variant, 'caption' => 'The change'];
        }

        return DB::table('ux_prompts')->insertGetId([
            'kind' => 'experiment',
            'flow' => $exp->flow,
            'experiment_id' => $exp->id,
            'question' => 'For what you were doing, is it better or worse?',
            // The change only: the effect we hope for would lead members towards "Better".
            'body' => trim('Here is a change we are trying: '.$exp->change_description),
            'options' => json_encode(self::EXPERIMENT_OPTIONS),
            'images' => $images ? json_encode($images) : null,
            'status' => 'open',
            'max_responses' => 200,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * What microvolunteers said about an experiment, as counts per option and the comments. A
     * skipped prompt carries no choice and so is in no count.
     */
    public function reaction(int $experimentId): array
    {
        $promptIds = DB::table('ux_prompts')->where('experiment_id', $experimentId)->pluck('id')->all();
        $counts = ['better' => 0, 'worse' => 0, 'dont_care' => 0, 'dont_understand' => 0];
        $comments = [];
        if ($promptIds) {
            foreach (DB::table('ux_prompt_responses')->whereIn('prompt_id', $promptIds)->orderByDesc('timestamp')->get() as $r) {
                $answer = json_decode($r->answer, true) ?: [];
                if (! empty($answer['skipped'])) {
                    continue;
                }
                $choice = $answer['choice'] ?? null;
                if (isset($counts[$choice])) {
                    $counts[$choice]++;
                }
                if ($r->comment && count($comments) < 20) {
                    $comments[] = $r->comment;
                }
            }
        }

        return ['counts' => $counts, 'comments' => $comments, 'total' => array_sum($counts)];
    }

    // ------------------------------------------------------------------ helpers

    /** The design object as stored (touches, expected_points, power), or []. */
    private function designOf(object $exp): array
    {
        return json_decode($exp->design ?? '', true) ?: [];
    }

    /**
     * The flows this experiment declares it can affect, validated. Absent, it is its own flow and
     * login (a change to a form most often shows up in whether people can still sign in); a
     * declared list, even an empty one, is believed.
     *
     * @return string[]
     */
    private function touches(object $exp): array
    {
        $design = $this->designOf($exp);
        $declared = $design['touches'] ?? null;
        $touches = is_array($declared) ? $declared : [$exp->flow, 'login'];

        return array_values(array_unique(array_filter($touches, fn ($f) => in_array($f, UxRollupService::FLOWS, true))));
    }

    /** The effect the idea behind this experiment expected, in points, if it recorded one. */
    private function ideaExpectedPoints(object $exp): ?float
    {
        if (! $exp->idea_id) {
            return null;
        }
        $evidence = json_decode((string) DB::table('ux_ideas')->where('id', $exp->idea_id)->value('evidence'), true);
        $points = $evidence['expected_points'] ?? null;

        return is_numeric($points) && (float) $points > 0 ? (float) $points : null;
    }

    /**
     * What a day of this flow looks like from the last week of the rollup: starts on each arm per
     * day (half the flow's starts) and the completion rate. Null when the rollup has nothing for
     * the flow.
     *
     * @return array{per_arm_per_day: int, rate: float}|null
     */
    private function flowVolume(string $flow, Carbon $now): ?array
    {
        // A week of one flow is a handful of rows, so three small aggregates cost nothing.
        $week = DB::table('ux_flow_outcome_daily')->where('flow', $flow)
            ->where('date', '>=', $now->copy()->subDays(7)->toDateString());
        $started = (int) (clone $week)->sum('started');
        $days = (clone $week)->distinct()->count('date');
        if (! $started || ! $days) {
            return null;
        }
        $completed = (int) (clone $week)->sum('completed');

        return ['per_arm_per_day' => (int) floor($started / $days / 2), 'rate' => $completed / $started];
    }

    private function mdeFromVolume(array $volume): ?float
    {
        $diff = BetaBinomial::minimumDetectableDiff($volume['rate'], $volume['per_arm_per_day']);

        return $diff === null ? null : round($diff * 100, 1);
    }

    /**
     * What a day of this flow could show, in points of completion, from the last week of the
     * rollup: half the daily starts on each arm. Null when the rollup has nothing for the flow.
     */
    public function minimumDetectablePoints(string $flow, Carbon $now): ?float
    {
        $volume = $this->flowVolume($flow, $now);

        return $volume ? $this->mdeFromVolume($volume) : null;
    }

    private function keepStartFacts(object $exp, array $read): array
    {
        $old = json_decode($exp->result ?? '', true) ?: [];
        foreach (['mde_points', 'winner', 'holdout_check'] as $k) {
            if (isset($old[$k]) && ! isset($read[$k])) {
                $read[$k] = $old[$k];
            }
        }

        return $read;
    }

    private function bothArmsAtLeast(array $read, string $arm, int $n): bool
    {
        return ($read['arms'][self::CONTROL]['starts'] ?? 0) >= $n && ($read['arms'][$arm]['starts'] ?? 0) >= $n;
    }

    /**
     * Move an experiment from $expected to $status. The UPDATE names the status it expects, so a
     * row that was stopped (or decided) by someone else in the meantime is left as they left it;
     * the answer is false and nothing else is touched. Callers hold the lock.
     */
    private function finish(object $exp, string $status, string $text, ?array $read, Carbon $now, string $expected = self::STATUS_RUNNING): bool
    {
        $result = $read ?? (json_decode($exp->result ?? '', true) ?: []);
        $result = $this->keepStartFacts($exp, $result);
        if ($status === self::STATUS_ADOPTED) {
            $result['winner'] = $this->bestArm($result) ?: $this->firstVariant(json_decode($exp->arms, true));
        }
        if ($expected === self::STATUS_RUNNING) {
            $reaction = $this->reaction($exp->id);
            if ($reaction['total']) {
                $c = $reaction['counts'];
                $text .= " Members asked: {$c['better']} said better, {$c['worse']} worse, {$c['dont_care']} that it makes no difference to them, {$c['dont_understand']} that they were not sure what it is for.";
            }
        }
        $n = DB::table('ux_experiments')->where('id', $exp->id)->where('status', $expected)->update([
            'status' => $status,
            'decided_at' => $now,
            'decision' => $text,
            'result' => json_encode($result),
            'updated_at' => $now,
        ]);
        if ($n !== 1) {
            return false;
        }
        DB::table('ux_prompts')->where('experiment_id', $exp->id)->where('status', 'open')->update(['status' => 'closed', 'updated_at' => $now]);
        $this->settleIdea($exp, $status, $now);
        $this->rebuildConfig();

        return true;
    }

    /**
     * What happens to the idea behind an experiment. A decision (adopted, reverted) or a stop uses
     * it up. An inconclusive test says the day was too short, not that the idea was bad, so the
     * idea goes back to the backlog for another go, and is used up after idea_max_attempts. A
     * rejected idea is never resurrected.
     */
    private function settleIdea(object $exp, string $status, Carbon $now): void
    {
        if (! $exp->idea_id) {
            return;
        }
        $idea = DB::table('ux_ideas')->useWritePdo()->where('id', $exp->idea_id)->first();
        if (! $idea || $idea->status === 'rejected') {
            return;
        }
        if ($status === self::STATUS_INCONCLUSIVE) {
            $attempts = (int) $idea->attempts + 1;
            $spent = $attempts >= (int) self::settings()['idea_max_attempts'];
            DB::table('ux_ideas')->where('id', $idea->id)->update([
                'status' => $spent ? 'done' : 'open',
                'attempts' => $attempts,
                'experiment_id' => $spent ? $idea->experiment_id : null,
                'updated_at' => $now,
            ]);

            return;
        }
        DB::table('ux_ideas')->where('id', $idea->id)->update(['status' => 'done', 'updated_at' => $now]);
    }

    /** The newest stored read, if it is recent enough to be used at all (stale_read_hours). */
    private function lastRead(object $exp, Carbon $now): ?array
    {
        $row = DB::table('ux_experiment_reads')->useWritePdo()->where('experiment_id', $exp->id)
            ->where('read_at', '>=', $now->copy()->subHours((int) self::settings()['stale_read_hours']))->orderByDesc('read_at')->first();

        return $row ? (json_decode($row->metrics, true) ?: null) : null;
    }

    private function hours(object $exp, Carbon $now): float
    {
        return round(Carbon::parse($exp->started_at)->diffInSeconds($now, false) / 3600, 2);
    }

    /** The variant with the highest probability of beating control. */
    private function bestArm(array $read): ?string
    {
        $best = null;
        $bestP = -1;
        foreach ($read['primary'] ?? [] as $arm => $p) {
            if ($p['p_better'] > $bestP) {
                $bestP = $p['p_better'];
                $best = $arm;
            }
        }

        return $best;
    }

    private function worstGuardrail(array $read, ?string $arm): ?array
    {
        $worst = null;
        foreach ($read['guardrails'] ?? [] as $g) {
            if ($arm !== null && $g['arm'] !== $arm) {
                continue;
            }
            if ($worst === null || $g['p_worse'] > $worst['p_worse']) {
                $worst = $g;
            }
        }

        return $worst;
    }

    private function firstVariant(array $arms): string
    {
        foreach (array_keys($arms) as $arm) {
            if ($arm !== self::CONTROL) {
                return $arm;
            }
        }

        return 'b';
    }

    private static function sharesFromArms(array $arms): array
    {
        $out = [];
        foreach ($arms as $arm => [$from, $to]) {
            $out[$arm] = max(0, $to - $from + 1);
        }

        return $out;
    }

    /**
     * A metric query with a [1h] range evaluated every hour, the last point at $end and the first
     * point's hour still inside the window: [[labels, {ts: value}]], so counts can be put into the
     * hour they happened in. Less than a whole hour of window is read as one point at $end.
     *
     * @throws UxLokiUnavailable
     */
    private function hourly(string $logql, Carbon $start, Carbon $end): array
    {
        $whole = (int) floor($start->diffInSeconds($end, false) / 3600);
        $first = $whole >= 1 ? $end->copy()->subHours($whole - 1) : $end->copy();
        $series = $this->loki->queryMetricRange($logql, $first, $end, 3600);
        if ($series === null) {
            throw new UxLokiUnavailable('hourly query failed: '.substr($logql, 0, 200));
        }
        $out = [];
        foreach ($series as $s) {
            if ($s['points']) {
                $out[] = [$s['labels'], $s['points']];
            }
        }

        return $out;
    }
}
