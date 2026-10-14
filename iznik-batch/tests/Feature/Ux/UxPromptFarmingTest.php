<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\UxIdeasService;
use App\Services\Ux\UxPromptService;
use App\Services\Ux\UxScrub;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Idea farming from microvolunteers: the open questions, coding their answers into problems,
 * values and suggestions, the follow-up ranking and its weights, and keeping member words only
 * as long as they are wanted. docs/developers/reference/ux-telemetry.md section 8.
 */
class UxPromptFarmingTest extends TestCase
{
    private UxPromptService $service;

    /** @var array{status: int, body: mixed, throw: bool} */
    private array $helper;

    /** @var Request[] */
    private array $sent = [];

    private int $nextUser = 100;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        foreach (['ux_prompt_responses', 'ux_prompts', 'ux_ideas', 'ux_problems'] as $t) {
            DB::table($t)->delete();
        }
        config()->set('freegle.ux.ideas', ['helper_url' => 'http://ai-support-helper:3000', 'internal_secret' => 'test-secret', 'themes_timeout' => 30]);
        $this->service = app(UxPromptService::class);
        $this->sent = [];
        $this->helper = ['status' => 200, 'throw' => false, 'body' => ['problems' => [], 'solutions' => [], 'values' => [], 'unclear_ids' => []]];
        Http::fake(function (Request $request) {
            $this->sent[] = $request;
            if ($this->helper['throw'] || ($this->helper['throw_first'] ?? 0) > 0) {
                $this->helper['throw_first'] = max(0, ($this->helper['throw_first'] ?? 0) - 1);
                throw new ConnectionException('connection refused');
            }

            return Http::response($this->helper['body'], $this->helper['status']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return int[] the response ids, in order */
    private function answer(int $prompt, array $texts): array
    {
        $ids = [];
        foreach ($texts as $text) {
            $ids[] = DB::table('ux_prompt_responses')->insertGetId([
                'prompt_id' => $prompt, 'userid' => $this->nextUser++,
                'answer' => json_encode(is_array($text) ? $text : ['text' => $text]),
            ]);
        }

        return $ids;
    }

    // ---------------------------------------------------------------- opening

    public function test_the_three_open_presets_ask_one_neutral_question_each_with_a_way_out(): void
    {
        $problems = DB::table('ux_prompts')->find($this->service->openOpen('problems'));
        $values = DB::table('ux_prompts')->find($this->service->openOpen('values'));
        $improve = DB::table('ux_prompts')->find($this->service->openOpen('improve'));

        $this->assertSame('open', $problems->kind);
        $this->assertSame('Think about the last time you used Freegle. What, if anything, got in your way?', $problems->question);
        $this->assertSame('Think of a time Freegle worked really well for you. What made it good?', $values->question);
        $this->assertSame('If you could change one thing about Freegle, what would it be? What would that help you do?', $improve->question);
        $this->assertSame([['key' => 'nothing', 'label' => 'Nothing got in my way'], ['key' => 'dont_understand', 'label' => "Don't understand"]], json_decode($problems->options, true));
        $this->assertSame('Can\'t think of one', json_decode($values->options, true)[0]['label']);
        $this->assertSame('Nothing comes to mind', json_decode($improve->options, true)[0]['label']);
        $this->assertSame('problems', json_decode($problems->settings, true)['preset']);
        $this->assertSame(300, (int) $problems->max_responses);
        $this->assertSame('open', $problems->status);
    }

    public function test_an_open_prompt_never_collects_more_than_300_answers_and_an_unknown_preset_is_refused(): void
    {
        $this->assertSame(300, (int) DB::table('ux_prompts')->find($this->service->openOpen('problems', 5000))->max_responses);
        $this->assertSame(50, (int) DB::table('ux_prompts')->find($this->service->openOpen('values', 50))->max_responses);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->openOpen('opinions');
    }

    public function test_opening_a_preset_again_closes_the_older_one_of_the_same_preset_only(): void
    {
        $a = $this->service->openOpen('problems');
        $v = $this->service->openOpen('values');
        $b = $this->service->openOpen('problems');

        $this->assertSame('closed', DB::table('ux_prompts')->find($a)->status);
        $this->assertSame('open', DB::table('ux_prompts')->find($v)->status);
        $this->assertSame('open', DB::table('ux_prompts')->find($b)->status);
    }

    public function test_the_standard_survey_carries_its_labels_and_randomises(): void
    {
        $survey = DB::table('ux_prompts')->find($this->service->openSurvey());

        $this->assertEquals(['show' => 4, 'best_label' => 'Works best for you', 'worst_label' => 'Most needs improving (optional)', 'none_label' => 'None of these', 'randomise' => true],
            json_decode($survey->settings, true));
    }

    public function test_an_awareness_question_asks_whether_it_is_possible_and_never_did_you_know(): void
    {
        $id = $this->service->openAwareness('chat_promise', 'promise an item to someone in a chat?', '  In a chat about your offer,   Promise marks it for that person. ', '/chats');
        $p = DB::table('ux_prompts')->find($id);

        $this->assertSame('awareness', $p->kind);
        $this->assertSame('chat_promise', $p->flow);
        $this->assertSame('On Freegle, is it possible to promise an item to someone in a chat?', $p->question);
        $this->assertStringNotContainsString('Did you know', $p->question.$p->body);
        $this->assertSame([['key' => 'yes', 'label' => 'Yes'], ['key' => 'no', 'label' => 'No'], ['key' => 'not_sure', 'label' => 'Not sure']], json_decode($p->options, true));
        $this->assertEquals(['reveal' => 'In a chat about your offer, Promise marks it for that person.', 'link' => '/chats', 'decoy' => false], json_decode($p->settings, true));
        $this->assertSame('open', $p->status);
        $this->assertNull($p->images, 'a picture would give the answer away before it is asked');
    }

    public function test_a_decoy_awareness_question_is_marked_and_a_link_must_be_a_path_on_this_site(): void
    {
        $settings = json_decode(DB::table('ux_prompts')->find($this->service->openAwareness('', 'pay a courier to collect an item', 'Freegle does not do that.', null, true))->settings, true);
        $this->assertEquals(['reveal' => 'Freegle does not do that.', 'link' => null, 'decoy' => true], $settings);

        foreach (['https://example.com/x', '//example.com/x', 'settings', '/a b', "/ok\n/x"] as $bad) {
            $this->assertRefusedInvalid(fn () => $this->service->openAwareness('', 'do a thing', 'A reveal.', $bad), 'link');
        }
        $this->assertRefusedInvalid(fn () => $this->service->openAwareness('', ' ? ', 'A reveal.'), 'action');
        $this->assertRefusedInvalid(fn () => $this->service->openAwareness('', 'do a thing', ' '), 'reveal');
        $this->assertRefusedInvalid(fn () => $this->service->openAwareness('bad flow!', 'do a thing', 'A reveal.'), 'flow');
    }

    private function assertRefusedInvalid(callable $do, string $fragment): void
    {
        try {
            $do();
            $this->fail("expected an invalid-argument refusal containing '$fragment'");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    public function test_the_command_opens_an_open_prompt(): void
    {
        $this->artisan('ux:prompt open --preset=values')->expectsOutputToContain('Opened prompt')->assertExitCode(0);
        $this->assertSame(1, DB::table('ux_prompts')->where('kind', 'open')->count());
        $this->artisan('ux:prompt open --preset=nonsense')->assertExitCode(1);
        $this->artisan('ux:prompt open')->assertExitCode(1);
    }

    public function test_a_skip_does_not_count_towards_a_prompts_quota(): void
    {
        $id = $this->service->openOpen('problems', 10);
        $this->answer($id, array_fill(0, 9, 'something'));
        $this->answer($id, [['skipped' => true]]);

        $this->assertSame(0, $this->service->closeFull());

        $this->answer($id, ['tenth']);
        $this->assertSame(1, $this->service->closeFull());
    }

    // ---------------------------------------------------------------- analysing

    public function test_analysing_sends_only_scrubbed_text_with_the_secret_and_files_the_themes(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b, $c, $d, $skip] = $this->answer($id, [
            'Photos will not upload, email me joe@example.com',
            'I could not find where to put my postcode SW1A 1AA',
            'Photos fail on my phone. Please add a retry button.',
            ['choice' => 'nothing'],
            ['skipped' => true],
        ]);
        $this->helper['body'] = [
            'problems' => [
                ['title' => 'Photos will not upload', 'description' => 'Upload fails on phones', 'flow' => 'give', 'answer_ids' => [$a, $c, 999999], 'quotes' => [
                    ['answer_id' => $a, 'span' => 'Photos will not upload'],
                    ['answer_id' => $c, 'span' => 'photos   FAIL on my phone'],
                    ['answer_id' => $c, 'span' => 'an invented sentence'],
                    ['answer_id' => $b, 'span' => 'put my postcode'],
                    ['answer_id' => $a, 'span' => 'joe@example.com'],
                ]],
                ['title' => str_repeat('A very long title ', 20), 'description' => '', 'flow' => 'nonsense', 'answer_ids' => [$b, $a, $b], 'quotes' => [['answer_id' => $b, 'span' => 'find where to put']]],
                ['title' => 'No answers behind this', 'description' => 'x', 'flow' => 'give', 'answer_ids' => [424242], 'quotes' => [['answer_id' => 424242, 'span' => 'x']]],
                ['title' => '', 'description' => 'x', 'flow' => null, 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'Photos']]],
                ['title' => 'Only one member said this', 'description' => '', 'flow' => 'give', 'answer_ids' => [$a], 'quotes' => [['answer_id' => $a, 'span' => 'Photos']]],
                ['title' => 'Quotes that are not in the answers', 'description' => '', 'flow' => 'give', 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'nobody wrote this']]],
                ['title' => 'No quotes at all', 'description' => '', 'flow' => 'give', 'answer_ids' => [$a, $b]],
            ],
            'solutions' => [
                ['title' => 'Retry failed photo uploads', 'problem_index' => 0, 'answer_ids' => [$c]],
                ['title' => 'A general wish', 'problem_index' => 1, 'answer_ids' => [$b]],
                ['title' => 'Points at a dropped problem', 'problem_index' => 2, 'answer_ids' => [$b]],
                ['title' => 'Nobody said this', 'problem_index' => 0, 'answer_ids' => []],
            ],
            'values' => [
                ['title' => 'Giving to neighbours', 'answer_ids' => [$a, $c], 'quotes' => [['answer_id' => $c, 'span' => 'add a retry']]],
                ['title' => 'Empty value', 'answer_ids' => [], 'quotes' => []],
                ['title' => 'One voice', 'answer_ids' => [$a], 'quotes' => [['answer_id' => $a, 'span' => 'Photos']]],
            ],
            'unclear_ids' => [$b, 12345, $d],
        ];

        $result = $this->service->analyse($id);

        $this->assertSame(['problems' => 2, 'values' => 1, 'solutions' => 3, 'unclear' => 1, 'answers' => 3], $result);

        // What went out: ids and scrubbed text, the secret, the question, and no user ids.
        $this->assertCount(1, $this->sent);
        $request = $this->sent[0];
        $this->assertSame('http://ai-support-helper:3000/internal/ux-themes', $request->url());
        $this->assertSame(['test-secret'], $request->header('X-Freegle-Internal-Secret'));
        $body = $request->data();
        $this->assertSame('problems', $body['preset']);
        $this->assertSame('Think about the last time you used Freegle. What, if anything, got in your way?', $body['question']);
        $this->assertSame([$a, $b, $c], array_column($body['answers'], 'id'));
        $this->assertSame('Photos will not upload, email me [email]', $body['answers'][0]['text']);
        $this->assertSame('I could not find where to put my postcode [postcode]', $body['answers'][1]['text']);
        $this->assertStringNotContainsString('userid', json_encode($body));
        $this->assertStringNotContainsString('joe@example.com', json_encode($body));

        $problems = DB::table('ux_problems')->where('kind', 'problem')->orderBy('id')->get();
        $this->assertCount(2, $problems);
        $this->assertSame('give', $problems[0]->flow);
        $this->assertSame(2, (int) $problems[0]->mentions, 'the answer id that was never sent is not counted');
        $this->assertSame(2, (int) $problems[1]->mentions, 'respondents are distinct answers, not statements');
        $this->assertSame('members', $problems[0]->source);
        $this->assertSame($id, (int) $problems[0]->prompt_id);
        $evidence = json_decode($problems[0]->evidence, true);
        $this->assertSame([$a, $c], $evidence['answer_ids']);
        // Only quotes that are in the answer they name, and belong to the theme, are kept; the
        // words are as the member wrote them, not as they were matched.
        $this->assertEquals([['answer_id' => $a, 'span' => 'Photos will not upload'], ['answer_id' => $c, 'span' => 'photos   FAIL on my phone']], $evidence['quotes']);
        $this->assertSame(100, mb_strlen($problems[1]->title));
        $this->assertNull($problems[1]->flow, 'a flow that is not a funnel means not tied to one');
        $value = DB::table('ux_problems')->where('kind', 'value')->first();
        $this->assertSame('Giving to neighbours', $value->title);
        $this->assertSame(2, (int) $value->mentions);
        $this->assertEquals([['answer_id' => $c, 'span' => 'add a retry']], json_decode($value->evidence, true)['quotes']);
        $this->assertSame(1, DB::table('ux_problems')->where('kind', 'value')->count());
        $this->assertNull($value->flow);

        $ideas = DB::table('ux_ideas')->orderBy('id')->get();
        $this->assertCount(3, $ideas);
        $this->assertSame('member', $ideas[0]->source);
        $this->assertSame('give', $ideas[0]->flow);
        $this->assertSame((int) $problems[0]->id, (int) $ideas[0]->problem_id);
        $this->assertSame('open', $ideas[0]->status);
        $this->assertSame('experiment', $ideas[0]->type);
        $this->assertEquals(0, $ideas[0]->score);
        // A suggestion for a problem with no funnel is general; one for a dropped problem is untied.
        $this->assertSame('general', $ideas[1]->flow);
        $this->assertSame((int) $problems[1]->id, (int) $ideas[1]->problem_id);
        $this->assertNull($ideas[2]->problem_id);
    }

    public function test_an_analysed_prompt_is_not_analysed_again_without_force_and_force_replaces_its_themes(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b] = $this->answer($id, ['Photos will not upload', 'Photos fail']);
        $this->helper['body'] = ['problems' => [['title' => 'Photos', 'description' => '', 'flow' => 'give', 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'Photos']]]],
            'solutions' => [['title' => 'Retry', 'problem_index' => 0, 'answer_ids' => [$a]]], 'values' => [], 'unclear_ids' => []];
        $this->service->analyse($id);

        try {
            $this->service->analyse($id);
            $this->fail('analysed twice');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('analysed already', $e->getMessage());
        }
        $this->assertCount(1, $this->sent);

        $this->helper['body']['problems'][0]['title'] = 'Photos, better worded';
        $this->service->analyse($id, true);

        $this->assertSame(['Photos, better worded'], DB::table('ux_problems')->pluck('title')->all());
        $this->assertSame(1, DB::table('ux_ideas')->count());
    }

    public function test_analysing_refuses_what_it_cannot_do(): void
    {
        $id = $this->service->openOpen('problems');
        $this->answer($id, [['choice' => 'nothing'], ['skipped' => true]]);

        $this->assertRefused(fn () => $this->service->analyse($id), 'no written answers');
        $this->assertRefused(fn () => $this->service->analyse(999999), 'No open prompt');
        $survey = $this->service->openSurvey();
        $this->assertRefused(fn () => $this->service->analyse($survey), 'No open prompt');

        $this->answer($id, ['Something']);
        config()->set('freegle.ux.ideas.internal_secret', '');
        $this->assertRefused(fn () => $this->service->analyse($id), 'UX_INTERNAL_SECRET');
        $this->assertCount(0, $this->sent);
    }

    public function test_analysing_fails_cleanly_when_the_helper_does(): void
    {
        $id = $this->service->openOpen('problems');
        $this->answer($id, ['Something']);

        $this->helper['status'] = 502;
        $this->assertRefused(fn () => $this->service->analyse($id), '502');
        $this->helper['status'] = 200;
        $this->helper['body'] = ['nope' => true];
        $this->assertRefused(fn () => $this->service->analyse($id), 'no problems list');
        $this->helper['throw'] = true;
        $this->assertRefused(fn () => $this->service->analyse($id), 'could not be reached');

        $this->assertSame(0, DB::table('ux_problems')->count());
    }

    public function test_a_cut_connection_to_the_helper_is_tried_once_more(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b, $c] = $this->answer($id, ['One', 'Two', 'Three']);
        $this->helper['throw_first'] = 1;
        $this->helper['body'] = ['problems' => [['title' => 'Slow', 'description' => '', 'flow' => null, 'answer_ids' => [$a, $b, $c], 'quotes' => [['answer_id' => $a, 'span' => 'One']]]],
            'solutions' => [], 'values' => [], 'unclear_ids' => []];

        $counts = $this->service->analyse($id);

        $this->assertCount(2, $this->sent, 'one cut attempt and one that went through');
        $this->assertSame(1, $counts['problems']);
    }

    public function test_the_wait_for_the_themes_outlasts_the_helpers_own_limit(): void
    {
        // The helper gives up after 10 minutes (UX_THEMES_TIMEOUT_MS in claude-agent-sdk); if the
        // batch hung up first the helper would abort and every retry would fail the same way.
        $source = file_get_contents(base_path('config/freegle.php'));
        preg_match("/'themes_timeout' => \(int\) EnvSetting::env\('UX_THEMES_TIMEOUT', (\d+)\)/", $source, $m);
        $this->assertGreaterThan(600, (int) ($m[1] ?? 0));
    }

    private function assertRefused(callable $do, string $fragment): void
    {
        try {
            $do();
            $this->fail("expected a refusal containing '$fragment'");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    public function test_the_command_analyses_and_follows_up(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b, $c] = $this->answer($id, ['One thing', 'Another thing', 'A third thing']);
        $this->helper['body'] = ['problems' => [
            ['title' => 'First problem', 'description' => '', 'flow' => 'give', 'answer_ids' => [$a, $b, $c], 'quotes' => [['answer_id' => $a, 'span' => 'One thing']]],
            ['title' => 'Second problem', 'description' => '', 'flow' => null, 'answer_ids' => [$a, $b, $c], 'quotes' => [['answer_id' => $b, 'span' => 'Another']]],
        ], 'solutions' => [], 'values' => [], 'unclear_ids' => []];

        $this->artisan("ux:prompt analyse $id")->expectsOutputToContain('Analysed 3 answers: 2 problems, 0 values, 0 suggestions, 0 unclear.')->assertExitCode(0);
        $this->artisan("ux:prompt analyse $id")->expectsOutputToContain('analysed already')->assertExitCode(1);
        // Three written answers are not enough to rank anything.
        $this->artisan("ux:prompt followup $id --confirm")->expectsOutputToContain('3 written answers')->assertExitCode(1);
        $this->answer($id, array_fill(0, 97, 'More words'));
        // The 97 arrived after the themes were drawn, so the ranking still waits: it ranks themes
        // from 3 answers and must not say they came from 100.
        $this->artisan("ux:prompt followup $id --confirm")->expectsOutputToContain('analysed over 3 written answers')->assertExitCode(1);
        $this->artisan("ux:prompt analyse $id --force")->expectsOutputToContain('Analysed 100 answers')->assertExitCode(0);
        // The titles are shown first and nothing is opened until they are confirmed.
        $this->artisan("ux:prompt followup $id")->expectsOutputToContain('First problem')->assertExitCode(1);
        $this->assertSame(0, DB::table('ux_prompts')->where('kind', 'survey')->count());
        $this->artisan("ux:prompt followup $id --confirm")->expectsOutputToContain('Opened prompt')->assertExitCode(0);
        $this->artisan('ux:prompt followup 999999')->assertExitCode(1);
        $this->artisan('ux:prompt analyse')->assertExitCode(1);
    }

    // ---------------------------------------------------------------- the follow-up

    private function problem(int $prompt, string $title, int $mentions, string $kind = 'problem', string $status = 'open'): int
    {
        return DB::table('ux_problems')->insertGetId([
            'flow' => 'give', 'kind' => $kind, 'title' => $title, 'source' => 'members', 'prompt_id' => $prompt,
            'mentions' => $mentions, 'status' => $status, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    /** A prompt with enough written answers for its problems to be ranked. */
    private function rankable(string $preset = 'problems', int $written = 100): int
    {
        $prompt = $this->service->openOpen($preset);
        $this->answer($prompt, array_fill(0, $written, 'Some words'));
        $this->analysedOver($prompt, $written);

        return $prompt;
    }

    /** What analyse() records: how many answers the themes were drawn from. */
    private function analysedOver(int $prompt, int $n): void
    {
        $settings = json_decode((string) DB::table('ux_prompts')->where('id', $prompt)->value('settings'), true) ?: [];
        $settings['analysed_answers'] = $n;
        DB::table('ux_prompts')->where('id', $prompt)->update(['settings' => json_encode($settings)]);
    }

    public function test_the_follow_up_ranks_the_eight_most_mentioned_problems_four_at_a_time(): void
    {
        $prompt = $this->rankable();
        $ids = [];
        foreach (range(1, 12) as $n) {
            $ids[$n] = $this->problem($prompt, "Problem $n", $n);
        }
        $this->problem($prompt, 'A value', 99, 'value');
        $this->problem($prompt, 'Closed', 99, 'problem', 'closed');
        $this->problem($prompt + 1, 'Another prompt', 99);

        $follow = DB::table('ux_prompts')->find($this->service->followup($prompt, 200, true));

        $this->assertSame('survey', $follow->kind);
        $options = json_decode($follow->options, true);
        $this->assertCount(8, $options);
        $this->assertSame('problem:'.$ids[12], $options[0]['key']);
        $this->assertSame('Problem 12', $options[0]['label']);
        $this->assertSame('problem:'.$ids[5], $options[7]['key']);
        $this->assertSame('Which of these has got in your way most in the last few months?', $follow->question);
        $this->assertEquals(['show' => 4, 'best_label' => 'Got in my way most', 'worst_label' => 'Got in my way least (optional)',
            'none_label' => 'None of these has got in my way', 'question_hint' => 'in the last few months', 'followup_of' => $prompt],
            json_decode($follow->settings, true));
        $this->assertSame('open', $follow->status);
    }

    public function test_a_follow_up_needs_two_problems_and_replaces_the_older_one_for_the_same_prompt(): void
    {
        $prompt = $this->rankable();
        $this->problem($prompt, 'Only one', 3);
        $this->assertRefused(fn () => $this->service->followup($prompt, 200, true), 'fewer than two');

        $this->problem($prompt, 'Second', 3);
        $first = $this->service->followup($prompt, 200, true);
        $survey = $this->service->openSurvey();
        $second = $this->service->followup($prompt, 200, true);

        $this->assertSame('closed', DB::table('ux_prompts')->find($first)->status);
        $this->assertSame('open', DB::table('ux_prompts')->find($second)->status);
        // The ordinary survey is a different thing: opening it did not close the follow-up, and the
        // follow-up did not close it.
        $this->assertSame('open', DB::table('ux_prompts')->find($survey)->status);
        $third = $this->service->openSurvey();
        $this->assertSame('open', DB::table('ux_prompts')->find($second)->status);
        $this->assertSame('closed', DB::table('ux_prompts')->find($survey)->status);
        $this->assertSame('open', DB::table('ux_prompts')->find($third)->status);
    }

    public function test_a_theme_only_one_or_two_members_raised_is_never_shown_to_other_members(): void
    {
        $prompt = $this->rankable();
        $this->problem($prompt, 'Moderator Jane Smith at Anytown deletes my posts', 1);
        $this->problem($prompt, 'Two members said this', 2);
        $a = $this->problem($prompt, 'Photos are slow', 5);
        $b = $this->problem($prompt, 'Too many emails', 3);

        $follow = DB::table('ux_prompts')->find($this->service->followup($prompt, 200, true));

        $this->assertSame(['problem:'.$a, 'problem:'.$b], array_column(json_decode($follow->options, true), 'key'));
    }

    public function test_the_follow_up_lists_the_titles_and_opens_nothing_until_they_are_confirmed(): void
    {
        $prompt = $this->rankable();
        $this->problem($prompt, 'Photos are slow', 5);
        $this->problem($prompt, 'Too many emails', 3);

        $this->assertRefused(fn () => $this->service->followup($prompt), 'Photos are slow');
        $this->assertRefused(fn () => $this->service->followup($prompt), '5 of 100 who answered');
        $this->assertRefused(fn () => $this->service->followup($prompt), '--confirm');
        $this->assertSame(0, DB::table('ux_prompts')->where('kind', 'survey')->count());
    }

    public function test_a_follow_up_is_refused_below_a_hundred_written_answers(): void
    {
        $prompt = $this->rankable('problems', 99);
        // Picks, skips and "nothing got in my way" are answers but not words.
        $this->answer($prompt, [['choice' => 'nothing'], ['skipped' => true], ['text' => '   '], ['choice' => 'dont_understand']]);
        $this->problem($prompt, 'A', 5);
        $this->problem($prompt, 'B', 4);

        $this->assertRefused(fn () => $this->service->followup($prompt, 200, true), '99 written answers');
        $this->assertRefused(fn () => $this->service->followup($prompt, 200, true), 'next answer still often adds a theme');
        $this->assertSame(0, DB::table('ux_prompts')->where('kind', 'survey')->count());

        // Answers that arrive after the analysis do not count: the themes were not drawn from them.
        $this->answer($prompt, array_fill(0, 40, 'Arrived later'));
        $this->assertRefused(fn () => $this->service->followup($prompt, 200, true), 'analysed over 99 written answers');

        // Analysing again draws the themes from all of them.
        $this->analysedOver($prompt, 140);
        $this->assertGreaterThan(0, $this->service->followup($prompt, 200, true));
    }

    public function test_the_catch_all_other_theme_is_not_ranked_and_is_not_given_to_the_generator_as_a_problem(): void
    {
        $prompt = $this->rankable();
        $this->problem($prompt, 'Other', 50);
        $a = $this->problem($prompt, 'Photos are slow', 5);
        $b = $this->problem($prompt, 'Too many emails', 3);

        $follow = DB::table('ux_prompts')->find($this->service->followup($prompt, 200, true));
        $this->assertSame(['problem:'.$a, 'problem:'.$b], array_column(json_decode($follow->options, true), 'key'));

        $brief = (new \ReflectionMethod(UxIdeasService::class, 'problems'));
        $brief->setAccessible(true);
        $text = $brief->invoke(app(UxIdeasService::class), 'give');
        $this->assertStringContainsString('Photos are slow', $text);
        $this->assertStringNotContainsString('"Other"', $text);
    }

    public function test_analysing_records_how_many_answers_the_themes_were_drawn_from(): void
    {
        $id = $this->service->openOpen('problems');
        [$a, $b, $c] = $this->answer($id, ['One thing', 'Another thing', 'A third thing']);
        $this->helper['body'] = ['problems' => [
            ['title' => 'First problem', 'description' => '', 'flow' => 'give', 'answer_ids' => [$a, $b], 'quotes' => [['answer_id' => $a, 'span' => 'One thing']]],
        ], 'solutions' => [], 'values' => [], 'unclear_ids' => []];

        $this->service->analyse($id);

        $settings = json_decode(DB::table('ux_prompts')->where('id', $id)->value('settings'), true);
        $this->assertSame(3, $settings['analysed_answers']);
        $this->assertSame('problems', $settings['preset'], 'what was already in the settings is kept');
    }

    public function test_the_tick_weighs_problems_by_how_often_each_was_shown(): void
    {
        $prompt = $this->rankable();
        $a = $this->problem($prompt, 'A', 5);
        $b = $this->problem($prompt, 'B', 4);
        $c = $this->problem($prompt, 'C', 3);
        $d = $this->problem($prompt, 'D', 3);
        $follow = $this->service->followup($prompt, 200, true);
        $all = ["problem:$a", "problem:$b", "problem:$c"];
        $this->answer($follow, [
            ['best' => "problem:$a", 'worst' => "problem:$c", 'shown' => $all],
            ['best' => "problem:$a", 'shown' => ["problem:$a", "problem:$b", "problem:$d"]],
            ['best' => "problem:$b", 'worst' => "problem:$c", 'shown' => $all],
            ['choice' => 'none', 'shown' => ["problem:$b", "problem:$c", "problem:$d"]],
            // These are not answers: a skip, and "don't understand".
            ['skipped' => true],
            ['choice' => 'dont_understand'],
        ]);

        $this->assertSame(4, $this->service->weighFollowups(), 'D was shown twice but never picked, so it is weighed too');
        $this->assertEquals(0.0, (float) DB::table('ux_problems')->find($d)->weight);

        // A: shown 3 times, most twice, least never. B: shown 4, most once. C: shown 3, least twice.
        $weight = fn ($id) => (float) DB::table('ux_problems')->find($id)->weight;
        $this->assertEquals(0.667, $weight($a));
        $this->assertEquals(0.25, $weight($b));
        $this->assertEquals(-0.667, $weight($c));
        $evidence = json_decode(DB::table('ux_problems')->find($a)->evidence ?? '', true);
        $this->assertEquals(['prompt_id' => $follow, 'most' => 2, 'least' => 0, 'times_shown' => 3], $evidence['ranking']);
        $this->assertSame(4, json_decode(DB::table('ux_problems')->find($b)->evidence, true)['ranking']['times_shown']);
    }

    public function test_weighing_keeps_the_evidence_the_problem_already_had_and_skips_what_was_never_shown(): void
    {
        $prompt = $this->rankable();
        $a = $this->problem($prompt, 'A', 5);
        $b = $this->problem($prompt, 'B', 4);
        $c = $this->problem($prompt, 'C', 3);
        DB::table('ux_problems')->where('id', $a)->update(['evidence' => json_encode(['answer_ids' => [1, 2], 'quotes' => [['answer_id' => 1, 'span' => 'x']]])]);
        $follow = $this->service->followup($prompt, 200, true);
        $this->answer($follow, [['best' => "problem:$a", 'worst' => "problem:$b", 'shown' => ["problem:$a", "problem:$b"]]]);

        $this->assertSame(2, $this->service->weighFollowups());

        $evidence = json_decode(DB::table('ux_problems')->find($a)->evidence, true);
        $this->assertSame([1, 2], $evidence['answer_ids']);
        $this->assertSame('x', $evidence['quotes'][0]['span']);
        $this->assertSame(1, $evidence['ranking']['times_shown']);
        $this->assertNull(DB::table('ux_problems')->find($c)->weight, 'C was never in front of anybody');
    }

    public function test_an_answer_with_no_shown_list_is_counted_as_having_seen_every_option(): void
    {
        $prompt = $this->rankable();
        $a = $this->problem($prompt, 'A', 5);
        $b = $this->problem($prompt, 'B', 4);
        $follow = $this->service->followup($prompt, 200, true);
        $this->answer($follow, [['best' => "problem:$a", 'worst' => "problem:$b"], ['best' => "problem:$a"]]);

        $this->service->weighFollowups();

        $this->assertEquals(1.0, (float) DB::table('ux_problems')->find($a)->weight);
        $this->assertEquals(-0.5, (float) DB::table('ux_problems')->find($b)->weight);
    }

    public function test_nothing_is_weighed_before_the_first_answer_and_the_tick_command_does_it(): void
    {
        $prompt = $this->rankable();
        $a = $this->problem($prompt, 'A', 5);
        $b = $this->problem($prompt, 'B', 4);
        $follow = $this->service->followup($prompt, 200, true);
        $this->assertSame(0, $this->service->weighFollowups());
        $this->assertNull(DB::table('ux_problems')->find($a)->weight);

        $this->answer($follow, [['best' => "problem:$a", 'worst' => "problem:$b", 'shown' => ["problem:$a", "problem:$b"]]]);
        $this->artisan('ux:experiments-tick')->expectsOutputToContain('Weighed 2 problem(s)')->assertExitCode(0);

        $this->assertEquals(1.0, (float) DB::table('ux_problems')->find($a)->weight);
        $this->assertEquals(-1.0, (float) DB::table('ux_problems')->find($b)->weight);
    }

    // ---------------------------------------------------------------- privacy

    private function written(int $prompt, array $answer, string $when, ?string $comment = null): int
    {
        return DB::table('ux_prompt_responses')->insertGetId([
            'prompt_id' => $prompt, 'userid' => $this->nextUser++, 'answer' => json_encode($answer),
            'comment' => $comment, 'timestamp' => $when,
        ]);
    }

    public function test_words_and_comments_are_blanked_ninety_days_after_they_were_written_whatever_the_prompt_is_doing(): void
    {
        // An open question that never filled and is still open: nothing closes it by time.
        $open = $this->service->openOpen('problems');
        $survey = $this->service->openSurvey();
        $oldText = $this->written($open, ['text' => 'A secret address'], '2026-07-01 00:00:00', 'And a comment');
        $oldNothing = $this->written($open, ['choice' => 'nothing'], '2026-07-01 00:00:00');
        $oldSkip = $this->written($open, ['skipped' => true], '2026-07-01 00:00:00');
        $newText = $this->written($open, ['text' => 'Still wanted'], '2026-10-01 00:00:00');
        $oldPick = $this->written($survey, ['best' => 'give', 'worst' => 'login'], '2026-07-01 00:00:00', 'Jane Smith is a bully');
        $newPick = $this->written($survey, ['best' => 'give', 'worst' => 'login'], '2026-10-01 00:00:00', 'Fresh comment');
        // A later touch of the prompt row does not restart anyone's clock.
        DB::table('ux_prompts')->where('id', $open)->update(['updated_at' => '2026-10-13 00:00:00']);

        $this->assertSame(2, $this->service->blankOldAnswers());

        $row = fn ($id) => DB::table('ux_prompt_responses')->find($id);
        $this->assertSame(['text' => null, 'removed' => true], json_decode($row($oldText)->answer, true));
        $this->assertNull($row($oldText)->comment, 'the comment on an old answer goes too');
        $this->assertSame(['choice' => 'nothing'], json_decode($row($oldNothing)->answer, true));
        $this->assertSame(['skipped' => true], json_decode($row($oldSkip)->answer, true));
        $this->assertSame(['text' => 'Still wanted'], json_decode($row($newText)->answer, true));
        // A survey's picks are not member words and stay for the counts; its comment is.
        $this->assertSame(['best' => 'give', 'worst' => 'login'], json_decode($row($oldPick)->answer, true));
        $this->assertNull($row($oldPick)->comment);
        $this->assertSame('Fresh comment', $row($newPick)->comment);
        $this->assertSame(6, DB::table('ux_prompt_responses')->count(), 'the rows, and so the counts, stay');

        // Doing it again finds nothing more.
        $this->assertSame(0, $this->service->blankOldAnswers());
    }

    public function test_the_tick_command_blanks_old_answers(): void
    {
        $old = $this->service->openOpen('problems');
        $id = $this->written($old, ['text' => 'Words'], '2026-07-01 00:00:00');

        $this->artisan('ux:experiments-tick')->expectsOutputToContain('Removed the words of 1 answer(s) written over 90 days ago.')->assertExitCode(0);

        $this->assertSame(['text' => null, 'removed' => true], json_decode(DB::table('ux_prompt_responses')->find($id)->answer, true));
    }

    // ---------------------------------------------------------------- scrubbing

    public function test_the_scrub_removes_emails_phone_numbers_and_postcodes_but_not_dates_or_ordinary_numbers(): void
    {
        $this->assertSame('Mail [email] or ring [number] or [number]; I am at [postcode], [postcode].',
            UxScrub::text('Mail Joe.Bloggs+freegle@mail.example.co.uk or ring 07700 900123 or +44 (0)20 7946 0958; I am at SW1A 1AA, m1 1ae.'));
        $this->assertSame('On 2026-10-14 I posted 12 items and 3 were taken, about 250 grams.', UxScrub::text('On 2026-10-14 I posted 12 items and 3 were taken, about 250 grams.'));
        $this->assertSame('a b c', UxScrub::text("a\n\n  b\t c "));
        $this->assertSame('abcde', UxScrub::text('abcdefgh', 5));
    }
}
