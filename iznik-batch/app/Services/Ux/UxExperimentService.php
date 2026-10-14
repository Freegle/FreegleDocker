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
 * never one step: a step fixed can push the loss later. Guardrails are read the same way: submit
 * failures, validation failures, help sought, and every OTHER flow's completion by the same arm,
 * so a change to Give that hurts Reply is caught.
 *
 * Thresholds, caps and the bandit floor are config('freegle.ux.experiments'), not code.
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

    /** What a member can say about a change. The last two are answers too, not refusals. */
    public const EXPERIMENT_OPTIONS = [
        ['key' => 'better', 'label' => 'Better'],
        ['key' => 'worse', 'label' => 'Worse'],
        ['key' => 'dont_care', 'label' => "Don't care"],
        ['key' => 'dont_understand', 'label' => "Don't understand"],
    ];

    /** Events read per arm and flow. */
    private const EVENTS = ['flow_start', 'flow_complete', 'flow_submit_result', 'validation_failed', 'help_sought'];

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
            'min_starts_per_arm' => 50,
            'adopt_probability' => 0.80,
            'early_adopt_probability' => 0.95,
            'early_revert_probability' => 0.05,
            'guardrail_harm_probability' => 0.90,
            'early_harm_probability' => 0.95,
            'bandit' => true,
            'min_control_share' => 10,
            'max_variant_share' => 90,
            'guardrail_min_events' => 30,
        ];
    }

    // ------------------------------------------------------------------ lifecycle

    /**
     * A new draft experiment. $a has flow, name, title, hypothesis, change, expected and
     * optionally arms, pr_url, image_control, image_variant, idea_id.
     */
    public function create(array $a): int
    {
        $name = (string) ($a['name'] ?? '');
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
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
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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

    public function find(int|string $idOrName): ?object
    {
        $q = DB::table('ux_experiments');
        $row = is_numeric($idOrName) ? $q->where('id', (int) $idOrName)->first() : $q->where('name', $idOrName)->first();

        return $row ?: null;
    }

    public function runningCount(): int
    {
        return DB::table('ux_experiments')->whereIn('status', self::ACTIVE)->count();
    }

    /**
     * Start a draft (or a stopped) experiment: it runs from now for at most max_hours, within the
     * concurrency cap, and its arms are published. A prompt for microvolunteers is opened from its
     * description and images.
     */
    public function start(int|string $idOrName, ?Carbon $now = null): object
    {
        $now = $now ?: Carbon::now('UTC');
        $exp = $this->find($idOrName);
        if (! $exp) {
            throw new \RuntimeException("No experiment $idOrName");
        }
        if (! in_array($exp->status, [self::STATUS_DRAFT, self::STATUS_STOPPED, self::STATUS_INCONCLUSIVE, self::STATUS_REVERTED], true)) {
            throw new \RuntimeException("Experiment {$exp->name} is {$exp->status}, not startable");
        }
        $s = self::settings();
        if ($this->runningCount() >= (int) $s['max_concurrent']) {
            throw new \RuntimeException('Already '.$s['max_concurrent'].' experiments running; that is the cap');
        }

        DB::table('ux_experiments')->where('id', $exp->id)->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => $now,
            'ends_at' => $now->copy()->addHours((int) $s['max_hours']),
            'decided_at' => null,
            'decision' => null,
            'result' => null,
            'updated_at' => $now,
        ]);
        if ($exp->idea_id) {
            DB::table('ux_ideas')->where('id', $exp->idea_id)->update(['status' => 'running', 'experiment_id' => $exp->id, 'updated_at' => $now]);
        }
        $this->openPrompt($exp, $now);
        $this->rebuildConfig();

        return $this->find($exp->id);
    }

    /** Stop a running experiment by hand: everyone goes back to control. */
    public function stop(int|string $idOrName, string $reason = 'Stopped by hand.', ?Carbon $now = null): object
    {
        $now = $now ?: Carbon::now('UTC');
        $exp = $this->find($idOrName);
        if (! $exp) {
            throw new \RuntimeException("No experiment $idOrName");
        }
        if ($exp->status !== self::STATUS_RUNNING) {
            throw new \RuntimeException("Experiment {$exp->name} is {$exp->status}, not running");
        }
        $this->finish($exp, self::STATUS_STOPPED, $reason, null, $now);

        return $this->find($exp->id);
    }

    /** Mark an adopted experiment's code cleanup done: the switch is gone, so the row leaves the config. */
    public function close(int|string $idOrName, ?string $cleanupPr = null, ?Carbon $now = null): object
    {
        $now = $now ?: Carbon::now('UTC');
        $exp = $this->find($idOrName);
        if (! $exp) {
            throw new \RuntimeException("No experiment $idOrName");
        }
        DB::table('ux_experiments')->where('id', $exp->id)->update([
            'status' => self::STATUS_CLOSED,
            'cleanup_pr_url' => $cleanupPr ?: $exp->cleanup_pr_url,
            'updated_at' => $now,
        ]);
        $this->rebuildConfig();

        return $this->find($exp->id);
    }

    /**
     * The hourly tick: read every running experiment, record the read, move traffic, and decide
     * when the time is up or the answer is already clear. Returns one line per experiment.
     *
     * @return string[]
     */
    public function tick(?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now('UTC');
        $lines = [];
        foreach (DB::table('ux_experiments')->whereIn('status', self::ACTIVE)->orderBy('id')->get() as $exp) {
            try {
                $read = $this->read($exp, $now);
            } catch (UxLokiUnavailable $e) {
                Log::warning("ux:experiments-tick: Loki unavailable for {$exp->name}: ".$e->getMessage());
                $read = $this->lastRead($exp, $now);
                if ($read === null) {
                    if ($now->gte(Carbon::parse($exp->ends_at))) {
                        $this->finish($exp, self::STATUS_REVERTED, 'Time was up and the logs could not be read, so the change was taken off to be safe.', null, $now);
                        $lines[] = "{$exp->name}: reverted (Loki unavailable at the end)";
                    } else {
                        $lines[] = "{$exp->name}: Loki unavailable, no read this hour";
                    }
                    continue;
                }
                $read['stale'] = true;
            }

            $hours = $this->hours($exp, $now);
            $summary = $this->summary($exp, $read, $hours);
            if (empty($read['stale'])) {
                DB::table('ux_experiment_reads')->insert([
                    'experiment_id' => $exp->id,
                    'read_at' => $now,
                    'hours' => $hours,
                    'metrics' => json_encode($read),
                    'summary' => $summary,
                ]);
            }
            DB::table('ux_experiments')->where('id', $exp->id)->update(['result' => json_encode($read), 'updated_at' => $now]);

            $decision = $this->decide($exp, $read, $now);
            if ($decision) {
                $this->finish($exp, $decision['status'], $decision['text'], $read, $now);
                $lines[] = "{$exp->name}: {$decision['status']}";
                continue;
            }

            if (self::settings()['bandit']) {
                $arms = $this->allocate($read, json_decode($exp->arms, true));
                // MySQL stores JSON object keys in its own order, so compare by value, not order.
                if ($arms != json_decode($exp->arms, true)) {
                    DB::table('ux_experiments')->where('id', $exp->id)->update(['arms' => json_encode($arms), 'updated_at' => $now]);
                }
            }
            $lines[] = "{$exp->name}: ".round($hours, 1)."h, ".$this->oneLine($read);
        }
        $this->rebuildConfig();

        return $lines;
    }

    // ------------------------------------------------------------------ reading

    /**
     * Counts by arm since the experiment started, and the statistics over them.
     *
     * @throws UxLokiUnavailable
     */
    public function read(object $exp, Carbon $now): array
    {
        $start = Carbon::parse($exp->started_at);
        $minutes = max(1, (int) ceil($start->diffInSeconds($now, false) / 60));
        $name = $exp->name;
        $selector = '{app="freegle", source="client", event_type="flow"}';
        // The arm is the token after "<name>:" inside the variant field, which holds every
        // experiment the member is in as name:arm pairs. regexp runs over the raw line.
        // Names are letters, digits, _ and -, none of which RE2 treats specially outside a class.
        $armStage = sprintf('| variant=~".*%s:.*" | regexp "\\"variant\\":\\"[^\\"]*%s:(?P<arm>[A-Za-z0-9_]+)"', $name, $name);

        $counts = $this->metric(sprintf(
            'sum by (arm, flow, action_name) (count_over_time(%s | json flow, action_name, variant | __error__="" %s | action_name=~"%s" [%dm]))',
            $selector, $armStage, implode('|', self::EVENTS), $minutes
        ));
        $okCounts = $this->metric(sprintf(
            'sum by (arm, flow) (count_over_time(%s | json flow, action_name, variant, ok | __error__="" %s | action_name="flow_submit_result" | ok="true" [%dm]))',
            $selector, $armStage, $minutes
        ));

        $arms = [];
        foreach (array_keys(json_decode($exp->arms, true)) as $arm) {
            $arms[$arm] = ['starts' => 0, 'completes' => 0, 'submitted' => 0, 'submit_ok' => 0, 'submit_failed' => 0,
                'validation_failed' => 0, 'help_sought' => 0, 'other_flows' => []];
        }
        $other = fn (array &$a, string $flow) => $a['other_flows'][$flow] ??= ['starts' => 0, 'completes' => 0];

        foreach ($counts as [$labels, $value]) {
            $arm = $labels['arm'] ?? '';
            if (! isset($arms[$arm])) {
                continue;
            }
            $flow = $labels['flow'] ?? '';
            $n = (int) round($value);
            $event = $labels['action_name'] ?? '';
            if ($flow === $exp->flow) {
                $arms[$arm][match ($event) {
                    'flow_start' => 'starts',
                    'flow_complete' => 'completes',
                    'flow_submit_result' => 'submitted',
                    'validation_failed' => 'validation_failed',
                    'help_sought' => 'help_sought',
                    default => 'ignored',
                }] = ($arms[$arm][match ($event) {
                    'flow_start' => 'starts',
                    'flow_complete' => 'completes',
                    'flow_submit_result' => 'submitted',
                    'validation_failed' => 'validation_failed',
                    'help_sought' => 'help_sought',
                    default => 'ignored',
                }] ?? 0) + $n;
            } elseif ($event === 'flow_start' || $event === 'flow_complete') {
                $other($arms[$arm], $flow);
                $arms[$arm]['other_flows'][$flow][$event === 'flow_start' ? 'starts' : 'completes'] += $n;
            }
        }
        foreach ($okCounts as [$labels, $value]) {
            $arm = $labels['arm'] ?? '';
            if (isset($arms[$arm]) && ($labels['flow'] ?? '') === $exp->flow) {
                $arms[$arm]['submit_ok'] += (int) round($value);
            }
        }
        foreach ($arms as &$a) {
            unset($a['ignored']);
            $a['submit_failed'] = max(0, $a['submitted'] - $a['submit_ok']);
        }
        unset($a);

        return $this->statistics($exp, $arms, $minutes, $now);
    }

    /** The statistics over per-arm counts: the primary comparison and the guardrails. */
    public function statistics(object $exp, array $arms, int $minutes, Carbon $now): array
    {
        $s = self::settings();
        $control = $arms[self::CONTROL] ?? ['starts' => 0, 'completes' => 0];
        $primary = [];
        $guardrails = [];

        foreach ($arms as $arm => $a) {
            if ($arm === self::CONTROL) {
                continue;
            }
            $sa = $control['completes'];
            $fa = max(0, $control['starts'] - $control['completes']);
            $sb = $a['completes'];
            $fb = max(0, $a['starts'] - $a['completes']);
            $p = BetaBinomial::probabilityBGreaterThanA($sa, $fa, $sb, $fb);
            [$lo, $hi] = BetaBinomial::credibleIntervalDiff($sa, $fa, $sb, $fb);
            $primary[$arm] = [
                'control_rate' => $control['starts'] ? $control['completes'] / $control['starts'] : null,
                'variant_rate' => $a['starts'] ? $a['completes'] / $a['starts'] : null,
                'diff_points' => ($control['starts'] && $a['starts'])
                    ? round(($a['completes'] / $a['starts'] - $control['completes'] / $control['starts']) * 100, 1) : null,
                'p_better' => round($p, 4),
                'ci90_points' => [round($lo * 100, 1), round($hi * 100, 1)],
                'enough' => $control['starts'] >= $s['min_starts_per_arm'] && $a['starts'] >= $s['min_starts_per_arm'],
            ];

            // Guardrails: a higher rate is worse for the first three, a lower one for the others.
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
                $cBad = min($control[$g['bad']], $cb);
                $vBad = min($a[$g['bad']], $vb);
                $pWorse = BetaBinomial::probabilityBGreaterThanA($cBad, $cb - $cBad, $vBad, $vb - $vBad);
                $guardrails[] = ['arm' => $arm, 'name' => $key, 'label' => $g['label'],
                    'control_rate' => $cb ? $cBad / $cb : null, 'variant_rate' => $vb ? $vBad / $vb : null,
                    'p_worse' => round($pWorse, 4)];
            }
            foreach ($a['other_flows'] as $flow => $v) {
                $c = $control['other_flows'][$flow] ?? ['starts' => 0, 'completes' => 0];
                if ($c['starts'] + $v['starts'] < $s['guardrail_min_events']) {
                    continue;
                }
                // Worse means completing less often: P(variant rate < control rate).
                $pWorse = 1 - BetaBinomial::probabilityBGreaterThanA(
                    $c['completes'], max(0, $c['starts'] - $c['completes']),
                    $v['completes'], max(0, $v['starts'] - $v['completes']));
                $guardrails[] = ['arm' => $arm, 'name' => "flow:$flow", 'label' => "$flow completion",
                    'control_rate' => $c['starts'] ? $c['completes'] / $c['starts'] : null,
                    'variant_rate' => $v['starts'] ? $v['completes'] / $v['starts'] : null,
                    'p_worse' => round($pWorse, 4)];
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
     * decision. Reads the best variant against control.
     */
    public function decide(object $exp, array $read, Carbon $now): ?array
    {
        $s = self::settings();
        $timeUp = $now->gte(Carbon::parse($exp->ends_at));
        $best = $this->bestArm($read);
        $harm = $this->worstGuardrail($read, $best);
        $figures = $best ? $this->figures($read, $best) : 'no reads yet';

        if ($harm && $harm['p_worse'] >= $s['early_harm_probability']) {
            return ['status' => self::STATUS_REVERTED, 'text' => "Reverted early: {$harm['label']} got worse with the change "
                .'('.$this->rate($harm['variant_rate']).' against '.$this->rate($harm['control_rate'])." without it; probability it is worse ".$this->pct($harm['p_worse']).').'];
        }

        if ($best && $read['primary'][$best]['enough']) {
            $p = $read['primary'][$best]['p_better'];
            if ($p >= $s['early_adopt_probability'] && ! ($harm && $harm['p_worse'] >= $s['guardrail_harm_probability'])) {
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
        $p = $read['primary'][$best]['p_better'];
        if ($harm && $harm['p_worse'] >= $s['guardrail_harm_probability']) {
            return ['status' => self::STATUS_REVERTED, 'text' => "Reverted at the end: {$harm['label']} was probably worse with the change (".$this->pct($harm['p_worse'])."), whatever the main result. $figures"];
        }
        if ($p >= $s['adopt_probability']) {
            return ['status' => self::STATUS_ADOPTED, 'text' => "Adopted at the end: the change was probably better. $figures"];
        }
        if ($p <= 1 - $s['adopt_probability']) {
            return ['status' => self::STATUS_REVERTED, 'text' => "Reverted at the end: the change was probably worse. $figures"];
        }

        return ['status' => self::STATUS_INCONCLUSIVE, 'text' => "Inconclusive at the end, so the change was taken off: the result could have gone either way. $figures"];
    }

    /**
     * Thompson sampling over the arms' completion posteriors: each arm's share of traffic is
     * the share of draws it wins, with a floor for control and a cap for any variant so the
     * comparison never stops being one. Ranges are rewritten as contiguous blocks over 0..99.
     */
    public function allocate(array $read, array $arms): array
    {
        $s = self::settings();
        // Control first, then the variants by name: the ranges are laid out in this order, and
        // MySQL returns JSON keys in its own order, so fix it here.
        $names = array_keys($arms);
        usort($names, fn ($a, $b) => ($a === self::CONTROL ? 0 : 1) <=> ($b === self::CONTROL ? 0 : 1) ?: strcmp($a, $b));
        $counts = $read['arms'];
        foreach ($names as $arm) {
            if (($counts[$arm]['starts'] ?? 0) < $s['min_starts_per_arm']) {
                return $arms; // Not enough on every arm yet to move anything.
            }
        }

        mt_srand((int) ($read['window_minutes'] ?? 0) + 7);
        $wins = array_fill_keys($names, 0);
        $draws = 2000;
        for ($i = 0; $i < $draws; $i++) {
            $bestArm = null;
            $bestVal = -1;
            foreach ($names as $arm) {
                $v = $this->sampleRate($counts[$arm]['completes'], max(0, $counts[$arm]['starts'] - $counts[$arm]['completes']));
                if ($v > $bestVal) {
                    $bestVal = $v;
                    $bestArm = $arm;
                }
            }
            $wins[$bestArm]++;
        }

        $shares = [];
        foreach ($names as $arm) {
            $shares[$arm] = (int) round($wins[$arm] / $draws * 100);
        }
        $shares[self::CONTROL] = max($shares[self::CONTROL], (int) $s['min_control_share']);
        foreach ($names as $arm) {
            if ($arm !== self::CONTROL) {
                $shares[$arm] = min($shares[$arm], (int) $s['max_variant_share']);
            }
        }
        // Make them sum to 100, giving any slack to control (the safe side).
        $sum = array_sum($shares);
        $shares[self::CONTROL] += 100 - $sum;
        if ($shares[self::CONTROL] < (int) $s['min_control_share']) {
            $shares[self::CONTROL] = (int) $s['min_control_share'];
        }
        $sum = array_sum($shares);
        if ($sum !== 100) {
            // Over 100 only when every variant is at its cap: trim the largest variant.
            arsort($shares);
            foreach ($shares as $arm => $v) {
                if ($arm !== self::CONTROL) {
                    $shares[$arm] -= $sum - 100;
                    break;
                }
            }
        }

        $out = [];
        $from = 0;
        foreach ($names as $arm) {
            $n = max(0, $shares[$arm]);
            if ($n === 0) {
                // An arm with no traffic keeps an empty range rather than vanishing.
                $out[$arm] = [99, 98];
                continue;
            }
            $out[$arm] = [$from, $from + $n - 1];
            $from += $n;
        }

        return $out;
    }

    // ------------------------------------------------------------------ wording

    /** The paragraph stored with every read and shown on the tab. */
    public function summary(object $exp, array $read, float $hours): string
    {
        $best = $this->bestArm($read);
        $h = round($hours, 1);
        if (! $best) {
            return "After {$h} hours: nothing recorded yet.";
        }
        $p = $read['primary'][$best];
        $c = $read['arms'][self::CONTROL];
        $v = $read['arms'][$best];
        $text = "After {$h} hours: ".number_format($v['starts']).' people started '.$this->flowName($exp->flow).' with the change and '
            .number_format($c['starts']).' without. ';
        if ($p['variant_rate'] !== null && $p['control_rate'] !== null) {
            $text .= $this->pct($p['variant_rate']).' finished with the change, '.$this->pct($p['control_rate']).' without ('
                .$this->points($p['diff_points']).'). Probability the change is better: '.$this->pct($p['p_better']).'. ';
        }
        $harm = $this->worstGuardrail($read, $best);
        if ($harm && $harm['p_worse'] >= 0.5) {
            $text .= 'Watch: '.$harm['label'].' looks worse with the change ('.$this->rate($harm['variant_rate']).' against '
                .$this->rate($harm['control_rate']).', probability '.$this->pct($harm['p_worse']).'). ';
        } elseif ($read['guardrails']) {
            $text .= 'Guardrails: nothing worse. ';
        }
        $alloc = $read['allocation'][$best] ?? null;
        if ($alloc !== null) {
            $text .= "Traffic on the change: {$alloc}%. ";
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

        return sprintf('%s vs %s starts, %s vs %s done, p_better %s',
            $read['arms'][$best]['starts'], $read['arms'][self::CONTROL]['starts'],
            $read['arms'][$best]['completes'], $read['arms'][self::CONTROL]['completes'], $p['p_better']);
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

    /** The JSON the client reads: running experiments' arms, adopted ones at 100% of the winner. */
    public function rebuildConfig(): string
    {
        $out = [];
        foreach (DB::table('ux_experiments')->whereIn('status', [self::STATUS_RUNNING, self::STATUS_ADOPTED])->orderBy('id')->get() as $exp) {
            $arms = json_decode($exp->arms, true) ?: [];
            if ($exp->status === self::STATUS_ADOPTED) {
                $winner = json_decode($exp->result ?? '', true)['winner'] ?? $this->firstVariant($arms);
                $arms = [$winner => [0, 99]];
            }
            $out[$exp->name] = $arms;
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
            'question' => 'Would this change be better or worse for you?',
            'body' => $exp->change_description."\n\n".$exp->expected,
            'options' => json_encode(self::EXPERIMENT_OPTIONS),
            'images' => $images ? json_encode($images) : null,
            'status' => 'open',
            'max_responses' => 200,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** What microvolunteers said about an experiment, as counts per option and the comments. */
    public function reaction(int $experimentId): array
    {
        $promptIds = DB::table('ux_prompts')->where('experiment_id', $experimentId)->pluck('id')->all();
        $counts = ['better' => 0, 'worse' => 0, 'dont_care' => 0, 'dont_understand' => 0];
        $comments = [];
        if ($promptIds) {
            foreach (DB::table('ux_prompt_responses')->whereIn('prompt_id', $promptIds)->orderByDesc('timestamp')->get() as $r) {
                $answer = json_decode($r->answer, true);
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

    private function finish(object $exp, string $status, string $text, ?array $read, Carbon $now): void
    {
        $result = $read ?? (json_decode($exp->result ?? '', true) ?: []);
        if ($status === self::STATUS_ADOPTED) {
            $result['winner'] = $this->bestArm($result) ?: $this->firstVariant(json_decode($exp->arms, true));
        }
        $reaction = $this->reaction($exp->id);
        if ($reaction['total']) {
            $c = $reaction['counts'];
            $text .= " Members asked: {$c['better']} said better, {$c['worse']} worse, {$c['dont_care']} don't care, {$c['dont_understand']} didn't understand.";
        }
        DB::table('ux_experiments')->where('id', $exp->id)->update([
            'status' => $status,
            'decided_at' => $now,
            'decision' => $text,
            'result' => json_encode($result),
            'updated_at' => $now,
        ]);
        DB::table('ux_prompts')->where('experiment_id', $exp->id)->where('status', 'open')->update(['status' => 'closed', 'updated_at' => $now]);
        if ($exp->idea_id) {
            DB::table('ux_ideas')->where('id', $exp->idea_id)->update(['status' => 'done', 'updated_at' => $now]);
        }
        $this->rebuildConfig();
    }

    private function lastRead(object $exp, Carbon $now): ?array
    {
        $row = DB::table('ux_experiment_reads')->where('experiment_id', $exp->id)
            ->where('read_at', '>=', $now->copy()->subHours(3))->orderByDesc('read_at')->first();

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

    private function sampleRate(int $s, int $f): float
    {
        // A cheap Beta draw: the mean of n uniform-driven Bernoulli trials would be slow; use the
        // normal approximation to the posterior, which is fine once each arm has 50 starts.
        $n = $s + $f + 2;
        $mean = ($s + 1) / $n;
        $sd = sqrt($mean * (1 - $mean) / ($n + 1));
        $u1 = max((mt_rand() + 0.5) / (mt_getrandmax() + 1), 1e-12);
        $u2 = (mt_rand() + 0.5) / (mt_getrandmax() + 1);

        return $mean + $sd * sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    /** @throws UxLokiUnavailable */
    private function metric(string $logql): array
    {
        $end = Carbon::now('UTC');
        $series = $this->loki->queryMetricRange($logql, $end->copy()->subSecond(), $end, 3600);
        if ($series === null) {
            throw new UxLokiUnavailable('metric query failed: '.substr($logql, 0, 200));
        }
        $out = [];
        foreach ($series as $s) {
            if ($s['points']) {
                $out[] = [$s['labels'], (float) end($s['points'])];
            }
        }

        return $out;
    }
}
