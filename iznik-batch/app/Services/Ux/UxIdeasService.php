<?php

namespace App\Services\Ux;

use App\Services\DiscourseClient;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The batch half of idea generation for the automated UX experiment loop.
 *
 * Claude cannot reach the database, so the batch writes down what it knows about a funnel (the
 * last week's figures, the problems members raised and ranked and what they value, what the surveys
 * said, volunteers' reports and open issues, and everything already tried or already waiting in the
 * backlog), the support helper container turns that into ideas, and the batch files them as
 * ux_ideas rows. docs/developers/reference/ux-telemetry.md section 8.
 *
 * Problems and solutions are kept apart: members are asked about problems, and the generator
 * proposes solutions to them. Members' own words never reach the generator (they are free text
 * and could carry instructions, names or addresses); it gets problem titles, how many members said
 * each and how they ranked it, and nothing else of theirs. Its own list of problems is filed back
 * as ux_problems (source ai), each idea tied to the one it answers.
 *
 * An idea is never filed twice: a title already used by any idea of the funnel, in ANY status
 * (done and rejected too), or by any of its experiments, is skipped, and the brief lists
 * all of them as "tried" so the helper is also told not to propose them.
 */
class UxIdeasService
{
    private const ENDPOINT = '/internal/ux-ideas';

    /** A device class needs this many visits to a step before it can be called the worst. */
    private const MIN_DEVICE_VISITS = 20;

    private const FIELD_ROWS = 8;

    private const SURVEY_PROMPTS = 10;

    /** Problems and values in the brief, most-mentioned first. */
    private const PROBLEM_ROWS = 20;

    /** Lines of reports and issues in the brief, and the characters kept of each. */
    private const REPORT_LINES = 40;

    private const REPORT_CHARS = 400;

    private const BACKOFF_KEY = 'ux_ideas_backoff';

    /** @var string|null The reports text, built once per object: it is the same for every funnel. */
    private ?string $reportsCache = null;

    /** The brief is context for a model, not a report, so the history it carries is bounded. */
    private const TRIED_ROWS = 80;

    // ------------------------------------------------------------------ the brief

    /**
     * What the helper is told about a funnel: figures, problems, values, surveys and reports as
     * plain text, and what has been tried as a list.
     *
     * @return array{figures: string, problems: string, values: string, surveys: string, reports: string, tried: array<int, array{title: string, status: string, decision: string}>}
     */
    public function brief(string $flow): array
    {
        return [
            'figures' => $this->figures($flow),
            'problems' => $this->problems($flow),
            'values' => $this->values(),
            'surveys' => $this->surveys(),
            'reports' => $this->reports(),
            'tried' => $this->tried($flow),
        ];
    }

    private function figures(string $flow): string
    {
        $today = Carbon::now('UTC')->startOfDay();
        $range = [$today->copy()->subDays(7)->toDateString(), $today->copy()->subDay()->toDateString()];
        $lines = [];

        // A week of one flow is a few hundred rows at most, so the sums are done here rather than
        // in SQL.
        $o = DB::table('ux_flow_outcome_daily')->where('flow', $flow)->whereBetween('date', $range)->get();
        $started = (int) $o->sum('started');
        if ($started > 0) {
            $lines[] = sprintf(
                'Whole flow, %s to %s: %s started, %s completed (%s), %s abandoned, %s submitted of which %s failed, %s resumed later.',
                $range[0], $range[1], number_format($started), number_format((int) $o->sum('completed')),
                $this->pct((int) $o->sum('completed'), $started), number_format((int) $o->sum('abandoned')),
                number_format((int) $o->sum('submitted')), number_format((int) $o->sum('submit_failed')), number_format((int) $o->sum('resumed'))
            );
        }

        $steps = DB::table('ux_flow_daily')->where('flow', $flow)->whereBetween('date', $range)->get();
        foreach ($steps->groupBy('step') as $step => $rows) {
            $visits = (int) ($rows->sum('entered') + $rows->sum('reentered'));
            if ($visits === 0) {
                continue;
            }
            $line = sprintf('Step %s: %s entered, %s visits, %s completed (%s), %s abandoned.',
                $step, number_format((int) $rows->sum('entered')), number_format($visits),
                number_format((int) $rows->sum('completed')), $this->pct((int) $rows->sum('completed'), $visits),
                number_format((int) $rows->sum('abandoned')));
            $worst = $this->worstDevice($rows);
            if ($worst) {
                $line .= " Worst device: $worst.";
            }
            $lines[] = $line;
        }

        $fields = [];
        foreach (DB::table('ux_field_daily')->where('flow', $flow)->whereBetween('date', $range)->get()->groupBy(fn ($r) => $r->step."\0".$r->field) as $rows) {
            $f = [
                'step' => $rows->first()->step,
                'field' => $rows->first()->field,
                'validation_failures' => (int) $rows->sum('validation_failures'),
                'revisits' => (int) $rows->sum('revisits'),
                'choice_switches' => (int) $rows->sum('choice_switches'),
            ];
            $f['total'] = $f['validation_failures'] + $f['revisits'] + $f['choice_switches'];
            if ($f['total'] > 0) {
                $fields[] = $f;
            }
        }
        usort($fields, fn ($a, $b) => $b['total'] <=> $a['total']);
        foreach (array_slice($fields, 0, self::FIELD_ROWS) as $f) {
            $lines[] = sprintf('Field %s on step %s: %s validation failures, %s revisits, %s choice switches.',
                $f['field'], $f['step'], number_format($f['validation_failures']), number_format($f['revisits']),
                number_format($f['choice_switches']));
        }

        return $lines ? implode("\n", $lines) : 'no figures yet';
    }

    /** The device class that completes a step least often, with its rate, when there is a comparison. */
    private function worstDevice($rows): ?string
    {
        $byDevice = [];
        foreach ($rows->groupBy(fn ($r) => $r->device_class === '' ? 'unknown' : $r->device_class) as $device => $group) {
            $visits = (int) ($group->sum('entered') + $group->sum('reentered'));
            if ($visits >= self::MIN_DEVICE_VISITS) {
                $byDevice[$device] = [(int) $group->sum('completed') / $visits, $visits];
            }
        }
        if (count($byDevice) < 2) {
            return null;
        }
        uasort($byDevice, fn ($a, $b) => $a[0] <=> $b[0]);
        $device = array_key_first($byDevice);

        return sprintf('%s, %d%% completing (%s visits)', $device, round($byDevice[$device][0] * 100), number_format($byDevice[$device][1]));
    }

    private function surveys(): string
    {
        $prompts = DB::table('ux_prompts')->whereIn('kind', ['survey', 'awareness'])
            ->orderByDesc('id')->limit(self::SURVEY_PROMPTS)->get();
        if ($prompts->isEmpty()) {
            return 'no surveys yet';
        }

        $lines = [];
        // Across the decoy awareness questions (about something Freegle does not do), how many said
        // yes: the rate at which this sample says yes to anything.
        $decoyYes = 0;
        $decoyAnswers = 0;
        foreach ($prompts as $p) {
            $settings = json_decode($p->settings ?? '', true) ?: [];
            $followup = isset($settings['followup_of']);
            $labels = [];
            foreach (json_decode($p->options, true) ?: [] as $o) {
                $labels[$o['key']] = $o['label'];
            }
            $tally = ['best' => [], 'worst' => [], 'choice' => []];
            $shown = [];
            $total = 0;
            foreach (DB::table('ux_prompt_responses')->where('prompt_id', $p->id)->orderByDesc('timestamp')->get() as $r) {
                $answer = json_decode($r->answer, true) ?: [];
                // A skip records that the prompt was seen; it is not an answer.
                if (! empty($answer['skipped'])) {
                    continue;
                }
                $total++;
                // The options a member was shown, so a count can be read against how often the
                // option had the chance to be picked.
                foreach (isset($answer['shown']) && is_array($answer['shown']) ? array_unique($answer['shown']) : [] as $k) {
                    if (is_string($k)) {
                        $shown[$k] = ($shown[$k] ?? 0) + 1;
                    }
                }
                foreach ($tally as $key => $counts) {
                    if (isset($answer[$key])) {
                        $tally[$key][$answer[$key]] = ($counts[$answer[$key]] ?? 0) + 1;
                    }
                }
            }

            $head = ucfirst($p->kind).' "'.$p->question.'"'.($p->flow ? " (about {$p->flow})" : '').": $total answer".($total === 1 ? '' : 's').'.';
            $parts = [];
            foreach ($labels as $key => $label) {
                $bits = [];
                if (isset($tally['best'][$key])) {
                    $bits[] = $followup ? "{$tally['best'][$key]} picked it as the biggest problem" : "{$tally['best'][$key]} said best";
                }
                if (isset($tally['worst'][$key])) {
                    $bits[] = $followup ? "{$tally['worst'][$key]} picked it as the smallest problem" : "{$tally['worst'][$key]} said needs most work";
                }
                if (isset($tally['choice'][$key])) {
                    $bits[] = "{$tally['choice'][$key]} chose it";
                }
                if ($bits && isset($shown[$key])) {
                    $bits[] = "shown {$shown[$key]} time".($shown[$key] === 1 ? '' : 's');
                }
                if ($bits) {
                    $parts[] = "$label: ".implode(', ', $bits);
                }
            }
            if (! empty($tally['choice']['none'])) {
                $head .= ' '.$tally['choice']['none'].' said none of these'.($followup ? ' has been a problem for them' : '').'.';
            }
            if ($p->kind === 'awareness' && array_key_exists('decoy', $settings)) {
                // The right answer to a real question is yes, to a decoy no.
                $decoy = ! empty($settings['decoy']);
                $right = $tally['choice'][$decoy ? 'no' : 'yes'] ?? 0;
                $answered = ($tally['choice']['yes'] ?? 0) + ($tally['choice']['no'] ?? 0) + ($tally['choice']['not_sure'] ?? 0);
                $head .= $decoy ? ' A decoy: Freegle does not do this, so the right answer is no.' : '';
                $head .= $answered ? " $right of $answered answered it right." : '';
                if ($decoy) {
                    $decoyYes += $tally['choice']['yes'] ?? 0;
                    $decoyAnswers += $answered;
                }
            }
            $lines[] = $parts ? $head.' '.implode('; ', $parts).'.' : $head;
        }
        if ($decoyAnswers > 0) {
            $lines[] = sprintf('On the decoy questions %d of %d said yes, so discount the yes answers on the real awareness questions by about that much.', $decoyYes, $decoyAnswers);
        }
        // The free-text comments members attach to a survey answer are deliberately NOT included:
        // they are untrusted text that could carry instructions or personal details, and what they
        // say reaches the generator only as themes (problems and values), never in their words.

        return implode("\n", $lines);
    }

    /**
     * The problems members raised that bear on this funnel (and those tied to no funnel), as
     * plain text: the title as a theme, how many of the answers raised it, and how other members
     * ranked it in a follow-up (most picks minus least picks, per time it was shown).
     */
    private function problems(string $flow): string
    {
        // The catch-all theme is a residue, not a problem to propose a fix for.
        $rows = DB::table('ux_problems')->where('kind', 'problem')->where('status', 'open')
            ->where('title', '!=', UxPromptService::CATCH_ALL_THEME)
            ->where(fn ($q) => $q->where('flow', $flow)->orWhereNull('flow'))
            // MySQL sorts NULL lowest, so problems nobody has ranked yet come after the ranked ones.
            ->orderByDesc('weight')->orderByDesc('mentions')->orderBy('id')
            ->limit(self::PROBLEM_ROWS)->get();
        if ($rows->isEmpty()) {
            return 'no problems raised by members yet';
        }

        $lines = [];
        foreach ($rows as $p) {
            // The number lets the generator say an idea answers this problem without copying the
            // title word for word; fileProblems() then ties the idea to this row.
            $line = '[#'.$p->id.'] "'.$this->oneLine($p->title, 120).'"'.($p->flow ? '' : ' (not tied to one funnel)');
            $line .= $p->source === 'members'
                ? ': raised by '.$this->mentionsText($p)
                : ': found by '.$p->source;
            if ($p->weight !== null) {
                $line .= sprintf('; ranked by other members, score %+.2f (times picked as the biggest problem minus times picked as the smallest problem, per time it was shown)', (float) $p->weight);
            }
            $lines[] = $line.'.';
        }

        return implode("\n", $lines);
    }

    /** What members value, which a change must not take away. */
    private function values(): string
    {
        $rows = DB::table('ux_problems')->where('kind', 'value')->where('status', 'open')
            ->where('title', '!=', UxPromptService::CATCH_ALL_THEME)
            ->orderByDesc('mentions')->orderBy('id')->limit(self::PROBLEM_ROWS)->get();
        if ($rows->isEmpty()) {
            return 'no values collected yet';
        }

        return implode("\n", $rows->map(fn ($v) => '"'.$this->oneLine($v->title, 120).'": valued by '.$this->mentionsText($v).'.')->all());
    }

    /** "14 of 120 members who answered", the base always shown with the count. */
    private function mentionsText(object $problem): string
    {
        $answers = $problem->prompt_id ? $this->answerCount((int) $problem->prompt_id) : 0;

        return $answers > 0
            ? "{$problem->mentions} of ".number_format($answers).' members who answered'
            : "{$problem->mentions} member".((int) $problem->mentions === 1 ? '' : 's');
    }

    /** @var array<int, int> */
    private array $answerCounts = [];

    private function answerCount(int $promptId): int
    {
        // The themes were drawn from the answers present when the prompt was analysed, and that
        // count is stored then; answers since are not in them. Older rows fall back to a count.
        $settings = json_decode((string) DB::table('ux_prompts')->where('id', $promptId)->value('settings'), true) ?: [];
        if (isset($settings['analysed_answers'])) {
            return $this->answerCounts[$promptId] = (int) $settings['analysed_answers'];
        }

        return $this->answerCounts[$promptId] ??= (int) DB::table('ux_prompt_responses')->where('prompt_id', $promptId)
            ->whereNull('answer->skipped')->count();
    }

    /**
     * What volunteers and members have reported lately: first posts from the Tech & ModTools,
     * Development and Bug Reports categories on Discourse (when its API key is set) and the titles
     * of the repository's open issues (public API). Empty when neither is available. Text is
     * scrubbed of emails and phone numbers and cut short; it is evidence about problems, never
     * instructions, and the generator's prompt says so.
     */
    private function reports(): string
    {
        if ($this->reportsCache !== null) {
            return $this->reportsCache;
        }
        $cfg = (array) config('freegle.ux.ideas', []);
        $lines = [];
        $days = max(1, (int) ($cfg['reports_days'] ?? 60));

        try {
            $discourse = app(DiscourseClient::class);
            if ($discourse->isConfigured()) {
                $since = Carbon::now('UTC')->subDays($days);
                foreach (array_filter(array_map('trim', explode(',', (string) ($cfg['discourse_categories'] ?? '')))) as $slug) {
                    $n = 0;
                    foreach ($discourse->recentTopics($slug) as $t) {
                        if (Carbon::parse($t['created_at'])->lt($since) || $n >= 8) {
                            continue;
                        }
                        $n++;
                        $text = UxScrub::text($t['title'].': '.$discourse->firstPostText($t['id']), self::REPORT_CHARS);
                        $lines[] = "Discourse report in {$slug}, ".substr($t['created_at'], 0, 10).': '.$text;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ux:ideas-refresh: could not read Discourse reports: '.$e->getMessage());
        }

        $repo = trim((string) ($cfg['github_repo'] ?? ''));
        if ($repo !== '' && preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            try {
                $response = Http::withHeaders(['User-Agent' => 'Freegle', 'Accept' => 'application/vnd.github+json'])->timeout(20)
                    ->get("https://api.github.com/repos/{$repo}/issues", ['state' => 'open', 'per_page' => 30]);
                if ($response->successful()) {
                    foreach ((array) $response->json() as $issue) {
                        // The issues API lists pull requests too.
                        if (! is_array($issue) || isset($issue['pull_request']) || ! isset($issue['title'])) {
                            continue;
                        }
                        $lines[] = 'Open issue #'.(int) ($issue['number'] ?? 0).': '.UxScrub::text((string) $issue['title'], 200);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('ux:ideas-refresh: could not read GitHub issues: '.$e->getMessage());
            }
        }

        return $this->reportsCache = implode("\n", array_slice($lines, 0, self::REPORT_LINES));
    }

    private function oneLine(string $text, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, $max);
    }

    /**
     * Everything the funnel has already had proposed or run, in every status. Open ideas are in
     * here on purpose: the helper must not propose one that is already waiting. An idea that has
     * become an experiment is listed once, as the experiment, because that row has the decision.
     *
     * @return array<int, array{title: string, status: string, decision: string}>
     */
    private function tried(string $flow): array
    {
        $out = [];
        $seen = [];
        foreach (DB::table('ux_experiments')->where('flow', $flow)->orderByDesc('id')->limit(self::TRIED_ROWS)->get() as $e) {
            $out[] = ['title' => $e->title, 'status' => $e->status, 'decision' => (string) ($e->decision ?? '')];
            $seen[self::normalise($e->title)] = true;
        }
        foreach (DB::table('ux_ideas')->where('flow', $flow)->orderByDesc('id')->limit(self::TRIED_ROWS)->get() as $i) {
            $key = self::normalise($i->title);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['title' => $i->title, 'status' => $i->status, 'decision' => ''];
        }

        return $out;
    }

    // ------------------------------------------------------------------ refreshing

    /**
     * Ask the helper for ideas for a funnel and file the new ones. With $dryRun nothing is written.
     * 'ideas' holds the ones filed (or, on a dry run, the ones that would be), each with its score.
     *
     * @return array{inserted: int, skipped: int, problems: int, ideas: array<int, array>, cost_usd: float|null}
     *
     * @throws \RuntimeException when the helper cannot be reached or does not answer 2xx
     */
    public function refresh(string $flow, bool $dryRun = false): array
    {
        $cfg = (array) config('freegle.ux.ideas', []);
        $secret = (string) ($cfg['internal_secret'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException('UX_INTERNAL_SECRET is not set, so the helper would refuse the request');
        }
        $url = rtrim((string) ($cfg['helper_url'] ?? 'http://ai-support-helper:3000'), '/').self::ENDPOINT;
        $body = ['flow' => $flow] + $this->brief($flow) + ['count' => (int) ($cfg['count'] ?? 12)];

        try {
            // The wait is longer than the helper's own limit, so a slow generation ends there, not here.
            $response = Http::withHeaders(['X-Freegle-Internal' => '1', 'X-Freegle-Internal-Secret' => $secret])
                ->timeout((int) ($cfg['timeout'] ?? 960))
                ->acceptJson()
                ->asJson()
                ->post($url, $body);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('UX ideas helper could not be reached: '.$e->getMessage(), 0, $e);
        }
        if (! $response->successful()) {
            throw new \RuntimeException('UX ideas helper answered '.$response->status().': '.mb_substr((string) $response->body(), 0, 300));
        }

        $proposed = $response->json('ideas');
        if (! is_array($proposed)) {
            throw new \RuntimeException('UX ideas helper answered '.$response->status().' with no ideas list');
        }

        // Same flow only: "Show progress" is a fair idea for Give and for Ask. Against every
        // status, done and rejected included, and against the funnel's experiments: a tried or
        // refused idea proposed again under the same title is the same idea.
        $known = [];
        foreach (DB::table('ux_ideas')->where('flow', $flow)->pluck('title') as $title) {
            $known[self::normalise($title)] = true;
        }
        foreach (DB::table('ux_experiments')->where('flow', $flow)->pluck('title') as $title) {
            $known[self::normalise($title)] = true;
        }

        $now = Carbon::now('UTC');
        $problemIds = $dryRun ? [] : $this->fileProblems($flow, (array) ($response->json('problems') ?? []), $now);
        $inserted = 0;
        $skipped = 0;
        $ideas = [];
        foreach ($proposed as $idea) {
            if (! is_array($idea)) {
                $skipped++;

                continue;
            }
            $title = mb_substr(trim((string) ($idea['title'] ?? '')), 0, 160);
            if ($title === '' || isset($known[self::normalise($title)])) {
                $skipped++;

                continue;
            }
            // Also guards against the helper proposing the same title twice in one answer.
            $known[self::normalise($title)] = true;

            $score = self::score($idea);
            $type = ($idea['type'] ?? '') === 'fix' ? 'fix' : 'experiment';
            $ideas[] = ['title' => $title, 'score' => $score, 'type' => $type, 'change' => (string) ($idea['change'] ?? '')] + $idea;
            $inserted++;
            if ($dryRun) {
                continue;
            }

            DB::table('ux_ideas')->insert([
                'flow' => $flow,
                'title' => $title,
                'rationale' => trim(((string) ($idea['change'] ?? '')).' '.((string) ($idea['rationale'] ?? ''))),
                'expected' => (string) ($idea['expected'] ?? ''),
                'evidence' => json_encode([
                    'category' => $idea['category'] ?? null,
                    'expected_points' => $idea['expected_points'] ?? null,
                    'confidence' => $idea['confidence'] ?? null,
                    'locality' => $idea['locality'] ?? null,
                    'risk' => $idea['risk'] ?? null,
                    'files' => $idea['files'] ?? [],
                    'like_tried' => $idea['like_tried'] ?? '',
                ]),
                'score' => $score,
                'status' => 'open',
                'source' => 'ai',
                'problem_id' => $problemIds[(int) ($idea['problem_id'] ?? -1)] ?? null,
                'type' => $type,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $cost = $response->json('costUsd');

        return ['inserted' => $inserted, 'skipped' => $skipped, 'problems' => count($problemIds), 'ideas' => $ideas,
            'cost_usd' => is_numeric($cost) ? (float) $cost : null];
    }

    /**
     * File the problems the generator listed as ux_problems (source ai), and answer a map from
     * the generator's own problem number to the row, so each idea can be tied to the problem it
     * solves. A problem already on file for the funnel (or for no funnel) under the same title,
     * whoever raised it, is reused rather than duplicated: the ideas then hang off the problem
     * members actually raised.
     *
     * @param  array<int, mixed>  $problems
     * @return array<int, int> generator problem id => ux_problems.id
     */
    private function fileProblems(string $flow, array $problems, Carbon $now): array
    {
        $map = [];
        $existing = [];
        // The problems the brief listed by number: open, of this funnel or of none.
        $citable = [];
        foreach (DB::table('ux_problems')->where('kind', 'problem')->where(fn ($q) => $q->where('flow', $flow)->orWhereNull('flow'))->get(['id', 'title', 'status']) as $row) {
            $existing[self::normalise($row->title)] = (int) $row->id;
            if ($row->status === 'open') {
                $citable[(int) $row->id] = true;
            }
        }

        foreach ($problems as $p) {
            if (! is_array($p) || ! isset($p['id'])) {
                continue;
            }
            // The generator says this is a problem already on file, by its number in the brief.
            // Its title may be reworded, so the number decides; one that was not listed is ignored.
            $cited = isset($p['member_problem']) && is_numeric($p['member_problem']) ? (int) $p['member_problem'] : 0;
            if (isset($citable[$cited])) {
                $map[(int) $p['id']] = $cited;

                continue;
            }
            $title = mb_substr(trim((string) ($p['title'] ?? '')), 0, 100);
            if ($title === '') {
                continue;
            }
            $key = self::normalise($title);
            if (! isset($existing[$key])) {
                $existing[$key] = DB::table('ux_problems')->insertGetId([
                    'flow' => $flow,
                    'kind' => 'problem',
                    'title' => $title,
                    'description' => mb_substr(trim((string) ($p['where'] ?? '')), 0, 500) ?: null,
                    'source' => 'ai',
                    'mentions' => 0,
                    'evidence' => json_encode([
                        'source' => $p['source'] ?? null,
                        'where' => $p['where'] ?? null,
                        'evidence' => mb_substr((string) ($p['evidence'] ?? ''), 0, 500),
                    ]),
                    'status' => 'open',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $map[(int) $p['id']] = $existing[$key];
        }

        return $map;
    }

    public function openCount(string $flow): int
    {
        return DB::table('ux_ideas')->where('flow', $flow)->where('status', 'open')->count();
    }

    // ------------------------------------------------------------------ backing off after a failure

    /**
     * When a funnel failed, the next attempt waits: a day after one failure is a day wasted on a
     * helper that is down or a brief that is too big, so it doubles (2, 4, 7 days at most) and a
     * success clears it. Kept in the config table so it survives the process.
     */
    public function backoffUntil(string $flow): ?Carbon
    {
        $until = $this->backoff()[$flow]['until'] ?? null;

        return $until ? Carbon::parse($until, 'UTC') : null;
    }

    public function recordFailure(string $flow, ?Carbon $now = null): Carbon
    {
        $now = $now ?: Carbon::now('UTC');
        $all = $this->backoff();
        $fails = (int) ($all[$flow]['fails'] ?? 0) + 1;
        $until = $now->copy()->addDays(min(7, 2 ** $fails));
        $all[$flow] = ['fails' => $fails, 'until' => $until->toIso8601String()];
        $this->saveBackoff($all);

        return $until;
    }

    public function recordSuccess(string $flow): void
    {
        $all = $this->backoff();
        if (isset($all[$flow])) {
            unset($all[$flow]);
            $this->saveBackoff($all);
        }
    }

    /** @return array<string, array{fails: int, until: string}> */
    private function backoff(): array
    {
        return json_decode((string) DB::table('config')->useWritePdo()->where('key', self::BACKOFF_KEY)->value('value'), true) ?: [];
    }

    private function saveBackoff(array $all): void
    {
        DB::table('config')->upsert([['key' => self::BACKOFF_KEY, 'value' => json_encode($all ?: new \stdClass())]], ['key'], ['value']);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Expected points of completion, discounted by how sure the helper is and by how big the
     * change is: a one-line variant (locality 5) keeps the whole score, a sprawling one a fifth.
     * The helper is asked for a confidence from 0 to 1, but a percentage is read as one too, so a
     * model that slips into 0 to 100 does not push its ideas to the top of the list.
     */
    public static function score(array $idea): float
    {
        $points = (float) ($idea['expected_points'] ?? 0);
        $confidence = (float) ($idea['confidence'] ?? 0);
        if ($confidence > 1 && $confidence <= 100) {
            $confidence /= 100;
        }
        $confidence = max(0.0, min(1.0, $confidence));
        $locality = max(1.0, min(5.0, (float) ($idea['locality'] ?? 1)));

        // The column holds four digits and two places; a wild guess must not fail the insert.
        return max(-9999.99, min(9999.99, round($points * $confidence * ($locality / 5), 2)));
    }

    /** A title with case and all whitespace removed, so trivial rewording does not defeat the match. */
    private static function normalise(string $title): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', mb_substr(trim($title), 0, 160)));
    }

    private function pct(int $part, int $whole): string
    {
        return $whole > 0 ? round($part / $whole * 100).'%' : 'n/a';
    }
}
