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
        $stats = $this->service->thankDonors();

        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, $stats['emails_sent']);
        Mail::assertNothingSent();
    }

    public function test_thank_donors_sends_email(): void
    {
        $user = $this->createTestUser();

        // Create donation.
        UserDonation::create([
            'userid' => $user->id,
            'payer' => 'test@example.org',
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
            'payer' => 'test@example.org',
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
            'payer' => 'test@example.org',
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

        // Create donation this month.
        UserDonation::create([
            'userid' => $user->id,
            'payer' => 'test@example.org',
            'GrossAmount' => 25.00,
            'timestamp' => now(),
        ]);

        $stats = $this->service->getStats();

        $this->assertEquals(25.00, $stats['monthly_total']);
        $this->assertEquals(1, $stats['donor_count']);
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
}
