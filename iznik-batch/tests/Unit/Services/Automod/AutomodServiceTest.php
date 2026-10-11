<?php

namespace Tests\Unit\Services\Automod;

use App\Models\MessageAutomod;
use App\Models\MessageGroup;
use App\Services\Automod\AutomodFactsService;
use App\Services\Automod\AutomodService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\LokiSpy;
use Tests\TestCase;

/**
 * Covers AutomodService::review() (the single POST /review call and its
 * success/unavailable/persist-false paths) and ::process() (the Pending-candidate
 * query and loop that drives it), per plans/active/automod-flowchart.md.
 */
class AutomodServiceTest extends TestCase
{
    private AutomodService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AutomodService();

        config([
            'freegle.automod.url' => 'http://automod-test:8080',
            'freegle.automod.timeout' => 20,
            'freegle.automod.shadow_group_ids' => '',
            'freegle.autoapprove.enabled' => false,
            'freegle.autoapprove.trial_group_ids' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Creates a user, group and Pending, content-checked message - a candidate row
     * AutomodService::process() would pick up - without seeding a messages_automod row.
     * Mirrors AutoApproveCleanServiceTest::makeApprovable()'s shape.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\Group, 2: \App\Models\Message}
     */
    private function makeCandidate(array $opts = []): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup($opts['group'] ?? []);
        $this->createMembership($user, $group, $opts['membership'] ?? []);
        $message = $this->createTestMessage($user, $group, $opts['message'] ?? []);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(array_merge([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subMinutes(21),
                'contentcheck_checked_at' => now()->subMinutes(20),
                'contentcheck_reasons' => null,
                'deleted' => 0,
                'rippled_in' => 0,
            ], $opts['mg'] ?? []));

        return [$user, $group, $message];
    }

    private function fakeReviewResponse(array $body): void
    {
        Http::fake(['*/review' => Http::response(json_encode($body), 200)]);
    }

    private function automodRow(int $msgid, int $groupid)
    {
        return DB::table('messages_automod')
            ->where('msgid', $msgid)
            ->where('groupid', $groupid)
            ->first();
    }

    // --- review() -----------------------------------------------------------------

    public function test_review_records_verdict_on_success(): void
    {
        [$user, $group, $message] = $this->makeCandidate();

        $this->fakeReviewResponse([
            'version' => 'v3',
            'verdict' => MessageAutomod::VERDICT_APPROVE,
            'end' => 'APPROVE',
            'reason' => 'Trusted member, low-risk item',
            'path' => ['start', 'member_ok', 'APPROVE'],
        ]);

        $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        $this->assertSame(MessageAutomod::VERDICT_APPROVE, $result['verdict']);

        $row = $this->automodRow($message->id, $group->id);
        $this->assertNotNull($row);
        $this->assertSame(MessageAutomod::MODE_SHADOW, $row->mode);
        $this->assertSame('v3', $row->chart_version);
        $this->assertSame(MessageAutomod::VERDICT_APPROVE, $row->verdict);
        $this->assertSame('APPROVE', $row->end_node);

        Http::assertSent(function ($request) use ($message, $group) {
            $data = $request->data();

            return str_contains($request->url(), '/review')
                && $data['msgid'] === $message->id
                && $data['groupid'] === $group->id
                && array_key_exists('facts', $data)
                && array_key_exists('rules', $data);
        });
    }

    public function test_review_includes_backend_in_payload_when_given(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        $this->fakeReviewResponse(['version' => 'v1', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end' => 'HOLD']);

        $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW, 'claude');

        Http::assertSent(fn ($request) => ($request->data()['backend'] ?? null) === 'claude');
    }

    public function test_review_omits_backend_key_when_not_given(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        $this->fakeReviewResponse(['version' => 'v1', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end' => 'HOLD']);

        $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        Http::assertSent(fn ($request) => !array_key_exists('backend', $request->data()));
    }

    public function test_review_returns_null_for_missing_message(): void
    {
        Http::fake();

        $result = $this->service->review(999999999, 1, MessageAutomod::MODE_SHADOW);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_review_records_unavailable_on_http_error_status(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        Http::fake(['*/review' => Http::response('Server Error', 500)]);

        $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        $this->assertNull($result);
        $row = $this->automodRow($message->id, $group->id);
        $this->assertSame('UNAVAILABLE', $row->end_node);
        $this->assertSame(MessageAutomod::VERDICT_HOLD, $row->verdict);
    }

    public function test_review_records_unavailable_on_unparseable_body(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        Http::fake(['*/review' => Http::response('not json', 200)]);

        $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        $this->assertNull($result);
        $row = $this->automodRow($message->id, $group->id);
        $this->assertSame('UNAVAILABLE', $row->end_node);
        $this->assertSame(MessageAutomod::VERDICT_HOLD, $row->verdict);
    }

    public function test_review_records_unavailable_on_thrown_exception(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('connection refused');
        });

        $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        $this->assertNull($result);
        $row = $this->automodRow($message->id, $group->id);
        $this->assertSame('UNAVAILABLE', $row->end_node);
        $this->assertSame(MessageAutomod::VERDICT_HOLD, $row->verdict);
    }

    public function test_review_persist_false_does_not_write_row_on_success(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        $this->fakeReviewResponse(['version' => 'v1', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE']);

        $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW, null, false);

        $this->assertSame(MessageAutomod::VERDICT_APPROVE, $result['verdict']);
        $this->assertSame(0, DB::table('messages_automod')
            ->where('msgid', $message->id)->where('groupid', $group->id)->count());
    }

    public function test_review_persist_false_does_not_write_row_on_failure(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        Http::fake(['*/review' => Http::response('Server Error', 500)]);

        $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW, null, false);

        $this->assertNull($result);
        $this->assertSame(0, DB::table('messages_automod')
            ->where('msgid', $message->id)->where('groupid', $group->id)->count());
    }

    public function test_review_rerun_updates_rather_than_duplicates_row(): void
    {
        [$user, $group, $message] = $this->makeCandidate();

        // Http::fake keeps the first stub that matches, so the two answers come as a sequence.
        Http::fake(['*/review' => Http::sequence()
            ->push(json_encode(['version' => 'v1', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end' => 'HOLD']), 200)
            ->push(json_encode(['version' => 'v2', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE']), 200)]);

        $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);
        $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        $this->assertSame(1, DB::table('messages_automod')
            ->where('msgid', $message->id)->where('groupid', $group->id)->count());

        $row = $this->automodRow($message->id, $group->id);
        $this->assertSame('v2', $row->chart_version);
        $this->assertSame(MessageAutomod::VERDICT_APPROVE, $row->verdict);
    }

    // --- process() ------------------------------------------------------------------

    public function test_process_returns_zero_stats_when_automod_is_dark_everywhere(): void
    {
        Http::fake();
        [$user, $group, $message] = $this->makeCandidate();

        $stats = $this->service->process();

        $this->assertSame(['reviewed' => 0, 'unavailable' => 0, 'errors' => 0, 'timeboxed' => 0], $stats);
        Http::assertNothingSent();
    }

    public function test_process_reviews_pending_candidate_with_no_existing_row(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate();
        $this->fakeReviewResponse(['version' => 'v1', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE']);

        $stats = $this->service->process();

        $this->assertSame(1, $stats['reviewed']);
        $this->assertSame(0, $stats['unavailable']);
        $this->assertSame(0, $stats['errors']);
        $this->assertNotNull($this->automodRow($message->id, $group->id));
    }

    public function test_process_skips_existing_unedited_row(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate();
        MessageAutomod::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'mode' => MessageAutomod::MODE_APPROVE,
            'chart_version' => 'test',
            'verdict' => MessageAutomod::VERDICT_APPROVE,
            'end_node' => 'APPROVE',
            'reason' => null,
            'path' => [],
            'created' => now(),
        ]);
        Http::fake();

        $stats = $this->service->process();

        $this->assertSame(0, $stats['reviewed']);
        Http::assertNothingSent();
    }

    public function test_process_reprocesses_row_older_than_edit(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate();
        MessageAutomod::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'mode' => MessageAutomod::MODE_APPROVE,
            'chart_version' => 'test',
            'verdict' => MessageAutomod::VERDICT_APPROVE,
            'end_node' => 'APPROVE',
            'reason' => null,
            'path' => [],
            'created' => now()->subMinutes(30),
        ]);
        DB::table('messages')->where('id', $message->id)->update(['editedat' => now()->subMinutes(5)]);
        // The content check has already seen the edit.
        DB::table('messages_groups')->where('msgid', $message->id)->update(['contentcheck_checked_at' => now()->subMinutes(4)]);
        $this->fakeReviewResponse(['version' => 'v2', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end' => 'HOLD']);

        $stats = $this->service->process();

        $this->assertSame(1, $stats['reviewed']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/review'));
    }

    public function test_process_excludes_deleted_rows(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate(['mg' => ['deleted' => 1]]);
        Http::fake();

        $stats = $this->service->process();

        $this->assertSame(0, $stats['reviewed']);
        Http::assertNothingSent();
    }

    public function test_process_excludes_rippled_in_rows(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate(['mg' => ['rippled_in' => 1]]);
        Http::fake();

        $stats = $this->service->process();

        $this->assertSame(0, $stats['reviewed']);
        Http::assertNothingSent();
    }

    public function test_process_excludes_not_content_checked_rows(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate(['mg' => ['contentcheck_checked_at' => null]]);
        Http::fake();

        $stats = $this->service->process();

        $this->assertSame(0, $stats['reviewed']);
        Http::assertNothingSent();
    }

    public function test_process_counts_errors_when_facts_service_throws(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate();
        Http::fake();

        $service = new AutomodService(new class extends AutomodFactsService {
            public function facts(int $msgid, int $groupid): array
            {
                throw new \Exception('boom');
            }
        });

        $stats = $service->process();

        $this->assertSame(0, $stats['reviewed']);
        $this->assertSame(1, $stats['errors']);
        $this->assertSame(0, DB::table('messages_automod')
            ->where('msgid', $message->id)->where('groupid', $group->id)->count());
    }

    public function test_a_community_that_moderates_everything_is_decided_without_the_service(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        DB::table("groups")->where("id", $group->id)->update(["settings" => json_encode(["moderated" => 1])]);
        Http::fake();

        $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        Http::assertNothingSent();
        $this->assertSame("GROUP_MODERATES_ALL", $result["end"]);
        $row = $this->automodRow($message->id, $group->id);
        $this->assertSame(MessageAutomod::VERDICT_HOLD, $row->verdict);
        $this->assertSame("GROUP_MODERATES_ALL", $row->end_node);
    }

    public function test_a_decision_the_review_could_not_make_is_tried_again_after_five_minutes(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate();
        DB::table("messages_automod")->insert([
            "msgid" => $message->id, "groupid" => $group->id, "mode" => MessageAutomod::MODE_SHADOW,
            "chart_version" => "", "verdict" => MessageAutomod::VERDICT_HOLD, "end_node" => "UNAVAILABLE",
            "reason" => "Automated review unavailable", "path" => "[]", "created" => now()->subMinutes(10),
        ]);
        $this->fakeReviewResponse(["version" => "5", "verdict" => MessageAutomod::VERDICT_APPROVE, "end" => "APPROVE"]);

        $stats = $this->service->process();

        $this->assertSame(1, $stats["reviewed"]);
        $this->assertSame("APPROVE", $this->automodRow($message->id, $group->id)->end_node);
    }

    public function test_the_request_carries_the_members_other_open_posts(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        // Earlier than the post under review: only earlier posts are listed (the later of two
        // similar posts is the duplicate).
        $this->createTestMessage($user, $group, ["subject" => "OFFER: Kettle (Leeds)", "arrival" => now()->subMinutes(5)]);
        $this->fakeReviewResponse(["version" => "5", "verdict" => MessageAutomod::VERDICT_APPROVE, "end" => "APPROVE"]);

        $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $facts = (array) ($body["facts"] ?? []);

            return isset($body["other_posts"][0]) && str_contains($body["other_posts"][0], "Kettle")
                && ($facts["has_other_posts"] ?? false) === true
                && !array_key_exists("other_posts", $facts);
        });
    }

    // --- edits, staleness and the decision timestamp ---------------------------------------

    private function seedDecision(int $msgid, int $groupid, Carbon $created, string $verdict = MessageAutomod::VERDICT_APPROVE, string $end = 'APPROVE'): void
    {
        MessageAutomod::create([
            'msgid' => $msgid, 'groupid' => $groupid, 'mode' => MessageAutomod::MODE_APPROVE,
            'chart_version' => 'test', 'verdict' => $verdict, 'end_node' => $end, 'reason' => null,
            'path' => [], 'created' => $created,
        ]);
    }

    /**
     * An edit re-runs the content check (contentcheck_checked_at moves past editedat) and the
     * chart, in the same minute. The chart's facts come from the content check's findings, so it
     * must not re-decide until the check has seen the edited text: otherwise a phone number or
     * link typed in after the first decision is judged on the findings for the text before it.
     */
    public function test_a_post_edited_since_its_content_check_waits_for_the_check_to_re_run(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate(['mg' => ['contentcheck_checked_at' => now()->subMinutes(20)]]);
        $this->seedDecision($message->id, $group->id, now()->subMinutes(30));
        DB::table('messages')->where('id', $message->id)->update(['editedat' => now()->subMinutes(5)]);
        $this->fakeReviewResponse(['version' => '5', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end' => 'HOLD_MONEY', 'path' => []]);

        $stats = $this->service->process();

        $this->assertSame(0, $stats['reviewed'], 'the content check has not seen the edit yet');
        Http::assertNothingSent();

        DB::table('messages_groups')->where('msgid', $message->id)->update(['contentcheck_checked_at' => now()->subMinutes(4)]);

        $stats = $this->service->process();

        $this->assertSame(1, $stats['reviewed'], 'reviewed once the check has caught up');
        $this->assertSame('HOLD_MONEY', $this->automodRow($message->id, $group->id)->end_node);
    }

    /**
     * The row is dated from when the text was read, not when the reply was written. A member
     * who edits while the model is still answering (10 to 20 seconds) gets a decision about the
     * text before the edit, and dating it later would make that decision look current.
     */
    public function test_the_decision_is_dated_from_when_the_text_was_read(): void
    {
        $readAt = Carbon::create(2026, 10, 11, 12, 0, 0);
        Carbon::setTestNow($readAt);
        [$user, $group, $message] = $this->makeCandidate();
        Http::fake(function () use ($message, $readAt) {
            DB::table('messages')->where('id', $message->id)->update(['editedat' => $readAt->copy()->addSeconds(5)]);
            Carbon::setTestNow($readAt->copy()->addSeconds(15));

            return Http::response(json_encode(['version' => '5', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE', 'path' => []]), 200);
        });

        $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        $row = $this->automodRow($message->id, $group->id);
        $this->assertSame($readAt->toDateTimeString(), Carbon::parse($row->created)->toDateTimeString());
        $this->assertSame(1, DB::table('messages as m')
            ->join('messages_automod as ma', 'ma.msgid', '=', 'm.id')
            ->where('m.id', $message->id)
            ->whereColumn('m.editedat', '>', 'ma.created')
            ->count(), 'the edit postdates the decision, so the next run re-reads the post');
    }

    // --- candidates the clean path could never publish --------------------------------------

    public function test_process_skips_posts_the_clean_path_could_never_publish(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        Http::fake();

        [$user] = $this->makeCandidate(['mg' => ['heldby' => $this->createTestUser()->id]]);
        $this->makeCandidate(['mg' => ['spamreason' => 'Known spammer']]);
        [$user3, $group3, $message3] = $this->makeCandidate();
        DB::table('messages')->where('id', $message3->id)->update(['heldby' => $user3->id]);
        [$user4, $group4, $message4] = $this->makeCandidate();
        DB::table('messages')->where('id', $message4->id)->update(['spamreason' => 'Bulk mail']);
        [$user5, $group5, $message5] = $this->makeCandidate();
        DB::table('messages')->where('id', $message5->id)->update(['deleted' => now()]);
        [$user6] = $this->makeCandidate();
        DB::table('users')->where('id', $user6->id)->update(['deleted' => now()]);

        $stats = $this->service->process();

        $this->assertSame(0, $stats['reviewed']);
        Http::assertNothingSent();
    }

    // --- the reply's shape -------------------------------------------------------------------

    public function test_a_malformed_decision_is_recorded_as_unavailable(): void
    {
        [$user, $group, $message] = $this->makeCandidate();

        foreach ([
            ['verdict' => MessageAutomod::VERDICT_APPROVE],
            ['verdict' => 'reject', 'end' => 'REJECT', 'path' => []],
            ['verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => '', 'path' => []],
            ['verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE', 'path' => 'not a list'],
        ] as $body) {
            $this->fakeReviewResponse($body);

            $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_APPROVE);

            $this->assertNull($result, json_encode($body));
            $row = $this->automodRow($message->id, $group->id);
            $this->assertSame(MessageAutomod::VERDICT_HOLD, $row->verdict, json_encode($body));
            $this->assertSame('UNAVAILABLE', $row->end_node, json_encode($body));
        }
    }

    // --- what the service is sent, and what it says back -------------------------------------

    public function test_the_request_carries_a_deadline_and_the_reply_detail_is_stored(): void
    {
        config(['freegle.automod.timeout' => 20]);
        [$user, $group, $message] = $this->makeCandidate();
        $this->fakeReviewResponse([
            'version' => '5', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE', 'path' => [],
            'features' => ['items' => ['kettle']], 'ms' => 1234, 'calls' => 1, 'model' => 'claude-opus-5',
            'cost_usd' => 0.012, 'tokens' => ['input' => 900, 'output' => 120], 'prompt_sha' => 'abc', 'reply_sha' => 'def',
        ]);

        $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        Http::assertSent(fn ($request) => ($request->data()['deadline_ms'] ?? null) === 18000);
        $row = MessageAutomod::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertSame('claude-opus-5', $row->detail['model']);
        $this->assertSame(1234, $row->detail['ms']);
        $this->assertSame(1, $row->detail['calls']);
        $this->assertSame(['items' => ['kettle']], $row->detail['features']);
        $this->assertSame(0.012, $row->detail['cost_usd']);
        $this->assertSame(['input' => 900, 'output' => 120], $row->detail['tokens']);
        $this->assertSame('abc', $row->detail['prompt_sha']);
        $this->assertSame('def', $row->detail['reply_sha']);
    }

    // --- the quality sample is decided with the verdict --------------------------------------

    public function test_an_approve_on_an_approve_mode_community_stamps_the_quality_sample(): void
    {
        config(['freegle.autoapprove.enabled' => true, 'freegle.autoapprove.quality_check_percent' => 100]);
        [$user, $group, $message] = $this->makeCandidate();
        $this->fakeReviewResponse(['version' => '5', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE', 'path' => []]);

        $this->service->review($message->id, $group->id, MessageAutomod::MODE_APPROVE);

        $this->assertSame(1, (int) DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)->value('quality_sample'));
    }

    public function test_a_hold_a_shadow_decision_or_no_sample_rate_leaves_the_quality_sample_alone(): void
    {
        config(['freegle.autoapprove.enabled' => true, 'freegle.autoapprove.quality_check_percent' => 100]);
        [$user, $group, $message] = $this->makeCandidate();
        $sampled = fn () => (int) DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)->value('quality_sample');

        $this->fakeReviewResponse(['version' => '5', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end' => 'HOLD_MONEY', 'path' => []]);
        $this->service->review($message->id, $group->id, MessageAutomod::MODE_APPROVE);
        $this->assertSame(0, $sampled(), 'a hold is not sampled');

        $this->fakeReviewResponse(['version' => '5', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE', 'path' => []]);
        $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);
        $this->assertSame(0, $sampled(), 'shadow decides nothing');

        config(['freegle.autoapprove.quality_check_percent' => 0]);
        $this->service->review($message->id, $group->id, MessageAutomod::MODE_APPROVE);
        $this->assertSame(0, $sampled(), 'no sample rate, no sample');
    }

    // --- what reaches Loki -------------------------------------------------------------------

    public function test_a_decision_and_an_outage_are_logged_to_loki(): void
    {
        $loki = new LokiSpy();
        $service = new AutomodService(null, $loki);
        [$user, $group, $message] = $this->makeCandidate();
        // First call answers; the second finds the service down.
        Http::fake(['*/review' => Http::sequence()
            ->push(json_encode([
                'version' => '5', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE', 'path' => [],
                'model' => 'claude-opus-5', 'ms' => 1500, 'cost_usd' => 0.01,
            ]), 200)
            ->push('down', 503)]);

        $service->review($message->id, $group->id, MessageAutomod::MODE_APPROVE);

        $decisions = $loki->eventsOf('automod', 'decision');
        $this->assertCount(1, $decisions);
        $this->assertSame($message->id, $decisions[0]['context']['msgid']);
        $this->assertSame($group->id, $decisions[0]['context']['groupid']);
        $this->assertSame(MessageAutomod::MODE_APPROVE, $decisions[0]['context']['mode']);
        $this->assertSame(MessageAutomod::VERDICT_APPROVE, $decisions[0]['context']['verdict']);
        $this->assertSame('APPROVE', $decisions[0]['context']['end_node']);
        $this->assertSame('5', $decisions[0]['context']['chart_version']);
        $this->assertSame('claude-opus-5', $decisions[0]['context']['model']);
        $this->assertSame(1500, $decisions[0]['context']['ms']);
        $this->assertSame(0.01, $decisions[0]['context']['cost_usd']);

        $service->review($message->id, $group->id, MessageAutomod::MODE_APPROVE);

        $outages = $loki->eventsOf('automod', 'unavailable');
        $this->assertCount(1, $outages);
        $this->assertSame($message->id, $outages[0]['context']['msgid']);
        $this->assertStringContainsString('503', (string) $outages[0]['context']['error']);
    }

    public function test_each_run_logs_the_backlog_to_loki(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        $loki = new LokiSpy();
        $service = new AutomodService(null, $loki);
        $this->makeCandidate();
        [$user2, $group2, $message2] = $this->makeCandidate(['mg' => ['contentcheck_checked_at' => now()->subMinutes(30)]]);
        DB::table('messages_automod')->insert([
            'msgid' => $message2->id, 'groupid' => $group2->id, 'mode' => MessageAutomod::MODE_APPROVE,
            'chart_version' => '', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end_node' => 'UNAVAILABLE',
            'reason' => 'Automated review unavailable', 'path' => '[]', 'created' => now()->subMinute(),
        ]);
        $this->fakeReviewResponse(['version' => '5', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE', 'path' => []]);

        $service->process();

        $this->assertCount(1, $loki->jobs);
        $run = $loki->jobs[0];
        $this->assertSame('messages:automod', $run['job']);
        $this->assertSame('run', $run['event']);
        $this->assertSame(1, $run['context']['reviewed']);
        $this->assertSame(0, $run['context']['candidates_waiting'], 'the one candidate was reviewed; the failed one is not due another try yet');
        $this->assertNull($run['context']['oldest_wait_minutes']);
        $this->assertSame(1, $run['context']['unavailable_rows']);
        $this->assertIsNumeric($run['context']['ms_p50']);
        $this->assertIsNumeric($run['context']['ms_p95']);
    }

    // --- the time box ------------------------------------------------------------------------

    public function test_a_run_stops_taking_candidates_once_its_time_box_is_spent(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        $this->makeCandidate();
        $this->makeCandidate();
        $this->makeCandidate();
        $this->fakeReviewResponse(['version' => '5', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE', 'path' => []]);
        $service = new class extends AutomodService {
            /** What the clock reads on successive calls: at the start, before the first candidate, before the second. */
            public array $clock = [0.0, 0.0, 60.0];

            protected function runClock(): float
            {
                return array_shift($this->clock) ?? 60.0;
            }
        };

        $stats = $service->process(200, 50.0);

        $this->assertSame(1, $stats['reviewed']);
        $this->assertSame(2, $stats['timeboxed']);
        Http::assertSentCount(1);
    }
}
