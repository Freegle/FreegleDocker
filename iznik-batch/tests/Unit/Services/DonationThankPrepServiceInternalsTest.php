<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Services\DonationThankPrepService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the private helpers of {@see DonationThankPrepService} that the
 * command-level tests never reach: snippet cleaning, mod-chat history, the
 * birthday hint, the first-donation test and duplicate-account candidates.
 */
class DonationThankPrepServiceInternalsTest extends TestCase
{
    private DonationThankPrepService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DonationThankPrepService();
    }

    private function invoke(string $method, ...$args)
    {
        $m = new \ReflectionMethod($this->service, $method);

        return $m->invoke($this->service, ...$args);
    }

    private function donation(array $attrs = []): int
    {
        return (int) DB::table('users_donations')->insertGetId(array_merge([
            'userid' => null,
            'GrossAmount' => 5.00,
            'TransactionType' => 'subscr_payment',
            'Payer' => 'donor-' . uniqid() . '@example.com',
            'PayerDisplayName' => 'Donor',
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'timestamp' => now(),
        ], $attrs));
    }

    public static function snippetProvider(): array
    {
        return [
            'plain' => ['hello world', 140, 'hello world'],
            'whitespace collapsed' => ["  a \n\t b   c ", 140, 'a b c'],
            'tags stripped' => ['<b>bold</b> text', 140, 'bold text'],
            'entities decoded' => ['Fish &amp; chips', 140, 'Fish & chips'],
            'truncated with ellipsis' => ['abcdefghij', 5, 'abcd…'],
            'exactly max not truncated' => ['abcde', 5, 'abcde'],
            'multibyte not cut' => ['ééééééé', 4, 'ééé…'],
            'empty' => ['', 10, ''],
        ];
    }

    #[DataProvider('snippetProvider')]
    public function test_clean_snippet(string $raw, int $max, string $expected): void
    {
        $this->assertSame($expected, $this->invoke('cleanSnippet', $raw, $max));
    }

    public static function excludedPayerProvider(): array
    {
        return [
            'empty payer never excluded' => ['', 'a@x.com', false],
            'listed' => ['a@x.com', 'a@x.com, b@x.com', true],
            'case-insensitive' => ['A@X.com', 'a@x.com', true],
            'second entry' => ['b@x.com', 'a@x.com,  b@x.com ', true],
            'not listed' => ['c@x.com', 'a@x.com,b@x.com', false],
            'empty list' => ['a@x.com', '', false],
        ];
    }

    #[DataProvider('excludedPayerProvider')]
    public function test_is_excluded_payer(string $payer, string $list, bool $expected): void
    {
        config(['freegle.donations.excluded_payers' => $list]);

        $this->assertSame($expected, $this->invoke('isExcludedPayer', $payer));
    }

    public static function externalProvider(): array
    {
        return [
            'bank transfer source' => [['source' => 'BankTransfer', 'type' => 'PayPal'], true],
            'external type' => [['source' => 'x', 'type' => 'External'], true],
            'paypal' => [['source' => 'DonateWithPayPal', 'type' => 'PayPal'], false],
            'missing fields' => [[], false],
        ];
    }

    #[DataProvider('externalProvider')]
    public function test_is_external_donation(array $fields, bool $expected): void
    {
        $this->assertSame($expected, $this->invoke('isExternalDonation', (object) $fields));
    }

    public function test_first_donation_by_userid(): void
    {
        $user = $this->createTestUser();
        $first = $this->donation(['userid' => $user->id]);
        $second = $this->donation(['userid' => $user->id]);

        $this->assertTrue($this->invoke('isFirstDonation', (object) ['id' => $first, 'userid' => $user->id]));
        $this->assertFalse($this->invoke('isFirstDonation', (object) ['id' => $second, 'userid' => $user->id]));
    }

    public function test_first_donation_falls_back_to_payer_when_unmatched(): void
    {
        $payer = 'unmatched-' . uniqid() . '@example.com';
        $first = $this->donation(['Payer' => $payer]);
        $second = $this->donation(['Payer' => $payer]);

        $this->assertTrue($this->invoke('isFirstDonation', (object) ['id' => $first, 'userid' => null, 'Payer' => $payer]));
        $this->assertFalse($this->invoke('isFirstDonation', (object) ['id' => $second, 'userid' => null, 'Payer' => $payer]));
    }

    public function test_recent_mod_chats_empty_without_rooms(): void
    {
        $user = $this->createTestUser();

        $this->assertSame([], $this->invoke('fetchRecentModChats', (int) $user->id));
    }

    public function test_recent_mod_chats_returns_member_and_mod_messages(): void
    {
        $member = $this->createTestUser();
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $room = $this->createTestChatRoom($member, $mod, ['chattype' => 'User2Mod', 'groupid' => $group->id]);
        $this->createTestChatMessage($room, $member, ['message' => '<i>Thanks</i> &amp; hello', 'date' => now()->subHour()]);
        $this->createTestChatMessage($room, $mod, ['message' => 'Welcome', 'date' => now()]);
        // Not shown: system-type and null messages.
        $this->createTestChatMessage($room, $mod, ['message' => 'sys', 'type' => 'System']);
        $this->createTestChatMessage($room, $mod, ['message' => null, 'type' => 'Default']);

        $chats = $this->invoke('fetchRecentModChats', (int) $member->id);

        $this->assertCount(2, $chats);
        $this->assertSame('Welcome', $chats[0]['snippet']);
        $this->assertFalse($chats[0]['fromMember']);
        $this->assertSame('Thanks & hello', $chats[1]['snippet']);
        $this->assertTrue($chats[1]['fromMember']);
        $this->assertSame((int) $room->id, $chats[0]['chatid']);
        $this->assertMatchesRegularExpression('/^\d{1,2} [A-Z][a-z]{2} \d{4}$/', $chats[0]['date']);
    }

    private function memberOfGroupFounded(string $founded, array $groupAttrs = []): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup(array_merge(
            ['type' => Group::TYPE_FREEGLE, 'publish' => 1, 'onmap' => 1, 'founded' => $founded],
            $groupAttrs
        ));
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MEMBER]);

        return (int) $user->id;
    }

    public static function birthdayProvider(): array
    {
        return [
            'founded today, earlier year' => ['-3 years', [], true],
            'founded yesterday' => ['-1 day -3 years', [], true],
            'founded two days ago' => ['-2 days -3 years', [], true],
            'founded three days ago' => ['-3 days -3 years', [], false],
            'founded this year' => ['now', [], false],
            'not on map' => ['-3 years', ['onmap' => 0], false],
            'unpublished' => ['-3 years', ['publish' => 0], false],
            'not freegle' => ['-3 years', ['type' => Group::TYPE_OTHER], false],
        ];
    }

    #[DataProvider('birthdayProvider')]
    public function test_donor_has_birthday_group(string $rel, array $groupAttrs, bool $expected): void
    {
        $founded = date('Y-m-d H:i:s', strtotime($rel));
        $uid = $this->memberOfGroupFounded($founded, $groupAttrs);

        $this->assertSame($expected, $this->invoke('donorHasBirthdayGroup', $uid));
    }

    public function test_birthday_hint_non_recurring_uses_group_check(): void
    {
        $uid = $this->memberOfGroupFounded(date('Y-m-d H:i:s', strtotime('-3 years')));
        $donation = (object) ['GrossAmount' => 5.00];

        $this->assertTrue($this->invoke('donorHasBirthdayHint', $uid, $donation, false));
        $this->assertTrue($this->invoke('donorHasBirthdayHint', $uid, $donation, true));
    }

    public function test_birthday_hint_suppressed_when_recurring_donation_already_seen_last_month(): void
    {
        $uid = $this->memberOfGroupFounded(date('Y-m-d H:i:s', strtotime('-3 years')));
        $this->donation([
            'userid' => $uid,
            'GrossAmount' => 7.00,
            'TransactionType' => 'subscr_payment',
            'timestamp' => now()->subDays(10),
        ]);
        $donation = (object) ['GrossAmount' => 7.00];

        $this->assertFalse($this->invoke('donorHasBirthdayHint', $uid, $donation, true));
        // Not recurring: the earlier payment is irrelevant.
        $this->assertTrue($this->invoke('donorHasBirthdayHint', $uid, $donation, false));
    }

    public function test_candidate_row_formats_name_email_and_link(): void
    {
        config(['freegle.sites.mod' => 'https://mod.example/']);

        $named = $this->invoke('candidateRow', (object) ['userid' => '12', 'firstname' => 'Ann', 'lastname' => 'Lee', 'fullname' => 'X', 'email' => 'a@b.c'], 'why');
        $this->assertSame(['userid' => 12, 'name' => 'Ann Lee', 'email' => 'a@b.c', 'reason' => 'why', 'link' => 'https://mod.example/support/12'], $named);

        $fullOnly = $this->invoke('candidateRow', (object) ['userid' => 3, 'firstname' => '', 'lastname' => null, 'fullname' => 'Full Name'], 'r');
        $this->assertSame('Full Name', $fullOnly['name']);
        $this->assertNull($fullOnly['email']);

        $unknown = $this->invoke('candidateRow', (object) ['userid' => 4], 'r');
        $this->assertSame('Unknown', $unknown['name']);
    }

    public function test_gather_candidates_by_email_prefix_and_name(): void
    {
        $prefix = 'sharedprefix' . uniqid();
        $other = $this->createTestUser(['email_preferred' => $prefix . '@gmail.com', 'fullname' => 'Zed Unique']);
        $nameOnly = $this->createTestUser(['fullname' => 'Pat Payer Name ' . uniqid()]);
        $trash = $this->createTestUser(['email_preferred' => $prefix . '@users.ilovefreegle.org']);

        $donation = (object) [
            'Payer' => $prefix . '@paypal.example',
            'PayerDisplayName' => $nameOnly->fullname,
        ];

        $cands = $this->invoke('gatherCandidates', $donation);
        $byId = array_column($cands, null, 'userid');

        $this->assertArrayHasKey((int) $other->id, $byId);
        $this->assertStringStartsWith('same email prefix', $byId[(int) $other->id]['reason']);
        $this->assertSame($prefix . '@gmail.com', $byId[(int) $other->id]['email']);
        $this->assertArrayNotHasKey((int) $trash->id, $byId, 'internal alias domains are excluded');
        $this->assertArrayHasKey((int) $nameOnly->id, $byId);
        $this->assertSame('name match', $byId[(int) $nameOnly->id]['reason']);
        $this->assertNull($byId[(int) $nameOnly->id]['email']);
    }

    public function test_gather_candidates_does_not_duplicate_a_user_matched_twice(): void
    {
        $prefix = 'dup' . uniqid();
        $user = $this->createTestUser(['email_preferred' => $prefix . '@gmail.com', 'fullname' => 'Dup Match ' . uniqid()]);

        $cands = $this->invoke('gatherCandidates', (object) [
            'Payer' => $prefix . '@paypal.example',
            'PayerDisplayName' => $user->fullname,
        ]);

        $this->assertCount(1, array_filter($cands, fn ($c) => $c['userid'] === (int) $user->id));
    }

    public static function noCandidateProvider(): array
    {
        return [
            'short local part' => ['ab@x.com', ''],
            'name is an email' => ['', 'someone@x.com'],
            'blank everything' => ['', ''],
        ];
    }

    #[DataProvider('noCandidateProvider')]
    public function test_gather_candidates_skips_unusable_input(string $payer, string $name): void
    {
        $this->assertSame([], $this->invoke('gatherCandidates', (object) ['Payer' => $payer, 'PayerDisplayName' => $name]));
    }
}
