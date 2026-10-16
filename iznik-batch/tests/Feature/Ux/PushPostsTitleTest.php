<?php

namespace Tests\Feature\Ux;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\UserDigest;
use App\Services\Lockdown\LockdownService;
use App\Services\PushNotificationService;
use App\Services\Ux\Sources\ActiveAfterSource;
use App\Services\Ux\UxArm;
use App\Services\Ux\UxExperimentService;
use App\Support\ExperimentBucket;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HidesExposureTable;
use Tests\TestCase;

/**
 * The title of the daily posts push in the experiment push-posts-title
 * (docs/developers/reference/ux-experiments.md, "The daily posts push title").
 *
 * Arm control keeps the title as it always was: "12 new things near you". Arm lead names the first item and
 * how many others there are: "Sofa and 11 more near you". Only a push of two or more posts is in the
 * experiment. A push of one post is already titled with its item, and a push whose first post has no usable
 * name keeps the control title in both arms and writes no row.
 *
 * Members on "push-posts-title" with arms control [0,49] and lead [50,99] (CRC-32 of "<id>|push-posts-title"):
 *   member   1    2    3    5   12345  999999   121  127  185   16
 *   bucket  83   25   60   13    35      23      0   49   50   99
 *   arm   lead  ctl lead  ctl   ctl     ctl    ctl  ctl lead lead
 *
 * A test that sends needs a real member, whose id cannot be chosen, so it publishes a split that puts
 * everyone in one arm. The tests do not touch ux_experiment_exposures before the test that hides it:
 * each runs in a transaction, a statement on the table inside it holds a lock until the rollback, and
 * HidesExposureTable renames the table on a second connection that would wait for that lock. The rollback
 * removes every row a test writes.
 */
class PushPostsTitleTest extends TestCase
{
    use HidesExposureTable;

    private const NAME = 'push-posts-title';

    /** The flags of the create command in the pull request, as it prints them. --pr is the entry's own. */
    private const CREATE = [
        '--flow' => 'reply',
        '--name' => self::NAME,
        '--title' => 'Daily push names its first item',
        '--hypothesis' => 'A title that names the first item gives members more reason to open the app than a title that counts new things.',
        '--change' => 'For half of members, a daily posts push of two or more posts is titled with its first item and how many others there are, such as Sofa and 11 more near you. Only the title changes: the body, the list and the photo are the same in both versions. A push of one post is not changed.',
        '--expected' => 'More members are active within a day of the push, by 2 points or more, and no more of them turn the push off.',
        '--arms' => '{"control":[0,49],"lead":[50,99]}',
    ];

    private const DESIGN = '{"channel":"push","type":"daily_new_posts","source":"active_after","window_hours":24,"intake_hours":24,"max_hours":48,"expected_points":2,"copy":{"control":"12 new things near you","lead":"Sofa and 11 more near you"}}';

    private PushNotificationService $service;

    private ?Group $group = null;

    private ?User $poster = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 08:00:00');
        UxArm::flush();
        DB::table('config')->whereIn('key', [UxExperimentService::CONFIG_KEY, UxArm::SWITCH_KEY])->delete();
        $this->service = new PushNotificationService;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        UxArm::flush();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function setConfig(string $key, string $value): void
    {
        DB::table('config')->upsert([['key' => $key, 'value' => $value]], ['key'], ['value']);
    }

    /**
     * Publish entries as the engine would, and set the switch for server arms: '1' is on, null deletes the row.
     *
     * @param  array<string, array<string, mixed>>  $entries  experiment name => entry
     */
    private function publishEntries(array $entries, ?string $switch = '1'): void
    {
        // No entries is the object {} that a stop leaves in the key, not the list [].
        $this->setConfig(UxExperimentService::CONFIG_KEY, $entries === [] ? '{}' : json_encode($entries));
        if ($switch === null) {
            DB::table('config')->where('key', UxArm::SWITCH_KEY)->delete();
        } else {
            $this->setConfig(UxArm::SWITCH_KEY, $switch);
        }
        UxArm::flush();
    }

    /**
     * Publish the experiment, running for another hour, with the real 50/50 split unless the arms are given.
     *
     * @param  array<string, array{0: int, 1: int}>  $arms
     * @param  array<string, mixed>  $entry  replaces keys of the entry, such as until
     */
    private function publish(array $arms = ['control' => [0, 49], 'lead' => [50, 99]], array $entry = [], ?string $switch = '1'): void
    {
        $this->publishEntries([self::NAME => $entry + ['arms' => $arms, 'until' => Carbon::now()->getTimestamp() + 3600]], $switch);
    }

    /** Everyone is in $arm, so a test that needs a real member can send. */
    private function everyoneIn(string $arm, array $entry = []): void
    {
        $this->publish([$arm => [0, 99]], $entry);
    }

    private function poster(): User
    {
        return $this->poster ??= $this->createTestUser();
    }

    private function group(): Group
    {
        return $this->group ??= $this->createTestGroup();
    }

    private function makePost(string $subject, array $extra = []): Message
    {
        return $this->createTestMessage($this->poster(), $this->group(), $extra + [
            'subject' => $subject,
            'type' => str_starts_with(strtolower($subject), 'wanted') ? Message::TYPE_WANTED : Message::TYPE_OFFER,
        ]);
    }

    /**
     * A list of $count posts by another member, the first with the subject given. The others are copies of
     * one second post, so a push of a hundred posts needs two rows.
     *
     * @return array<int, array{message: Message, postedToGroups: int[]}>
     */
    private function postsOf(int $count, string $firstSubject = 'OFFER: Sofa (Kingston)'): array
    {
        $first = $this->makePost($firstSubject);
        $other = $count > 1 ? $this->makePost('OFFER: Lamp (Kingston)') : null;
        $posts = [];
        for ($i = 0; $i < $count; $i++) {
            $posts[] = ['message' => $i === 0 ? $first : $other, 'postedToGroups' => [$this->group()->id]];
        }

        return $posts;
    }

    /**
     * @param  Message[]  $messages
     * @return array<int, array{message: Message, postedToGroups: int[]}>
     */
    private function listOf(array $messages): array
    {
        return array_map(fn (Message $m) => ['message' => $m, 'postedToGroups' => [$this->group()->id]], $messages);
    }

    private function registerDevice(int $userId, string $type = 'FCMIOS'): void
    {
        DB::table('users_push_notifications')->insert([
            'userid' => $userId,
            'type' => $type,
            'subscription' => 'tok-'.uniqid('', true),
            'apptype' => 'User',
            'added' => now(),
            'lastsent' => null,
        ]);
    }

    private function memberWithDevices(int $devices = 1): User
    {
        $user = $this->createTestUser();
        for ($i = 0; $i < $devices; $i++) {
            $this->registerDevice($user->id);
        }

        return $user;
    }

    /**
     * A messaging client that records the messages it takes. $outcomes says what the sends do in turn: true
     * takes the message and false throws. A send after the last outcome is taken.
     *
     * @param  bool[]  $outcomes
     */
    private function fakeMessaging(array $outcomes = []): object
    {
        $fake = new class($outcomes)
        {
            /** @var object[] */
            public array $sent = [];

            public int $attempts = 0;

            public function __construct(private array $outcomes) {}

            public function validate($message): void {}

            public function send($message): void
            {
                $ok = $this->outcomes[$this->attempts] ?? true;
                $this->attempts++;
                if (! $ok) {
                    throw new \RuntimeException('The send failed');
                }
                $this->sent[] = $message;
            }
        };

        $property = new \ReflectionProperty($this->service, 'messaging');
        $property->setAccessible(true);
        $property->setValue($this->service, $fake);

        return $fake;
    }

    /** The title of the n-th message the fake took, as the phone is given it. */
    private function sentTitle(object $fake, int $n = 0): string
    {
        return $fake->sent[$n]->jsonSerialize()['data']['title'];
    }

    /** @return object[] */
    private function exposures(?int $userId = null): array
    {
        $query = DB::table('ux_experiment_exposures')->where('experiment', self::NAME)->orderBy('id');
        if ($userId !== null) {
            $query->where('userid', $userId);
        }

        return $query->get()->all();
    }

    private function day(): int
    {
        return intdiv(Carbon::now()->getTimestamp(), 86400);
    }

    /** The experiment row the metric source reads, started on the 18th. */
    private function experiment(): object
    {
        return (object) [
            'name' => self::NAME,
            'arms' => json_encode(['control' => [0, 49], 'lead' => [50, 99]]),
            'design' => json_encode(['channel' => 'push', 'type' => 'daily_new_posts', 'source' => 'active_after', 'window_hours' => 24]),
            'started_at' => '2026-10-18 00:00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function entry(): array
    {
        $entries = array_values(array_filter((array) config('ux_switches'), fn ($e) => is_array($e) && ($e['name'] ?? null) === self::NAME));
        $this->assertCount(1, $entries, 'config/ux_switches.php holds one entry for '.self::NAME);

        return $entries[0];
    }

    private function noWarning(): void
    {
        Log::shouldNotHaveReceived('warning');
    }

    private function warned(string $text): void
    {
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, $text))->once();
    }

    // ---------------------------------------------------------------- the lead arm

    public function test_the_lead_arm_titles_several_posts_with_the_first_item_and_changes_nothing_else(): void
    {
        // The first post has no photo and the second has a real one, so the photo is the second post's in both arms.
        $first = $this->makePost('OFFER: Sofa (Kingston)');
        $second = $this->makePost('WANTED: Bike (Surbiton)');
        MessageAttachment::create(['msgid' => $second->id, 'externalurl' => 'https://cdn.example.com/bike.jpg', 'archived' => 0, 'primary' => 1]);
        $second->load('attachments');
        $others = array_map(fn ($i) => $this->makePost("OFFER: Item $i (Kingston)"), range(1, 10));
        $posts = $this->listOf(array_merge([$first, $second], $others));
        $member = $this->createTestUser();

        $this->everyoneIn('control');
        $control = $this->service->buildDailyNewPostsPayload($member->id, $posts);
        $this->everyoneIn('lead');
        $lead = $this->service->buildDailyNewPostsPayload($member->id, $posts);

        $this->assertSame('12 new things near you', $control['title']);
        $this->assertSame('Sofa and 11 more near you', $lead['title']);
        $this->assertSame('https://cdn.example.com/bike.jpg', $lead['image'], 'the photo is the second post, as in the control');
        unset($control['timestamp'], $lead['timestamp']);
        $this->assertSame(array_merge($control, ['title' => 'Sofa and 11 more near you']), $lead, 'only the title differs, key for key');
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function postCounts(): array
    {
        return [
            'two posts' => [2, 'Sofa and 1 more near you'],
            'three posts' => [3, 'Sofa and 2 more near you'],
            'five posts, the number of lines the push lists' => [5, 'Sofa and 4 more near you'],
            'six posts, one more than the lines' => [6, 'Sofa and 5 more near you'],
            'a hundred posts' => [100, 'Sofa and 99 more near you'],
        ];
    }

    #[DataProvider('postCounts')]
    public function test_the_lead_title_counts_every_other_post_not_only_the_listed_ones(int $count, string $title): void
    {
        $this->everyoneIn('lead');

        $payload = $this->service->buildDailyNewPostsPayload($this->createTestUser()->id, $this->postsOf($count));

        $this->assertSame($title, $payload['title']);
        $this->assertSame((string) $count, $payload['count']);
        $this->assertSame((string) max(0, $count - 5), $payload['moreCount'], 'the list is capped at five lines in both arms');
    }

    public function test_the_control_arm_keeps_todays_title(): void
    {
        $this->everyoneIn('control');

        $payload = $this->service->buildDailyNewPostsPayload($this->createTestUser()->id, $this->postsOf(12));

        $this->assertSame('12 new things near you', $payload['title']);
    }

    public function test_an_arm_the_caller_has_looked_up_is_used_without_another_lookup(): void
    {
        // Member 127 is in control (bucket 49), and the caller says lead, so the title follows the caller.
        $posts = $this->postsOf(3);
        $this->publish();
        UxArm::flush();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $given = $this->service->buildDailyNewPostsPayload(127, $posts, 'lead');
        $none = $this->service->buildDailyNewPostsPayload(127, $posts, '');

        $this->assertSame('Sofa and 2 more near you', $given['title']);
        $this->assertSame('3 new things near you', $none['title'], 'the arm "" is control, and is not looked up again');
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => str_contains($sql, '`config`'))), 'the config is not read when the arm is given');
    }

    // ---------------------------------------------------------------- who is in it

    /** @return array<string, array{0: string}> */
    public static function waysToHaveNoArm(): array
    {
        return [
            'no switch row' => ['no switch row'],
            'the switch is 0' => ['switch 0'],
            'the switch is true' => ['switch true'],
            'the entry is for another name' => ['another name'],
            'until is now' => ['until now'],
            'until is over fifteen days ahead' => ['until too far'],
            'an arm this code has no title for' => ['unknown arm'],
        ];
    }

    #[DataProvider('waysToHaveNoArm')]
    public function test_every_way_of_having_no_arm_keeps_the_control_title_and_writes_no_row(string $way): void
    {
        $member = $this->memberWithDevices();
        $fake = $this->fakeMessaging();
        $now = Carbon::now()->getTimestamp();
        $lead = ['lead' => [0, 99]];

        // Each way is one defect in a state that would otherwise put everyone in lead.
        match ($way) {
            'no switch row' => $this->publish($lead, [], null),
            'switch 0' => $this->publish($lead, [], '0'),
            'switch true' => $this->publish($lead, [], 'true'),
            'another name' => $this->publishEntries(['some-other-test' => ['arms' => $lead, 'until' => $now + 3600]]),
            'until now' => $this->publish($lead, ['until' => $now]),
            'until too far' => $this->publish($lead, ['until' => $now + 15 * 86400 + 1]),
            'unknown arm' => $this->publish(['bogus' => [0, 99]]),
        };

        $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $this->postsOf(12)), $way);
        $this->assertSame('12 new things near you', $this->sentTitle($fake), $way);
        $this->assertSame([], $this->exposures(), $way);
    }

    public function test_a_push_of_one_post_is_not_in_the_experiment(): void
    {
        $member = $this->memberWithDevices();
        $fake = $this->fakeMessaging();
        $this->everyoneIn('lead');

        $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $this->postsOf(1)));

        $this->assertSame('Sofa', $this->sentTitle($fake), 'the title is the item, as it always was');
        $this->assertSame([], $this->exposures());
    }

    public function test_the_members_own_posts_are_left_out_before_the_lead_is_chosen(): void
    {
        $member = $this->memberWithDevices();
        $fake = $this->fakeMessaging();
        $this->everyoneIn('lead');
        $own = $this->createTestMessage($member, $this->group(), ['subject' => 'OFFER: Mine (Kingston)']);
        $lamp = $this->makePost('OFFER: Lamp (Kingston)');
        $books = $this->makePost('OFFER: Books (Kingston)');

        $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $this->listOf([$own, $lamp, $books])));
        $this->assertSame('Lamp and 1 more near you', $this->sentTitle($fake), 'the lead is the first of the posts that are left, and the count agrees');
        $this->assertSame(['posts' => 2], json_decode($this->exposures()[0]->detail, true));

        // The next day only one post is left once the member's own is out: it is a push of one post, with no row.
        Carbon::setTestNow('2026-10-21 08:00:00');
        $this->everyoneIn('lead');
        $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $this->listOf([$own, $lamp])));
        $this->assertSame('Lamp', $this->sentTitle($fake, 1));
        $this->assertCount(1, $this->exposures(), 'and the first push is the only row');
    }

    /** @return array<string, array{0: int, 1: int, 2: string}> */
    public static function pinnedMembers(): array
    {
        return [
            'member 1' => [1, 83, 'lead'],
            'member 2' => [2, 25, 'control'],
            'member 3' => [3, 60, 'lead'],
            'member 5' => [5, 13, 'control'],
            'member 12345' => [12345, 35, 'control'],
            'member 999999' => [999999, 23, 'control'],
            'member 121, the first bucket of control' => [121, 0, 'control'],
            'member 127, the last bucket of control' => [127, 49, 'control'],
            'member 185, the first bucket of lead' => [185, 50, 'lead'],
            'member 16, the last bucket of lead' => [16, 99, 'lead'],
        ];
    }

    #[DataProvider('pinnedMembers')]
    public function test_the_published_split_puts_each_pinned_member_in_their_arm(int $userId, int $bucket, string $arm): void
    {
        $this->publish();

        $payload = $this->service->buildDailyNewPostsPayload($userId, $this->postsOf(2));

        $this->assertSame($bucket, ExperimentBucket::bucket($userId, self::NAME), 'the bucket is for the name '.self::NAME);
        $this->assertSame($arm === 'lead' ? 'Sofa and 1 more near you' : '2 new things near you', $payload['title']);
    }

    // ---------------------------------------------------------------- the name

    /** @return array<string, array{0: string, 1: string}> */
    public static function names(): array
    {
        return [
            'a short name' => ['OFFER: Sofa (Kingston)', 'Sofa and 11 more near you'],
            'a name of exactly 20 is kept whole' => ['OFFER: Wooden dining table! (Kingston)', 'Wooden dining table! and 11 more near you'],
            'a name of 21 is cut at 20 and ends in an ellipsis' => ['OFFER: Wooden dining tables! (Kingston)', 'Wooden dining tables… and 11 more near you'],
            'a cut at a space drops the space' => ['OFFER: Wooden dining table with chairs (Kingston)', 'Wooden dining table… and 11 more near you'],
            'one long word' => ['OFFER: Refrigerator-freezer-combination (Kingston)', 'Refrigerator-freezer… and 11 more near you'],
            'a multibyte letter is one column' => ['OFFER: Café table and six chairs (Kingston)', 'Café table and six c… and 11 more near you'],
            'wide characters are two columns each' => ['OFFER: 家具の大きなソファとテーブルのセット (Kingston)', '家具の大きなソファと… and 11 more near you'],
            'an ampersand stored encoded' => ['OFFER: Coffee &amp; Cake (London)', 'Coffee & Cake and 11 more near you'],
            'an apostrophe stored encoded, 20 once decoded' => ['OFFER: Boys&#039; bike frame set (London)', "Boys' bike frame set and 11 more near you"],
            'a cut that cannot land inside an entity' => ['OFFER: Large wooden table&amp;chairs (London)', 'Large wooden table&c… and 11 more near you'],
            'a stored emoji escape is decoded before the cut' => ['OFFER: Sofa bed set \\\\u1f6cb\\\\u x (Kingston)', "Sofa bed set \u{1F6CB} x and 11 more near you"],
            'a Wanted post leads with its name' => ['WANTED: Bike (Surbiton)', 'Bike and 11 more near you'],
            'lower case prefixes are cut too' => ['offer : Sofa (Kingston)', 'Sofa and 11 more near you'],
            'a name with a bracket inside keeps it' => ['OFFER: Sofa (3 seater) bed (Kingston)', 'Sofa (3 seater) bed and 11 more near you'],
        ];
    }

    #[DataProvider('names')]
    public function test_the_item_name_is_kept_to_twenty_columns_and_cut_with_an_ellipsis(string $subject, string $title): void
    {
        $this->everyoneIn('lead');

        $payload = $this->service->buildDailyNewPostsPayload($this->createTestUser()->id, $this->postsOf(12, $subject));

        $this->assertSame($title, $payload['title']);
        $this->assertTrue(mb_check_encoding($payload['title'], 'UTF-8'), 'and it is still valid UTF-8');
        $this->assertStringNotContainsString('\\u', $payload['title'], 'and no stored escape is cut in half');
    }

    public function test_a_clearance_leads_with_its_bare_name_and_a_wanted_post_may_lead(): void
    {
        $this->everyoneIn('lead');
        $clearance = $this->makePost('OFFER: Office clearance (Brighton)');
        foreach (['Desk', 'Chair', 'Lamp'] as $i => $name) {
            DB::table('messages_bulk_items')->insert(['msgid' => $clearance->id, 'position' => $i, 'name' => $name, 'quantity' => 1, 'condition' => 'Good']);
        }
        $other = $this->makePost('OFFER: Lamp (Kingston)');
        $member = $this->createTestUser();

        $several = $this->service->buildDailyNewPostsPayload($member->id, $this->listOf([$clearance, $other, $other]));
        $one = $this->service->buildDailyNewPostsPayload($member->id, $this->listOf([$clearance]));

        $this->assertSame('Office clearance and 2 more near you', $several['title'], 'not "Office clearance — 3 items", which is long as a teaser');
        $this->assertSame('Office clearance — 3 items', $one['title'], 'the title of one post still says how many items there are');
    }

    /** @return array<string, array{0: string}> */
    public static function subjectsWithNoUsableName(): array
    {
        return [
            'an empty subject' => [''],
            'a prefix only' => ['OFFER:'],
            'a prefix and a space' => ['WANTED: '],
            'a prefix and a place only' => ['OFFER: (Kingston)'],
        ];
    }

    #[DataProvider('subjectsWithNoUsableName')]
    public function test_a_first_post_with_no_usable_name_keeps_the_control_title_in_both_arms_and_writes_no_row(string $subject): void
    {
        Log::spy();
        $member = $this->memberWithDevices();
        $fake = $this->fakeMessaging();
        $posts = $this->postsOf(3, $subject);

        foreach (['lead', 'control'] as $arm) {
            $this->everyoneIn($arm);
            $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $posts), $arm);
        }

        $this->assertSame(['3 new things near you', '3 new things near you'], [$this->sentTitle($fake, 0), $this->sentTitle($fake, 1)]);
        $this->assertSame([], $this->exposures());
        $this->noWarning();
    }

    public function test_a_stored_escape_that_cannot_be_decoded_takes_the_push_out_of_the_experiment_and_is_logged(): void
    {
        // Two backslashes, u, 24 letters f, two backslashes, u: a number that does not fit in an integer.
        Log::spy();
        $member = $this->memberWithDevices();
        $fake = $this->fakeMessaging();
        $posts = $this->postsOf(3, 'OFFER: Thing \\\\uffffffffffffffffffffffff\\\\u (Kingston)');

        foreach (['lead', 'control'] as $arm) {
            $this->everyoneIn($arm);
            $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $posts), $arm);
        }

        $this->assertSame(['3 new things near you', '3 new things near you'], [$this->sentTitle($fake, 0), $this->sentTitle($fake, 1)]);
        $this->assertSame([], $this->exposures());
        // One warning for each time the name was tried: once for the lookup in each arm.
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'the lead title was not built'))->twice();
    }

    public function test_the_lookup_of_the_arm_never_stops_a_push(): void
    {
        // A list the code does not expect (here two strings, not posts) is a failure inside the lookup.
        Log::spy();
        $this->everyoneIn('lead');

        $method = new \ReflectionMethod($this->service, 'dailyPostsTitleArm');
        $method->setAccessible(true);
        $arm = $method->invoke($this->service, 1234, [['message' => 'x'], ['message' => 'y']]);

        $this->assertSame('', $arm);
        $this->warned('the title experiment was skipped');
    }

    // ---------------------------------------------------------------- the exposure

    public function test_a_delivered_push_writes_one_row_for_the_lead_arm(): void
    {
        $member = $this->memberWithDevices();
        $fake = $this->fakeMessaging();
        $this->everyoneIn('lead');

        $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $this->postsOf(3)));

        $this->assertCount(1, $fake->sent);
        $this->assertSame('Sofa and 2 more near you', $this->sentTitle($fake));
        $rows = $this->exposures();
        $this->assertCount(1, $rows);
        $this->assertSame(self::NAME, $rows[0]->experiment);
        $this->assertSame('lead', $rows[0]->arm);
        $this->assertSame($member->id, (int) $rows[0]->userid);
        $this->assertSame('push', $rows[0]->subject_type);
        $this->assertSame($this->day(), (int) $rows[0]->subject_id, 'the subject is the UTC day');
        $this->assertNull($rows[0]->stratum);
        $this->assertSame('2026-10-20 08:00:00', $rows[0]->exposed_at);
        // The column is JSON, so MySQL hands it back in its own form.
        $this->assertEquals(['posts' => 3], json_decode($rows[0]->detail, true));
    }

    public function test_the_control_arm_is_exposed_too(): void
    {
        $member = $this->memberWithDevices();
        $fake = $this->fakeMessaging();
        $this->everyoneIn('control');

        $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $this->postsOf(3)));

        $this->assertSame('3 new things near you', $this->sentTitle($fake));
        $rows = $this->exposures();
        $this->assertCount(1, $rows);
        $this->assertSame('control', $rows[0]->arm);
        $this->assertEquals(['posts' => 3], json_decode($rows[0]->detail, true));
    }

    public function test_two_devices_make_one_row_and_a_send_that_failed_makes_none(): void
    {
        $posts = $this->postsOf(3);
        $this->everyoneIn('lead');

        // Two devices take the push: two sends, one row.
        $two = $this->memberWithDevices(2);
        $fake = $this->fakeMessaging();
        $this->assertSame(2, $this->service->notifyDailyNewPosts($two->id, $posts));
        $this->assertCount(2, $fake->sent);
        $this->assertCount(1, $this->exposures($two->id));

        // The first send throws and the second works: one device took it, so one row.
        $oneFails = $this->memberWithDevices(2);
        $fake = $this->fakeMessaging([false, true]);
        $this->assertSame(1, $this->service->notifyDailyNewPosts($oneFails->id, $posts));
        $this->assertSame(2, $fake->attempts);
        $this->assertCount(1, $this->exposures($oneFails->id));

        // Both throw: nothing was delivered, so no row.
        $bothFail = $this->memberWithDevices(2);
        $this->fakeMessaging([false, false]);
        $this->assertSame(0, $this->service->notifyDailyNewPosts($bothFail->id, $posts));
        $this->assertSame([], $this->exposures($bothFail->id));

        // No device at all: no row.
        $none = $this->createTestUser();
        $this->fakeMessaging();
        $this->assertSame(0, $this->service->notifyDailyNewPosts($none->id, $posts));
        $this->assertSame([], $this->exposures($none->id));
    }

    public function test_a_second_send_the_same_day_keeps_the_first_row_and_the_next_day_adds_one(): void
    {
        $member = $this->memberWithDevices();
        $this->fakeMessaging();
        $posts = $this->postsOf(3);

        $this->everyoneIn('lead');
        $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $posts));

        // A second send the same UTC day, as a manual resend or a second pass of a shard would make, in the other arm.
        Carbon::setTestNow('2026-10-20 08:30:00');
        $this->everyoneIn('control');
        $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $posts));

        $rows = $this->exposures($member->id);
        $this->assertCount(1, $rows);
        $this->assertSame('lead', $rows[0]->arm, 'the first row stays');
        $this->assertSame('2026-10-20 08:00:00', $rows[0]->exposed_at);

        // The next UTC day is a new row. The entry is published again because UxArm gives "" once its until is past.
        Carbon::setTestNow('2026-10-21 08:00:00');
        $this->everyoneIn('lead');
        $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $posts));

        $rows = $this->exposures($member->id);
        $this->assertCount(2, $rows);
        $this->assertSame($this->day(), (int) $rows[1]->subject_id);
        $this->assertSame((int) $rows[0]->subject_id + 1, (int) $rows[1]->subject_id);

        // The source counts the member once, on the first row.
        Carbon::setTestNow('2026-10-23 12:00:00');
        $counts = (new ActiveAfterSource)->counts($this->experiment(), Carbon::now('UTC'), null);
        $this->assertSame(1, $counts['arms']['lead']['starts']);
        $this->assertSame(0, $counts['arms']['control']['starts']);
        $this->assertEquals(['control' => 0, 'lead' => 1], $counts['extra']['exposed']);
    }

    public function test_arms_off_write_nothing_and_leave_the_control_title(): void
    {
        $member = $this->memberWithDevices();
        $fake = $this->fakeMessaging();
        $posts = $this->postsOf(12);

        // Switched off, and after a stop, which leaves the switch on and the entry gone from the key.
        $this->publish(['lead' => [0, 99]], [], '0');
        $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $posts));
        $this->publishEntries([], '1');
        $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $posts));

        $this->assertSame(['12 new things near you', '12 new things near you'], [$this->sentTitle($fake, 0), $this->sentTitle($fake, 1)]);
        $this->assertSame([], $this->exposures());
    }

    public function test_a_push_held_by_the_lockdown_is_not_sent_and_writes_no_row(): void
    {
        $member = $this->memberWithDevices();
        $fake = $this->fakeMessaging();
        $this->everyoneIn('lead');
        DB::table('lockdowns')->delete();
        DB::table('lockdown_counters')->delete();
        LockdownService::flushCache();
        (new LockdownService)->press(null, 'test lockdown');

        $this->assertSame(0, $this->service->notifyDailyNewPosts($member->id, $this->postsOf(3)));

        $this->assertCount(0, $fake->sent);
        $this->assertSame([], $this->exposures());
    }

    public function test_a_missing_exposure_table_does_not_stop_the_push(): void
    {
        Log::spy();
        $member = $this->memberWithDevices();
        $fake = $this->fakeMessaging();
        $this->everyoneIn('lead');
        $posts = $this->postsOf(3);

        $sent = $this->withoutExposureTable(fn () => $this->service->notifyDailyNewPosts($member->id, $posts));

        $this->assertSame(1, $sent);
        $this->assertSame('Sofa and 2 more near you', $this->sentTitle($fake));
        $this->warned('UxArm: could not record the exposure for push-posts-title');
        $this->assertSame([], $this->exposures(), 'and the table is back afterwards');
    }

    public function test_a_member_with_no_arm_is_not_touched_by_the_build(): void
    {
        // The first post's name cannot be decoded. A build for this member would log that, and would be the
        // title experiment's work on a member it does not serve: the lookup comes first, so nothing is built.
        Log::spy();
        $member = $this->memberWithDevices();
        $fake = $this->fakeMessaging();
        $posts = $this->postsOf(3, 'OFFER: Thing \\\\uffffffffffffffffffffffff\\\\u (Kingston)');
        $bucket = ExperimentBucket::bucket($member->id, self::NAME);
        $elsewhere = ($bucket + 1) % 100;

        foreach ([
            fn () => $this->publish(['lead' => [0, 99]], [], '0'),
            fn () => $this->publishEntries([], '1'),
            fn () => $this->publish(['lead' => [$elsewhere, $elsewhere]]),
        ] as $n => $state) {
            $state();
            $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $posts), "state $n");
            $this->assertSame('3 new things near you', $this->sentTitle($fake, $n), "state $n");
        }

        $this->assertSame([], $this->exposures());
        $this->noWarning();
    }

    // ---------------------------------------------------------------- the dry run, and the reader

    public function test_the_dry_run_shows_the_title_of_the_members_arm_and_writes_nothing(): void
    {
        Config::set('freegle.posts_push_allowlist', '*');
        $member = $this->createTestUser();
        $this->createMembership($member, $this->group(), ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->registerDevice($member->id, 'FCMAndroid');
        // The command orders the posts by arrival, so the older one leads.
        $this->makePost('OFFER: Lamp (Kingston)', ['arrival' => now()->subHours(2)]);
        $this->makePost('OFFER: Sofa (Kingston)', ['arrival' => now()->subHours(3)]);
        $this->everyoneIn('lead');

        $this->artisan('push:daily-posts', ['--user' => $member->id, '--dry-run' => true])
            ->expectsOutputToContain('posts=2 title="Sofa and 1 more near you"')
            ->assertExitCode(0);

        $this->assertSame([], $this->exposures());
        $this->assertNull(UserDigest::where('userid', $member->id)->where('mode', 'push')->first()?->lastsent, 'the cursor does not move');
    }

    public function test_the_source_counts_what_the_push_writes_and_the_guardrail_reads_the_setting(): void
    {
        $member = $this->memberWithDevices();
        $this->fakeMessaging();
        $this->everyoneIn('lead');
        $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $this->postsOf(3)));

        // The row is a day old and the member was active in the hour after it.
        DB::table('ux_experiment_exposures')->where('experiment', self::NAME)->update(['exposed_at' => '2026-10-19 06:30:00']);
        DB::table('users_active')->insert(['userid' => $member->id, 'timestamp' => '2026-10-19 07:00:00']);
        $source = new ActiveAfterSource;

        $counts = $source->counts($this->experiment(), Carbon::now('UTC'), null);

        $this->assertSame(1, $counts['arms']['lead']['starts'], 'counted');
        $this->assertSame(1, $counts['arms']['lead']['completes'], 'and converted');
        $this->assertSame(0, $counts['arms']['control']['starts']);
        $this->assertSame(['bad' => 0, 'base' => 1], $counts['guards']['push_opt_out']['lead']);

        // The member turns the push off: the guardrail counts them.
        $member->settings = ['notifications' => ['dailypostspush' => false]];
        $member->save();

        $counts = $source->counts($this->experiment(), Carbon::now('UTC'), null);

        $this->assertSame(['bad' => 1, 'base' => 1], $counts['guards']['push_opt_out']['lead']);
    }

    // ---------------------------------------------------------------- the control path, byte for byte

    public function test_the_control_path_is_unchanged_in_every_state_that_gives_no_lead(): void
    {
        $messages = [$this->makePost('OFFER: Sofa (Kingston)'), $this->makePost('WANTED: Bike (Surbiton)'), $this->makePost('OFFER: Lamp (Kingston)')];
        $posts = $this->listOf($messages);
        $member = $this->createTestUser();
        $expected = [
            'channel_id' => 'new_posts',
            'category' => 'NEW_POSTS',
            'notId' => '200000001',
            'count' => '3',
            'title' => '3 new things near you',
            'message' => 'Sofa, Bike, Lamp',
            'route' => '/browse',
            'image' => '',
            'images' => '[]',
            'lines' => '["Offer: Sofa (Kingston)","Wanted: Bike (Surbiton)","Offer: Lamp (Kingston)"]',
            'summary' => 'Freegle • 3 new posts',
            'moreCount' => '0',
            'badge' => '0',
            'content-available' => '1',
            'modtools' => 'false',
        ];
        $build = function (?string $arm = null) use ($member, $posts) {
            $payload = $arm === null
                ? $this->service->buildDailyNewPostsPayload($member->id, $posts)
                : $this->service->buildDailyNewPostsPayload($member->id, $posts, $arm);
            unset($payload['timestamp']);

            return $payload;
        };

        $this->publish(['lead' => [0, 99]], [], null);
        $this->assertSame($expected, $build(), 'arms off');
        $this->assertSame($expected, $build(''), 'the arm "" given by the caller');
        $this->everyoneIn('control');
        $this->assertSame($expected, $build(), 'control published');
        $this->assertSame($expected, $build('control'), 'control given by the caller');
        $this->publish(['bogus' => [0, 99]]);
        $this->assertSame($expected, $build(), 'an arm this code has no title for');

        // The message the phone is given is the same with arms off and in control, apart from the time and the token.
        $messageOf = function (string $state) use ($posts) {
            $member = $this->memberWithDevices();
            $fake = $this->fakeMessaging();
            if ($state === 'off') {
                $this->publish(['lead' => [0, 99]], [], null);
            } else {
                $this->everyoneIn('control');
            }
            $this->assertSame(1, $this->service->notifyDailyNewPosts($member->id, $posts));
            $json = $fake->sent[0]->jsonSerialize();
            unset($json['token'], $json['data']['timestamp']);

            return $json;
        };
        $this->assertSame($messageOf('off'), $messageOf('control'));
    }

    // ---------------------------------------------------------------- the entry, and the command

    public function test_the_switch_list_entry_has_the_shape_the_code_expects(): void
    {
        $entry = $this->entry();
        $design = json_decode(self::DESIGN, true);

        $keys = array_keys($entry);
        sort($keys);
        $this->assertSame(['arms', 'channel', 'keep_until', 'metric', 'name', 'opened', 'pr', 'type', 'varies'], $keys, 'exactly the keys of the docblock in config/ux_switches.php');
        $this->assertSame(['control', 'lead'], $entry['arms']);
        $this->assertSame(['source' => 'active_after', 'window_hours' => 24], $entry['metric']);
        $this->assertSame('push', $entry['channel']);
        $this->assertSame('daily_new_posts', $entry['type']);
        $this->assertSame('title', $entry['varies']);
        $this->assertMatchesRegularExpression('#^https://github\.com/Freegle/Iznik/pull/[0-9]+$#', $entry['pr'], 'the number of the pull request is in it');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $entry['opened']);
        $this->assertSame($entry['opened'], Carbon::parse($entry['opened'], 'UTC')->format('Y-m-d'), 'opened is a real day');
        $this->assertTrue($entry['keep_until'] === null || ($entry['keep_until'] === Carbon::parse($entry['keep_until'], 'UTC')->format('Y-m-d')), 'keep_until is empty or a real day');
        $this->assertSame($design['source'], $entry['metric']['source']);
        $this->assertSame($design['window_hours'], $entry['metric']['window_hours']);
        $this->assertSame($design['channel'], $entry['channel']);
        $this->assertSame($design['type'], $entry['type']);
        $names = array_map(fn ($e) => $e['name'] ?? null, (array) config('ux_switches'));
        $this->assertSame(1, count(array_keys($names, self::NAME, true)), 'no other entry has the name');
    }

    public function test_the_create_command_of_the_pull_request_starts_against_the_real_switch_list(): void
    {
        DB::table('ux_experiment_reads')->delete();
        DB::table('ux_experiments')->delete();
        $service = app(UxExperimentService::class);

        $this->artisan('ux:experiment', ['action' => 'create', '--pr' => $this->entry()['pr'], '--design' => self::DESIGN] + self::CREATE)
            ->expectsOutputToContain('Created experiment')
            ->assertExitCode(0);
        $row = DB::table('ux_experiments')->where('name', self::NAME)->first();
        $this->assertSame('draft', $row->status);
        $this->assertSame('reply', $row->flow);
        $this->assertSame($this->entry()['pr'], $row->pr_url);
        $draft = json_decode($row->design, true);
        $this->assertArrayNotHasKey('guardrail', $draft, 'there is no such design key: the guardrail comes from the source');
        $this->assertArrayNotHasKey('slot', $draft, 'the slot is push:daily_new_posts, from the channel and the type');
        $this->assertTrue($draft['fixed_split']);
        foreach (array_merge(array_values(self::CREATE), array_values($draft['copy'])) as $text) {
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $text, 'no long dash in words staff read');
        }

        $this->setConfig(UxArm::SWITCH_KEY, '1');
        UxArm::flush();
        $started = $service->start(self::NAME);

        $this->assertSame('running', $started->status);
        $this->assertSame('2026-10-20 08:00:00', $started->started_at);
        $this->assertSame('2026-10-22 08:00:00', $started->ends_at, '48 hours');
        $design = json_decode($started->design, true);
        $this->assertSame(24, $design['intake_hours']);
        $this->assertSame(48, $design['max_hours']);
        $this->assertEquals(2, $design['expected_points']);
        $this->assertSame([], $design['touches'], 'a source experiment watches no funnel');
        $published = json_decode((string) DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->value('value'), true);
        $this->assertEquals(
            [self::NAME => ['arms' => ['control' => [0, 49], 'lead' => [50, 99]], 'until' => Carbon::parse('2026-10-21 09:00:00', 'UTC')->getTimestamp()]],
            $published,
            'served until 25 hours after the start, with no salt'
        );

        // The two lines of copy the tab shows are the titles the arms send.
        UxArm::flush();
        $posts = $this->postsOf(12);
        $this->assertSame('control', UxArm::for(127, self::NAME));
        $this->assertSame('lead', UxArm::for(185, self::NAME));
        $this->assertSame($design['copy']['control'], $this->service->buildDailyNewPostsPayload(127, $posts)['title']);
        $this->assertSame($design['copy']['lead'], $this->service->buildDailyNewPostsPayload(185, $posts)['title']);

        // A stop puts everyone back in control.
        $service->stop(self::NAME, 'test');
        UxArm::flush();
        $this->assertSame('', UxArm::for(185, self::NAME));
        $this->assertSame('12 new things near you', $this->service->buildDailyNewPostsPayload(185, $posts)['title']);
    }
}
