<?php

namespace Tests\Feature\Mail;

use App\Services\EmailSpoolerService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\TextPart;
use Tests\Support\MailpitHelper;
use Tests\TestCase;

/**
 * Integration tests for AMP email MIME structure.
 *
 * These tests verify that AMP emails sent through the spooler
 * actually contain the text/x-amp-html MIME part in the sent message.
 *
 * Requires Mailpit to be running and accessible.
 *
 * Note: These tests may show as "risky" due to log output during execution.
 * This is expected for integration tests that actually send emails.
 */
class AmpEmailSpoolIntegrationTest extends TestCase
{
    protected MailpitHelper $mailpit;
    protected EmailSpoolerService $spooler;
    protected string $testSpoolDir;
    protected string $testRunId;

    protected function setUp(): void
    {
        parent::setUp();

        // Generate unique ID for this test run.
        $this->testRunId = uniqid('amp_test_', true);

        // Configure for actual SMTP sending.
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', 'mailpit');
        Config::set('mail.mailers.smtp.port', 1025);

        // Set up test spool directory.
        $this->testSpoolDir = storage_path('spool/mail-test-' . $this->testRunId);
        mkdir($this->testSpoolDir . '/pending', 0755, true);
        mkdir($this->testSpoolDir . '/sending', 0755, true);
        mkdir($this->testSpoolDir . '/sent', 0755, true);
        mkdir($this->testSpoolDir . '/failed', 0755, true);

        // Create spooler with test directory.
        $this->spooler = new class($this->testSpoolDir) extends EmailSpoolerService {
            public function __construct(string $testDir)
            {
                $this->spoolDir = $testDir;
                $this->pendingDir = $testDir . '/pending';
                $this->sendingDir = $testDir . '/sending';
                $this->failedDir = $testDir . '/failed';
                $this->sentDir = $testDir . '/sent';
                $this->lokiService = app(\App\Services\LokiService::class);
            }
        };

        // Set up Mailpit helper.
        $this->mailpit = new MailpitHelper('http://mailpit:8025');

        // Clear all messages before each test.
        $this->mailpit->deleteAllMessages();
    }

    protected function tearDown(): void
    {
        // Clean up test spool directory.
        if (is_dir($this->testSpoolDir)) {
            $this->recursiveDelete($this->testSpoolDir);
        }

        parent::tearDown();
    }

    protected function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Generate unique email address for this test.
     */
    protected function uniqueEmail(string $prefix = 'test'): string
    {
        return "{$prefix}_{$this->testRunId}@example.com";
    }

    /**
     * Check if Mailpit is available.
     */
    protected function isMailpitAvailable(): bool
    {
        try {
            $ch = curl_init('http://mailpit:8025/api/v1/messages');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create a test mailable with AMP content.
     */
    protected function createAmpMailable(string $recipient): Mailable
    {
        return new class($recipient) extends Mailable {
            public function __construct(private string $recipient)
            {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address('noreply@test.com', 'Test Sender'),
                    subject: 'AMP Test Email - ' . date('Y-m-d H:i:s'),
                );
            }

            public function build(): static
            {
                $ampHtml = '<!doctype html>
<html amp4email>
<head>
    <meta charset="utf-8">
    <script async src="https://cdn.ampproject.org/v0.js"></script>
    <style amp4email-boilerplate>body{visibility:hidden}</style>
</head>
<body>
    <h1>AMP Email Content</h1>
    <p>This is the AMP version of the email.</p>
    <amp-img src="https://example.com/image.jpg" width="300" height="200"></amp-img>
</body>
</html>';

                // Set HTML first, then override body structure with withSymfonyMessage.
                // Note: Don't use ->text() as it expects a view name.
                return $this->html('<p>This is the regular HTML version.</p>')
                    ->withSymfonyMessage(function (Email $message) use ($ampHtml) {
                        // Build multipart/alternative with AMP part.
                        // Order matters for email clients: text, AMP, HTML.
                        $textPart = new TextPart('This is the plain text version.', 'utf-8', 'plain');
                        $ampPart = new TextPart($ampHtml, 'utf-8', 'x-amp-html');
                        $htmlPart = new TextPart('<p>This is the regular HTML version.</p>', 'utf-8', 'html');
                        $alternativePart = new AlternativePart($textPart, $ampPart, $htmlPart);
                        $message->setBody($alternativePart);
                    });
            }
        };
    }

    /**
     * Test that AMP email sent through spooler contains text/x-amp-html MIME part.
     *
     * This is the critical test that verifies AMP content survives the full
     * spool -> send pipeline and appears in the actual sent message.
     */
    public function test_spooled_amp_email_contains_amp_mime_part(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('amp_mime');

        // Create and spool the AMP mailable.
        $mailable = $this->createAmpMailable($recipientEmail);
        $id = $this->spooler->spool($mailable, $recipientEmail);

        // Verify AMP content was captured in spool file.
        $spoolFile = $this->testSpoolDir . '/pending/' . $id . '.json';
        $this->assertFileExists($spoolFile, 'Spool file should exist');

        $spoolData = json_decode(file_get_contents($spoolFile), true);
        $this->assertArrayHasKey('amp_html', $spoolData, 'Spool should contain amp_html');
        $this->assertNotNull($spoolData['amp_html'], 'amp_html should not be null');
        $this->assertStringContainsString('amp4email', $spoolData['amp_html'], 'amp_html should contain AMP doctype');

        // Process the spool to actually send the email.
        $stats = $this->spooler->processSpool();

        $this->assertEquals(1, $stats['sent'], 'One email should have been sent');

        // Wait for and verify the message via Mailpit API.
        $message = $this->mailpit->assertMessageSentTo($recipientEmail);
        $this->assertNotNull($message, 'Message should have been sent to Mailpit');

        $messageId = $message['ID'];

        // THE CRITICAL ASSERTION: Verify the sent email has the AMP MIME part.
        $hasAmpPart = $this->mailpit->hasAmpMimePart($messageId);
        $this->assertTrue(
            $hasAmpPart,
            'Sent email should contain text/x-amp-html MIME part'
        );

        // Also verify the AMP content is correct.
        $ampContent = $this->mailpit->getAmpContent($messageId);
        $this->assertNotNull($ampContent, 'Should be able to extract AMP content');
        $this->assertStringContainsString('amp4email', $ampContent, 'AMP content should contain amp4email doctype');
        $this->assertStringContainsString('AMP Email Content', $ampContent, 'AMP content should contain expected text');
    }

    /**
     * Test that the raw MIME message has the correct multipart/alternative structure.
     */
    public function test_amp_email_has_multipart_alternative_structure(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('amp_structure');

        $mailable = $this->createAmpMailable($recipientEmail);
        $this->spooler->spool($mailable, $recipientEmail);
        $this->spooler->processSpool();

        $message = $this->mailpit->assertMessageSentTo($recipientEmail);
        $messageId = $message['ID'];

        $raw = $this->mailpit->getRawMessage($messageId);
        $this->assertNotNull($raw, 'Should be able to get raw message');

        // Verify multipart/alternative structure.
        $this->assertStringContainsString(
            'multipart/alternative',
            $raw,
            'Email should be multipart/alternative'
        );

        // Verify all three content types are present.
        $this->assertStringContainsString(
            'text/plain',
            $raw,
            'Email should contain text/plain part'
        );

        $this->assertStringContainsString(
            'text/x-amp-html',
            $raw,
            'Email should contain text/x-amp-html part'
        );

        $this->assertStringContainsString(
            'text/html',
            $raw,
            'Email should contain text/html part'
        );
    }

    /**
     * Test that AMP content is preserved exactly through spooling.
     */
    public function test_amp_content_is_preserved_exactly(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('amp_exact');

        $mailable = $this->createAmpMailable($recipientEmail);
        $this->spooler->spool($mailable, $recipientEmail);
        $this->spooler->processSpool();

        $message = $this->mailpit->assertMessageSentTo($recipientEmail);
        $messageId = $message['ID'];

        $ampContent = $this->mailpit->getAmpContent($messageId);

        // Verify key AMP elements are preserved.
        $this->assertStringContainsString('<html amp4email>', $ampContent);
        $this->assertStringContainsString('<amp-img', $ampContent);
        $this->assertStringContainsString('amp4email-boilerplate', $ampContent);
    }

    /**
     * Test that non-AMP email does NOT have AMP MIME part.
     *
     * This is a sanity check to ensure our detection is working correctly.
     */
    public function test_non_amp_email_does_not_have_amp_mime_part(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('no_amp');

        // Create a simple mailable without AMP content.
        $mailable = new class($recipientEmail) extends Mailable {
            public function __construct(private string $recipient)
            {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address('noreply@test.com', 'Test'),
                    subject: 'Non-AMP Test',
                );
            }

            public function build(): static
            {
                // Just use HTML - text version is auto-generated.
                return $this->html('<p>Regular HTML only</p>');
            }
        };

        $this->spooler->spool($mailable, $recipientEmail);
        $this->spooler->processSpool();

        $message = $this->mailpit->assertMessageSentTo($recipientEmail);
        $messageId = $message['ID'];

        // Verify the email does NOT have an AMP part.
        $hasAmpPart = $this->mailpit->hasAmpMimePart($messageId);
        $this->assertFalse(
            $hasAmpPart,
            'Non-AMP email should NOT contain text/x-amp-html MIME part'
        );
    }
}
