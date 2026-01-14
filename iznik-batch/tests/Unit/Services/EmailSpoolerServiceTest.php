<?php

namespace Tests\Unit\Services;

use App\Mail\Welcome\WelcomeMail;
use App\Services\EmailSpoolerService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

class EmailSpoolerServiceTest extends TestCase
{
    use IsolatedSpoolDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedSpoolDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    public function test_spool_creates_pending_file(): void
    {
        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);

        $id = $this->spooler->spool($mailable, $email, 'welcome');

        $this->assertNotEmpty($id);
        $this->assertFileExists($this->testSpoolDir . '/pending/' . $id . '.json');

        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);
        $this->assertEquals($id, $data['id']);
        // 'to' is now an array of address objects.
        $this->assertEquals($email, $data['to'][0]['address']);
        $this->assertEquals('welcome', $data['email_type']);
        $this->assertEquals(0, $data['attempts']);
    }

    public function test_spool_stores_email_content(): void
    {
        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email, 'testpass123');

        $id = $this->spooler->spool($mailable, $email);

        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);

        $this->assertArrayHasKey('html', $data);
        $this->assertStringContainsString('Welcome', $data['html']);
        $this->assertArrayHasKey('subject', $data);
    }

    public function test_get_backlog_stats_empty_queue(): void
    {
        $stats = $this->spooler->getBacklogStats();

        $this->assertEquals(0, $stats['pending_count']);
        $this->assertEquals(0, $stats['sending_count']);
        $this->assertEquals(0, $stats['failed_count']);
        $this->assertEquals('healthy', $stats['status']);
        $this->assertNull($stats['oldest_pending_at']);
    }

    public function test_get_backlog_stats_with_pending(): void
    {
        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);

        $this->spooler->spool($mailable, $email);
        $this->spooler->spool($mailable, $email);

        $stats = $this->spooler->getBacklogStats();

        $this->assertEquals(2, $stats['pending_count']);
        $this->assertEquals('healthy', $stats['status']);
        $this->assertNotNull($stats['oldest_pending_at']);
    }

    public function test_process_spool_sends_email(): void
    {
        // Don't use Mail::fake() - it interferes with processSpool()'s Mail::html() call.
        // Array mail driver (phpunit.xml) prevents actual sending.

        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);
        $id = $this->spooler->spool($mailable, $email);

        $stats = $this->spooler->processSpool();

        $this->assertEquals(1, $stats['processed']);
        $this->assertEquals(1, $stats['sent']);
        $this->assertEquals(0, $stats['retried']);
        $this->assertEquals(0, $stats['stuck_alerts']);

        // File should be moved to sent.
        $this->assertFileDoesNotExist($this->testSpoolDir . '/pending/' . $id . '.json');
        $this->assertFileExists($this->testSpoolDir . '/sent/' . $id . '.json');

        // Mail::html sends a closure, not a Mailable class.
        // We verify the file was moved to sent directory as proof of success.
    }

    public function test_process_spool_respects_limit(): void
    {
        // Don't use Mail::fake() - it interferes with processSpool()'s Mail::html() call.

        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);

        // Spool 5 emails.
        for ($i = 0; $i < 5; $i++) {
            $this->spooler->spool($mailable, $email);
        }

        // Process only 2.
        $stats = $this->spooler->processSpool(limit: 2);

        $this->assertEquals(2, $stats['processed']);
        $this->assertEquals(2, $stats['sent']);

        // 3 should still be pending.
        $remaining = glob($this->testSpoolDir . '/pending/*.json');
        $this->assertCount(3, $remaining);
    }

    public function test_cleanup_sent_removes_old_files(): void
    {
        // Don't use Mail::fake() - it interferes with processSpool()'s Mail::html() call.

        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);

        // Spool and process an email.
        $id = $this->spooler->spool($mailable, $email);
        $this->spooler->processSpool();

        // Backdate the sent file.
        $sentFile = $this->testSpoolDir . '/sent/' . $id . '.json';
        touch($sentFile, strtotime('-10 days'));

        $deleted = $this->spooler->cleanupSent(daysToKeep: 7);

        $this->assertEquals(1, $deleted);
        $this->assertFileDoesNotExist($sentFile);
    }

    public function test_retry_failed_moves_to_pending(): void
    {
        // Create a fake failed email file.
        $id = 'test_failed_' . uniqid();
        $data = [
            'id' => $id,
            'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => '']],
            'from' => [['address' => $this->uniqueEmail('noreply'), 'name' => 'Test']],
            'subject' => 'Test',
            'html' => '<p>Test</p>',
            'attempts' => 3,
            'last_error' => 'Connection failed',
        ];

        file_put_contents(
            $this->testSpoolDir . '/failed/' . $id . '.json',
            json_encode($data)
        );

        $result = $this->spooler->retryFailed($id);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->testSpoolDir . '/failed/' . $id . '.json');
        $this->assertFileExists($this->testSpoolDir . '/pending/' . $id . '.json');

        // Check attempts was reset.
        $retried = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);
        $this->assertEquals(0, $retried['attempts']);
        $this->assertNull($retried['last_error']);
    }

    public function test_retry_all_failed(): void
    {
        // Create multiple fake failed emails.
        for ($i = 0; $i < 3; $i++) {
            $id = 'test_failed_' . $i . '_' . uniqid();
            $data = [
                'id' => $id,
                'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => '']],
                'from' => [['address' => $this->uniqueEmail('noreply'), 'name' => 'Test']],
                'subject' => 'Test ' . $i,
                'html' => '<p>Test</p>',
                'attempts' => 3,
                'last_error' => 'Error ' . $i,
            ];

            file_put_contents(
                $this->testSpoolDir . '/failed/' . $id . '.json',
                json_encode($data)
            );
        }

        $count = $this->spooler->retryAllFailed();

        $this->assertEquals(3, $count);

        $failed = glob($this->testSpoolDir . '/failed/*.json');
        $pending = glob($this->testSpoolDir . '/pending/*.json');

        $this->assertCount(0, $failed);
        $this->assertCount(3, $pending);
    }

    public function test_health_status_warning_on_large_queue(): void
    {
        // Create many pending files to trigger warning status.
        for ($i = 0; $i < 150; $i++) {
            $id = 'test_' . $i . '_' . uniqid();
            $data = [
                'id' => $id,
                'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => '']],
                'created_at' => now()->toIso8601String(),
            ];

            file_put_contents(
                $this->testSpoolDir . '/pending/' . $id . '.json',
                json_encode($data)
            );
        }

        $stats = $this->spooler->getBacklogStats();

        $this->assertEquals(150, $stats['pending_count']);
        $this->assertEquals('warning', $stats['status']);
    }

    /**
     * Test that custom headers added via withSymfonyMessage survive spooling.
     *
     * This is the key test that ensures headers are never lost through the spooler.
     */
    public function test_custom_headers_survive_spooling(): void
    {
        $recipientEmail = $this->uniqueEmail('recipient');
        $fromEmail = $this->uniqueEmail('noreply');

        // Create a test mailable that adds custom headers.
        $mailable = new class($recipientEmail, $fromEmail) extends Mailable {
            public function __construct(private string $recipient, private string $fromAddress)
            {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address($this->fromAddress, 'Test Sender'),
                    subject: 'Test with headers',
                );
            }

            public function build(): static
            {
                return $this->html('<p>Test content</p>')
                    ->withSymfonyMessage(function (Email $message) {
                        $headers = $message->getHeaders();
                        $headers->addTextHeader('X-Custom-Test', 'test-value-123');
                        $headers->addTextHeader('X-Another-Header', 'another-value');
                        $headers->addTextHeader('List-Unsubscribe', '<https://example.com/unsubscribe>');
                        $headers->addTextHeader('Feedback-Id', 'test:feedback:id');
                    });
            }
        };

        $id = $this->spooler->spool($mailable, $recipientEmail);

        // Read the spool file and verify headers are captured.
        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);

        $this->assertArrayHasKey('headers', $data);
        $this->assertArrayHasKey('X-Custom-Test', $data['headers']);
        $this->assertEquals('test-value-123', $data['headers']['X-Custom-Test']);
        $this->assertArrayHasKey('X-Another-Header', $data['headers']);
        $this->assertEquals('another-value', $data['headers']['X-Another-Header']);
        $this->assertArrayHasKey('List-Unsubscribe', $data['headers']);
        $this->assertArrayHasKey('Feedback-Id', $data['headers']);
    }

    /**
     * Test that headers are applied when processing the spool.
     */
    public function test_headers_applied_when_sending_from_spool(): void
    {
        // Create a spool file with custom headers.
        $id = 'test_headers_' . uniqid();
        $data = [
            'id' => $id,
            'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => 'Test User']],
            'from' => [['address' => $this->uniqueEmail('noreply'), 'name' => 'Test Sender']],
            'subject' => 'Test Subject',
            'html' => '<p>Test content</p>',
            'text' => 'Test content',
            'headers' => [
                'X-Custom-Header' => 'custom-value',
                'List-Unsubscribe' => '<https://example.com/unsubscribe>',
            ],
            'reply_to' => [['address' => $this->uniqueEmail('reply'), 'name' => 'Reply To']],
            'cc' => [],
            'bcc' => [],
            'created_at' => now()->toIso8601String(),
            'attempts' => 0,
        ];

        file_put_contents(
            $this->testSpoolDir . '/pending/' . $id . '.json',
            json_encode($data)
        );

        // Don't use Mail::fake() - it interferes with processSpool()'s Mail::html() call.

        // Process the spool.
        $stats = $this->spooler->processSpool();

        $this->assertEquals(1, $stats['sent']);

        // Read the sent file to confirm it was processed.
        $sentData = json_decode(file_get_contents($this->testSpoolDir . '/sent/' . $id . '.json'), true);
        $this->assertEquals($id, $sentData['id']);

        // The headers should still be in the data.
        $this->assertArrayHasKey('X-Custom-Header', $sentData['headers']);
        $this->assertArrayHasKey('List-Unsubscribe', $sentData['headers']);
    }

    /**
     * Test that AMP content is preserved through spooling.
     */
    public function test_amp_content_preserved_in_spool(): void
    {
        $recipientEmail = $this->uniqueEmail('recipient');
        $fromEmail = $this->uniqueEmail('noreply');

        // Create a test mailable that includes AMP content.
        $mailable = new class($recipientEmail, $fromEmail) extends Mailable {
            public function __construct(private string $recipient, private string $fromAddress)
            {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address($this->fromAddress, 'Test Sender'),
                    subject: 'Test with AMP',
                );
            }

            public function build(): static
            {
                $ampHtml = '<!doctype html><html amp4email><head></head><body>AMP Content</body></html>';

                return $this->html('<p>Regular HTML</p>')
                    ->withSymfonyMessage(function (Email $message) use ($ampHtml) {
                        // Build multipart/alternative with AMP part.
                        $textPart = new \Symfony\Component\Mime\Part\TextPart('Plain text', 'utf-8', 'plain');
                        $ampPart = new \Symfony\Component\Mime\Part\TextPart($ampHtml, 'utf-8', 'x-amp-html');
                        $htmlPart = new \Symfony\Component\Mime\Part\TextPart('<p>Regular HTML</p>', 'utf-8', 'html');
                        $alternativePart = new \Symfony\Component\Mime\Part\Multipart\AlternativePart($textPart, $ampPart, $htmlPart);
                        $message->setBody($alternativePart);
                    });
            }
        };

        $id = $this->spooler->spool($mailable, $recipientEmail);

        // Read the spool file and verify AMP content is captured.
        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);

        $this->assertArrayHasKey('amp_html', $data);
        $this->assertNotNull($data['amp_html']);
        $this->assertStringContainsString('amp4email', $data['amp_html']);
        $this->assertStringContainsString('AMP Content', $data['amp_html']);
    }

    /**
     * Test that reply-to addresses are preserved through spooling.
     */
    public function test_reply_to_preserved_in_spool(): void
    {
        $recipientEmail = $this->uniqueEmail('recipient');
        $fromEmail = $this->uniqueEmail('noreply');
        $replyToEmail = $this->uniqueEmail('reply');

        $mailable = new class($recipientEmail, $fromEmail, $replyToEmail) extends Mailable {
            public function __construct(private string $recipient, private string $fromAddress, private string $replyToAddress)
            {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address($this->fromAddress, 'Test Sender'),
                    subject: 'Test with Reply-To',
                    replyTo: [new \Illuminate\Mail\Mailables\Address($this->replyToAddress, 'Reply Handler')],
                );
            }

            public function build(): static
            {
                return $this->html('<p>Test content</p>');
            }
        };

        $id = $this->spooler->spool($mailable, $recipientEmail);

        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);

        $this->assertArrayHasKey('reply_to', $data);
        $this->assertNotEmpty($data['reply_to']);
        $this->assertEquals($replyToEmail, $data['reply_to'][0]['address']);
        $this->assertEquals('Reply Handler', $data['reply_to'][0]['name']);
    }

    /**
     * Test that BCC addresses are preserved through spooling.
     */
    public function test_bcc_preserved_in_spool(): void
    {
        $recipientEmail = $this->uniqueEmail('recipient');
        $fromEmail = $this->uniqueEmail('noreply');
        $bcc1Email = $this->uniqueEmail('bcc1');
        $bcc2Email = $this->uniqueEmail('bcc2');

        $mailable = new class($recipientEmail, $fromEmail, $bcc1Email, $bcc2Email) extends Mailable {
            public function __construct(
                private string $recipient,
                private string $fromAddress,
                private string $bcc1Address,
                private string $bcc2Address
            ) {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address($this->fromAddress, 'Test Sender'),
                    subject: 'Test with BCC',
                    bcc: [
                        new \Illuminate\Mail\Mailables\Address($this->bcc1Address, 'BCC User 1'),
                        new \Illuminate\Mail\Mailables\Address($this->bcc2Address, 'BCC User 2'),
                    ],
                );
            }

            public function build(): static
            {
                return $this->html('<p>Test content</p>');
            }
        };

        $id = $this->spooler->spool($mailable, $recipientEmail);

        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);

        $this->assertArrayHasKey('bcc', $data);
        $this->assertNotEmpty($data['bcc']);
        $this->assertCount(2, $data['bcc']);
        $this->assertEquals($bcc1Email, $data['bcc'][0]['address']);
        $this->assertEquals('BCC User 1', $data['bcc'][0]['name']);
        $this->assertEquals($bcc2Email, $data['bcc'][1]['address']);
        $this->assertEquals('BCC User 2', $data['bcc'][1]['name']);
    }

    /**
     * Test that BCC addresses are applied when sending from spool.
     */
    public function test_bcc_applied_when_sending_from_spool(): void
    {
        // Create a spool file with BCC addresses.
        $id = 'test_bcc_send_' . uniqid();
        $data = [
            'id' => $id,
            'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => 'Test User']],
            'from' => [['address' => $this->uniqueEmail('noreply'), 'name' => 'Test Sender']],
            'subject' => 'Test Subject',
            'html' => '<p>Test content</p>',
            'text' => 'Test content',
            'headers' => [],
            'reply_to' => [],
            'cc' => [],
            'bcc' => [
                ['address' => $this->uniqueEmail('bcc1'), 'name' => 'BCC User 1'],
                ['address' => $this->uniqueEmail('bcc2'), 'name' => 'BCC User 2'],
            ],
            'created_at' => now()->toIso8601String(),
            'attempts' => 0,
        ];

        file_put_contents(
            $this->testSpoolDir . '/pending/' . $id . '.json',
            json_encode($data)
        );

        // Don't use Mail::fake() - it interferes with processSpool()'s Mail::html() call.

        // Process the spool.
        $stats = $this->spooler->processSpool();

        $this->assertEquals(1, $stats['sent']);

        // Read the sent file to confirm BCC data is preserved.
        $sentData = json_decode(file_get_contents($this->testSpoolDir . '/sent/' . $id . '.json'), true);
        $this->assertEquals($id, $sentData['id']);
        $this->assertCount(2, $sentData['bcc']);
    }

    /**
     * Test that plain text body is included in non-AMP emails when processing spool.
     *
     * This is critical for email clients like TrashNothing that parse the plain text
     * body of chat notification emails.
     */
    public function test_plain_text_body_included_in_non_amp_emails(): void
    {
        // Create a spool file with both HTML and text content (no AMP).
        $id = 'test_text_body_' . uniqid();
        $plainText = "New message from Test User\n\nHello, this is a test message.\n\nReply: https://example.com/chat/123";
        $htmlContent = '<p>New message from Test User</p><p>Hello, this is a test message.</p><a href="https://example.com/chat/123">Reply</a>';

        $data = [
            'id' => $id,
            'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => 'Test User']],
            'from' => [['address' => $this->uniqueEmail('noreply'), 'name' => 'Test Sender']],
            'subject' => 'Test Subject',
            'html' => $htmlContent,
            'text' => $plainText,
            'amp_html' => null,  // No AMP content.
            'headers' => [],
            'reply_to' => [],
            'cc' => [],
            'bcc' => [],
            'created_at' => now()->toIso8601String(),
            'attempts' => 0,
        ];

        file_put_contents(
            $this->testSpoolDir . '/pending/' . $id . '.json',
            json_encode($data)
        );

        // Capture the sent message to verify the body structure.
        $capturedMessage = null;

        // Use a custom mailer transport to capture the message.
        \Illuminate\Support\Facades\Mail::extend('capture', function () use (&$capturedMessage) {
            return new class($capturedMessage) extends \Symfony\Component\Mailer\Transport\AbstractTransport {
                private mixed $capturedRef;

                public function __construct(&$captured)
                {
                    parent::__construct();
                    $this->capturedRef = &$captured;
                }

                protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
                {
                    $this->capturedRef = $message->getOriginalMessage();
                }

                public function __toString(): string
                {
                    return 'capture://';
                }
            };
        });

        // Temporarily switch to capture transport.
        $originalDriver = config('mail.default');
        config(['mail.default' => 'capture']);

        // Process the spool.
        $stats = $this->spooler->processSpool();

        // Restore original driver.
        config(['mail.default' => $originalDriver]);

        $this->assertEquals(1, $stats['sent']);

        // Verify the message was captured and has the correct body structure.
        $this->assertNotNull($capturedMessage, 'Message should have been captured');
        $this->assertInstanceOf(Email::class, $capturedMessage);

        // The body should be multipart/alternative with text and HTML parts.
        $body = $capturedMessage->getBody();
        $this->assertInstanceOf(
            \Symfony\Component\Mime\Part\Multipart\AlternativePart::class,
            $body,
            'Email body should be multipart/alternative to include both text and HTML'
        );

        // Verify both parts are present.
        $parts = $body->getParts();
        $this->assertGreaterThanOrEqual(2, count($parts), 'Should have at least text and HTML parts');

        // Find text and HTML parts.
        $hasTextPart = false;
        $hasHtmlPart = false;
        foreach ($parts as $part) {
            if ($part instanceof \Symfony\Component\Mime\Part\TextPart) {
                if ($part->getMediaSubtype() === 'plain') {
                    $hasTextPart = true;
                    $this->assertStringContainsString('Hello, this is a test message', $part->getBody());
                } elseif ($part->getMediaSubtype() === 'html') {
                    $hasHtmlPart = true;
                }
            }
        }

        $this->assertTrue($hasTextPart, 'Email should have a text/plain part');
        $this->assertTrue($hasHtmlPart, 'Email should have a text/html part');
    }
}
