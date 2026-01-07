<?php

namespace Tests\Unit\Mail;

use App\Mail\Welcome\WelcomeMail;
use Tests\TestCase;

class WelcomeMailTest extends TestCase
{
    public function test_welcome_mail_can_be_constructed_with_email_only(): void
    {
        $email = $this->uniqueEmail('welcome');
        $mail = new WelcomeMail($email);

        $this->assertInstanceOf(WelcomeMail::class, $mail);
        $this->assertEquals($email, $mail->recipientEmail);
        $this->assertNull($mail->password);
        $this->assertNull($mail->userId);
    }

    public function test_welcome_mail_can_be_constructed_with_password(): void
    {
        $email = $this->uniqueEmail('welcome');
        $mail = new WelcomeMail($email, 'secretpass123');

        $this->assertEquals($email, $mail->recipientEmail);
        $this->assertEquals('secretpass123', $mail->password);
    }

    public function test_welcome_mail_can_be_constructed_with_user_id(): void
    {
        $user = $this->createTestUser();

        $mail = new WelcomeMail($user->email_preferred, null, $user->id);

        $this->assertEquals($user->id, $mail->userId);
    }

    public function test_welcome_mail_has_correct_subject(): void
    {
        $mail = new WelcomeMail($this->uniqueEmail('welcome'));
        $envelope = $mail->envelope();

        $this->assertStringContainsString('Welcome', $envelope->subject);
        $this->assertStringContainsString(config('freegle.branding.name'), $envelope->subject);
    }

    public function test_welcome_mail_has_correct_from_address(): void
    {
        $mail = new WelcomeMail($this->uniqueEmail('welcome'));
        $envelope = $mail->envelope();

        $this->assertNotNull($envelope->from);
        $this->assertEquals(config('freegle.mail.noreply_addr'), $envelope->from->address);
    }

    public function test_welcome_mail_build_returns_self(): void
    {
        $mail = new WelcomeMail($this->uniqueEmail('welcome'));
        $result = $mail->build();

        $this->assertInstanceOf(WelcomeMail::class, $result);
    }

    public function test_welcome_mail_has_attachments_method(): void
    {
        $mail = new WelcomeMail($this->uniqueEmail('welcome'));
        $attachments = $mail->attachments();

        $this->assertIsArray($attachments);
        $this->assertEmpty($attachments);
    }

    public function test_welcome_mail_with_existing_user_loads_user(): void
    {
        $user = $this->createTestUser(['firstname' => 'TestFirstName']);

        $mail = new WelcomeMail($user->email_preferred, null, $user->id);
        $mail->build();

        // The user should be loaded since we passed userId.
        $this->assertEquals($user->id, $mail->userId);
    }
}
