<?php

namespace Tests\Unit\Mail;

use App\Mail\Donation\AskForDonation;
use App\Mail\Donation\DonationThankYou;
use App\Models\User;
use Tests\TestCase;

class DonationMailTest extends TestCase
{
    public function test_donation_thank_you_can_be_constructed(): void
    {
        $user = $this->createTestUser();

        $mail = new DonationThankYou($user);

        $this->assertInstanceOf(DonationThankYou::class, $mail);
    }

    public function test_donation_thank_you_has_user(): void
    {
        $user = $this->createTestUser();

        $mail = new DonationThankYou($user);

        $this->assertSame($user->id, $mail->user->id);
    }

    public function test_donation_thank_you_has_user_site(): void
    {
        $user = $this->createTestUser();

        $mail = new DonationThankYou($user);

        $this->assertNotEmpty($mail->userSite);
        $this->assertStringContainsString('http', $mail->userSite);
    }

    public function test_donation_thank_you_build_returns_self(): void
    {
        $user = $this->createTestUser();

        $mail = new DonationThankYou($user);
        $result = $mail->build();

        $this->assertInstanceOf(DonationThankYou::class, $result);
    }

    public function test_donation_thank_you_has_correct_subject(): void
    {
        $user = $this->createTestUser();

        $mail = new DonationThankYou($user);
        $envelope = $mail->envelope();

        $this->assertEquals('Thank you for your donation to Freegle!', $envelope->subject);
    }

    public function test_donation_thank_you_has_attachments(): void
    {
        $user = $this->createTestUser();

        $mail = new DonationThankYou($user);
        $attachments = $mail->attachments();

        $this->assertIsArray($attachments);
        $this->assertEmpty($attachments);
    }

    public function test_ask_for_donation_can_be_constructed(): void
    {
        $user = $this->createTestUser();

        $mail = new AskForDonation($user);

        $this->assertInstanceOf(AskForDonation::class, $mail);
        $this->assertEquals($user->id, $mail->user->id);
    }

    public function test_ask_for_donation_with_item_subject(): void
    {
        $user = $this->createTestUser();
        $itemSubject = 'OFFER: Free sofa (London)';

        $mail = new AskForDonation($user, $itemSubject);

        $this->assertEquals($itemSubject, $mail->itemSubject);
    }

    public function test_ask_for_donation_build_returns_self(): void
    {
        $user = $this->createTestUser();

        $mail = new AskForDonation($user, 'Test Item');
        $result = $mail->build();

        $this->assertInstanceOf(AskForDonation::class, $result);
    }

    public function test_ask_for_donation_subject_with_item(): void
    {
        $user = $this->createTestUser();
        $itemSubject = 'OFFER: Test Item (Location)';

        $mail = new AskForDonation($user, $itemSubject);
        $mail->build();

        // Subject should contain the item.
        $this->assertTrue($mail->hasSubject("Regarding: {$itemSubject}"));
    }

    public function test_ask_for_donation_subject_without_item(): void
    {
        $user = $this->createTestUser();

        $mail = new AskForDonation($user);
        $mail->build();

        // Subject should be the default.
        $this->assertTrue($mail->hasSubject('Thanks for freegling!'));
    }

    public function test_ask_for_donation_has_user_site(): void
    {
        $user = $this->createTestUser();

        $mail = new AskForDonation($user);

        $this->assertNotEmpty($mail->userSite);
    }

    public function test_ask_for_donation_has_target(): void
    {
        $user = $this->createTestUser();

        $mail = new AskForDonation($user);

        $this->assertIsFloat($mail->target);
    }

    public function test_ask_for_donation_has_donate_url(): void
    {
        $user = $this->createTestUser();

        $mail = new AskForDonation($user);

        $this->assertNotEmpty($mail->donateUrl);
    }
}
