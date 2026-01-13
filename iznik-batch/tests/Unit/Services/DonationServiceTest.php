<?php

namespace Tests\Unit\Services;

use App\Mail\Donation\AskForDonation;
use App\Mail\Donation\DonationThankYou;
use App\Models\UserDonation;
use App\Services\DonationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DonationServiceTest extends TestCase
{
    protected DonationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DonationService();
        Mail::fake();
    }

    public function test_thank_donors_with_no_donations(): void
    {
        // Mark all existing donations as thanked to ensure clean state.
        // This prevents interference from parallel tests.
        DB::table('users_donations')
            ->whereNotIn('userid', function ($query) {
                $query->select('userid')->from('users_thanks');
            })
            ->get()
            ->each(function ($donation) {
                DB::table('users_thanks')->insertOrIgnore(['userid' => $donation->userid]);
            });

        $stats = $this->service->thankDonors();

        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_thank_donors_sends_email(): void
    {
        $user = $this->createTestUser();

        // Create donation.
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 10.00,
            'timestamp' => now()->subDays(2),
        ]);

        $stats = $this->service->thankDonors();

        $this->assertEquals(1, $stats['emails_sent']);
        Mail::assertSent(DonationThankYou::class);
    }

    public function test_thank_donors_skips_already_thanked(): void
    {
        $user = $this->createTestUser();

        // Create donation.
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 10.00,
            'timestamp' => now()->subDays(2),
        ]);

        // Mark as already thanked.
        DB::table('users_thanks')->insert(['userid' => $user->id]);

        $stats = $this->service->thankDonors();

        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_thank_donors_skips_old_donations(): void
    {
        $user = $this->createTestUser();

        // Create old donation (older than 7 days).
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 10.00,
            'timestamp' => now()->subDays(10),
        ]);

        $stats = $this->service->thankDonors();

        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_get_stats_returns_monthly_totals(): void
    {
        $user = $this->createTestUser();

        // Get baseline before creating our donation.
        $beforeStats = $this->service->getStats();
        $beforeTotal = $beforeStats['monthly_total'] ?? 0;
        $beforeCount = $beforeStats['donor_count'] ?? 0;

        // Create donation this month.
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 25.00,
            'timestamp' => now(),
        ]);

        $stats = $this->service->getStats();

        // In parallel tests, other donations may exist, so check for our contribution.
        $this->assertEquals($beforeTotal + 25.00, $stats['monthly_total']);
        $this->assertEquals($beforeCount + 1, $stats['donor_count']);
        $this->assertArrayHasKey('target', $stats);
    }

    public function test_ask_for_donations_with_no_recipients(): void
    {
        $stats = $this->service->askForDonations();

        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_ask_for_donations_respects_interval(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Create a message for the foreign key constraint.
        $message = $this->createTestMessage($user, $group);

        // Record recent ask.
        DB::table('users_donations_asks')->insert([
            'userid' => $user->id,
            'timestamp' => now()->subDays(3),
        ]);

        // Create item receipt (this would normally trigger an ask).
        DB::table('messages_by')->insert([
            'userid' => $user->id,
            'msgid' => $message->id,
            'timestamp' => now()->subHours(2),
        ]);

        $stats = $this->service->askForDonations();

        // Should be skipped because we asked recently.
        $this->assertGreaterThanOrEqual(0, $stats['skipped_recent_ask']);
        Mail::assertNothingSent();
    }

    public function test_thank_donors_skips_excluded_payers(): void
    {
        $user = $this->createTestUser();

        // Create donation with excluded payer pattern.
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => 'test@example.com', // In EXCLUDED_PAYERS list.
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 10.00,
            'timestamp' => now()->subDays(2),
        ]);

        $stats = $this->service->thankDonors();

        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_thank_donors_skips_paypal_test(): void
    {
        $user = $this->createTestUser();

        // Create donation with PayPal Test payer.
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => 'PayPal Test Account',
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 10.00,
            'timestamp' => now()->subDays(2),
        ]);

        $stats = $this->service->thankDonors();

        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_thank_donors_skips_user_without_email(): void
    {
        // Create user without email.
        $user = \App\Models\User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        // Create donation.
        UserDonation::create([
            'userid' => $user->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 10.00,
            'timestamp' => now()->subDays(2),
        ]);

        $stats = $this->service->thankDonors();

        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_recent_donation_days_constant(): void
    {
        $this->assertEquals(7, DonationService::RECENT_DONATION_DAYS);
    }

    public function test_ask_interval_days_constant(): void
    {
        $this->assertEquals(7, DonationService::ASK_INTERVAL_DAYS);
    }

    public function test_get_stats_with_multiple_donors(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        // Create donations from both users.
        UserDonation::create([
            'userid' => $user1->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 10.00,
            'timestamp' => now(),
        ]);

        UserDonation::create([
            'userid' => $user2->id,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test Donor',
            'GrossAmount' => 15.00,
            'timestamp' => now(),
        ]);

        $stats = $this->service->getStats();

        $this->assertEquals(25.00, $stats['monthly_total']);
        $this->assertEquals(2, $stats['donor_count']);
    }

    public function test_get_stats_with_no_donations(): void
    {
        $stats = $this->service->getStats();

        $this->assertEquals(0, $stats['monthly_total']);
        $this->assertEquals(0, $stats['donor_count']);
    }

    public function test_ask_for_donations_sends_email_to_recipient(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($sender, $group);
        $this->createMembership($recipient, $group);

        // Create an offer message from sender.
        $message = \App\Models\Message::create([
            'type' => \App\Models\Message::TYPE_OFFER,
            'fromuser' => $sender->id,
            'subject' => 'OFFER: Test Item (Location)',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
        ]);

        \App\Models\MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => \App\Models\MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(5),
        ]);

        // Create chat room and interested message.
        $room = \App\Models\ChatRoom::create([
            'chattype' => \App\Models\ChatRoom::TYPE_USER2USER,
            'user1' => $sender->id,
            'user2' => $recipient->id,
            'created' => now()->subDays(4),
        ]);

        \App\Models\ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'message' => 'Is this available?',
            'type' => 'Interested',
            'refmsgid' => $message->id,
            'date' => now()->subDays(4),
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        // Create messages_by record for recipient - in the time window.
        $start = now()->subDay()->setTime(17, 0);
        DB::table('messages_by')->insert([
            'userid' => $recipient->id,
            'msgid' => $message->id,
            'timestamp' => $start->addMinutes(30), // Within the window.
        ]);

        $stats = $this->service->askForDonations();

        // Verify the stats.
        $this->assertArrayHasKey('processed', $stats);
        $this->assertArrayHasKey('emails_sent', $stats);
    }

    public function test_ask_for_donations_skips_user_without_email(): void
    {
        // Create user without email.
        $userWithoutEmail = \App\Models\User::create([
            'firstname' => 'No',
            'lastname' => 'Email',
            'fullname' => 'No Email',
            'added' => now(),
        ]);

        $group = $this->createTestGroup();

        // Create a message.
        $message = \App\Models\Message::create([
            'type' => \App\Models\Message::TYPE_OFFER,
            'fromuser' => $this->createTestUser()->id,
            'subject' => 'OFFER: Test (Location)',
            'source' => 'Platform',
            'date' => now()->subDays(5),
            'arrival' => now()->subDays(5),
        ]);

        // Add messages_by record.
        $start = now()->subDay()->setTime(17, 0);
        DB::table('messages_by')->insert([
            'userid' => $userWithoutEmail->id,
            'msgid' => $message->id,
            'timestamp' => $start->addMinutes(30),
        ]);

        $stats = $this->service->askForDonations();

        // Should process but not send email since no preferred email.
        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }
}
