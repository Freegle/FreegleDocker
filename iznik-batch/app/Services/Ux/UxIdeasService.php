<?php

namespace App\Services\Ux;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The batch half of idea generation for the automated UX experiment loop.
 *
 * Claude cannot reach the database, so the batch writes down what it knows about a funnel (the
 * last week's figures, what members said in the surveys, and everything already tried or already
 * waiting in the backlog), the support helper container turns that into ideas, and the batch
 * files them as ux_ideas rows. docs/developers/reference/ux-telemetry.md section 8.
 *
 * Ideas that are already open, scheduled or running under the same title are not filed twice, and
 * the brief lists every idea in every status as "tried", so the helper is also told not to
 * propose them.
 */
class UxIdeasService
{
    private const ENDPOINT = '/internal/ux-ideas';

    /** An idea in one of these states is still live: proposing it again would be a duplicate. */
    private const LIVE = ['open', 'scheduled', 'running'];

    /** A device class needs this many visits to a step before it can be called the worst. */
    private const MIN_DEVICE_VISITS = 20;

    private const FIELD_ROWS = 8;

    private const SURVEY_PROMPTS = 10;

    private const COMMENTS = 10;

    /** The brief is context for a model, not a report, so the history it carries is bounded. */
    private const TRIED_ROWS = 80;

    // ------------------------------------------------------------------ the brief

    /**
     * What the helper is told about a funnel: figures and surveys as plain text, and what has been
     * tried as a list.
     *
     * @return array{figures: string, surveys: string, tried: array<int, array{title: string, status: string, decision: string}>}
     */
    public function brief(string $flow): array
    {
        return [
            'figures' => $this->figures($flow),
            'surveys' => $this->surveys(),
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
        $comments = [];
        foreach ($prompts as $p) {
            $labels = [];
            foreach (json_decode($p->options, true) ?: [] as $o) {
                $labels[$o['key']] = $o['label'];
            }
            $tally = ['best' => [], 'worst' => [], 'choice' => []];
            $total = 0;
            foreach (DB::table('ux_prompt_responses')->where('prompt_id', $p->id)->orderByDesc('timestamp')->get() as $r) {
                $answer = json_decode($r->answer, true) ?: [];
                $total++;
                foreach ($tally as $key => $counts) {
                    if (isset($answer[$key])) {
                        $tally[$key][$answer[$key]] = ($counts[$answer[$key]] ?? 0) + 1;
                    }
                }
                if (trim((string) $r->comment) !== '' && count($comments) < self::COMMENTS) {
                    $comments[] = trim($r->comment);
                }
            }

            $head = ucfirst($p->kind).' "'.$p->question.'"'.($p->flow ? " (about {$p->flow})" : '').": $total answer".($total === 1 ? '' : 's').'.';
            $parts = [];
            foreach ($labels as $key => $label) {
                $bits = [];
                if (isset($tally['best'][$key])) {
                    $bits[] = "{$tally['best'][$key]} said best";
                }
                if (isset($tally['worst'][$key])) {
                    $bits[] = "{$tally['worst'][$key]} said needs most work";
                }
                if (isset($tally['choice'][$key])) {
                    $bits[] = "{$tally['choice'][$key]} chose it";
                }
                if ($bits) {
                    $parts[] = "$label: ".implode(', ', $bits);
                }
            }
            $lines[] = $parts ? $head.' '.implode('; ', $parts).'.' : $head;
        }
        foreach ($comments as $c) {
            $lines[] = 'Comment: '.mb_substr(preg_replace('/\s+/u', ' ', $c), 0, 300);
        }

        return implode("\n", $lines);
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
     * @return array{inserted: int, skipped: int, ideas: array<int, array>, cost_usd: float|null}
     *
     * @throws \RuntimeException when the helper cannot be reached or does not answer 2xx
     */
    public function refresh(string $flow, bool $dryRun = false): array
    {
        $cfg = (array) config('freegle.ux.ideas', []);
        $url = rtrim((string) ($cfg['helper_url'] ?? 'http://ai-support-helper:3000'), '/').self::ENDPOINT;
        $body = ['flow' => $flow] + $this->brief($flow) + ['count' => (int) ($cfg['count'] ?? 12)];

        try {
            $response = Http::withHeaders(['X-Freegle-Internal' => '1'])
                ->timeout((int) ($cfg['timeout'] ?? 600))
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

        // Same flow only: "Show progress" is a fair idea for Give and for Ask.
        $live = [];
        foreach (DB::table('ux_ideas')->where('flow', $flow)->whereIn('status', self::LIVE)->pluck('title') as $title) {
            $live[self::normalise($title)] = true;
        }

        $now = Carbon::now('UTC');
        $inserted = 0;
        $skipped = 0;
        $ideas = [];
        foreach ($proposed as $idea) {
            if (! is_array($idea)) {
                $skipped++;

                continue;
            }
            $title = mb_substr(trim((string) ($idea['title'] ?? '')), 0, 160);
            if ($title === '' || isset($live[self::normalise($title)])) {
                $skipped++;

                continue;
            }
            // Also guards against the helper proposing the same title twice in one answer.
            $live[self::normalise($title)] = true;

            $score = self::score($idea);
            $ideas[] = ['title' => $title, 'score' => $score, 'change' => (string) ($idea['change'] ?? '')] + $idea;
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
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $cost = $response->json('costUsd');

        return ['inserted' => $inserted, 'skipped' => $skipped, 'ideas' => $ideas, 'cost_usd' => is_numeric($cost) ? (float) $cost : null];
    }

    public function openCount(string $flow): int
    {
        return DB::table('ux_ideas')->where('flow', $flow)->where('status', 'open')->count();
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
