<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\BetaBinomial;
use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

class UxExperimentServiceTest extends TestCase
{
    use FakesUxLoki;

    /** The one query the service reads an experiment with: by hour, by arm, flow, event, login (now and at the start of the flow) and ok. */
    private const HOURLY = '/sum by \(arm, flow, action_name, logged_in, start_logged_in, ok\)/';

    private UxExperimentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->fakeLoki();
        foreach (['ux_prompt_responses', 'ux_prompts', 'ux_ideas', 'ux_problems', 'ux_experiment_reads', 'ux_experiments', 'ux_flow_outcome_daily'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->delete();
        config()->set('freegle.ux.experiments.min_starts_per_arm', 50);
        // No rollup rows in these tests, so no power gate unless a test seeds volume.
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

    private function labels(string $arm, string $flow, string $event, string $loggedIn = 'false', string $ok = '', ?string $startLoggedIn = null): array
    {
        // A flow's events carry whether the member was signed in when it started; unless a test
        // says otherwise that is the same as now.
        return ['arm' => $arm, 'flow' => $flow, 'action_name' => $event, 'logged_in' => $loggedIn,
            'start_logged_in' => $startLoggedIn ?? $loggedIn, 'ok' => $ok];
    }

    /** Feed Loki with counts by arm for the experiment's flow and, optionally, other flows (one point each). */
    private function lokiArms(array $arms, array $other = []): void
    {
        $series = [];
        foreach ($arms as $arm => $c) {
            foreach (['flow_start' => 'starts', 'flow_complete' => 'completes',
                'validation_failed' => 'validation_failed', 'help_sought' => 'help_sought'] as $event => $key) {
                if (! empty($c[$key])) {
                    $series[] = [$this->labels($arm, 'give', $event), $c[$key]];
                }
            }
            if (! empty($c['submitted'])) {
                $ok = $c['submit_ok'] ?? 0;
                if ($ok > 0) {
                    $series[] = [$this->labels($arm, 'give', 'flow_submit_result', 'false', 'true'), $ok];
                }
                if ($c['submitted'] - $ok > 0) {
                    $series[] = [$this->labels($arm, 'give', 'flow_submit_result', 'false', 'false'), $c['submitted'] - $ok];
                }
            }
        }
        foreach ($other as $flow => $byArm) {
            foreach ($byArm as $arm => [$starts, $completes]) {
                $series[] = [$this->labels($arm, $flow, 'flow_start'), $starts];
                $series[] = [$this->labels($arm, $flow, 'flow_complete'), $completes];
            }
        }
        $this->lokiMatrix(self::HOURLY, $series);
    }

    /**
     * Feed Loki with hourly points: rows of [arm, event, logged in (bool), 'Y-m-d H:i' of the END of
     * the hour counted, count], all for the give flow.
     */
    private function lokiHourly(array $rows): void
    {
        $grouped = [];
        foreach ($rows as [$arm, $event, $in, $when, $n]) {
            $key = $arm.'|'.$event.'|'.($in ? 'true' : 'false');
            $grouped[$key]['labels'] = $this->labels($arm, 'give', $event, $in ? 'true' : 'false');
            $ts = Carbon::parse($when, 'UTC')->getTimestamp();
            $grouped[$key]['points'][$ts] = ($grouped[$key]['points'][$ts] ?? 0) + $n;
        }
        $this->lokiMatrix(self::HOURLY, array_map(fn ($g) => [$g['labels'], $g['points']], array_values($grouped)));
    }

    /** What the client reads, whichever shape an entry has: name => arms. */
    private function configArms(): array
    {
        return array_map(fn ($e) => $e['arms'] ?? $e, $this->configRaw());
    }

    private function configRaw(): array
    {
        return json_decode(DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->value('value') ?? '{}', true);
    }

    private function seedVolume(string $flow, int $startedPerDay, float $rate): void
    {
        for ($i = 1; $i <= 7; $i++) {
            DB::table('ux_flow_outcome_daily')->insert([
                'date' => Carbon::now()->subDays($i)->toDateString(), 'flow' => $flow,
                'started' => $startedPerDay, 'completed' => (int) round($startedPerDay * $rate),
            ]);
        }
    }

    /** A running experiment, started now. */
    private function running(array $over = []): int
    {
        $id = $this->service->create($this->attrs($over));
        $this->service->start($id);

        return $id;
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

    public function test_create_rejects_a_design_that_touches_an_unknown_flow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($this->attrs(['design' => ['touches' => ['give', 'checkout']]]));
    }

    public function test_create_keeps_only_the_design_keys_it_knows(): void
    {
        $id = $this->service->create($this->attrs(['design' => ['touches' => ['reply'], 'expected_points' => '3.5', 'anything' => 'else']]));
        $this->assertSame(['touches' => ['reply'], 'expected_points' => 3.5], json_decode($this->service->find($id)->design, true));
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
        $this->assertSame(['Better', 'Worse', 'Makes no difference to me', "I'm not sure what it's for"], array_column(json_decode($prompt->options, true), 'label'));
        $this->assertSame('For what you were doing, is it better or worse?', $prompt->question);
        $this->assertStringStartsWith('Here is a change we are trying: ', $prompt->body);
        // Members judge the change itself. Telling them the effect we hope for would lead them
        // towards "Better".
        $this->assertStringNotContainsString('More people finish', $prompt->body);
    }

    public function test_the_config_entry_of_a_running_experiment_expires_an_hour_after_it_ends(): void
    {
        $this->running();

        $entry = $this->configRaw()['give-photos-skip'];

        $this->assertEquals(['control' => [0, 49], 'b' => [50, 99]], $entry['arms']);
        $this->assertSame(Carbon::parse('2026-10-15 11:00:00', 'UTC')->getTimestamp(), $entry['until']);
        $this->assertArrayNotHasKey('adopted', $entry);
    }

    public function test_a_restart_begins_from_an_even_split_not_where_the_bandit_left_off(): void
    {
        $id = $this->running();
        // The bandit last left this at a skewed split, even with an empty arm.
        DB::table('ux_experiments')->where('id', $id)->update(['arms' => json_encode(['control' => [0, 63], 'b' => [64, 99]])]);
        $this->service->stop($id);
        $this->assertSame('stopped', $this->service->find($id)->status);

        $exp = $this->service->start($id);

        $this->assertEquals(['control' => [0, 49], 'b' => [50, 99]], json_decode($exp->arms, true));
        $this->assertEquals(['control' => [0, 49], 'b' => [50, 99]], $this->configArms()['give-photos-skip']);
    }

    public function test_a_draft_keeps_the_arms_it_was_created_with(): void
    {
        $id = $this->service->create($this->attrs(['arms' => ['control' => [0, 79], 'b' => [80, 99]]]));

        $exp = $this->service->start($id);

        $this->assertEquals(['control' => [0, 79], 'b' => [80, 99]], json_decode($exp->arms, true));
    }

    public function test_even_arms_share_everything_and_put_control_first(): void
    {
        $this->assertSame(['control' => [0, 49], 'b' => [50, 99]], UxExperimentService::evenArms(['b', 'control']));
        $this->assertSame(['control' => [0, 33], 'b' => [34, 66], 'c' => [67, 99]], UxExperimentService::evenArms(['c', 'b', 'control']));
        $this->assertSame(['control' => [0, 33], 'b' => [34, 66], 'c' => [67, 99]], UxExperimentService::evenArms(['b', 'c']));
    }

    public function test_the_config_never_publishes_an_empty_range(): void
    {
        $id = $this->running();
        DB::table('ux_experiments')->where('id', $id)->update(['arms' => json_encode(['control' => [0, 99], 'b' => [99, 98]])]);

        $this->service->rebuildConfig();

        $this->assertSame(['control' => [0, 99]], $this->configArms()['give-photos-skip']);
    }

    public function test_start_refuses_a_sixth_running_experiment(): void
    {
        config()->set('freegle.ux.experiments.max_concurrent', 2);
        $this->service->start($this->service->create($this->attrs(['name' => 'one', 'flow' => 'give'])));
        $this->service->start($this->service->create($this->attrs(['name' => 'two', 'flow' => 'ask'])));
        $third = $this->service->create($this->attrs(['name' => 'three', 'flow' => 'reply']));

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

    public function test_only_one_experiment_runs_per_flow(): void
    {
        $this->running(['name' => 'first']);
        $second = $this->service->create($this->attrs(['name' => 'second']));
        $elsewhere = $this->service->create($this->attrs(['name' => 'elsewhere', 'flow' => 'ask']));

        try {
            $this->service->start($second);
            $this->fail('a second experiment on the give flow was started');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('first is already running on the give flow', $e->getMessage());
        }
        $this->assertSame('draft', $this->service->find($second)->status);
        // Another flow is no problem, and once the first has finished the second may go.
        $this->assertSame('running', $this->service->start($elsewhere)->status);
        $this->service->stop('first');
        $this->assertSame('running', $this->service->start($second)->status);
    }

    public function test_stop_takes_the_change_off_and_closes_the_prompt(): void
    {
        $id = $this->running();

        $exp = $this->service->stop($id, 'Looked broken on iOS.');

        $this->assertSame('stopped', $exp->status);
        $this->assertSame('Looked broken on iOS.', $exp->decision);
        $this->assertSame([], $this->configArms());
        $this->assertSame('closed', DB::table('ux_prompts')->where('experiment_id', $id)->value('status'));
    }

    public function test_only_an_adopted_experiment_can_be_closed(): void
    {
        $id = $this->running();

        try {
            $this->service->close($id);
            $this->fail('a running experiment was closed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('only an adopted experiment can be closed', $e->getMessage());
        }
        $this->assertSame('running', $this->service->find($id)->status);
        $this->assertArrayHasKey('give-photos-skip', $this->configArms());
    }

    // ---------------------------------------------------------------- start: the power gate

    public function test_start_refuses_a_change_a_day_of_the_flow_could_hardly_show(): void
    {
        // 40 starts a day is 20 a side: five points is far inside the noise.
        $this->seedVolume('give', 40, 0.5);
        $id = $this->service->create($this->attrs());

        try {
            $this->service->start($id);
            $this->fail('an experiment with almost no power was started');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('would adopt a change of 5 points only', $e->getMessage());
            $this->assertStringContainsString('Merge this idea with another', $e->getMessage());
        }
        $this->assertSame('draft', $this->service->find($id)->status);
        $this->assertSame([], $this->configArms());
    }

    public function test_start_stores_the_power_and_the_guarded_flows_in_the_design(): void
    {
        $this->seedVolume('give', 20000, 0.5);
        $idea = DB::table('ux_ideas')->insertGetId(['flow' => 'give', 'title' => 'x', 'rationale' => 'y', 'expected' => 'z',
            'evidence' => json_encode(['expected_points' => 2.5]), 'status' => 'open']);
        $id = $this->service->create($this->attrs(['idea_id' => $idea]));

        $exp = $this->service->start($id);

        $design = json_decode($exp->design, true);
        $this->assertSame(['give', 'login'], $design['touches']);
        $this->assertSame(2.5, $design['expected_points']);
        $this->assertGreaterThan(0.95, $design['power']);
        $this->assertSame('give-photos-skip', $exp->name);
        // A day of 10,000 a side can show a change of about two points, and the read says so.
        $this->assertEqualsWithDelta(2.0, json_decode($exp->result, true)['mde_points'], 0.2);
    }

    public function test_the_power_gate_can_be_switched_off(): void
    {
        config()->set('freegle.ux.experiments.min_power', 0);
        $this->seedVolume('give', 40, 0.5);

        $exp = $this->service->start($this->service->create($this->attrs()));

        $this->assertSame('running', $exp->status);
        $this->assertLessThan(0.3, json_decode($exp->design, true)['power']);
    }

    // ---------------------------------------------------------------- the lock and conditional updates

    public function test_a_writer_gives_up_when_another_connection_holds_the_lock(): void
    {
        config()->set('freegle.ux.experiments.lock_wait_seconds', 1);
        $c = config('database.connections.mysql');
        $other = new \PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', $c['write']['host'][0], $c['port'], $c['database']), $c['username'], $c['password']);
        $this->assertSame(1, (int) $other->query("SELECT GET_LOCK('ux_experiments', 0)")->fetchColumn());
        $id = $this->service->create($this->attrs());

        try {
            $this->service->start($id);
            $this->fail('start went ahead while another writer held the lock');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('in progress', $e->getMessage());
        }
        $this->assertSame('draft', $this->service->find($id)->status);

        $other->query("SELECT RELEASE_LOCK('ux_experiments')");
        $this->assertSame('running', $this->service->start($id)->status);
        // And the lock was let go again, so the next writer is not kept waiting.
        $this->assertSame('stopped', $this->service->stop($id)->status);
        $this->assertSame(1, (int) $other->query("SELECT GET_LOCK('ux_experiments', 0)")->fetchColumn());
    }

    public function test_a_stop_that_lands_while_the_tick_is_reading_wins(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-14 18:00:00');
        // This read would adopt the change early. The stop arrives while Loki is being asked.
        $this->lokiArms(['control' => ['starts' => 300, 'completes' => 120], 'b' => ['starts' => 300, 'completes' => 170]]);
        [$pattern, $answer] = $this->lokiRules[0];
        $this->lokiRules[0] = [$pattern, function () use ($id, $answer) {
            $this->service->stop($id, 'Stopped from the tab.');

            return $answer;
        }];

        $lines = $this->service->tick();

        $this->assertSame(['give-photos-skip: no longer running, left alone'], $lines);
        $exp = $this->service->find($id);
        $this->assertSame('stopped', $exp->status);
        $this->assertStringStartsWith('Stopped from the tab.', $exp->decision);
        $this->assertSame([], $this->configArms());
        $this->assertSame(0, DB::table('ux_experiment_reads')->count());
    }

    public function test_a_decision_never_overwrites_an_experiment_someone_else_has_decided(): void
    {
        $id = $this->running();
        $exp = $this->service->find($id);
        DB::table('ux_experiments')->where('id', $id)->update(['status' => 'stopped', 'decision' => 'By hand.']);

        $finish = new \ReflectionMethod($this->service, 'finish');
        $done = $finish->invoke($this->service, $exp, 'adopted', 'Adopted.', null, Carbon::now('UTC'));

        $this->assertFalse($done);
        $this->assertSame('stopped', $this->service->find($id)->status);
        $this->assertSame('By hand.', $this->service->find($id)->decision);
    }

    // ---------------------------------------------------------------- tick: reading

    public function test_a_read_after_one_hour_says_hour(): void
    {
        $exp = $this->service->find($this->running());

        $this->assertSame('After 1 hour: nothing recorded yet.', $this->service->summary($exp, ['primary' => []], 1.0));
        $this->assertSame('After 1.5 hours: nothing recorded yet.', $this->service->summary($exp, ['primary' => []], 1.5));
    }

    public function test_tick_records_a_plain_english_read_and_keeps_running(): void
    {
        $id = $this->running(['design' => ['touches' => ['reply']]]);
        Carbon::setTestNow('2026-10-14 16:00:00');
        $this->lokiArms([
            'control' => ['starts' => 200, 'completes' => 90, 'submitted' => 95, 'submit_ok' => 93, 'validation_failed' => 5, 'help_sought' => 2],
            'b' => ['starts' => 210, 'completes' => 110, 'submitted' => 112, 'submit_ok' => 110, 'validation_failed' => 4, 'help_sought' => 1],
        ], ['reply' => ['control' => [100, 70], 'b' => [100, 72]], 'ask' => ['control' => [100, 10], 'b' => [100, 10]]]);

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
        // Only the flows the experiment declares are kept: reply, not ask.
        $this->assertSame(['reply' => ['starts' => 100, 'completes' => 72]], $metrics['arms']['b']['other_flows']);
        $this->assertSame(7.4, $metrics['primary']['b']['diff_points']);
        $this->assertTrue($metrics['primary']['b']['enough']);
        $names = array_column($metrics['guardrails'], 'name');
        $this->assertContains('submit_failed', $names);
        $this->assertContains('flow:reply', $names);
        $this->assertNotContains('flow:ask', $names);
        // The guardrail count is recorded: three own-flow measures and one other flow.
        $this->assertSame(4, $metrics['guardrails'][0]['k']);
        // The query asked Loki for this experiment's arm, and only for the flows it declared.
        $this->assertTrue($this->lokiSent('give-photos-skip:(?P<arm>', 'flow=~"give|reply"'));
    }

    public function test_only_flows_that_started_before_the_settle_cut_count_but_what_they_did_is_read_to_now(): void
    {
        $this->running();
        Carbon::setTestNow('2026-10-14 16:00:00');
        $captured = [];
        array_unshift($this->lokiRules, [self::HOURLY, function ($q) use (&$captured) {
            $captured = $q;

            return ['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]];
        }]);

        $this->service->tick();

        // The window runs to now, so a flow that started at 15:10 and finished at 15:50 counts
        // its completion; the flows counted at all are the ones that started by 15:15, which is
        // what started_ms says. Five whole hours between 10:00 and 16:00 after the first.
        $this->assertSame(Carbon::parse('2026-10-14 16:00:00', 'UTC')->getTimestamp() * 1_000_000_000, (int) $captured['end']);
        $this->assertSame(Carbon::parse('2026-10-14 11:00:00', 'UTC')->getTimestamp() * 1_000_000_000, (int) $captured['start']);
        $this->assertSame('3600', (string) $captured['step']);
        $cut = Carbon::parse('2026-10-14 15:15:00', 'UTC')->getTimestamp() * 1000;
        $this->assertStringContainsString("| started_ms <= $cut |", $captured['query']);
        // Lines with no started_ms are dropped by a line filter first, or the numeric filter would
        // raise an error on them instead of skipping them.
        $this->assertStringContainsString('|= "started_ms" | json', $captured['query']);
    }

    public function test_after_the_end_the_window_runs_to_the_end_and_flows_are_cut_before_it(): void
    {
        $this->running();
        Carbon::setTestNow('2026-10-15 13:17:00');
        $captured = [];
        array_unshift($this->lokiRules, [self::HOURLY, function ($q) use (&$captured) {
            $captured = $q;

            return ['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]];
        }]);

        $this->service->tick();

        $this->assertSame(Carbon::parse('2026-10-15 10:00:00', 'UTC')->getTimestamp() * 1_000_000_000, (int) $captured['end']);
        $cut = Carbon::parse('2026-10-15 09:15:00', 'UTC')->getTimestamp() * 1000;
        $this->assertStringContainsString("| started_ms <= $cut |", $captured['query']);
    }

    public function test_a_flow_that_signs_the_member_in_is_compared_by_who_they_were_when_it_started(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-15 09:00:00');
        // A login-like flow: every start is logged out, and every completion is logged in because
        // finishing IS signing in. Control finishes 40%, the variant 44%.
        $series = [];
        foreach (['control' => 400, 'b' => 440] as $arm => $completes) {
            $series[] = [$this->labels($arm, 'give', 'flow_start', 'false', '', 'false'), 1000];
            $series[] = [$this->labels($arm, 'give', 'flow_complete', 'true', '', 'false'), $completes];
        }
        $this->lokiMatrix(self::HOURLY, $series);

        $this->service->tick();

        $metrics = json_decode(DB::table('ux_experiment_reads')->where('experiment_id', $id)->value('metrics'), true);
        // One stratum, not an all-starts one and an all-completes one: that shape gives P near
        // one half whatever the effect.
        $this->assertSame(1, $metrics['primary']['b']['strata']);
        $this->assertGreaterThan(0.9, $metrics['primary']['b']['p_better']);
        $this->assertEquals(4.0, $metrics['primary']['b']['diff_points']);
    }

    public function test_a_line_without_the_start_stamp_falls_back_to_logged_in_now(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $labels = fn ($arm, $event) => ['arm' => $arm, 'flow' => 'give', 'action_name' => $event, 'logged_in' => 'true', 'ok' => ''];
        $this->lokiMatrix(self::HOURLY, [
            [$labels('control', 'flow_start'), 100], [$labels('control', 'flow_complete'), 40],
            [$labels('b', 'flow_start'), 100], [$labels('b', 'flow_complete'), 50],
        ]);

        $this->service->tick();

        $metrics = json_decode(DB::table('ux_experiment_reads')->where('experiment_id', $id)->value('metrics'), true);
        $this->assertSame(1, $metrics['primary']['b']['strata']);
        $this->assertCount(1, $metrics['arms']['b']['strata']);
        $this->assertStringStartsWith('in|', array_key_first($metrics['arms']['b']['strata']));
    }

    public function test_the_arm_is_matched_at_the_start_of_the_variant_field_or_after_a_comma(): void
    {
        $this->running(['name' => 'cta']);
        Carbon::setTestNow('2026-10-14 16:00:00');
        $this->lokiArms(['control' => ['starts' => 10], 'b' => ['starts' => 10]]);
        $this->service->tick();

        $query = collect($this->lokiQueries)->first(fn ($q) => str_contains($q, 'sum by (arm, flow'));
        preg_match('/variant=~"([^"]*)"/', $query, $label);
        preg_match('/\| regexp "((?:[^"\\\\]|\\\\.)*)"/', $query, $stage);
        $this->assertNotEmpty($label, 'no variant label filter in: '.$query);
        $this->assertNotEmpty($stage, 'no regexp stage in: '.$query);
        $pattern = str_replace('\\"', '"', $stage[1]);

        $arm = function (string $variant) use ($pattern) {
            return preg_match('/'.$pattern.'/', json_encode(['variant' => $variant], JSON_UNESCAPED_SLASHES), $m) ? $m['arm'] : null;
        };
        $accepts = fn (string $variant) => (bool) preg_match('/^(?:'.$label[1].')$/', $variant);

        $this->assertSame('b', $arm('cta:b'));
        $this->assertSame('control', $arm('other:b,cta:control,third:a'));
        // Another experiment whose name ends in this one is not this experiment.
        $this->assertNull($arm('login_cta:b'));
        $this->assertNull($arm('x:b,give-cta:control'));
        $this->assertNull($arm('mycta:b'));
        $this->assertTrue($accepts('cta:b'));
        $this->assertTrue($accepts('x:a,cta:b'));
        $this->assertFalse($accepts('login_cta:b'));
        $this->assertFalse($accepts('x:a,give-cta:b'));
    }

    public function test_a_name_that_could_break_the_query_is_refused_before_it_reaches_loki(): void
    {
        $now = Carbon::now('UTC');
        $row = [
            'flow' => 'give', 'title' => 't', 'hypothesis' => 'h', 'change_description' => 'c', 'expected' => 'e',
            'arms' => json_encode(['control' => [0, 49], 'b' => [50, 99]]), 'status' => 'running',
            'started_at' => $now, 'ends_at' => $now->copy()->addDay(), 'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('ux_experiments')->insert($row + ['name' => 'bad"name']);
        $bad = $this->service->find('bad"name');

        try {
            $this->service->read($bad, $now->copy()->addHours(3));
            $this->fail('read accepted a name that is not safe in LogQL');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not safe', $e->getMessage());
        }
        DB::table('ux_experiments')->where('id', $bad->id)->update(['name' => 'fine', 'flow' => 'give" or 1=1']);
        try {
            $this->service->read($this->service->find('fine'), $now->copy()->addHours(3));
            $this->fail('read accepted a flow that is not known');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not a known flow', $e->getMessage());
        }
        $this->assertSame([], $this->lokiQueries);

        // One bad row does not stop the others being read.
        DB::table('ux_experiments')->where('id', $bad->id)->update(['name' => 'bad"name', 'flow' => 'give']);
        DB::table('ux_experiments')->insert($row + ['name' => 'good', 'flow' => 'ask']);
        Carbon::setTestNow($now->copy()->addHours(3));
        $lines = $this->service->tick();
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('error, left alone', $lines[0]);
        $this->assertStringContainsString('good: 3h', $lines[1]);
    }

    public function test_tick_moves_traffic_towards_the_winning_arm_but_keeps_both_arms_in_the_band(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-14 14:00:00');
        // Better, with enough people to move traffic on, but short of the early-adopt bar.
        $this->lokiArms(['control' => ['starts' => 320, 'completes' => 144], 'b' => ['starts' => 320, 'completes' => 165]]);

        $this->service->tick();

        $arms = json_decode($this->service->find($id)->arms, true);
        $this->assertSame('running', $this->service->find($id)->status);
        $b = $arms['b'][1] - $arms['b'][0] + 1;
        $control = $arms['control'][1] - $arms['control'][0] + 1;
        $this->assertGreaterThan(50, $b, 'b should have more than half');
        $this->assertLessThanOrEqual(65, $b, 'a variant never takes more than 65');
        $this->assertGreaterThanOrEqual(35, $control, 'control keeps its floor');
        $this->assertSame(100, $b + $control);
        $this->assertSame(0, $arms['control'][0]);
        $this->assertSame(99, $arms['b'][1]);
        $this->assertEquals($arms, $this->configArms()['give-photos-skip']);
    }

    public function test_the_split_follows_the_stratified_comparison_not_the_raw_rates(): void
    {
        // The same data as the day/night test: the raw rates say b is far ahead, the like-with-like
        // comparison says nothing is going on. The split must stay near even.
        $id = $this->running();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $night = '2026-10-15 03:00';
        $day = '2026-10-14 15:00';
        $this->lokiHourly([
            ['control', 'flow_start', false, $night, 200], ['control', 'flow_complete', false, $night, 40],
            ['control', 'flow_start', false, $day, 100], ['control', 'flow_complete', false, $day, 60],
            ['b', 'flow_start', false, $night, 100], ['b', 'flow_complete', false, $night, 20],
            ['b', 'flow_start', false, $day, 200], ['b', 'flow_complete', false, $day, 120],
        ]);

        $this->service->tick();

        $arms = json_decode($this->service->find($id)->arms, true);
        $b = $arms['b'][1] - $arms['b'][0] + 1;
        $this->assertGreaterThan(40, $b);
        $this->assertLessThan(60, $b);
    }

    public function test_the_split_never_leaves_an_arm_empty_or_inverted_whatever_the_evidence(): void
    {
        $arms = ['control' => [0, 49], 'b' => [50, 99]];
        foreach ([0.0, 0.001, 0.2, 0.5, 0.8, 0.999, 1.0] as $p) {
            $read = ['arms' => ['control' => ['starts' => 400], 'b' => ['starts' => 400]], 'primary' => ['b' => ['p_better' => $p]]];
            $out = $this->service->allocate($read, $arms);
            foreach ($out as $arm => [$from, $to]) {
                $this->assertLessThanOrEqual($to, $from, "arm $arm is empty at p=$p");
                $share = $to - $from + 1;
                $this->assertGreaterThanOrEqual(35, $share, "arm $arm is below the floor at p=$p");
                $this->assertLessThanOrEqual(65, $share, "arm $arm is above the cap at p=$p");
            }
            $this->assertSame([0, 99], [$out['control'][0], $out['b'][1]]);
            $this->assertSame($out['control'][1] + 1, $out['b'][0], 'contiguous');
        }
    }

    public function test_a_variant_that_is_unlucky_for_a_while_keeps_enough_traffic_to_recover(): void
    {
        $read = ['arms' => ['control' => ['starts' => 400], 'b' => ['starts' => 400]], 'primary' => ['b' => ['p_better' => 0.02]]];

        $out = $this->service->allocate($read, ['control' => [0, 49], 'b' => [50, 99]]);

        // 64 and 36: not the 98 and 2 the raw evidence would suggest, and never an empty range.
        $this->assertSame([0, 63], $out['control']);
        $this->assertSame([64, 99], $out['b']);
    }

    public function test_more_than_two_arms_each_keep_a_share_and_the_ranges_cover_everything(): void
    {
        $read = ['arms' => ['control' => ['starts' => 400], 'b' => ['starts' => 400], 'c' => ['starts' => 400]],
            'primary' => ['b' => ['p_better' => 0.9], 'c' => ['p_better' => 0.1]]];

        $out = $this->service->allocate($read, ['control' => [0, 33], 'b' => [34, 66], 'c' => [67, 99]]);

        $sum = 0;
        foreach ($out as $arm => [$from, $to]) {
            $this->assertGreaterThanOrEqual(23, $to - $from + 1, "arm $arm");
            $sum += $to - $from + 1;
        }
        $this->assertSame(100, $sum);
        $this->assertGreaterThan($out['c'][1] - $out['c'][0], $out['b'][1] - $out['b'][0], 'the likelier winner gets more');
    }

    public function test_nothing_moves_without_a_comparison_to_go_on(): void
    {
        $arms = ['control' => [0, 49], 'b' => [50, 99]];
        $read = ['arms' => ['control' => ['starts' => 400], 'b' => ['starts' => 400]], 'primary' => []];

        $this->assertSame($arms, $this->service->allocate($read, $arms));
    }

    public function test_tick_does_not_move_traffic_before_each_arm_has_enough_starts(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-14 11:00:00');
        $this->lokiArms(['control' => ['starts' => 20, 'completes' => 5], 'b' => ['starts' => 30, 'completes' => 20]]);
        $this->service->tick();
        $this->assertEquals(['control' => [0, 49], 'b' => [50, 99]], json_decode($this->service->find($id)->arms, true));
        $this->assertStringContainsString('Too few people so far', DB::table('ux_experiment_reads')->value('summary'));

        // Even 250 on each side, past the minimum to read it, is too few to move anyone on.
        Carbon::setTestNow('2026-10-14 13:00:00');
        $this->lokiArms(['control' => ['starts' => 250, 'completes' => 100], 'b' => ['starts' => 250, 'completes' => 140]]);
        $this->service->tick();
        $this->assertEquals(['control' => [0, 49], 'b' => [50, 99]], json_decode($this->service->find($id)->arms, true));
    }

    public function test_allocating_traffic_does_not_reseed_the_process_wide_generator(): void
    {
        mt_srand(4242);
        $expected = [mt_rand(), mt_rand()];
        mt_srand(4242);
        $read = ['window_minutes' => 300, 'arms' => ['control' => ['starts' => 400, 'completes' => 150], 'b' => ['starts' => 400, 'completes' => 190]],
            'primary' => ['b' => ['p_better' => 0.9]]];
        $this->service->allocate($read, ['control' => [0, 49], 'b' => [50, 99]]);
        BetaBinomial::probabilityDiffExceeds(10, 90, 20, 80, 0.0);
        BetaBinomial::stratifiedDiff([['sa' => 10, 'fa' => 90, 'sb' => 20, 'fb' => 80]]);

        $this->assertSame($expected, [mt_rand(), mt_rand()]);
    }

    // ---------------------------------------------------------------- tick: strata

    public function test_a_moving_split_with_a_day_night_swing_does_not_adopt_a_null_change(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $night = '2026-10-15 03:00'; // the hour 02:00 to 03:00, in the 00 to 06 block
        $day = '2026-10-14 15:00';   // the hour 14:00 to 15:00, in the 12 to 18 block
        // The change does nothing: 20% finish at night and 60% by day, in both arms. The bandit
        // happened to send control mostly night traffic and the variant mostly day traffic.
        $this->lokiHourly([
            ['control', 'flow_start', false, $night, 200], ['control', 'flow_complete', false, $night, 40],
            ['control', 'flow_start', false, $day, 100], ['control', 'flow_complete', false, $day, 60],
            ['b', 'flow_start', false, $night, 100], ['b', 'flow_complete', false, $night, 20],
            ['b', 'flow_start', false, $day, 200], ['b', 'flow_complete', false, $day, 120],
        ]);

        $lines = $this->service->tick();

        // Pooled, this is 47% against 33% and would be adopted at once (300 a side, P over 0.999).
        $this->assertGreaterThan(0.99, BetaBinomial::probabilityBGreaterThanA(100, 200, 140, 160));
        $metrics = json_decode(DB::table('ux_experiment_reads')->where('experiment_id', $id)->value('metrics'), true);
        $this->assertGreaterThan(10, $metrics['primary']['b']['diff_points'], 'the raw difference is large');
        $this->assertSame(2, $metrics['primary']['b']['strata']);
        $this->assertGreaterThan(0.25, $metrics['primary']['b']['p_better']);
        $this->assertLessThan(0.75, $metrics['primary']['b']['p_better']);
        $this->assertSame('running', $this->service->find($id)->status);
        $this->assertStringStartsWith('give-photos-skip: 23h', $lines[0]);
    }

    public function test_a_swing_in_who_is_signed_in_is_compared_like_with_like_too(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $hour = '2026-10-14 15:00';
        // Signed-in members finish 80% of the time, visitors 20%, in both arms; the variant got
        // mostly signed-in members.
        $this->lokiHourly([
            ['control', 'flow_start', true, $hour, 100], ['control', 'flow_complete', true, $hour, 80],
            ['control', 'flow_start', false, $hour, 200], ['control', 'flow_complete', false, $hour, 40],
            ['b', 'flow_start', true, $hour, 200], ['b', 'flow_complete', true, $hour, 160],
            ['b', 'flow_start', false, $hour, 100], ['b', 'flow_complete', false, $hour, 20],
        ]);

        $this->service->tick();

        $metrics = json_decode(DB::table('ux_experiment_reads')->where('experiment_id', $id)->value('metrics'), true);
        $this->assertGreaterThan(10, $metrics['primary']['b']['diff_points']);
        $this->assertLessThan(0.75, $metrics['primary']['b']['p_better']);
        $this->assertSame('running', $this->service->find($id)->status);
    }

    public function test_a_real_effect_inside_every_stratum_is_still_found(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $night = '2026-10-15 03:00';
        $day = '2026-10-14 15:00';
        // The variant adds ten points in both blocks.
        $this->lokiHourly([
            ['control', 'flow_start', false, $night, 300], ['control', 'flow_complete', false, $night, 60],
            ['control', 'flow_start', false, $day, 300], ['control', 'flow_complete', false, $day, 180],
            ['b', 'flow_start', false, $night, 300], ['b', 'flow_complete', false, $night, 90],
            ['b', 'flow_start', false, $day, 300], ['b', 'flow_complete', false, $day, 210],
        ]);

        $this->service->tick();

        $this->assertSame('adopted', $this->service->find($id)->status);
    }

    // ---------------------------------------------------------------- tick: deciding

    public function test_a_clear_winner_is_adopted_early_and_served_to_most_with_a_holdout(): void
    {
        $idea = DB::table('ux_ideas')->insertGetId(['flow' => 'give', 'title' => 'x', 'rationale' => 'y', 'expected' => 'z', 'status' => 'open']);
        $id = $this->running(['idea_id' => $idea]);
        Carbon::setTestNow('2026-10-14 18:00:00');
        $this->lokiArms(['control' => ['starts' => 300, 'completes' => 120], 'b' => ['starts' => 300, 'completes' => 170]]);

        $lines = $this->service->tick();

        $this->assertSame(['give-photos-skip: adopted'], $lines);
        $exp = $this->service->find($id);
        $this->assertSame('adopted', $exp->status);
        $this->assertStringStartsWith('Adopted early: the change was clearly better.', $exp->decision);
        $this->assertStringContainsString('57% finished against 40%', $exp->decision);
        $this->assertSame('b', json_decode($exp->result, true)['winner']);
        $this->assertSame('closed', DB::table('ux_prompts')->where('experiment_id', $id)->value('status'));
        $this->assertSame('done', DB::table('ux_ideas')->where('id', $idea)->value('status'));

        $entry = $this->configRaw()['give-photos-skip'];
        $this->assertSame(['b' => [0, 89], 'control' => [90, 99]], $entry['arms']);
        $this->assertTrue($entry['adopted']);
        $this->assertSame(Carbon::parse('2026-10-29 18:00:00', 'UTC')->getTimestamp(), $entry['until']);
    }

    public function test_the_holdout_share_is_a_setting(): void
    {
        config()->set('freegle.ux.experiments.holdout_share', 20);
        $this->running();
        Carbon::setTestNow('2026-10-14 18:00:00');
        $this->lokiArms(['control' => ['starts' => 300, 'completes' => 120], 'b' => ['starts' => 300, 'completes' => 170]]);
        $this->service->tick();

        $this->assertSame(['b' => [0, 79], 'control' => [80, 99]], $this->configArms()['give-photos-skip']);
    }

    public function test_a_modest_result_is_not_adopted_early_even_with_many_people(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-14 18:00:00');
        // About +4 points on 400 a side: P(better) near 0.86, nowhere near 0.99.
        $this->lokiArms(['control' => ['starts' => 400, 'completes' => 190], 'b' => ['starts' => 400, 'completes' => 205]]);

        $this->service->tick();

        $this->assertSame('running', $this->service->find($id)->status);
    }

    public function test_a_harmed_guardrail_reverts_early_whatever_the_main_result(): void
    {
        $id = $this->running();
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
        $this->assertStringContainsString('by more than 2 points', $exp->decision);
        $this->assertSame([], $this->configArms());
    }

    public function test_a_guardrail_that_is_only_slightly_worse_is_ignored(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-14 18:00:00');
        // 5% against 6% of 8,000 submissions failing: worse, but by one point, inside the margin.
        $this->lokiArms([
            'control' => ['starts' => 400, 'completes' => 200, 'submitted' => 8000, 'submit_ok' => 7600],
            'b' => ['starts' => 400, 'completes' => 200, 'submitted' => 8000, 'submit_ok' => 7520],
        ]);

        $this->service->tick();

        $this->assertSame('running', $this->service->find($id)->status);
        $metrics = json_decode(DB::table('ux_experiment_reads')->where('experiment_id', $id)->value('metrics'), true);
        $failed = collect($metrics['guardrails'])->firstWhere('name', 'submit_failed');
        $this->assertLessThan(0.05, $failed['p_worse']);
    }

    public function test_a_guardrail_is_not_read_before_enough_events_are_behind_it(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-14 18:00:00');
        // 2 of 40 against 20 of 40 failing is stark, but 80 submissions in all is under the floor of 100.
        $this->lokiArms([
            'control' => ['starts' => 400, 'completes' => 200, 'submitted' => 40, 'submit_ok' => 38],
            'b' => ['starts' => 400, 'completes' => 200, 'submitted' => 40, 'submit_ok' => 20],
        ]);

        $this->service->tick();

        $this->assertSame('running', $this->service->find($id)->status);
        $metrics = json_decode(DB::table('ux_experiment_reads')->where('experiment_id', $id)->value('metrics'), true);
        $this->assertNotContains('submit_failed', array_column($metrics['guardrails'], 'name'));
    }

    public function test_a_change_that_hurts_a_declared_flow_is_caught(): void
    {
        $id = $this->running(['design' => ['touches' => ['reply']]]);
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

    public function test_a_flow_the_change_does_not_declare_is_not_a_guardrail(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-14 18:00:00');
        $this->lokiArms(
            ['control' => ['starts' => 300, 'completes' => 150], 'b' => ['starts' => 300, 'completes' => 160]],
            ['reply' => ['control' => [200, 150], 'b' => [200, 100]]]
        );

        $this->service->tick();

        $this->assertSame('running', $this->service->find($id)->status);
        $this->assertFalse($this->lokiSent('flow=~"give|login|reply"'));
        $this->assertTrue($this->lokiSent('flow=~"give|login"'));
    }

    /** A read shaped like read()'s, for deciding on figures chosen to sit either side of a bar. */
    private function readWith(float $pWorse, int $k, int $starts = 400): array
    {
        return [
            'arms' => ['control' => ['starts' => $starts, 'completes' => 200], 'b' => ['starts' => $starts, 'completes' => 200]],
            'primary' => ['b' => ['p_better' => 0.5, 'expected_loss_points' => 1.0, 'control_rate' => 0.5, 'variant_rate' => 0.5,
                'diff_points' => 0.0, 'enough' => true]],
            'guardrails' => [['arm' => 'b', 'name' => 'submit_failed', 'label' => 'submissions that failed',
                'control_rate' => 0.05, 'variant_rate' => 0.09, 'p_worse' => $pWorse, 'k' => $k]],
        ];
    }

    private function expAt(): object
    {
        return (object) ['ends_at' => '2026-10-15 10:00:00'];
    }

    public function test_the_guardrail_bars_are_shared_between_the_guardrails(): void
    {
        $before = Carbon::parse('2026-10-14 20:00:00');
        $end = Carbon::parse('2026-10-15 10:00:00');

        // One guardrail: the early bar is 0.97.
        $this->assertSame('reverted', $this->service->decide($this->expAt(), $this->readWith(0.975, 1), $before)['status']);
        // Nine guardrails: the same figure is one of nine chances to be fooled, so the bar is 0.9967.
        $this->assertNull($this->service->decide($this->expAt(), $this->readWith(0.975, 9), $before));
        $this->assertNull($this->service->decide($this->expAt(), $this->readWith(0.99, 9), $before));
        $this->assertSame('reverted', $this->service->decide($this->expAt(), $this->readWith(0.998, 9), $before)['status']);
        // At the end the bar is 1 - 0.10 / 9 = 0.9889.
        $this->assertSame('reverted', $this->service->decide($this->expAt(), $this->readWith(0.99, 9), $end)['status']);
        $this->assertStringStartsWith('Reverted at the end', $this->service->decide($this->expAt(), $this->readWith(0.99, 9), $end)['text']);
        $this->assertStringContainsString('could have gone either way', $this->service->decide($this->expAt(), $this->readWith(0.98, 9), $end)['text']);
    }

    public function test_an_early_revert_waits_for_enough_starts_on_both_arms(): void
    {
        $before = Carbon::parse('2026-10-14 20:00:00');

        $this->assertNull($this->service->decide($this->expAt(), $this->readWith(0.999, 1, 120), $before));
        $this->assertSame('reverted', $this->service->decide($this->expAt(), $this->readWith(0.999, 1, 300), $before)['status']);
    }

    public function test_a_stale_read_is_never_used_to_adopt_early(): void
    {
        $read = $this->readWith(0.5, 1);
        $read['primary']['b'] = ['p_better' => 0.995, 'expected_loss_points' => 0.1, 'control_rate' => 0.4, 'variant_rate' => 0.5,
            'diff_points' => 10.0, 'enough' => true];
        $before = Carbon::parse('2026-10-14 20:00:00');

        $this->assertSame('adopted', $this->service->decide($this->expAt(), $read, $before)['status']);
        $this->assertNull($this->service->decide($this->expAt(), $read, $before, true));
    }

    public function test_at_the_end_a_probable_winner_is_adopted(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-15 10:17:00');
        // About +4 points on 400 a side: P(better) about 0.86, below the early bar, above adopt.
        $this->lokiArms(['control' => ['starts' => 400, 'completes' => 190], 'b' => ['starts' => 400, 'completes' => 205]]);

        $this->service->tick();

        $exp = $this->service->find($id);
        $this->assertSame('adopted', $exp->status);
        $this->assertStringStartsWith('Adopted at the end: the change was probably better.', $exp->decision);
    }

    public function test_at_the_end_a_probable_winner_is_not_adopted_when_the_loss_if_wrong_is_too_big(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-15 10:17:00');
        // 75 of 150 against 83 of 150: P(better) 0.82, which clears 0.80, but if it were not better
        // we would expect to lose more than half a point, and 150 a side cannot rule that out.
        $this->lokiArms(['control' => ['starts' => 150, 'completes' => 75], 'b' => ['starts' => 150, 'completes' => 83]]);

        $this->service->tick();

        $exp = $this->service->find($id);
        $metrics = json_decode(DB::table('ux_experiment_reads')->where('experiment_id', $id)->value('metrics'), true);
        $this->assertGreaterThanOrEqual(0.80, $metrics['primary']['b']['p_better']);
        $this->assertGreaterThan(0.5, $metrics['primary']['b']['expected_loss_points']);
        $this->assertSame('inconclusive', $exp->status);
        $this->assertStringContainsString('we would lose more than 0.5 points', $exp->decision);
        $this->assertSame([], $this->configArms());
    }

    public function test_the_expected_loss_bar_is_a_setting(): void
    {
        config()->set('freegle.ux.experiments.max_expected_loss_points', 1.0);
        $id = $this->running();
        Carbon::setTestNow('2026-10-15 10:17:00');
        $this->lokiArms(['control' => ['starts' => 150, 'completes' => 75], 'b' => ['starts' => 150, 'completes' => 83]]);

        $this->service->tick();

        $this->assertSame('adopted', $this->service->find($id)->status);
    }

    public function test_at_the_end_a_probable_loser_is_reverted(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-15 10:17:00');
        $this->lokiArms(['control' => ['starts' => 400, 'completes' => 205], 'b' => ['starts' => 400, 'completes' => 188]]);

        $this->service->tick();

        $this->assertSame('reverted', $this->service->find($id)->status);
    }

    public function test_at_the_end_too_few_people_is_inconclusive_and_off(): void
    {
        $id = $this->running();
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
        $id = $this->running();
        Carbon::setTestNow('2026-10-15 10:17:00');
        $this->lokiArms(['control' => ['starts' => 400, 'completes' => 200], 'b' => ['starts' => 400, 'completes' => 203]]);

        $this->service->tick();

        $exp = $this->service->find($id);
        $this->assertSame('inconclusive', $exp->status);
        $this->assertStringContainsString('could have gone either way', $exp->decision);
    }

    public function test_members_reaction_is_written_into_the_decision(): void
    {
        $id = $this->running();
        $prompt = DB::table('ux_prompts')->where('experiment_id', $id)->value('id');
        foreach ([[1, 'better'], [2, 'worse'], [3, 'worse'], [4, 'dont_care'], [5, 'dont_understand']] as [$u, $choice]) {
            DB::table('ux_prompt_responses')->insert(['prompt_id' => $prompt, 'userid' => $u, 'answer' => json_encode(['choice' => $choice]), 'comment' => $u === 2 ? 'Hate it' : null]);
        }
        // A member who skipped is in no count.
        DB::table('ux_prompt_responses')->insert(['prompt_id' => $prompt, 'userid' => 6, 'answer' => json_encode(['skipped' => true])]);
        Carbon::setTestNow('2026-10-15 10:17:00');
        $this->lokiArms(['control' => ['starts' => 400, 'completes' => 200], 'b' => ['starts' => 400, 'completes' => 203]]);

        $this->service->tick();

        $this->assertStringContainsString('Members asked: 1 said better, 2 worse, 1 that it makes no difference to them, 1 that they were not sure what it is for.', $this->service->find($id)->decision);
        $reaction = $this->service->reaction($id);
        $this->assertSame(['Hate it'], $reaction['comments']);
        $this->assertSame(5, $reaction['total']);
    }

    // ---------------------------------------------------------------- ideas after a test

    private function ideaRun(int $idea, string $name, string $startAt): int
    {
        Carbon::setTestNow($startAt);
        $id = $this->service->create($this->attrs(['name' => $name, 'idea_id' => $idea]));
        $this->service->start($id);
        Carbon::setTestNow(Carbon::parse($startAt, 'UTC')->addHours(24)->addMinutes(17));
        $this->lokiArms(['control' => ['starts' => 400, 'completes' => 200], 'b' => ['starts' => 400, 'completes' => 203]]);
        $this->service->tick();

        return $id;
    }

    public function test_an_inconclusive_test_returns_the_idea_to_the_backlog_twice_then_uses_it_up(): void
    {
        $idea = DB::table('ux_ideas')->insertGetId(['flow' => 'give', 'title' => 'x', 'rationale' => 'y', 'expected' => 'z', 'status' => 'open']);

        $first = $this->ideaRun($idea, 'first-try', '2026-10-14 10:00:00');
        $this->assertSame('inconclusive', $this->service->find($first)->status);
        $row = DB::table('ux_ideas')->where('id', $idea)->first();
        $this->assertSame('open', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNull($row->experiment_id);

        $second = $this->ideaRun($idea, 'second-try', '2026-10-16 10:00:00');
        $this->assertSame('inconclusive', $this->service->find($second)->status);
        $row = DB::table('ux_ideas')->where('id', $idea)->first();
        $this->assertSame('done', $row->status);
        $this->assertSame(2, (int) $row->attempts);
    }

    public function test_a_reverted_idea_is_done_and_a_rejected_one_is_left_alone(): void
    {
        $idea = DB::table('ux_ideas')->insertGetId(['flow' => 'give', 'title' => 'x', 'rationale' => 'y', 'expected' => 'z', 'status' => 'open']);
        $id = $this->running(['idea_id' => $idea]);
        $this->service->stop($id);
        $this->assertSame('done', DB::table('ux_ideas')->where('id', $idea)->value('status'));

        $rejected = DB::table('ux_ideas')->insertGetId(['flow' => 'give', 'title' => 'r', 'rationale' => 'y', 'expected' => 'z', 'status' => 'rejected']);
        $other = $this->service->create($this->attrs(['name' => 'other', 'idea_id' => $rejected]));
        DB::table('ux_experiments')->where('id', $other)->update(['status' => 'running', 'started_at' => '2026-10-14 10:00:00', 'ends_at' => '2026-10-15 10:00:00']);
        $exp = $this->service->find($other);
        $finish = new \ReflectionMethod($this->service, 'finish');
        $finish->invoke($this->service, $exp, 'inconclusive', 'Either way.', null, Carbon::now('UTC'));
        $this->assertSame('rejected', DB::table('ux_ideas')->where('id', $rejected)->value('status'));
    }

    // ---------------------------------------------------------------- the holdout after adoption

    /** Adopt an experiment early at 18:00 on the first day. */
    private function adopted(): int
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-14 18:00:00');
        $this->lokiArms(['control' => ['starts' => 300, 'completes' => 120], 'b' => ['starts' => 300, 'completes' => 170]]);
        $this->service->tick();
        $this->assertSame('adopted', $this->service->find($id)->status);
        $this->lokiRules = [];
        $this->lokiQueries = [];

        return $id;
    }

    public function test_when_the_holdout_does_clearly_better_on_two_looks_in_a_row_the_adoption_is_reversed(): void
    {
        $id = $this->adopted();
        Carbon::setTestNow('2026-10-17 19:00:00');
        // Three days on, the change finishes 30% and the people who kept the old version 50%.
        $this->lokiArms(['b' => ['starts' => 900, 'completes' => 270], 'control' => ['starts' => 330, 'completes' => 165]]);

        $lines = $this->service->tick();

        // One look is not enough: thirteen daily looks would undo good changes by chance.
        $this->assertStringContainsString('holdout checked after 3 days, still adopted', $lines[0]);
        $this->assertSame('adopted', $this->service->find($id)->status);
        $this->assertSame(1, json_decode($this->service->find($id)->result, true)['holdout_check']['strikes']);

        Carbon::setTestNow('2026-10-18 19:00:00');
        $lines = $this->service->tick();

        $this->assertSame(['give-photos-skip: reverted after adoption (the holdout did better)'], $lines);
        $exp = $this->service->find($id);
        $this->assertSame('reverted', $exp->status);
        $this->assertStringStartsWith('Reverted after adoption: the people who kept the old version did better over 4 days, on 2 looks in a row', $exp->decision);
        $this->assertStringNotContainsString('did not last', $exp->decision, 'it does not claim to know the gain decayed');
        $result = json_decode($exp->result, true);
        $this->assertTrue($result['decayed']);
        $this->assertSame('b', $result['winner']);
        $this->assertSame([], $this->configArms());
        // The read ran from the decision to now, not from the start.
        $this->assertTrue($this->lokiSent('give-photos-skip'));
    }

    public function test_a_look_that_is_not_clear_starts_the_count_again(): void
    {
        $id = $this->adopted();
        Carbon::setTestNow('2026-10-17 19:00:00');
        $this->lokiArms(['b' => ['starts' => 900, 'completes' => 270], 'control' => ['starts' => 330, 'completes' => 165]]);
        $this->service->tick();
        $this->assertSame(1, json_decode($this->service->find($id)->result, true)['holdout_check']['strikes']);

        // The next day the two are level.
        Carbon::setTestNow('2026-10-18 19:00:00');
        $this->lokiArms(['b' => ['starts' => 900, 'completes' => 450], 'control' => ['starts' => 330, 'completes' => 165]]);
        $this->service->tick();
        $this->assertSame(0, json_decode($this->service->find($id)->result, true)['holdout_check']['strikes']);

        // And a clear look the day after that is a first one again, not a second.
        Carbon::setTestNow('2026-10-19 19:00:00');
        $this->lokiArms(['b' => ['starts' => 900, 'completes' => 270], 'control' => ['starts' => 330, 'completes' => 165]]);
        $this->service->tick();
        $this->assertSame('adopted', $this->service->find($id)->status);
        $this->assertSame(1, json_decode($this->service->find($id)->result, true)['holdout_check']['strikes']);
    }

    public function test_one_adopted_experiment_that_cannot_be_checked_does_not_stop_the_rest_or_the_config(): void
    {
        $broken = $this->adopted();
        // A row the check cannot read at all.
        DB::table('ux_experiments')->where('id', $broken)->update(['arms' => 'null']);
        $other = DB::table('ux_experiments')->insertGetId([
            'flow' => 'reply', 'name' => 'reply-cta', 'title' => 't', 'hypothesis' => 'h', 'change_description' => 'c', 'expected' => 'e',
            'arms' => json_encode(['control' => [0, 49], 'b' => [50, 99]]), 'status' => 'adopted',
            'started_at' => '2026-10-14 10:00:00', 'decided_at' => '2026-10-14 18:00:00', 'result' => json_encode(['winner' => 'b']),
            'created_at' => '2026-10-14 10:00:00', 'updated_at' => '2026-10-14 18:00:00',
        ]);
        Carbon::setTestNow('2026-10-15 19:00:00');

        $lines = $this->service->tick();

        $this->assertStringContainsString('give-photos-skip: holdout check failed, left alone', $lines[0]);
        $this->assertStringContainsString('reply-cta: holdout checked after 1 day', $lines[1]);
        $this->assertNotNull(json_decode($this->service->find($other)->result, true)['holdout_check']);
        // The config was still rebuilt after the failure.
        $this->assertArrayHasKey('reply-cta', $this->configArms());
    }

    public function test_a_holdout_that_does_no_better_leaves_the_adoption_alone_and_is_checked_once_a_day(): void
    {
        $id = $this->adopted();
        Carbon::setTestNow('2026-10-15 19:00:00');
        $this->lokiArms(['b' => ['starts' => 900, 'completes' => 450], 'control' => ['starts' => 330, 'completes' => 165]]);

        $lines = $this->service->tick();

        $this->assertStringContainsString('holdout checked after 1 day, still adopted', $lines[0]);
        $this->assertSame('adopted', $this->service->find($id)->status);
        $check = json_decode($this->service->find($id)->result, true)['holdout_check'];
        $this->assertSame(900, $check['adopted_starts']);
        $this->assertSame(330, $check['holdout_starts']);
        $this->assertSame(['b' => [0, 89], 'control' => [90, 99]], $this->configArms()['give-photos-skip']);

        // An hour later is the same day: no new query.
        $queries = count($this->lokiQueries);
        Carbon::setTestNow('2026-10-15 20:00:00');
        $this->assertSame([], $this->service->tick());
        $this->assertCount($queries, $this->lokiQueries);

        // The next day it looks again.
        Carbon::setTestNow('2026-10-16 19:30:00');
        $this->assertCount(1, $this->service->tick());
        $this->assertGreaterThan($queries, count($this->lokiQueries));
    }

    public function test_a_holdout_too_small_to_read_is_not_acted_on(): void
    {
        $id = $this->adopted();
        Carbon::setTestNow('2026-10-15 19:00:00');
        $this->lokiArms(['b' => ['starts' => 900, 'completes' => 100], 'control' => ['starts' => 120, 'completes' => 90]]);

        $this->service->tick();

        $this->assertSame('adopted', $this->service->find($id)->status);
    }

    public function test_the_holdout_is_only_watched_for_fourteen_days_and_not_in_the_first_day(): void
    {
        $id = $this->adopted();
        // Adopted at 18:00 on the 14th: 21 hours on is too soon for the first look.
        $this->assertSame([], $this->service->tick(Carbon::parse('2026-10-15 15:00:00', 'UTC')));
        $this->assertSame([], $this->lokiQueries);

        // Long after the fourteen days there is no look either.
        DB::table('ux_experiments')->where('id', $id)->update(['decided_at' => '2026-09-20 10:00:00']);
        $this->assertSame([], $this->service->tick(Carbon::parse('2026-10-16 15:00:00', 'UTC')));
        $this->assertSame([], $this->lokiQueries);
    }

    public function test_closing_removes_the_adopted_experiment_and_its_holdout_from_the_config(): void
    {
        $id = $this->adopted();

        $exp = $this->service->close($id, 'https://github.com/Freegle/Iznik/pull/9999');

        $this->assertSame('closed', $exp->status);
        $this->assertSame('https://github.com/Freegle/Iznik/pull/9999', $exp->cleanup_pr_url);
        $this->assertSame([], $this->configArms());
    }

    // ---------------------------------------------------------------- tick: Loki trouble

    public function test_loki_down_mid_run_leaves_the_experiment_running(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->lokiDown = true;

        $lines = $this->service->tick();

        $this->assertStringContainsString('Loki unavailable', $lines[0]);
        $this->assertSame('running', $this->service->find($id)->status);
        $this->assertSame(0, DB::table('ux_experiment_reads')->count());
    }

    public function test_loki_down_at_the_end_waits_and_then_gives_up_as_inconclusive(): void
    {
        $id = $this->running();
        $this->lokiDown = true;

        Carbon::setTestNow('2026-10-15 10:17:00');
        $lines = $this->service->tick();
        $this->assertStringContainsString('waiting for it', $lines[0]);
        $this->assertSame('running', $this->service->find($id)->status);

        Carbon::setTestNow('2026-10-15 15:17:00');
        $this->service->tick();
        $this->assertSame('running', $this->service->find($id)->status);

        // Six hours past the end: nothing can be said, and the change comes off. Not "reverted":
        // nothing was learned about it.
        Carbon::setTestNow('2026-10-15 16:17:00');
        $this->service->tick();
        $exp = $this->service->find($id);
        $this->assertSame('inconclusive', $exp->status);
        $this->assertStringContainsString('logs could not be read', $exp->decision);
        $this->assertSame([], $this->configArms());
    }

    public function test_loki_down_at_the_end_uses_a_recent_read_when_there_is_one(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-15 09:17:00');
        $this->lokiArms(['control' => ['starts' => 400, 'completes' => 190], 'b' => ['starts' => 400, 'completes' => 205]]);
        $this->service->tick();
        $this->assertSame('running', $this->service->find($id)->status);

        Carbon::setTestNow('2026-10-15 10:17:00');
        $this->lokiDown = true;
        $this->service->tick();

        $this->assertSame('adopted', $this->service->find($id)->status);
    }

    public function test_a_read_more_than_two_hours_old_is_never_used_to_adopt(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-15 07:17:00');
        $this->lokiArms(['control' => ['starts' => 400, 'completes' => 190], 'b' => ['starts' => 400, 'completes' => 205]]);
        $this->service->tick();

        Carbon::setTestNow('2026-10-15 10:17:00');
        $this->lokiDown = true;
        $lines = $this->service->tick();

        $this->assertStringContainsString('waiting for it', $lines[0]);
        $this->assertSame('running', $this->service->find($id)->status);
    }

    // ---------------------------------------------------------------- config

    public function test_close_takes_an_adopted_experiment_out_of_the_config(): void
    {
        $id = $this->adopted();
        $this->assertSame(['b' => [0, 89], 'control' => [90, 99]], $this->configArms()['give-photos-skip']);

        $exp = $this->service->close($id, 'https://github.com/Freegle/Iznik/pull/9999');

        $this->assertSame('closed', $exp->status);
        $this->assertSame([], $this->configArms());
    }

    public function test_tick_command_reports_overdue_cleanup(): void
    {
        $id = $this->service->create($this->attrs());
        DB::table('ux_experiments')->where('id', $id)->update(['status' => 'adopted', 'decided_at' => '2026-09-20 00:00:00']);

        $this->artisan('ux:experiments-tick')
            ->expectsOutputToContain('No running experiments.')
            ->expectsOutputToContain('Cleanup overdue: give-photos-skip was adopted on 2026-09-20')
            ->assertExitCode(0);
    }

    public function test_experiment_command_round_trip(): void
    {
        $this->artisan('ux:experiment create --flow=give --name=cmd-test --title=T --hypothesis=H --change=C --expected=E --touches=give,reply --expected-points=3')
            ->expectsOutputToContain('Created experiment')
            ->assertExitCode(0);
        $this->assertEquals(['touches' => ['give', 'reply'], 'expected_points' => 3], json_decode($this->service->find('cmd-test')->design, true));
        $this->artisan('ux:experiment start cmd-test')->expectsOutputToContain('cmd-test: running')->assertExitCode(0);
        $this->artisan('ux:experiment config')->expectsOutputToContain('"cmd-test":{')->assertExitCode(0);
        $this->assertEquals(['cmd-test' => ['control' => [0, 49], 'b' => [50, 99]]], $this->configArms());
        $this->artisan('ux:experiment stop cmd-test --reason=Enough')->expectsOutputToContain('cmd-test: stopped')->assertExitCode(0);
        $this->artisan('ux:experiment start nope')->expectsOutputToContain('No experiment nope')->assertExitCode(1);
        $this->artisan('ux:experiment create --flow=nowhere --name=x --title=T --hypothesis=H --change=C --expected=E')->assertExitCode(1);
        $this->artisan('ux:experiment create --flow=give --name=y --title=T --hypothesis=H --change=C --expected=E --touches=give,checkout')->assertExitCode(1);
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

        $this->artisan('ux:prompt awareness --flow=chat_promise --action="promise an item to someone in a chat" --reveal="In a chat about your offer, Promise marks it for that person." --link=/chats')
            ->expectsOutputToContain('Opened prompt')->assertExitCode(0);
        $aw = DB::table('ux_prompts')->where('kind', 'awareness')->first();
        $this->assertSame('chat_promise', $aw->flow);
        $this->assertSame('On Freegle, is it possible to promise an item to someone in a chat?', $aw->question);
        $this->assertSame(['yes', 'no', 'not_sure'], array_column(json_decode($aw->options, true), 'key'));
        $this->assertEquals(['reveal' => 'In a chat about your offer, Promise marks it for that person.', 'link' => '/chats', 'decoy' => false], json_decode($aw->settings, true));
        $this->artisan('ux:prompt awareness --flow=x --action=q')->assertExitCode(1);
        $this->artisan('ux:prompt awareness --action="pay a courier" --reveal="Freegle does not do that." --decoy')->expectsOutputToContain('Opened prompt')->assertExitCode(0);
        $this->assertTrue((bool) json_decode(DB::table('ux_prompts')->where('question', 'On Freegle, is it possible to pay a courier?')->value('settings'), true)['decoy']);
        $this->artisan('ux:prompt awareness --action="x" --reveal="y" --link=https://evil.example')->assertExitCode(1);

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
