<?php

namespace App\Services\Ux;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Survey, awareness and open prompts for microvolunteers (an experiment's own prompt is opened by
 * UxExperimentService::openPrompt). docs/developers/reference/ux-telemetry.md section 8.
 *
 * Answers are a reaction gauge from members with a known status-quo bias. The best / worst
 * survey says where members feel the friction, so idea generation starts there; an awareness
 * question says whether a feature has been found at all, which separates "not found" from "not
 * wanted" and so tells a discoverability experiment from a retirement candidate.
 *
 * Idea farming keeps problems and solutions apart. An OPEN prompt asks members what got in their
 * way, what they value, or what they would change, in their own words. analyse() has a model with
 * no tools code those words into themes: problems (kept in ux_problems), values (also
 * ux_problems, kind value) and member solution suggestions (ux_ideas, source member, each tied to
 * the problem behind it). followup() then asks other members to rank the top problems, and the
 * tick turns that ranking into a weight. Only the themes ever reach the idea generator; the
 * members' words stay here, and are blanked 90 days after their prompt closes.
 */
class UxPromptService
{
    /** The areas the standard survey asks about: the funnels, in the words members use. */
    public const SURVEY_AREAS = [
        ['key' => 'give', 'label' => 'Offering something'],
        ['key' => 'ask', 'label' => 'Asking for something'],
        ['key' => 'reply', 'label' => 'Replying to a post'],
        ['key' => 'chat_promise', 'label' => 'Arranging a handover in chat'],
        ['key' => 'chat_outcome', 'label' => 'Marking a post as taken or received'],
        ['key' => 'login', 'label' => 'Signing in or joining'],
        ['key' => 'settings', 'label' => 'Settings and email choices'],
    ];

    public const AWARENESS_OPTIONS = [
        ['key' => 'use', 'label' => 'Yes, and I use it'],
        ['key' => 'know', 'label' => "Yes, but I don't use it"],
        ['key' => 'no', 'label' => "No, I didn't know"],
        ['key' => 'dont_understand', 'label' => "Don't understand"],
    ];

    /** The wording of the three open questions: one topic, neutral words, a true way out. */
    public const OPEN_PRESETS = [
        'problems' => [
            'question' => 'Think about the last time you used Freegle. What, if anything, got in your way?',
            'body' => 'Tell us the main thing in a sentence or two.',
            'nothing' => 'Nothing got in my way',
        ],
        'values' => [
            'question' => 'Think of a time Freegle worked really well for you. What made it good?',
            'body' => 'A sentence or two is plenty.',
            'nothing' => "Can't think of one",
        ],
        'improve' => [
            'question' => 'If you could change one thing about Freegle, what would it be? What would that help you do?',
            'body' => 'A sentence or two is plenty.',
            'nothing' => 'Nothing comes to mind',
        ],
    ];

    /** The follow-up ranking asks about this many problems at most. */
    public const FOLLOWUP_PROBLEMS = 7;

    /** A theme raised by fewer members than this is not put in front of other members. */
    public const FOLLOWUP_MIN_MENTIONS = 3;

    /** Most answers an open prompt collects. */
    public const OPEN_MAX = 300;

    /** The words of a plain survey's two picks. */
    public const DEFAULT_BEST = 'Works best for you';

    public const DEFAULT_WORST = 'Most needs improving';

    /** One open survey at a time: opening another closes the old one. */
    public function openSurvey(int $max = 200): int
    {
        $now = Carbon::now('UTC');
        DB::table('ux_prompts')->where('kind', 'survey')->where('status', 'open')
            ->whereNull('settings->followup_of')
            ->update(['status' => 'closed', 'updated_at' => $now]);

        return DB::table('ux_prompts')->insertGetId([
            'kind' => 'survey',
            'flow' => null,
            'question' => 'Which part of Freegle works best for you, and which most needs improving?',
            'body' => 'Think about the last few times you used Freegle. Pick the part that works best and the part that most needs work.',
            'options' => json_encode(self::SURVEY_AREAS),
            'images' => null,
            'settings' => json_encode(['best_label' => self::DEFAULT_BEST, 'worst_label' => self::DEFAULT_WORST, 'randomise' => true]),
            'status' => 'open',
            'max_responses' => max(10, $max),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function openAwareness(string $flow, string $question, string $body, ?string $image = null, int $max = 200): int
    {
        if (trim($question) === '' || trim($body) === '') {
            throw new \InvalidArgumentException('An awareness prompt needs a question and a body.');
        }
        if ($flow !== '' && ! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $flow)) {
            throw new \InvalidArgumentException('flow must be a short token.');
        }
        $now = Carbon::now('UTC');

        return DB::table('ux_prompts')->insertGetId([
            'kind' => 'awareness',
            'flow' => $flow ?: null,
            'question' => mb_substr(trim($question), 0, 255),
            'body' => trim($body),
            'options' => json_encode(self::AWARENESS_OPTIONS),
            'images' => $image ? json_encode([['url' => $image, 'caption' => 'Where it is']]) : null,
            'status' => 'open',
            'max_responses' => max(10, $max),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Open a free-text question: preset is problems, values or improve. Members answer in their
     * own words or pick a way out (nothing, or don't understand). One open prompt per preset at a
     * time, and no more than OPEN_MAX answers.
     */
    public function openOpen(string $preset, int $max = self::OPEN_MAX): int
    {
        $p = self::OPEN_PRESETS[$preset] ?? null;
        if ($p === null) {
            throw new \InvalidArgumentException('preset must be one of '.implode(', ', array_keys(self::OPEN_PRESETS)));
        }
        $now = Carbon::now('UTC');
        DB::table('ux_prompts')->where('kind', 'open')->where('status', 'open')
            ->where('settings->preset', $preset)
            ->update(['status' => 'closed', 'updated_at' => $now]);

        return DB::table('ux_prompts')->insertGetId([
            'kind' => 'open',
            'flow' => null,
            'question' => $p['question'],
            'body' => $p['body'],
            'options' => json_encode([['key' => 'nothing', 'label' => $p['nothing']], ['key' => 'dont_understand', 'label' => "Don't understand"]]),
            'images' => null,
            'settings' => json_encode(['preset' => $preset, 'randomise' => false]),
            'status' => 'open',
            'max_responses' => min(self::OPEN_MAX, max(10, $max)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function close(int $id): void
    {
        $n = DB::table('ux_prompts')->where('id', $id)->update(['status' => 'closed', 'updated_at' => Carbon::now('UTC')]);
        if (! $n) {
            throw new \RuntimeException("No prompt $id");
        }
    }

    /**
     * Prompts that have collected their quota are closed, so a question is never asked more
     * than it was meant to be. Called from the hourly tick. A skip is not an answer and does not
     * count towards the quota.
     */
    public function closeFull(): int
    {
        $closed = 0;
        foreach (DB::table('ux_prompts')->where('status', 'open')->get() as $p) {
            $n = $this->answers($p->id)->count();
            if ($n >= $p->max_responses) {
                $this->close($p->id);
                $closed++;
            }
        }

        return $closed;
    }

    // ------------------------------------------------------------------ analysing open answers

    /**
     * Have the helper code an open prompt's answers into themes and file them.
     *
     * Only the answer id and its text (scrubbed of emails, phone numbers and postcodes) go out,
     * never a user id. The helper codes them with no tools. Whatever comes back is checked here:
     * every answer id must be one that was sent, a theme with none is dropped, titles are cut to
     * 100 characters, and a flow that is not a real funnel becomes "not tied to one flow".
     *
     * Problems become ux_problems rows (kind problem), values ux_problems rows (kind value), and
     * members' own suggestions ux_ideas rows (source member) tied to the problem they answer. A
     * prompt analysed once is refused again unless $force, which replaces its themes.
     *
     * @return array{problems: int, values: int, solutions: int, unclear: int, answers: int}
     *
     * @throws \RuntimeException
     */
    public function analyse(int $promptId, bool $force = false): array
    {
        $prompt = DB::table('ux_prompts')->where('id', $promptId)->first();
        if (! $prompt || $prompt->kind !== 'open') {
            throw new \RuntimeException("No open prompt $promptId");
        }
        if (! $force && DB::table('ux_problems')->where('prompt_id', $promptId)->exists()) {
            throw new \RuntimeException("Prompt $promptId has been analysed already; use --force to replace its themes");
        }

        $answers = [];
        foreach ($this->answers($promptId)->get() as $r) {
            $text = trim((string) (json_decode($r->answer, true)['text'] ?? ''));
            if ($text !== '') {
                $answers[$r->id] = UxScrub::text($text, 1000);
            }
        }
        if (! $answers) {
            throw new \RuntimeException("Prompt $promptId has no written answers to analyse");
        }

        $cfg = (array) config('freegle.ux.ideas', []);
        $secret = (string) ($cfg['internal_secret'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException('UX_INTERNAL_SECRET is not set, so the helper would refuse the request');
        }
        $preset = json_decode($prompt->settings ?? '', true)['preset'] ?? 'problems';
        $body = ['question' => $prompt->question, 'preset' => $preset,
            'answers' => array_map(fn ($id, $t) => ['id' => $id, 'text' => $t], array_keys($answers), array_values($answers))];

        $timeout = (int) ($cfg['themes_timeout'] ?? 660);
        $url = rtrim((string) ($cfg['helper_url'] ?? 'http://ai-support-helper:3000'), '/').'/internal/ux-themes';
        $response = null;
        for ($attempt = 1; $response === null; $attempt++) {
            $began = microtime(true);
            try {
                $response = Http::withHeaders(['X-Freegle-Internal' => '1', 'X-Freegle-Internal-Secret' => $secret])
                    ->timeout($timeout)->acceptJson()->asJson()->post($url, $body);
            } catch (ConnectionException $e) {
                // A connection that was cut (the helper restarting, a proxy resetting it) is tried
                // once more; one that ran out the whole wait is not, because the helper is stuck
                // and a second wait would only double the time lost.
                if ($attempt >= 2 || microtime(true) - $began >= $timeout - 5) {
                    throw new \RuntimeException('UX themes helper could not be reached: '.$e->getMessage(), 0, $e);
                }
            }
        }
        if (! $response->successful()) {
            throw new \RuntimeException('UX themes helper answered '.$response->status().': '.mb_substr((string) $response->body(), 0, 300));
        }
        $themes = $response->json();
        if (! is_array($themes) || ! isset($themes['problems']) || ! is_array($themes['problems'])) {
            throw new \RuntimeException('UX themes helper answered with no problems list');
        }

        return $this->fileThemes($promptId, $themes, array_keys($answers), $force);
    }

    /**
     * @param  array<string, mixed>  $themes
     * @param  int[]  $sentIds
     */
    private function fileThemes(int $promptId, array $themes, array $sentIds, bool $replace): array
    {
        $sent = array_flip($sentIds);
        $ids = function ($raw) use ($sent): array {
            return array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : []), fn ($i) => isset($sent[$i]))));
        };
        $title = fn ($t) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $t) ?? ''), 0, 100);
        $now = Carbon::now('UTC');
        $counts = ['problems' => 0, 'values' => 0, 'solutions' => 0, 'unclear' => count($ids($themes['unclear_ids'] ?? [])), 'answers' => count($sentIds)];

        DB::transaction(function () use ($promptId, $themes, $ids, $title, $now, $replace, &$counts) {
            if ($replace) {
                $old = DB::table('ux_problems')->where('prompt_id', $promptId)->pluck('id')->all();
                if ($old) {
                    DB::table('ux_ideas')->whereIn('problem_id', $old)->where('source', 'member')->where('status', 'open')->delete();
                    DB::table('ux_problems')->where('prompt_id', $promptId)->delete();
                }
            }

            $problemIds = [];
            foreach (array_values($themes['problems']) as $i => $p) {
                $answerIds = $ids($p['answer_ids'] ?? []);
                $t = $title($p['title'] ?? '');
                if (! is_array($p) || $t === '' || ! $answerIds) {
                    continue;
                }
                $flow = $p['flow'] ?? null;
                $problemIds[$i] = DB::table('ux_problems')->insertGetId([
                    'flow' => is_string($flow) && in_array($flow, UxRollupService::FLOWS, true) ? $flow : null,
                    'kind' => 'problem',
                    'title' => $t,
                    'description' => mb_substr(trim((string) ($p['description'] ?? '')), 0, 1000) ?: null,
                    'source' => 'members',
                    'prompt_id' => $promptId,
                    'mentions' => count($answerIds),
                    'evidence' => json_encode(['answer_ids' => $answerIds]),
                    'status' => 'open',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $counts['problems']++;
            }

            foreach ($themes['values'] ?? [] as $v) {
                $answerIds = $ids($v['answer_ids'] ?? []);
                $t = $title($v['title'] ?? '');
                if (! is_array($v) || $t === '' || ! $answerIds) {
                    continue;
                }
                DB::table('ux_problems')->insert([
                    'flow' => null, 'kind' => 'value', 'title' => $t, 'source' => 'members', 'prompt_id' => $promptId,
                    'mentions' => count($answerIds), 'evidence' => json_encode(['answer_ids' => $answerIds]),
                    'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $counts['values']++;
            }

            foreach ($themes['solutions'] ?? [] as $sol) {
                $answerIds = $ids($sol['answer_ids'] ?? []);
                $t = $title($sol['title'] ?? '');
                if (! is_array($sol) || $t === '' || ! $answerIds) {
                    continue;
                }
                $problemId = $problemIds[(int) ($sol['problem_index'] ?? -1)] ?? null;
                // A suggestion is filed under the funnel of the problem behind it; one for a problem
                // that belongs to no funnel is filed as general.
                $flow = $problemId ? (DB::table('ux_problems')->where('id', $problemId)->value('flow') ?: 'general') : 'general';
                DB::table('ux_ideas')->insert([
                    'flow' => $flow,
                    'title' => $t,
                    'rationale' => count($answerIds).' member'.(count($answerIds) === 1 ? '' : 's').' suggested this in their own words.',
                    'expected' => '',
                    'evidence' => json_encode(['answer_ids' => $answerIds, 'source' => 'members']),
                    'score' => 0,
                    'status' => 'open',
                    'source' => 'member',
                    'problem_id' => $problemId,
                    'type' => 'experiment',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $counts['solutions']++;
            }
        });

        return $counts;
    }

    // ------------------------------------------------------------------ the follow-up ranking

    /**
     * Open a best / worst survey over the most-mentioned problems found in an open prompt's
     * answers: "gets in my way most" and "bothers me least". The options are keyed
     * problem:<id>, which is how the tick finds them to weigh the problems. One follow-up per
     * analysed prompt at a time; an older one for the same prompt is closed.
     *
     * @throws \RuntimeException when there is nothing to rank, or the titles have not been confirmed
     *
     * This is the one place member words reach other members: a problem's title is written by the
     * coder from what members said, and names are not scrubbed from it. So only themes several
     * members raised are used (a single member's complaint about a named person never gets here),
     * and the titles are shown to the person running the command, who must confirm them.
     */
    public function followup(int $promptId, int $max = 200, bool $confirmed = false): int
    {
        $problems = DB::table('ux_problems')->where('prompt_id', $promptId)->where('kind', 'problem')->where('status', 'open')
            ->where('mentions', '>=', self::FOLLOWUP_MIN_MENTIONS)
            ->orderByDesc('mentions')->orderBy('id')->limit(self::FOLLOWUP_PROBLEMS)->get();
        if ($problems->count() < 2) {
            throw new \RuntimeException("Prompt $promptId has fewer than two problems raised by at least ".self::FOLLOWUP_MIN_MENTIONS.' members to rank; run ux:prompt analyse first');
        }
        if (! $confirmed) {
            throw new \RuntimeException("These titles would be shown to up to $max other members. Check that none names a person, a place or an account, then run again with --confirm:\n"
                .$problems->map(fn ($p) => "  [{$p->id}] ({$p->mentions} members) {$p->title}")->implode("\n"));
        }
        $now = Carbon::now('UTC');
        DB::table('ux_prompts')->where('kind', 'survey')->where('status', 'open')
            ->where('settings->followup_of', $promptId)
            ->update(['status' => 'closed', 'updated_at' => $now]);

        return DB::table('ux_prompts')->insertGetId([
            'kind' => 'survey',
            'flow' => null,
            'question' => 'Which of these gets in your way most, and which bothers you least?',
            'body' => 'Think about the last few months of using Freegle. Pick the one that gets in your way most and the one that bothers you least.',
            'options' => json_encode($problems->map(fn ($p) => ['key' => 'problem:'.$p->id, 'label' => $p->title])->all()),
            'images' => null,
            'settings' => json_encode(['best_label' => 'Gets in my way most', 'worst_label' => 'Bothers me least', 'randomise' => true, 'followup_of' => $promptId]),
            'status' => 'open',
            'max_responses' => max(10, $max),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Turn the follow-up rankings into a weight on each problem: (times picked as getting in the
     * way most - times picked as bothering least) / answers. An answer is a best / worst pick or
     * "none of these bother me"; a skip or "don't understand" is not one. Called from the tick,
     * so the weights follow the answers as they come in. Returns how many problems were updated.
     */
    public function weighFollowups(): int
    {
        $updated = 0;
        $now = Carbon::now('UTC');
        foreach (DB::table('ux_prompts')->where('kind', 'survey')->whereNotNull('settings->followup_of')->get() as $prompt) {
            $most = [];
            $least = [];
            $answered = 0;
            foreach ($this->answers($prompt->id)->get() as $r) {
                $a = json_decode($r->answer, true) ?: [];
                $picked = isset($a['best']) || isset($a['worst']) || ($a['choice'] ?? null) === 'dont_care';
                if (! $picked) {
                    continue;
                }
                $answered++;
                if (isset($a['best'])) {
                    $most[$a['best']] = ($most[$a['best']] ?? 0) + 1;
                }
                if (isset($a['worst'])) {
                    $least[$a['worst']] = ($least[$a['worst']] ?? 0) + 1;
                }
            }
            if ($answered === 0) {
                continue;
            }
            foreach (json_decode($prompt->options, true) ?: [] as $o) {
                if (! preg_match('/^problem:(\d+)$/', (string) $o['key'], $m)) {
                    continue;
                }
                $weight = round((($most[$o['key']] ?? 0) - ($least[$o['key']] ?? 0)) / $answered, 3);
                $updated += DB::table('ux_problems')->where('id', (int) $m[1])->update(['weight' => $weight, 'updated_at' => $now]);
            }
        }

        return $updated;
    }

    // ------------------------------------------------------------------ privacy

    /**
     * Blank member words older than $days, whatever the prompt's state: the free text of an open
     * question's answer, and the comment on any kind of answer (they are shown in ModTools and can
     * carry names the same way). Counts and picks stay. The clock is each answer's own timestamp,
     * so a prompt that never closes (an open question that did not fill) is blanked too, and a
     * later touch of the prompt row does not restart it. Returns how many answers were changed.
     */
    public function blankOldAnswers(?Carbon $now = null, int $days = 90): int
    {
        $now = $now ?: Carbon::now('UTC');
        $blanked = 0;
        DB::table('ux_prompt_responses')->where('timestamp', '<', $now->copy()->subDays($days))
            ->whereRaw("(comment IS NOT NULL OR JSON_TYPE(JSON_EXTRACT(answer, '$.text')) = 'STRING')")
            ->orderBy('id')->chunkById(500, function ($rows) use (&$blanked) {
                foreach ($rows as $r) {
                    $update = ['comment' => null];
                    $a = json_decode($r->answer, true) ?: [];
                    if (array_key_exists('text', $a) && $a['text'] !== null) {
                        $update['answer'] = json_encode(['text' => null, 'removed' => true]);
                    }
                    DB::table('ux_prompt_responses')->where('id', $r->id)->update($update);
                    $blanked++;
                }
            });

        return $blanked;
    }

    // ------------------------------------------------------------------ helpers

    /** The answers that count: a skip is a record that the prompt was seen, not an answer. */
    private function answers(int $promptId)
    {
        return DB::table('ux_prompt_responses')->where('prompt_id', $promptId)
            ->whereRaw("JSON_EXTRACT(answer, '$.skipped') IS NULL");
    }
}
