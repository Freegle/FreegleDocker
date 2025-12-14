<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserDonation;
use Tests\TestCase;

class UserDonationModelTest extends TestCase
{
    public function test_donation_can_be_created(): void
    {
        $user = $this->createTestUser();

        $donation = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => 'test@example.com',
            'PayerDisplayName' => 'Test User',
        ]);

        $this->assertDatabaseHas('users_donations', ['id' => $donation->id]);
    }

    public function test_user_relationship(): void
    {
        $user = $this->createTestUser();

        $donation = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => 'test@example.com',
            'PayerDisplayName' => 'Test User',
        ]);

        $this->assertEquals($user->id, $donation->user->id);
    }

    public function test_without_gift_aid_consent_scope(): void
    {
        $user = $this->createTestUser();

        $noConsent = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => 'test1@example.com',
            'PayerDisplayName' => 'Test User',
            'giftaidconsent' => false,
        ]);

        $withConsent = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 20.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => 'test2@example.com',
            'PayerDisplayName' => 'Test User',
            'giftaidconsent' => true,
        ]);

        $results = UserDonation::withoutGiftAidConsent()->get();

        $this->assertTrue($results->contains('id', $noConsent->id));
        $this->assertFalse($results->contains('id', $withConsent->id));
    }

    public function test_not_chased_for_gift_aid_scope(): void
    {
        $user = $this->createTestUser();

        $notChased = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => 'test3@example.com',
            'PayerDisplayName' => 'Test User',
            'giftaidchaseup' => null,
        ]);

        $chased = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 20.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => 'test4@example.com',
            'PayerDisplayName' => 'Test User',
            'giftaidchaseup' => now(),
        ]);

        $results = UserDonation::notChasedForGiftAid()->get();

        $this->assertTrue($results->contains('id', $notChased->id));
        $this->assertFalse($results->contains('id', $chased->id));
    }

    public function test_recent_scope(): void
    {
        $user = $this->createTestUser();

        $recent = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now()->subDays(5),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => 'test5@example.com',
            'PayerDisplayName' => 'Test User',
        ]);

        $old = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now()->subDays(60),
            'GrossAmount' => 20.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => 'test6@example.com',
            'PayerDisplayName' => 'Test User',
        ]);

        $results = UserDonation::recent(30)->get();

        $this->assertTrue($results->contains('id', $recent->id));
        $this->assertFalse($results->contains('id', $old->id));
    }

    public function test_in_date_range_scope(): void
    {
        $user = $this->createTestUser();

        $inRange = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now()->subDays(10),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => 'test7@example.com',
            'PayerDisplayName' => 'Test User',
        ]);

        $tooRecent = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now()->subDays(2),
            'GrossAmount' => 20.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => 'test8@example.com',
            'PayerDisplayName' => 'Test User',
        ]);

        $tooOld = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now()->subDays(40),
            'GrossAmount' => 30.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => 'test9@example.com',
            'PayerDisplayName' => 'Test User',
        ]);

        $results = UserDonation::inDateRange(5, 30)->get();

        $this->assertTrue($results->contains('id', $inRange->id));
        $this->assertFalse($results->contains('id', $tooRecent->id));
        $this->assertFalse($results->contains('id', $tooOld->id));
    }

    public function test_source_constants(): void
    {
        $this->assertEquals('PayPal', UserDonation::SOURCE_PAYPAL);
        $this->assertEquals('Stripe', UserDonation::SOURCE_STRIPE);
        $this->assertEquals('Native', UserDonation::SOURCE_NATIVE);
    }
}
