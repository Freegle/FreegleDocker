<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Services\ChatReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Unit coverage for {@see ChatReviewService}: auto-rejecting chat messages
 * stuck in review for a week, and the daily nag to group mods plus the
 * mentors summary for messages pending over 48 hours.
 *
 * The test database is not empty mid-suite (earlier tests leak committed
 * rows), so assertions look at the rows and mails this test created rather
 * than at absolute totals.
 */
class ChatReviewServiceTest extends TestCase
{
    private ChatReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatReviewService();
        config(['mail.default' => 'array']);
        Mail::purge('array');
    }

    private function modbot(): int
    {
        $user = $this->createTestUser([
            'email_preferred' => config('freegle.mail.moderator_email'),
        ]);

        return (int) $user->id;
    }

    /** A review-held message from a sender to a recipient. */
    private function pendingMessage(int $hoursOld, array $attrs = []): array
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $message = $this->createTestChatMessage($room, $sender, array_merge([
            'date' => now()->subHours($hoursOld)->toDateTimeString(),
            'reviewrequired' => 1,
            'reviewedby' => null,
            'reviewrejected' => 0,
        ], $attrs));

        return [$sender, $recipient, $message];
    }

    /** @return list<Email> */
    private function sentMails(): array
    {
        $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();

        return array_map(fn ($m) => $m->getOriginalMessage(), $transport->messages()->all());
    }

    /** @return list<Email> mails addressed to $address */
    private function mailsTo(string $address): array
    {
        return array_values(array_filter($this->sentMails(), function ($mail) use ($address) {
            foreach ($mail->getTo() as $to) {
                if ($to->getAddress() === $address) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function test_return_shape(): void
    {
        $result = $this->service->processReview(true);

        $this->assertSame(['rejected', 'notified_groups', 'total_pending'], array_keys($result));
    }

    public function test_no_modbot_account_means_nothing_rejected(): void
    {
        DB::table('users_emails')->where('email', config('freegle.mail.moderator_email'))->delete();
        [, , $message] = $this->pendingMessage(24 * 10);

        $result = $this->service->processReview();

        $this->assertSame(0, $result['rejected']);
        $this->assertNull(DB::table('chat_messages')->where('id', $message->id)->value('reviewedby'));
    }

    public function test_deleted_modbot_account_is_ignored(): void
    {
        $modbot = $this->modbot();
        DB::table('users')->where('id', $modbot)->update(['deleted' => now()]);
        [, , $message] = $this->pendingMessage(24 * 10);

        $result = $this->service->processReview();

        $this->assertSame(0, $result['rejected']);
        $this->assertNull(DB::table('chat_messages')->where('id', $message->id)->value('reviewedby'));
    }

    public function test_stale_message_is_auto_rejected_by_modbot(): void
    {
        $modbot = $this->modbot();
        [, , $message] = $this->pendingMessage(24 * 10);

        $result = $this->service->processReview();

        $this->assertGreaterThanOrEqual(1, $result['rejected']);
        $row = DB::table('chat_messages')->where('id', $message->id)->first();
        $this->assertSame($modbot, (int) $row->reviewedby);
        $this->assertSame(1, (int) $row->reviewrejected);
    }

    public function test_dry_run_counts_but_does_not_reject(): void
    {
        $this->modbot();
        [, , $message] = $this->pendingMessage(24 * 10);

        $result = $this->service->processReview(true);

        $this->assertGreaterThanOrEqual(1, $result['rejected']);
        $row = DB::table('chat_messages')->where('id', $message->id)->first();
        $this->assertNull($row->reviewedby);
        $this->assertSame(0, (int) $row->reviewrejected);
    }

    public static function notRejectedProvider(): array
    {
        return [
            'recent (3 days)' => [24 * 3, []],
            'no longer needs review' => [24 * 10, ['reviewrequired' => 0]],
            'already reviewed' => [24 * 10, ['reviewedby' => 'SELF']],
        ];
    }

    #[DataProvider('notRejectedProvider')]
    public function test_messages_that_should_not_be_auto_rejected(int $hoursOld, array $attrs): void
    {
        $modbot = $this->modbot();
        [$sender, , $message] = $this->pendingMessage($hoursOld);
        $update = [];
        foreach ($attrs as $k => $v) {
            $update[$k] = $v === 'SELF' ? $sender->id : $v;
        }
        if ($update) {
            DB::table('chat_messages')->where('id', $message->id)->update($update);
        }

        $this->service->processReview();

        $row = DB::table('chat_messages')->where('id', $message->id)->first();
        $this->assertNotSame($modbot, (int) $row->reviewedby);
        $this->assertSame(0, (int) $row->reviewrejected);
    }

    public function test_pending_over_48_hours_notifies_group_contact_mail_and_mentors(): void
    {
        $this->modbot();
        [, $recipient] = $this->pendingMessage(60);
        $group = $this->createTestGroup(['type' => Group::TYPE_FREEGLE, 'publish' => 1, 'contactmail' => 'mods-' . uniqid() . '@example.org']);
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MEMBER]);

        $result = $this->service->processReview();

        $this->assertGreaterThanOrEqual(1, $result['notified_groups']);
        $this->assertGreaterThanOrEqual(1, $result['total_pending']);

        $mails = $this->mailsTo($group->contactmail);
        $this->assertCount(1, $mails);
        $this->assertSame(
            '1 message between members waiting for your review on ' . $group->nameshort,
            $mails[0]->getSubject()
        );
        $this->assertStringContainsString("Dear {$group->nameshort} Volunteers", $mails[0]->getTextBody());
        $this->assertStringContainsString(config('freegle.sites.mod') . '/chats/review', $mails[0]->getTextBody());

        $summaries = $this->mailsTo(config('freegle.mail.mentors_addr'));
        $this->assertCount(1, $summaries);
        $this->assertSame('Summary of chat messages waiting for review', $summaries[0]->getSubject());
        $this->assertStringContainsString($group->nameshort . ' count 1', $summaries[0]->getTextBody());
    }

    public function test_plural_subject_and_default_volunteers_address(): void
    {
        $this->modbot();
        $group = $this->createTestGroup(['type' => Group::TYPE_FREEGLE, 'publish' => 1, 'contactmail' => null]);
        foreach ([60, 61] as $hours) {
            [, $recipient] = $this->pendingMessage($hours);
            $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MEMBER]);
        }

        $this->service->processReview();

        $to = $group->nameshort . '-volunteers@' . config('freegle.mail.group_domain');
        $mails = $this->mailsTo($to);
        $this->assertCount(1, $mails);
        $this->assertSame(
            '2 messages between members waiting for your review on ' . $group->nameshort,
            $mails[0]->getSubject()
        );
    }

    public function test_dry_run_sends_no_mail_but_still_counts(): void
    {
        $this->modbot();
        [, $recipient] = $this->pendingMessage(60);
        $group = $this->createTestGroup(['type' => Group::TYPE_FREEGLE, 'publish' => 1, 'contactmail' => 'dry-' . uniqid() . '@example.org']);
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MEMBER]);

        $result = $this->service->processReview(true);

        $this->assertGreaterThanOrEqual(1, $result['notified_groups']);
        $this->assertSame([], $this->mailsTo($group->contactmail));
        $this->assertSame([], $this->mailsTo(config('freegle.mail.mentors_addr')));
    }

    public static function notNotifiedProvider(): array
    {
        return [
            'under 48 hours' => [24, [], []],
            'non-Freegle group' => [60, [], ['type' => Group::TYPE_OTHER]],
            'unpublished group' => [60, [], ['publish' => 0]],
            'already rejected' => [60, ['reviewrejected' => 1], []],
            'already reviewed' => [60, ['reviewedby' => 'SELF'], []],
            'not needing review' => [60, ['reviewrequired' => 0], []],
            'held' => [60, ['HELD' => true], []],
        ];
    }

    #[DataProvider('notNotifiedProvider')]
    public function test_groups_not_notified(int $hoursOld, array $msgAttrs, array $groupAttrs): void
    {
        $this->modbot();
        [$sender, $recipient, $message] = $this->pendingMessage($hoursOld);
        $update = [];
        foreach ($msgAttrs as $k => $v) {
            if ($k === 'HELD') {
                DB::table('chat_messages_held')->insert(['msgid' => $message->id, 'userid' => $sender->id]);
            } else {
                $update[$k] = $v === 'SELF' ? $sender->id : $v;
            }
        }
        if ($update) {
            DB::table('chat_messages')->where('id', $message->id)->update($update);
        }
        $contact = 'skip-' . uniqid() . '@example.org';
        $group = $this->createTestGroup(array_merge(
            ['type' => Group::TYPE_FREEGLE, 'publish' => 1, 'contactmail' => $contact],
            $groupAttrs
        ));
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MEMBER]);

        $this->service->processReview(true);
        $this->service->processReview();

        $this->assertSame([], $this->mailsTo($contact));
    }

    public function test_message_from_deleted_user_is_not_counted(): void
    {
        $this->modbot();
        [$sender, $recipient] = $this->pendingMessage(60);
        DB::table('users')->where('id', $sender->id)->update(['deleted' => now()]);
        $contact = 'del-' . uniqid() . '@example.org';
        $group = $this->createTestGroup(['type' => Group::TYPE_FREEGLE, 'publish' => 1, 'contactmail' => $contact]);
        $this->createMembership($recipient, $group, ['role' => Membership::ROLE_MEMBER]);

        $this->service->processReview();

        $this->assertSame([], $this->mailsTo($contact));
    }
}
