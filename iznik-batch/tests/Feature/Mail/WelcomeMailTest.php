<?php

namespace Tests\Feature\Mail;

use App\Mail\Welcome\WelcomeMail;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MailHogHelper;
use Tests\TestCase;

class WelcomeMailTest extends TestCase
{
    protected MailHogHelper $mailHog;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up MailHog helper with docker network URL.
        $this->mailHog = new MailHogHelper('http://mailhog:8025');
    }

    /**
     * Test that welcome email is sent via Laravel's mail system.
     */
    public function test_welcome_email_can_be_sent(): void
    {
        Mail::fake();

        $email = 'test@example.com';
        $password = 'testpassword123';

        Mail::to($email)->send(new WelcomeMail($email, $password));

        Mail::assertSent(WelcomeMail::class, function ($mail) use ($email) {
            return $mail->recipientEmail === $email;
        });
    }

    /**
     * Test welcome email without password.
     */
    public function test_welcome_email_without_password(): void
    {
        Mail::fake();

        $email = 'newuser@example.com';

        Mail::to($email)->send(new WelcomeMail($email));

        Mail::assertSent(WelcomeMail::class, function ($mail) {
            return $mail->password === NULL;
        });
    }

    /**
     * Test welcome email envelope has correct subject.
     */
    public function test_welcome_email_has_correct_subject(): void
    {
        $email = 'test@example.com';
        $mail = new WelcomeMail($email);

        $envelope = $mail->envelope();

        $this->assertEquals('Welcome to Freegle!', $envelope->subject);
    }

    /**
     * Test welcome email envelope has correct from address.
     */
    public function test_welcome_email_has_correct_from_address(): void
    {
        $email = 'test@example.com';
        $mail = new WelcomeMail($email);

        $envelope = $mail->envelope();

        $this->assertEquals(config('freegle.mail.noreply_addr'), $envelope->from->address);
        $this->assertEquals(config('freegle.branding.name'), $envelope->from->name);
    }

    /**
     * Test that welcome email view data is correct.
     */
    public function test_welcome_email_view_data(): void
    {
        $email = 'user@test.com';
        $password = 'secret123';

        $mail = new WelcomeMail($email, $password);

        $this->assertEquals($email, $mail->recipientEmail);
        $this->assertEquals($password, $mail->password);
    }

    /**
     * Test that welcome email renders HTML via build method.
     */
    public function test_welcome_email_build_renders_html(): void
    {
        $email = 'user@test.com';
        $password = 'secret123';

        $mail = new WelcomeMail($email, $password);
        $builtMail = $mail->build();

        $this->assertInstanceOf(WelcomeMail::class, $builtMail);
    }

    /**
     * Test welcome email attachments returns empty array.
     */
    public function test_welcome_email_attachments(): void
    {
        $email = 'user@test.com';
        $mail = new WelcomeMail($email);

        $this->assertEquals([], $mail->attachments());
    }
}
