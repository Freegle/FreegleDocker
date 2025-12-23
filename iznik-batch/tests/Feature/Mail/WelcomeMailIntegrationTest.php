<?php

namespace Tests\Feature\Mail;

use App\Mail\Welcome\WelcomeMail;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MailHogHelper;
use Tests\TestCase;

/**
 * Integration tests that actually send emails to MailHog and verify via API.
 *
 * These tests require MailHog to be running and accessible.
 * Note: These tests do NOT use RefreshDatabase - they use the real database
 * and send real emails to MailHog.
 *
 * Tests use unique identifiers (uniqid) to ensure they can run in parallel
 * without data conflicts.
 */
class WelcomeMailIntegrationTest extends TestCase
{
    protected MailHogHelper $mailHog;
    protected string $testRunId;

    protected function setUp(): void
    {
        parent::setUp();

        // Generate unique ID for this test run to avoid conflicts in parallel execution.
        $this->testRunId = uniqid('test_', TRUE);

        // Set up MailHog helper with docker network URL.
        $this->mailHog = new MailHogHelper('http://mailhog:8025');

        // Clear all messages before each test.
        $this->mailHog->deleteAllMessages();
    }

    protected function tearDown(): void
    {
        // Don't clear messages after test - leave for manual inspection.
        parent::tearDown();
    }

    /**
     * Generate unique email address for this test.
     */
    protected function uniqueEmail(string $prefix = 'test'): string
    {
        return "{$prefix}_{$this->testRunId}@example.com";
    }

    /**
     * Test welcome email is actually delivered to MailHog.
     */
    public function test_welcome_email_delivered_to_mailhog(): void
    {
        if (!$this->isMailHogAvailable()) {
            $this->markTestSkipped('MailHog is not available.');
        }

        $recipientEmail = $this->uniqueEmail('delivery');
        $password = 'welcomepass123';

        // Send the email.
        Mail::to($recipientEmail)->send(new WelcomeMail($recipientEmail, $password));

        // Wait for and verify the message via MailHog API.
        $message = $this->mailHog->assertMessageSentTo($recipientEmail);

        $this->assertNotNull($message);
        // Subject contains emoji and site name.
        $subject = $this->mailHog->getSubject($message);
        $this->assertStringContainsString('Welcome to Freegle', $subject);
    }

    /**
     * Test welcome email body contains expected content.
     */
    public function test_welcome_email_contains_expected_content(): void
    {
        if (!$this->isMailHogAvailable()) {
            $this->markTestSkipped('MailHog is not available.');
        }

        $recipientEmail = $this->uniqueEmail('content');
        $password = 'mypassword456';

        Mail::to($recipientEmail)->send(new WelcomeMail($recipientEmail, $password));

        $message = $this->mailHog->assertMessageSentTo($recipientEmail);

        // Verify body contains key content.
        $this->assertTrue(
            $this->mailHog->bodyContains($message, 'part of the'),
            'Email body should contain welcome text'
        );

        $this->assertTrue(
            $this->mailHog->bodyContains($message, $password),
            'Email body should contain the password'
        );

        $this->assertTrue(
            $this->mailHog->bodyContains($message, 'Happy freegling'),
            'Email body should contain closing text'
        );
    }

    /**
     * Test welcome email without password does not show password section.
     */
    public function test_welcome_email_without_password_excludes_password_section(): void
    {
        if (!$this->isMailHogAvailable()) {
            $this->markTestSkipped('MailHog is not available.');
        }

        $recipientEmail = $this->uniqueEmail('nopassword');

        Mail::to($recipientEmail)->send(new WelcomeMail($recipientEmail, NULL));

        $message = $this->mailHog->assertMessageSentTo($recipientEmail);

        // Verify the password section is not in the body.
        $this->assertFalse(
            $this->mailHog->bodyContains($message, "IMPORTANT: Your password"),
            'Email body should not contain password section when password is null'
        );
    }

    /**
     * Test email footer contains recipient email.
     */
    public function test_welcome_email_footer_contains_recipient(): void
    {
        if (!$this->isMailHogAvailable()) {
            $this->markTestSkipped('MailHog is not available.');
        }

        $recipientEmail = $this->uniqueEmail('footer');

        Mail::to($recipientEmail)->send(new WelcomeMail($recipientEmail));

        $message = $this->mailHog->assertMessageSentTo($recipientEmail);

        $this->assertTrue(
            $this->mailHog->bodyContains($message, $recipientEmail),
            'Email footer should contain recipient email address'
        );
    }

    /**
     * Test email contains call-to-action buttons.
     */
    public function test_welcome_email_contains_cta_buttons(): void
    {
        if (!$this->isMailHogAvailable()) {
            $this->markTestSkipped('MailHog is not available.');
        }

        $recipientEmail = $this->uniqueEmail('cta');

        Mail::to($recipientEmail)->send(new WelcomeMail($recipientEmail));

        $message = $this->mailHog->assertMessageSentTo($recipientEmail);

        // Check for CTA button text (URLs are tracked via redirect).
        $this->assertTrue(
            $this->mailHog->bodyContains($message, 'Give stuff away'),
            'Email should contain Give button text'
        );

        $this->assertTrue(
            $this->mailHog->bodyContains($message, 'Find what you need'),
            'Email should contain Find button text'
        );

        // Verify the three rules section exists.
        $this->assertTrue(
            $this->mailHog->bodyContains($message, 'Three simple rules'),
            'Email should contain rules section'
        );
    }

    /**
     * Check if MailHog is available.
     */
    protected function isMailHogAvailable(): bool
    {
        try {
            $ch = curl_init('http://mailhog:8025/api/v2/messages');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        } catch (\Exception $e) {
            return FALSE;
        }
    }
}
