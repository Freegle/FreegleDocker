<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\UxIdeasService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ux:ideas-refresh and UxIdeasService: the brief written from the ux_* tables, the merge of the
 * helper's ideas into ux_ideas, and the command's gate on how many ideas a funnel already has.
 *
 * Http::fake() merges and the first callback wins (see the batch traps rule), so the helper is
 * ONE closure that answers from $this->helper, which a test changes between calls.
 */
class UxIdeasRefreshTest extends TestCase
{
    private const URL = 'http://ai-support-helper:3000/internal/ux-ideas';

    /** @var array{status: int, body: array, throw: bool} What the fake helper answers next. */
    private array $helper;

    /** @var Request[] Every request the code sent to the helper. */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        foreach (['ux_prompt_responses', 'ux_prompts', 'ux_ideas', 'ux_experiment_reads', 'ux_experiments',
            'ux_flow_daily', 'ux_flow_outcome_daily', 'ux_field_daily'] as $t) {
            DB::table($t)->delete();
        }
        config()->set('freegle.ux.ideas', ['helper_url' => 'http://ai-support-helper:3000', 'timeout' => 30, 'min_open' => 3, 'count' => 12]);

        $this->sent = [];
        $this->helper = ['status' => 200, 'body' => ['problem' => 'p', 'journey' => 'j', 'ideas' => [], 'costUsd' => 0.42, 'model' => 'm'], 'throw' => false];
        Http::fake(function (Request $request) {
            $this->sent[] = $request;
            if ($this->helper['throw']) {
                throw new ConnectionException('cURL error 7: connection refused');
            }

            return Http::response($this->helper['body'], $this->helper['status']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function idea(array $over = []): array
    {
        return $over + [
            'title' => 'Skip photos for now', 'category' => 'copy', 'change' => 'Add a plain skip link.',
            'rationale' => 'People stall at photos.', 'expected' => 'More finish.', 'expected_points' => 4.0,
            'confidence' => 0.5, 'locality' => 5, 'risk' => 'Fewer photos.', 'files' => ['a.vue'], 'like_tried' => '',
        ];
    }

    private function helperIdeas(array ...$ideas): void
    {
        $this->helper['body']['ideas'] = $ideas;
    }

    private function seedIdea(string $flow, string $title, string $status = 'open'): int
    {
        return DB::table('ux_ideas')->insertGetId([
            'flow' => $flow, 'title' => $title, 'rationale' => 'r', 'expected' => 'e', 'status' => $status, 'score' => 1,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    private function seedFigures(): void
    {
        $day = '2026-10-10';
        DB::table('ux_flow_outcome_daily')->insert([
            'date' => $day, 'flow' => 'give', 'started' => 1000, 'submitted' => 600, 'submit_ok' => 580,
            'submit_failed' => 20, 'completed' => 500, 'abandoned' => 400, 'resumed' => 30,
        ]);
        // Mobile completes the photos step far less often than desktop.
        DB::table('ux_flow_daily')->insert([
            ['date' => $day, 'flow' => 'give', 'step' => 'photos', 'device_class' => 'mobile', 'entered' => 300, 'reentered' => 0, 'completed' => 90, 'abandoned' => 200],
            ['date' => $day, 'flow' => 'give', 'step' => 'photos', 'device_class' => 'desktop', 'entered' => 100, 'reentered' => 0, 'completed' => 80, 'abandoned' => 15],
        ]);
        DB::table('ux_field_daily')->insert([
            ['date' => $day, 'flow' => 'give', 'step' => 'details', 'field' => 'postcode', 'device_class' => 'mobile', 'revisits' => 5, 'validation_failures' => 40, 'choice_switches' => 0],
            ['date' => $day, 'flow' => 'give', 'step' => 'details', 'field' => 'postcode', 'device_class' => 'desktop', 'revisits' => 1, 'validation_failures' => 10, 'choice_switches' => 0],
        ]);
        // Outside the seven days and in another flow: neither may appear.
        DB::table('ux_flow_daily')->insert([
            ['date' => '2026-09-01', 'flow' => 'give', 'step' => 'ancient', 'entered' => 500, 'completed' => 1],
            ['date' => $day, 'flow' => 'ask', 'step' => 'other-flow', 'entered' => 500, 'completed' => 1],
        ]);
    }

    // ------------------------------------------------------------------ the brief

    public function test_brief_says_there_are_no_figures_when_the_tables_are_empty(): void
    {
        $brief = app(UxIdeasService::class)->brief('give');

        $this->assertSame('no figures yet', $brief['figures']);
        $this->assertSame('no surveys yet', $brief['surveys']);
        $this->assertSame([], $brief['tried']);
    }

    public function test_brief_writes_the_figures_in_plain_text(): void
    {
        $this->seedFigures();

        $figures = app(UxIdeasService::class)->brief('give')['figures'];

        $this->assertStringContainsString('1,000 started, 500 completed (50%), 400 abandoned, 600 submitted of which 20 failed, 30 resumed later', $figures);
        $this->assertStringContainsString('Step photos: 400 entered, 400 visits, 170 completed (43%), 215 abandoned.', $figures);
        $this->assertStringContainsString('Worst device: mobile, 30% completing (300 visits)', $figures);
        $this->assertStringContainsString('Field postcode on step details: 50 validation failures, 6 revisits, 0 choice switches.', $figures);
        $this->assertStringNotContainsString('ancient', $figures);
        $this->assertStringNotContainsString('other-flow', $figures);
    }

    public function test_brief_tallies_surveys_and_carries_comments(): void
    {
        $now = Carbon::now();
        $survey = DB::table('ux_prompts')->insertGetId([
            'kind' => 'survey', 'question' => 'Which part works best?', 'body' => 'b', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
            'options' => json_encode([['key' => 'give', 'label' => 'Offering something'], ['key' => 'reply', 'label' => 'Replying to a post']]),
        ]);
        $awareness = DB::table('ux_prompts')->insertGetId([
            'kind' => 'awareness', 'flow' => 'give', 'question' => 'Did you know you can repost?', 'body' => 'b', 'status' => 'open',
            'created_at' => $now, 'updated_at' => $now,
            'options' => json_encode([['key' => 'use', 'label' => 'Yes, and I use it'], ['key' => 'no', 'label' => "No, I didn't know"]]),
        ]);
        // An experiment's own prompt is not a survey and must not be tallied here.
        DB::table('ux_prompts')->insert([
            'kind' => 'experiment', 'question' => 'Better or worse?', 'body' => 'b', 'status' => 'open', 'created_at' => $now,
            'updated_at' => $now, 'options' => json_encode([['key' => 'better', 'label' => 'Better']]),
        ]);
        foreach ([[1, 'give', 'reply', 'Posting is a faff'], [2, 'give', 'reply', null], [3, 'reply', 'give', null]] as [$user, $best, $worst, $comment]) {
            DB::table('ux_prompt_responses')->insert(['prompt_id' => $survey, 'userid' => $user, 'answer' => json_encode(['best' => $best, 'worst' => $worst]), 'comment' => $comment]);
        }
        DB::table('ux_prompt_responses')->insert(['prompt_id' => $awareness, 'userid' => 1, 'answer' => json_encode(['choice' => 'no']), 'comment' => null]);

        $surveys = app(UxIdeasService::class)->brief('give')['surveys'];

        $this->assertStringContainsString('Survey "Which part works best?": 3 answers.', $surveys);
        $this->assertStringContainsString('Offering something: 2 said best, 1 said needs most work', $surveys);
        $this->assertStringContainsString('Replying to a post: 1 said best, 2 said needs most work', $surveys);
        $this->assertStringContainsString('Awareness "Did you know you can repost?" (about give): 1 answer.', $surveys);
        $this->assertStringContainsString("No, I didn't know: 1 chose it", $surveys);
        $this->assertStringContainsString('Comment: Posting is a faff', $surveys);
        $this->assertStringNotContainsString('Better or worse', $surveys);
    }

    public function test_brief_tried_lists_every_status_including_open_ideas_once(): void
    {
        $now = Carbon::now();
        DB::table('ux_experiments')->insert([
            'flow' => 'give', 'name' => 'give-photos-skip', 'title' => 'Make skipping photos obvious', 'hypothesis' => 'h',
            'change_description' => 'c', 'expected' => 'e', 'arms' => '{}', 'status' => 'reverted', 'decision' => 'Reverted: it was worse.',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('ux_experiments')->insert([
            'flow' => 'ask', 'name' => 'ask-other', 'title' => 'Another flow', 'hypothesis' => 'h', 'change_description' => 'c',
            'expected' => 'e', 'arms' => '{}', 'status' => 'adopted', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->seedIdea('give', 'Show a progress bar');
        $this->seedIdea('give', 'Rejected idea', 'rejected');
        // The same title as the experiment: listed once, as the experiment with its decision.
        $this->seedIdea('give', 'Make  skipping photos OBVIOUS', 'done');

        $tried = app(UxIdeasService::class)->brief('give')['tried'];
        $byTitle = array_column($tried, null, 'title');

        $this->assertCount(3, $tried);
        $this->assertSame(['title' => 'Make skipping photos obvious', 'status' => 'reverted', 'decision' => 'Reverted: it was worse.'], $byTitle['Make skipping photos obvious']);
        $this->assertSame('open', $byTitle['Show a progress bar']['status']);
        $this->assertSame('rejected', $byTitle['Rejected idea']['status']);
        $this->assertArrayNotHasKey('Another flow', $byTitle);
    }

    // ------------------------------------------------------------------ refresh

    public function test_refresh_files_new_ideas_and_scores_them(): void
    {
        $this->helperIdeas($this->idea(), $this->idea(['title' => 'Show a progress bar', 'expected_points' => 10, 'confidence' => 0.4, 'locality' => 2]));

        $result = app(UxIdeasService::class)->refresh('give');

        $this->assertSame(2, $result['inserted']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0.42, $result['cost_usd']);

        $row = DB::table('ux_ideas')->where('title', 'Skip photos for now')->first();
        $this->assertSame('give', $row->flow);
        $this->assertSame('open', $row->status);
        $this->assertSame('ai', $row->source);
        $this->assertSame('Add a plain skip link. People stall at photos.', $row->rationale);
        $this->assertSame('More finish.', $row->expected);
        // 4 points, half sure, one-line change: 4 * 0.5 * (5 / 5).
        $this->assertEquals(2.0, (float) $row->score);
        // 10 points, 0.4 sure, locality 2 of 5: 10 * 0.4 * 0.4.
        $this->assertEquals(1.6, (float) DB::table('ux_ideas')->where('title', 'Show a progress bar')->value('score'));
        $evidence = json_decode($row->evidence, true);
        $this->assertSame('copy', $evidence['category']);
        $this->assertSame(['a.vue'], $evidence['files']);
        $this->assertSame(5, $evidence['locality']);
        $this->assertArrayHasKey('like_tried', $evidence);
        $this->assertArrayHasKey('risk', $evidence);
    }

    public function test_refresh_sends_the_brief_with_the_internal_header(): void
    {
        $this->seedFigures();
        $this->seedIdea('give', 'Already waiting');

        app(UxIdeasService::class)->refresh('give');

        $this->assertCount(1, $this->sent);
        $request = $this->sent[0];
        $this->assertSame(self::URL, $request->url());
        $this->assertSame(['1'], $request->header('X-Freegle-Internal'));
        $body = $request->data();
        $this->assertSame('give', $body['flow']);
        $this->assertSame(12, $body['count']);
        $this->assertStringContainsString('1,000 started', $body['figures']);
        $this->assertSame('Already waiting', $body['tried'][0]['title']);
    }

    public function test_refresh_skips_an_idea_already_open_under_the_same_title(): void
    {
        $this->seedIdea('give', 'Skip photos for now');
        $this->seedIdea('give', 'Scheduled one', 'scheduled');
        $this->seedIdea('give', 'Running one', 'running');
        $this->seedIdea('give', 'Old rejected one', 'rejected');
        $this->seedIdea('ask', 'Show a progress bar');
        $this->helperIdeas(
            // Same title as an open row apart from case and spacing.
            $this->idea(['title' => '  SKIP  photos for  now ']),
            $this->idea(['title' => 'scheduled one']),
            $this->idea(['title' => 'RUNNING ONE']),
            // A rejected idea may be proposed again; another flow's open idea does not block this one.
            $this->idea(['title' => 'Old rejected one']),
            $this->idea(['title' => 'Show a progress bar']),
            // The helper repeating itself in one answer is filed once.
            $this->idea(['title' => 'Brand new']),
            $this->idea(['title' => 'brand new']),
            $this->idea(['title' => '']),
        );

        $result = app(UxIdeasService::class)->refresh('give');

        $this->assertSame(3, $result['inserted']);
        $this->assertSame(5, $result['skipped']);
        $this->assertSame(1, DB::table('ux_ideas')->where('flow', 'give')->whereRaw('LOWER(title) LIKE ?', ['%skip%'])->count());
        $this->assertSame(1, DB::table('ux_ideas')->where('flow', 'give')->where('title', 'Brand new')->count());
        $this->assertSame(1, DB::table('ux_ideas')->where('flow', 'give')->where('title', 'Show a progress bar')->count());
        $this->assertSame(2, DB::table('ux_ideas')->where('flow', 'give')->where('title', 'Old rejected one')->count());
    }

    public function test_refresh_truncates_a_long_title_and_clamps_a_wild_score(): void
    {
        $this->helperIdeas($this->idea(['title' => str_repeat('long ', 60), 'expected_points' => 5000000, 'confidence' => 80]));

        app(UxIdeasService::class)->refresh('give');

        $row = DB::table('ux_ideas')->first();
        $this->assertSame(160, mb_strlen($row->title));
        // 80 is read as 80%, and the column's ceiling holds.
        $this->assertEquals(9999.99, (float) $row->score);
    }

    public function test_dry_run_writes_nothing_but_reports_what_it_would(): void
    {
        $this->seedIdea('give', 'Skip photos for now');
        $this->helperIdeas($this->idea(), $this->idea(['title' => 'Show a progress bar']));

        $result = app(UxIdeasService::class)->refresh('give', true);

        $this->assertSame(1, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('Show a progress bar', $result['ideas'][0]['title']);
        $this->assertCount(1, $this->sent);
        $this->assertSame(1, DB::table('ux_ideas')->count());
    }

    public function test_a_502_from_the_helper_throws_with_the_status_and_files_nothing(): void
    {
        $this->helper['status'] = 502;
        $this->helper['body'] = ['error' => 'claude failed'];

        try {
            app(UxIdeasService::class)->refresh('give');
            $this->fail('A 502 should throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('502', $e->getMessage());
        }
        $this->assertSame(0, DB::table('ux_ideas')->count());
    }

    public function test_a_transport_error_throws_a_runtime_exception(): void
    {
        $this->helper['throw'] = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be reached');
        app(UxIdeasService::class)->refresh('give');
    }

    public function test_an_answer_with_no_ideas_list_throws(): void
    {
        $this->helper['body'] = ['problem' => 'p'];

        $this->expectException(\RuntimeException::class);
        app(UxIdeasService::class)->refresh('give');
    }

    public function test_open_count_counts_only_open_ideas_of_the_flow(): void
    {
        $this->seedIdea('give', 'a');
        $this->seedIdea('give', 'b');
        $this->seedIdea('give', 'c', 'running');
        $this->seedIdea('ask', 'd');

        $this->assertSame(2, app(UxIdeasService::class)->openCount('give'));
    }

    // ------------------------------------------------------------------ the command

    public function test_command_skips_a_flow_with_enough_open_ideas(): void
    {
        $this->seedIdea('give', 'a');
        $this->seedIdea('give', 'b');
        $this->seedIdea('give', 'c');
        $this->helperIdeas($this->idea());

        $this->artisan('ux:ideas-refresh', ['--flow' => 'give'])
            ->expectsOutputToContain('give: 3 open ideas, enough')
            ->assertExitCode(0);

        $this->assertCount(0, $this->sent);
        $this->assertSame(3, DB::table('ux_ideas')->count());
    }

    public function test_command_refreshes_a_flow_below_min_open(): void
    {
        $this->seedIdea('give', 'a');
        $this->seedIdea('give', 'b');
        $this->helperIdeas($this->idea());

        $this->artisan('ux:ideas-refresh', ['--flow' => 'give'])
            ->expectsOutputToContain('give: filed 1 idea(s), 0 skipped')
            ->assertExitCode(0);

        $this->assertCount(1, $this->sent);
        $this->assertSame(3, DB::table('ux_ideas')->where('flow', 'give')->count());
    }

    public function test_command_min_open_comes_from_config(): void
    {
        config()->set('freegle.ux.ideas.min_open', 1);
        $this->seedIdea('give', 'a');

        $this->artisan('ux:ideas-refresh', ['--flow' => 'give'])->assertExitCode(0);

        $this->assertCount(0, $this->sent);
    }

    public function test_command_force_refreshes_even_a_full_backlog(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $t) {
            $this->seedIdea('give', $t);
        }
        $this->helperIdeas($this->idea());

        $this->artisan('ux:ideas-refresh', ['--flow' => 'give', '--force' => true])->assertExitCode(0);

        $this->assertCount(1, $this->sent);
        $this->assertSame(5, DB::table('ux_ideas')->where('flow', 'give')->count());
    }

    public function test_command_with_no_flow_goes_through_every_funnel(): void
    {
        $this->artisan('ux:ideas-refresh')->assertExitCode(0);

        $this->assertCount(7, $this->sent);
        $flows = array_map(fn (Request $r) => $r->data()['flow'], $this->sent);
        $this->assertSame(['give', 'ask', 'reply', 'login', 'chat_promise', 'chat_outcome', 'settings'], $flows);
    }

    public function test_command_dry_run_prints_the_ideas_and_writes_nothing(): void
    {
        $this->helperIdeas($this->idea());

        $this->artisan('ux:ideas-refresh', ['--flow' => 'give', '--dry-run' => true])
            ->expectsOutputToContain('would file 1 idea(s)')
            ->expectsOutputToContain('[2] Skip photos for now')
            ->expectsOutputToContain('Add a plain skip link.')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('ux_ideas')->count());
    }

    public function test_command_fails_when_the_helper_does(): void
    {
        $this->helper['status'] = 502;

        $this->artisan('ux:ideas-refresh', ['--flow' => 'give'])
            ->expectsOutputToContain('502')
            ->assertExitCode(1);
    }

    public function test_command_carries_on_to_the_next_flow_after_a_failure(): void
    {
        $this->helper['status'] = 502;

        $this->artisan('ux:ideas-refresh')->assertExitCode(1);

        $this->assertCount(7, $this->sent);
    }

    public function test_command_rejects_an_unknown_flow(): void
    {
        $this->artisan('ux:ideas-refresh', ['--flow' => 'nonsense'])->assertExitCode(1);

        $this->assertCount(0, $this->sent);
    }
}
