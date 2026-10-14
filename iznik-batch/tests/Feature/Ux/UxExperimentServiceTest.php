<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

class UxExperimentServiceTest extends TestCase
{
    use FakesUxLoki;

    private UxExperimentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->fakeLoki();
        foreach (['ux_prompt_responses', 'ux_prompts', 'ux_ideas', 'ux_experiment_reads', 'ux_experiments'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->delete();
        config()->set('freegle.ux.experiments.min_starts_per_arm', 50);
        $this->service = app(UxExperimentService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function attrs(array $over = []): array
    {
        return $over + [
            'flow' => 'give', 'name' => 'give-photos-skip', 'title' => 'Make skipping photos obvious',
            'hypothesis' => 'People leave at photos because they cannot see they can skip.',
            'change' => 'A plain "Add photos later" link above the camera button.',
            'expected' => 'More people finish posting from the photos step.',
        ];
    }

    /** Feed Loki with counts by arm for the experiment's flow and, optionally, other flows. */
    private function lokiArms(array $arms, array $other = []): void
    {
        $series = [];
        foreach ($arms as $arm => $c) {
            foreach (['flow_start' => 'starts', 'flow_complete' => 'completes', 'flow_submit_result' => 'submitted',
                'validation_failed' => 'validation_failed', 'help_sought' => 'help_sought'] as $event => $key) {
                if (! empty($c[$key])) {
                    $series[] = [['arm' => $arm, 'flow' => 'give', 'action_name' => $event], $c[$key]];
                }
            }
        }
        foreach ($other as $flow => $byArm) {
            foreach ($byArm as $arm => [$starts, $completes]) {
                $series[] = [['arm' => $arm, 'flow' => $flow, 'action_name' => 'flow_start'], $starts];
                $series[] = [['arm' => $arm, 'flow' => $flow, 'action_name' => 'flow_complete'], $completes];
            }
        }
        $this->lokiMatrix('/sum by \(arm, flow, action_name\)/', $series);

        $ok = [];
        foreach ($arms as $arm => $c) {
            if (isset($c['submit_ok'])) {
                $ok[] = [['arm' => $arm, 'flow' => 'give'], $c['submit_ok']];
            }
        }
        $this->lokiMatrix('/ok="true"/', $ok);
    }

    private function configArms(): array
    {
        return json_decode(DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->value('value') ?? '{}', true);
    }

    // ---------------------------------------------------------------- create

    public function test_create_validates_name_flow_text_and_arms(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($this->attrs(['name' => 'has spaces']));
    }

    public function test_create_rejects_an_unknown_flow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($this->attrs(['flow' => 'checkout']));
    }

    public function test_create_rejects_overlapping_arms(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($this->attrs(['arms' => ['control' => [0, 60], 'b' => [50, 99]]]));
    }

    public function test_create_rejects_arms_without_control(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($this->attrs(['arms' => ['a' => [0, 49], 'b' => [50, 99]]]));
    }

    public function test_create_makes_a_draft_with_default_arms(): void
    {
        $id = $this->service->create($this->attrs());
        $exp = $this->service->find($id);
        $this->assertSame('draft', $exp->status);
        $this->assertEquals(['control' => [0, 49], 'b' => [50, 99]], json_decode($exp->arms, true));
        $this->assertSame($exp->id, $this->service->find('give-photos-skip')->id);
        // A draft is not in the config the client reads.
        $this->service->rebuildConfig();
        $this->assertSame([], $this->configArms());
    }

    // ---------------------------------------------------------------- start / stop

    public function test_start_runs_for_24_hours_publishes_the_arms_and_opens_a_prompt(): void
    {
        $idea = DB::table('ux_ideas')->insertGetId(['flow' => 'give', 'title' => 'x', 'rationale' => 'y', 'expected' => 'z', 'status' => 'open']);
        $id = $this->service->create($this->attrs(['image_control' => 'https://img/a.png', 'image_variant' => 'https://img/b.png', 'idea_id' => $idea]));

        $exp = $this->service->start($id);

        $this->assertSame('running', $exp->status);
        $this->assertSame('2026-10-14 10:00:00', $exp->started_at);
        $this->assertSame('2026-10-15 10:00:00', $exp->ends_at);
        $this->assertEquals(['give-photos-skip' => ['control' => [0, 49], 'b' => [50, 99]]], $this->configArms());
        $this->assertSame('running', DB::table('ux_ideas')->where('id', $idea)->value('status'));

        $prompt = DB::table('ux_prompts')->where('experiment_id', $id)->first();
        $this->assertSame('experiment', $prompt->kind);
        $this->assertSame('open', $prompt->status);
        $this->assertCount(2, json_decode($prompt->images, true));
        $this->assertStringContainsString('Add photos later', $prompt->body);
        $this->assertSame(['better', 'worse', 'dont_care', 'dont_understand'], array_column(json_decode($prompt->options, true), 'key'));
    }

    public function test_start_refuses_a_sixth_running_experiment(): void
    {
        config()->set('freegle.ux.experiments.max_concurrent', 2);
        $this->service->start($this->service->create($this->attrs(['name' => 'one'])));
        $this->service->start($this->service->create($this->attrs(['name' => 'two'])));
        $third = $this->service->create($this->attrs(['name' => 'three']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cap');
        $this->service->start($third);
    }

    public function test_start_refuses_an_experiment_that_is_already_running(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        $this->expectException(\RuntimeException::class);
        $this->service->start($id);
    }

    public function test_stop_takes_the_change_off_and_closes_the_prompt(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);

        $exp = $this->service->stop($id, 'Looked broken on iOS.');

        $this->assertSame('stopped', $exp->status);
        $this->assertSame('Looked broken on iOS.', $exp->decision);
        $this->assertSame([], $this->configArms());
        $this->assertSame('closed', DB::table('ux_prompts')->where('experiment_id', $id)->value('status'));
    }

    // ---------------------------------------------------------------- tick: reading

    public function test_tick_records_a_plain_english_read_and_keeps_running(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        Carbon::setTestNow('2026-10-14 16:00:00');
        $this->lokiArms([
            'control' => ['starts' => 200, 'completes' => 90, 'submitted' => 95, 'submit_ok' => 93, 'validation_failed' => 5, 'help_sought' => 2],
            'b' => ['starts' => 210, 'completes' => 110, 'submitted' => 112, 'submit_ok' => 110, 'validation_failed' => 4, 'help_sought' => 1],
        ], ['reply' => ['control' => [100, 70], 'b' => [100, 72]]]);

        $lines = $this->service->tick();

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('give-photos-skip: 6h', $lines[0]);
        $exp = $this->service->find($id);
        $this->assertSame('running', $exp->status);

        $read = DB::table('ux_experiment_reads')->where('experiment_id', $id)->first();
        $this->assertEquals(6.0, (float) $read->hours);
        $this->assertStringContainsString('After 6 hours: 210 people started an offer with the change and 200 without.', $read->summary);
        $this->assertStringContainsString('52% finished with the change, 45% without (+7.4 points)', $read->summary);
        $this->assertStringContainsString('Probability the change is better:', $read->summary);
        $this->assertStringContainsString('Enough people have been through', $read->summary);

        $metrics = json_decode($read->metrics, true);
        $this->assertSame(210, $metrics['arms']['b']['starts']);
        $this->assertSame(2, $metrics['arms']['b']['submit_failed']);
        $this->assertSame(['starts' => 100, 'completes' => 72], $metrics['arms']['b']['other_flows']['reply']);
        $this->assertSame(7.4, $metrics['primary']['b']['diff_points']);
        $this->assertTrue($metrics['primary']['b']['enough']);
        $names = array_column($metrics['guardrails'], 'name');
        $this->assertContains('submit_failed', $names);
        $this->assertContains('flow:reply', $names);
        // The query asked Loki for this experiment's arm, over the six hours since it started.
        $this->assertTrue($this->lokiSent('give-photos-skip:(?P<arm>', '[360m]'));
    }

    public function test_tick_moves_traffic_towards_the_winning_arm_but_keeps_a_control_floor(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        Carbon::setTestNow('2026-10-14 14:00:00');
        // Clearly better, but not yet past the early-adopt bar at these counts? Make it clear enough
        // to move traffic while keeping P below 0.95 by using modest counts.
        $this->lokiArms(['control' => ['starts' => 80, 'completes' => 36], 'b' => ['starts' => 80, 'completes' => 44]]);

        $this->service->tick();

        $arms = json_decode($this->service->find($id)->arms, true);
        $this->assertGreaterThan(50, $arms['b'][1] - $arms['b'][0] + 1, 'b should have more than half');
        $this->assertGreaterThanOrEqual(10, $arms['control'][1] - $arms['control'][0] + 1, 'control keeps its floor');
        $this->assertEquals($arms, $this->configArms()['give-photos-skip']);
    }

    public function test_tick_does_not_move_traffic_before_each_arm_has_enough_starts(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        Carbon::setTestNow('2026-10-14 11:00:00');
        $this->lokiArms(['control' => ['starts' => 20, 'completes' => 5], 'b' => ['starts' => 30, 'completes' => 20]]);

        $this->service->tick();

        $this->assertEquals(['control' => [0, 49], 'b' => [50, 99]], json_decode($this->service->find($id)->arms, true));
        $this->assertStringContainsString('Too few people so far', DB::table('ux_experiment_reads')->value('summary'));
    }

    // ---------------------------------------------------------------- tick: deciding

    public function test_a_clear_winner_is_adopted_early_and_served_to_everyone(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        Carbon::setTestNow('2026-10-14 18:00:00');
        $this->lokiArms(['control' => ['starts' => 300, 'completes' => 120], 'b' => ['starts' => 300, 'completes' => 170]]);

        $lines = $this->service->tick();

        $this->assertSame(['give-photos-skip: adopted'], $lines);
        $exp = $this->service->find($id);
        $this->assertSame('adopted', $exp->status);
        $this->assertStringStartsWith('Adopted early: the change was clearly better.', $exp->decision);
        $this->assertStringContainsString('57% finished against 40%', $exp->decision);
        $this->assertSame('b', json_decode($exp->result, true)['winner']);
        $this->assertSame(['give-photos-skip' => ['b' => [0, 99]]], $this->configArms());
        $this->assertSame('closed', DB::table('ux_prompts')->where('experiment_id', $id)->value('status'));
    }

    public function test_a_harmed_guardrail_reverts_early_whatever_the_main_result(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        Carbon::setTestNow('2026-10-14 18:00:00');
        // Completion up, but submissions failing far more often with the change.
        $this->lokiArms([
            'control' => ['starts' => 300, 'completes' => 120, 'submitted' => 130, 'submit_ok' => 128],
            'b' => ['starts' => 300, 'completes' => 170, 'submitted' => 180, 'submit_ok' => 140],
        ]);

        $this->service->tick();

        $exp = $this->service->find($id);
        $this->assertSame('reverted', $exp->status);
        $this->assertStringContainsString('submissions that failed got worse', $exp->decision);
        $this->assertSame([], $this->configArms());
    }

    public function test_a_change_that_hurts_another_flow_is_caught(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        Carbon::setTestNow('2026-10-14 18:00:00');
        $this->lokiArms(
            ['control' => ['starts' => 300, 'completes' => 150], 'b' => ['starts' => 300, 'completes' => 160]],
            ['reply' => ['control' => [200, 150], 'b' => [200, 100]]]
        );

        $this->service->tick();

        $exp = $this->service->find($id);
        $this->assertSame('reverted', $exp->status);
        $this->assertStringContainsString('reply completion got worse', $exp->decision);
    }

    public function test_at_the_end_a_probable_winner_is_adopted(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        Carbon::setTestNow('2026-10-15 10:17:00');
        // About +4 points on 400 a side: P(better) about 0.86, below the early bar, above adopt.
        $this->lokiArms(['control' => ['starts' => 400, 'completes' => 190], 'b' => ['starts' => 400, 'completes' => 205]]);

        $this->service->tick();

        $exp = $this->service->find($id);
        $this->assertSame('adopted', $exp->status);
        $this->assertStringStartsWith('Adopted at the end: the change was probably better.', $exp->decision);
    }

    public function test_at_the_end_too_few_people_is_inconclusive_and_off(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        Carbon::setTestNow('2026-10-15 10:17:00');
        $this->lokiArms(['control' => ['starts' => 30, 'completes' => 10], 'b' => ['starts' => 30, 'completes' => 20]]);

        $this->service->tick();

        $exp = $this->service->find($id);
        $this->assertSame('inconclusive', $exp->status);
        $this->assertStringContainsString('before enough people had been through each arm', $exp->decision);
        $this->assertSame([], $this->configArms());
    }

    public function test_at_the_end_a_coin_toss_is_inconclusive(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        Carbon::setTestNow('2026-10-15 10:17:00');
        $this->lokiArms(['control' => ['starts' => 400, 'completes' => 200], 'b' => ['starts' => 400, 'completes' => 203]]);

        $this->service->tick();

        $exp = $this->service->find($id);
        $this->assertSame('inconclusive', $exp->status);
        $this->assertStringContainsString('could have gone either way', $exp->decision);
    }

    public function test_members_reaction_is_written_into_the_decision(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        $prompt = DB::table('ux_prompts')->where('experiment_id', $id)->value('id');
        foreach ([[1, 'better'], [2, 'worse'], [3, 'worse'], [4, 'dont_care'], [5, 'dont_understand']] as [$u, $choice]) {
            DB::table('ux_prompt_responses')->insert(['prompt_id' => $prompt, 'userid' => $u, 'answer' => json_encode(['choice' => $choice]), 'comment' => $u === 2 ? 'Hate it' : null]);
        }
        Carbon::setTestNow('2026-10-15 10:17:00');
        $this->lokiArms(['control' => ['starts' => 400, 'completes' => 200], 'b' => ['starts' => 400, 'completes' => 203]]);

        $this->service->tick();

        $this->assertStringContainsString("Members asked: 1 said better, 2 worse, 1 don't care, 1 didn't understand.", $this->service->find($id)->decision);
        $reaction = $this->service->reaction($id);
        $this->assertSame(['Hate it'], $reaction['comments']);
    }

    // ---------------------------------------------------------------- tick: Loki trouble

    public function test_loki_down_mid_run_leaves_the_experiment_running(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->lokiDown = true;

        $lines = $this->service->tick();

        $this->assertStringContainsString('Loki unavailable', $lines[0]);
        $this->assertSame('running', $this->service->find($id)->status);
        $this->assertSame(0, DB::table('ux_experiment_reads')->count());
    }

    public function test_loki_down_at_the_end_reverts_to_be_safe(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        Carbon::setTestNow('2026-10-15 10:17:00');
        $this->lokiDown = true;

        $this->service->tick();

        $exp = $this->service->find($id);
        $this->assertSame('reverted', $exp->status);
        $this->assertStringContainsString('logs could not be read', $exp->decision);
    }

    public function test_loki_down_at_the_end_uses_a_recent_read_when_there_is_one(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        Carbon::setTestNow('2026-10-15 09:17:00');
        $this->lokiArms(['control' => ['starts' => 400, 'completes' => 190], 'b' => ['starts' => 400, 'completes' => 205]]);
        $this->service->tick();
        $this->assertSame('running', $this->service->find($id)->status);

        Carbon::setTestNow('2026-10-15 10:17:00');
        $this->lokiDown = true;
        $this->service->tick();

        $this->assertSame('adopted', $this->service->find($id)->status);
    }

    // ---------------------------------------------------------------- config

    public function test_close_takes_an_adopted_experiment_out_of_the_config(): void
    {
        $id = $this->service->create($this->attrs());
        $this->service->start($id);
        Carbon::setTestNow('2026-10-14 18:00:00');
        $this->lokiArms(['control' => ['starts' => 300, 'completes' => 120], 'b' => ['starts' => 300, 'completes' => 170]]);
        $this->service->tick();
        $this->assertSame(['give-photos-skip' => ['b' => [0, 99]]], $this->configArms());

        $exp = $this->service->close($id, 'https://github.com/Freegle/Iznik/pull/9999');

        $this->assertSame('closed', $exp->status);
        $this->assertSame('https://github.com/Freegle/Iznik/pull/9999', $exp->cleanup_pr_url);
        $this->assertSame([], $this->configArms());
    }

    public function test_tick_command_reports_overdue_cleanup(): void
    {
        $id = $this->service->create($this->attrs());
        DB::table('ux_experiments')->where('id', $id)->update(['status' => 'adopted', 'decided_at' => '2026-10-01 00:00:00']);

        $this->artisan('ux:experiments-tick')
            ->expectsOutputToContain('No running experiments.')
            ->expectsOutputToContain('Cleanup overdue: give-photos-skip was adopted on 2026-10-01')
            ->assertExitCode(0);
    }

    public function test_experiment_command_round_trip(): void
    {
        $this->artisan('ux:experiment create --flow=give --name=cmd-test --title=T --hypothesis=H --change=C --expected=E')
            ->expectsOutputToContain('Created experiment')
            ->assertExitCode(0);
        $this->artisan('ux:experiment start cmd-test')->expectsOutputToContain('cmd-test: running')->assertExitCode(0);
        $this->artisan('ux:experiment config')->expectsOutputToContain('"cmd-test":{')->assertExitCode(0);
        $this->assertEquals(['cmd-test' => ['control' => [0, 49], 'b' => [50, 99]]], $this->configArms());
        $this->artisan('ux:experiment stop cmd-test --reason=Enough')->expectsOutputToContain('cmd-test: stopped')->assertExitCode(0);
        $this->artisan('ux:experiment start nope')->expectsOutputToContain('No experiment nope')->assertExitCode(1);
        $this->artisan('ux:experiment create --flow=nowhere --name=x --title=T --hypothesis=H --change=C --expected=E')->assertExitCode(1);
    }

    public function test_prompt_command_opens_the_survey_and_awareness_questions_and_the_tick_closes_full_ones(): void
    {
        $this->artisan('ux:prompt survey --max=10')->expectsOutputToContain('Opened prompt')->assertExitCode(0);
        $survey = DB::table('ux_prompts')->where('kind', 'survey')->where('status', 'open')->first();
        $this->assertSame(7, count(json_decode($survey->options, true)));
        $this->assertSame(10, (int) $survey->max_responses);
        // A second survey replaces the first.
        $this->artisan('ux:prompt survey')->assertExitCode(0);
        $this->assertSame('closed', DB::table('ux_prompts')->where('id', $survey->id)->value('status'));

        $this->artisan('ux:prompt awareness --flow=chat_promise --question="Did you know you can promise an item to someone in a chat?" --body="In a chat about your offer, Promise marks it for that person." --image=https://img/promise.png')
            ->expectsOutputToContain('Opened prompt')->assertExitCode(0);
        $aw = DB::table('ux_prompts')->where('kind', 'awareness')->first();
        $this->assertSame('chat_promise', $aw->flow);
        $this->assertSame(['use', 'know', 'no', 'dont_understand'], array_column(json_decode($aw->options, true), 'key'));
        $this->assertSame('Where it is', json_decode($aw->images, true)[0]['caption']);
        $this->artisan('ux:prompt awareness --flow=x --question=q')->assertExitCode(1);

        // Ten answers fill the ten-answer survey; the tick closes it and leaves the others open.
        $open = DB::table('ux_prompts')->where('kind', 'survey')->where('status', 'open')->first();
        DB::table('ux_prompts')->where('id', $open->id)->update(['max_responses' => 2]);
        foreach ([1, 2] as $u) {
            DB::table('ux_prompt_responses')->insert(['prompt_id' => $open->id, 'userid' => $u, 'answer' => json_encode(['best' => 'give', 'worst' => 'login'])]);
        }
        $this->artisan('ux:experiments-tick')->expectsOutputToContain('Closed 1 prompt(s)')->assertExitCode(0);
        $this->assertSame('closed', DB::table('ux_prompts')->where('id', $open->id)->value('status'));
        $this->assertSame('open', DB::table('ux_prompts')->where('id', $aw->id)->value('status'));

        $this->artisan('ux:prompt close '.$aw->id)->expectsOutputToContain('Closed prompt')->assertExitCode(0);
        $this->artisan('ux:prompt close 999999')->assertExitCode(1);
        $this->artisan('ux:prompt list')->expectsOutputToContain('awareness')->assertExitCode(0);
    }
}
