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
 * Idea farming keeps problems and solutions apart. An OPEN prompt asks members what problems they
 * have, what they value or what they would change, in their own words. analyse() has a model with
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
        ['key' => 'chat_promise', 'label' => 'Arranging collection in chat'],
        ['key' => 'chat_outcome', 'label' => 'Marking a post as taken or received'],
        ['key' => 'login', 'label' => 'Signing in or joining'],
        ['key' => 'settings', 'label' => 'Settings and email choices'],
    ];

    /**
     * An awareness question is a plain yes / no / not sure about whether Freegle can do something.
     * "Did you know" is not used: it leads, teaches, and costs nothing to agree with.
     */
    public const AWARENESS_OPTIONS = [
        ['key' => 'yes', 'label' => 'Yes'],
        ['key' => 'no', 'label' => 'No'],
        ['key' => 'not_sure', 'label' => 'Not sure'],
    ];

    /** An awareness question's link is a path on our own site, never an outside address. */
    private const LINK_PATTERN = '#^/(?!/)[A-Za-z0-9\-._~/%?=&:+]{0,198}$#D';

    /** The follow-up ranking asks the four-at-a-time way, and the area survey does too. */
    public const SHOW_AT_A_TIME = 4;

    /** The wording of the three open questions: one topic, neutral words, a true way out. */
    public const OPEN_PRESETS = [
        'problems' => [
            'question' => 'Think about the last time you used Freegle. Did anything cause you a problem?',
            'body' => 'If it did, tell us the main one in a sentence or two.',
            'nothing' => 'No problems',
        ],
        'values' => [
            'question' => 'Think of a time Freegle worked really well for you. What made it good?',
            'body' => 'One or two sentences is enough.',
            'nothing' => "Can't think of one",
        ],
        'improve' => [
            'question' => 'What one thing would you change about Freegle? What would that help you do?',
            'body' => 'One or two sentences is enough.',
            'nothing' => "I can't think of anything",
        ],
    ];

    /** The follow-up ranking asks about this many problems at most, the ones most members raised. */
    public const FOLLOWUP_PROBLEMS = 8;

    /**
     * Below about this many analysed answers the next answer still often adds a theme, so the
     * themes are a list of what people said and are not ranked.
     */
    public const FOLLOWUP_MIN_ANSWERS = 100;

    /**
     * The title the coder gives to what fits no other theme. It is a residue, not a problem a
     * member can recognise, so it is not ranked and not given to the idea generator as one.
     */
    public const CATCH_ALL_THEME = 'Other';

    /** A theme is kept only when at least this many members raised it; one voice is an anecdote. */
    public const THEME_MIN_RESPONDENTS = 2;

    /** Most checked quotes kept per theme, and the longest one. */
    public const THEME_MAX_QUOTES = 8;

    public const QUOTE_MAX_CHARS = 300;

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
            'body' => 'Think about the last few times you used Freegle. Pick the part that works best. You can also pick the one that most needs work.',
            'options' => json_encode(self::SURVEY_AREAS),
            'images' => null,
            'settings' => json_encode(['show' => self::SHOW_AT_A_TIME, 'best_label' => self::DEFAULT_BEST, 'worst_label' => self::DEFAULT_WORST.' (optional)', 'none_label' => 'None of these', 'randomise' => true]),
            'status' => 'open',
            'max_responses' => max(10, $max),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Open an awareness question: "Do you know how to <action>?", answered yes, no or not sure.
     * It is a question, not a tip, so the usual one has no reveal. A reveal (one or two sentences,
     * and an optional link to the feature) is shown after the answer when there is one. A decoy
     * asks about something Freegle does not do, so its right answer is no; the share of members
     * who say yes to a decoy is how readily this sample says yes to anything, and is shown next to
     * the real questions so a reader can discount them. About one question in five should be a
     * decoy. A decoy must have a reveal: "Do you know how to ..." suggests the thing exists, so the
     * member has to be told afterwards that it does not.
     *
     * @throws \InvalidArgumentException
     */
    public function openAwareness(string $flow, string $action, ?string $reveal = null, ?string $link = null, bool $decoy = false, int $max = 200): int
    {
        $action = trim(preg_replace('/\s+/u', ' ', $action) ?? '');
        $action = rtrim($action, " ?\t");
        $reveal = trim(preg_replace('/\s+/u', ' ', (string) $reveal) ?? '');
        if ($action === '' || mb_strlen($action) > 180) {
            throw new \InvalidArgumentException('An awareness prompt needs an action in plain words (up to 180 characters), such as "promise an item to someone in a chat".');
        }
        if (mb_strlen($reveal) > 500) {
            throw new \InvalidArgumentException('An awareness reveal is one or two sentences (up to 500 characters), shown after the answer.');
        }
        if ($decoy && $reveal === '') {
            throw new \InvalidArgumentException('A decoy needs a reveal: "Do you know how to ..." suggests Freegle does this, so the member must be told afterwards that it does not.');
        }
        if ($flow !== '' && ! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $flow)) {
            throw new \InvalidArgumentException('flow must be a short token.');
        }
        $link = $link === null ? null : trim($link);
        if ($link === '') {
            $link = null;
        }
        if ($link !== null && ! preg_match(self::LINK_PATTERN, $link)) {
            throw new \InvalidArgumentException('link must be a path on this site, starting with a single /, such as /settings.');
        }
        if ($link !== null && $reveal === '') {
            // The link is shown with the reveal, so one without the other would be stored and never seen.
            throw new \InvalidArgumentException('A link is shown with the reveal after the answer, so give a reveal too or leave the link out.');
        }
        $now = Carbon::now('UTC');

        return DB::table('ux_prompts')->insertGetId([
            'kind' => 'awareness',
            'flow' => $flow ?: null,
            'question' => mb_substr('Do you know how to '.$action.'?', 0, 255),
            'body' => "Answer from what you know. It's fine to say Not sure.",
            'options' => json_encode(self::AWARENESS_OPTIONS),
            'images' => null,
            'settings' => json_encode($reveal === '' ? ['link' => $link, 'decoy' => $decoy] : ['reveal' => $reveal, 'link' => $link, 'decoy' => $decoy]),
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

        return $this->fileThemes($promptId, $themes, $answers, $force);
    }

    /**
     * @param  array<string, mixed>  $themes
     * @param  array<int, string>  $texts  the scrubbed text of each answer that was sent, by answer id
     */
    private function fileThemes(int $promptId, array $themes, array $texts, bool $replace): array
    {
        $sentIds = array_keys($texts);
        $sent = array_flip($sentIds);
        $normalised = [];
        $ids = function ($raw) use ($sent): array {
            return array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : []), fn ($i) => isset($sent[$i]))));
        };
        // The helper checks each quote against the answer it names; it is checked again here, so
        // a theme can never carry words nobody wrote. Case and runs of white space are ignored.
        $norm = fn ($t) => trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $t)) ?? '');
        $quotes = function ($raw, array $answerIds) use ($texts, &$normalised, $norm): array {
            $out = [];
            $seen = [];
            foreach (is_array($raw) ? $raw : [] as $q) {
                $aid = is_array($q) ? (int) ($q['answer_id'] ?? 0) : 0;
                $span = is_array($q) ? trim((string) ($q['span'] ?? '')) : '';
                if ($span === '' || ! in_array($aid, $answerIds, true) || isset($seen[$aid.'|'.$span])) {
                    continue;
                }
                $normalised[$aid] ??= $norm($texts[$aid]);
                $needle = $norm($span);
                if ($needle === '' || ! str_contains($normalised[$aid], $needle)) {
                    continue;
                }
                $seen[$aid.'|'.$span] = true;
                $out[] = ['answer_id' => $aid, 'span' => mb_substr($span, 0, self::QUOTE_MAX_CHARS)];
                if (count($out) >= self::THEME_MAX_QUOTES) {
                    break;
                }
            }

            return $out;
        };
        $title = fn ($t) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $t) ?? ''), 0, 100);
        $now = Carbon::now('UTC');
        $counts = ['problems' => 0, 'values' => 0, 'solutions' => 0, 'unclear' => count($ids($themes['unclear_ids'] ?? [])), 'answers' => count($sentIds)];

        DB::transaction(function () use ($promptId, $themes, $ids, $quotes, $title, $now, $replace, &$counts) {
            if ($replace) {
                $old = DB::table('ux_problems')->where('prompt_id', $promptId)->pluck('id')->all();
                if ($old) {
                    DB::table('ux_ideas')->whereIn('problem_id', $old)->where('source', 'member')->where('status', 'open')->delete();
                    DB::table('ux_problems')->where('prompt_id', $promptId)->delete();
                }
            }

            // A theme counts respondents, not statements: its distinct answer ids. It is kept when
            // at least THEME_MIN_RESPONDENTS members raised it and at least one quote is verified.
            $theme = function ($t) use ($ids, $quotes, $title): ?array {
                if (! is_array($t)) {
                    return null;
                }
                $answerIds = $ids($t['answer_ids'] ?? []);
                $name = $title($t['title'] ?? '');
                $checked = $quotes($t['quotes'] ?? [], $answerIds);
                if ($name === '' || count($answerIds) < self::THEME_MIN_RESPONDENTS || ! $checked) {
                    return null;
                }

                return ['title' => $name, 'answer_ids' => $answerIds, 'quotes' => $checked, 'src' => $t];
            };

            // The themes are drawn from exactly these answers. Answers that arrive later are not in
            // them, so the follow-up gate and the "of N who answered" base use this number and not
            // a fresh count.
            $settings = json_decode((string) DB::table('ux_prompts')->where('id', $promptId)->value('settings'), true) ?: [];
            $settings['analysed_answers'] = $counts['answers'];
            DB::table('ux_prompts')->where('id', $promptId)->update(['settings' => json_encode($settings), 'updated_at' => $now]);

            $problemIds = [];
            foreach (array_values($themes['problems']) as $i => $p) {
                $t = $theme($p);
                if ($t === null) {
                    continue;
                }
                $flow = $p['flow'] ?? null;
                $problemIds[$i] = DB::table('ux_problems')->insertGetId([
                    'flow' => is_string($flow) && in_array($flow, UxRollupService::FLOWS, true) ? $flow : null,
                    'kind' => 'problem',
                    'title' => $t['title'],
                    'description' => mb_substr(trim((string) ($p['description'] ?? '')), 0, 1000) ?: null,
                    'source' => 'members',
                    'prompt_id' => $promptId,
                    'mentions' => count($t['answer_ids']),
                    'evidence' => json_encode(['answer_ids' => $t['answer_ids'], 'quotes' => $t['quotes']]),
                    'status' => 'open',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $counts['problems']++;
            }

            foreach ($themes['values'] ?? [] as $v) {
                $t = $theme($v);
                if ($t === null) {
                    continue;
                }
                DB::table('ux_problems')->insert([
                    'flow' => null, 'kind' => 'value', 'title' => $t['title'], 'source' => 'members', 'prompt_id' => $promptId,
                    'mentions' => count($t['answer_ids']), 'evidence' => json_encode(['answer_ids' => $t['answer_ids'], 'quotes' => $t['quotes']]),
                    'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $counts['values']++;
            }

            // A member's suggestion is an idea to be weighed against the problem behind it, not a
            // theme to be counted, so one member is enough and it needs no quote.
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
     * Open a "which is the biggest problem for you" survey over the problems most members raised in an
     * open prompt's answers, shown four at a time. The options are keyed problem:<id>, which is
     * how the tick finds them to weigh the problems. One follow-up per analysed prompt at a time;
     * an older one for the same prompt is closed.
     *
     * Refused below FOLLOWUP_MIN_ANSWERS analysed answers: before that the next answer still often
     * adds a theme, so ranking would rank an unfinished list. The count is the one stored when
     * the prompt was analysed, because the themes come from those answers; answers that arrived
     * since do not count until it is analysed again.
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
        $settings = json_decode((string) DB::table('ux_prompts')->where('id', $promptId)->value('settings'), true) ?: [];
        $written = (int) ($settings['analysed_answers'] ?? 0);
        if ($written < self::FOLLOWUP_MIN_ANSWERS) {
            throw new \RuntimeException("Prompt $promptId was analysed over $written written answers. Below about ".self::FOLLOWUP_MIN_ANSWERS
                .' answers the next answer still often adds a theme, so the problems are not ranked yet; wait for more answers and analyse again (with --force).');
        }
        $problems = DB::table('ux_problems')->where('prompt_id', $promptId)->where('kind', 'problem')->where('status', 'open')
            ->where('title', '!=', self::CATCH_ALL_THEME)
            ->where('mentions', '>=', self::FOLLOWUP_MIN_MENTIONS)
            ->orderByDesc('mentions')->orderBy('id')->limit(self::FOLLOWUP_PROBLEMS)->get();
        if ($problems->count() < 2) {
            throw new \RuntimeException("Prompt $promptId has fewer than two problems raised by at least ".self::FOLLOWUP_MIN_MENTIONS.' members to rank; run ux:prompt analyse first');
        }
        if (! $confirmed) {
            throw new \RuntimeException("These titles would be shown to up to $max other members. Check that none names a person, a place or an account, then run again with --confirm:\n"
                .$problems->map(fn ($p) => "  [{$p->id}] ({$p->mentions} of $written who answered) {$p->title}")->implode("\n"));
        }
        $now = Carbon::now('UTC');
        DB::table('ux_prompts')->where('kind', 'survey')->where('status', 'open')
            ->where('settings->followup_of', $promptId)
            ->update(['status' => 'closed', 'updated_at' => $now]);

        return DB::table('ux_prompts')->insertGetId([
            'kind' => 'survey',
            'flow' => null,
            'question' => 'Which of these has been the biggest problem for you in the last few months?',
            'body' => 'Here are some problems people have told us about.',
            'options' => json_encode($problems->map(fn ($p) => ['key' => 'problem:'.$p->id, 'label' => $p->title])->all()),
            'images' => null,
            'settings' => json_encode([
                'show' => self::SHOW_AT_A_TIME,
                'best_label' => 'Biggest problem for me',
                'worst_label' => 'Smallest problem for me (optional)',
                'none_label' => 'None of these has been a problem for me',
                'question_hint' => 'in the last few months',
                'followup_of' => $promptId,
            ]),
            'status' => 'open',
            'max_responses' => max(10, $max),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Turn the follow-up rankings into a weight on each problem: (times picked as the biggest
     * problem - times picked as the smallest problem) / times shown. Only four of the options
     * are in front of a member at once, so the divisor is how often the option was actually shown,
     * from the `shown` list each answer carries; an answer from before `shown` existed saw every
     * option. An answer is a pick or "none of these has been a problem for me"; a skip or "don't
     * understand" is not one. The evidence keeps most, least and times_shown beside the weight, so
     * a reader can see how much it rests on. Called from the tick, so the weights follow the
     * answers as they come in. Returns how many problems were updated; one never shown is not.
     */
    public function weighFollowups(): int
    {
        $updated = 0;
        $now = Carbon::now('UTC');
        foreach (DB::table('ux_prompts')->where('kind', 'survey')->whereNotNull('settings->followup_of')->get() as $prompt) {
            $keys = array_column(json_decode($prompt->options, true) ?: [], 'key');
            $most = [];
            $least = [];
            $shownCount = [];
            foreach ($this->answers($prompt->id)->get() as $r) {
                $a = json_decode($r->answer, true) ?: [];
                $picked = isset($a['best']) || ($a['choice'] ?? null) === 'none';
                if (! $picked) {
                    continue;
                }
                $shown = isset($a['shown']) && is_array($a['shown']) ? array_values(array_unique($a['shown'])) : $keys;
                foreach ($shown as $k) {
                    $shownCount[$k] = ($shownCount[$k] ?? 0) + 1;
                }
                if (isset($a['best'])) {
                    $most[$a['best']] = ($most[$a['best']] ?? 0) + 1;
                }
                if (isset($a['worst'])) {
                    $least[$a['worst']] = ($least[$a['worst']] ?? 0) + 1;
                }
            }
            foreach ($keys as $key) {
                if (! preg_match('/^problem:(\d+)$/', (string) $key, $m) || empty($shownCount[$key])) {
                    continue;
                }
                $id = (int) $m[1];
                $mostN = $most[$key] ?? 0;
                $leastN = $least[$key] ?? 0;
                $evidence = json_decode((string) DB::table('ux_problems')->where('id', $id)->value('evidence'), true) ?: [];
                $evidence['ranking'] = ['prompt_id' => (int) $prompt->id, 'most' => $mostN, 'least' => $leastN, 'times_shown' => $shownCount[$key]];
                $updated += DB::table('ux_problems')->where('id', $id)->update([
                    'weight' => round(($mostN - $leastN) / $shownCount[$key], 3),
                    'evidence' => json_encode($evidence),
                    'updated_at' => $now,
                ]);
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
            // On a JSON path the builder's not-null test also skips a JSON null, so text already
            // blanked to null is not blanked again.
            ->where(fn ($q) => $q->whereNotNull('comment')->orWhereNotNull('answer->text'))
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

    /**
     * Drop the verbatim spans of an old answer from the themes they were filed under. The themes
     * keep a title, a count and the answer ids; the words themselves live only as long as the
     * answers do. A span goes when its answer is older than $days or no longer exists (a member
     * forgotten, an account merged away). Returns how many theme rows were changed.
     */
    public function blankOldQuotes(?Carbon $now = null, int $days = 90): int
    {
        $cutoff = ($now ?: Carbon::now('UTC'))->copy()->subDays($days);
        $changed = 0;
        DB::table('ux_problems')->where('evidence', 'like', '%"span"%')->orderBy('id')
            ->chunkById(200, function ($rows) use ($cutoff, &$changed) {
                foreach ($rows as $row) {
                    $evidence = json_decode($row->evidence, true) ?: [];
                    $quotes = $evidence['quotes'] ?? [];
                    if (! $quotes) {
                        continue;
                    }
                    $live = DB::table('ux_prompt_responses')->whereIn('id', array_unique(array_column($quotes, 'answer_id')))
                        ->where('timestamp', '>=', $cutoff)->pluck('id')->all();
                    $kept = array_values(array_filter($quotes, fn ($q) => in_array($q['answer_id'] ?? null, $live)));
                    if (count($kept) !== count($quotes)) {
                        $evidence['quotes'] = $kept;
                        DB::table('ux_problems')->where('id', $row->id)->update(['evidence' => json_encode($evidence)]);
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    /**
     * Take a member's answers out of the themes and member suggestions they fed: their spans and
     * their ids. Called before their answers are deleted (forget me), so the words do not outlive
     * the answers. Counts (`mentions`) stay: a count is not the member's words.
     *
     * @param  int[]  $answerIds
     */
    public static function forgetAnswers(array $answerIds): void
    {
        if (! $answerIds) {
            return;
        }
        $gone = fn ($id) => in_array($id, $answerIds);
        $strip = function ($rows, string $table) use ($gone) {
            foreach ($rows as $row) {
                $evidence = json_decode($row->evidence ?? '', true);
                if (! is_array($evidence)) {
                    continue;
                }
                $before = json_encode($evidence);
                if (isset($evidence['answer_ids'])) {
                    $evidence['answer_ids'] = array_values(array_filter($evidence['answer_ids'], fn ($id) => ! $gone($id)));
                }
                if (isset($evidence['quotes'])) {
                    $evidence['quotes'] = array_values(array_filter($evidence['quotes'], fn ($q) => ! $gone($q['answer_id'] ?? null)));
                }
                if (json_encode($evidence) !== $before) {
                    DB::table($table)->where('id', $row->id)->update(['evidence' => json_encode($evidence)]);
                }
            }
        };
        $prompts = DB::table('ux_prompt_responses')->whereIn('id', $answerIds)->pluck('prompt_id')->unique()->all();
        $strip(DB::table('ux_problems')->whereIn('prompt_id', $prompts)->get(['id', 'evidence']), 'ux_problems');
        // A member suggestion carries no prompt id of its own; there are few of them.
        $strip(DB::table('ux_ideas')->where('source', 'member')->get(['id', 'evidence']), 'ux_ideas');
    }

    // ------------------------------------------------------------------ helpers

    /** The answers that count: a skip is a record that the prompt was seen, not an answer. */
    private function answers(int $promptId)
    {
        return DB::table('ux_prompt_responses')->where('prompt_id', $promptId)
            ->whereNull('answer->skipped');
    }
}
