<?php

namespace App\Services\Ux;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Survey and awareness prompts for microvolunteers (an experiment's own prompt is opened by
 * UxExperimentService::openPrompt). docs/developers/reference/ux-telemetry.md section 8.
 *
 * Answers are a reaction gauge from members with a known status-quo bias. The best / worst
 * survey says where members feel the friction, so idea generation starts there; an awareness
 * question says whether a feature has been found at all, which separates "not found" from "not
 * wanted" and so tells a discoverability experiment from a retirement candidate.
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

    /** One open survey at a time: opening another closes the old one. */
    public function openSurvey(int $max = 200): int
    {
        $now = Carbon::now('UTC');
        DB::table('ux_prompts')->where('kind', 'survey')->where('status', 'open')->update(['status' => 'closed', 'updated_at' => $now]);

        return DB::table('ux_prompts')->insertGetId([
            'kind' => 'survey',
            'flow' => null,
            'question' => 'Which part of Freegle works best for you, and which most needs improving?',
            'body' => 'Think about the last few times you used Freegle. Pick the part that works best and the part that most needs work.',
            'options' => json_encode(self::SURVEY_AREAS),
            'images' => null,
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

    public function close(int $id): void
    {
        $n = DB::table('ux_prompts')->where('id', $id)->update(['status' => 'closed', 'updated_at' => Carbon::now('UTC')]);
        if (! $n) {
            throw new \RuntimeException("No prompt $id");
        }
    }

    /**
     * Prompts that have collected their quota are closed, so a question is never asked more
     * than it was meant to be. Called from the hourly tick.
     */
    public function closeFull(): int
    {
        $closed = 0;
        foreach (DB::table('ux_prompts')->where('status', 'open')->get() as $p) {
            $n = DB::table('ux_prompt_responses')->where('prompt_id', $p->id)->count();
            if ($n >= $p->max_responses) {
                $this->close($p->id);
                $closed++;
            }
        }

        return $closed;
    }
}
