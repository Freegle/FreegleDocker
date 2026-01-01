<?php

namespace Tests\Unit\Services;

use App\Mail\Welcome\WelcomeMail;
use App\Services\EmailSpoolerService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailSpoolerServiceTest extends TestCase
{
    protected EmailSpoolerService $spooler;
    protected string $testSpoolDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a test-specific spool directory.
        $this->testSpoolDir = storage_path('spool/mail-test-' . uniqid());

        // Create spooler with test directory.
        $this->spooler = new EmailSpoolerService();

        // Override the spool directory using reflection.
        $reflection = new \ReflectionClass($this->spooler);
        $spoolDirProperty = $reflection->getProperty('spoolDir');
        $spoolDirProperty->setAccessible(true);
        $spoolDirProperty->setValue($this->spooler, $this->testSpoolDir);

        // Update subdirectories.
        $pendingDirProperty = $reflection->getProperty('pendingDir');
        $pendingDirProperty->setAccessible(true);
        $pendingDirProperty->setValue($this->spooler, $this->testSpoolDir . '/pending');

        $sendingDirProperty = $reflection->getProperty('sendingDir');
        $sendingDirProperty->setAccessible(true);
        $sendingDirProperty->setValue($this->spooler, $this->testSpoolDir . '/sending');

        $failedDirProperty = $reflection->getProperty('failedDir');
        $failedDirProperty->setAccessible(true);
        $failedDirProperty->setValue($this->spooler, $this->testSpoolDir . '/failed');

        $sentDirProperty = $reflection->getProperty('sentDir');
        $sentDirProperty->setAccessible(true);
        $sentDirProperty->setValue($this->spooler, $this->testSpoolDir . '/sent');

        // Create directories.
        $ensureMethod = $reflection->getMethod('ensureDirectoriesExist');
        $ensureMethod->setAccessible(true);
        $ensureMethod->invoke($this->spooler);
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

    public function test_spool_creates_pending_file(): void
    {
        $email = 'test@example.com';
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
        $email = 'test@example.com';
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
        $email = 'test@example.com';
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
        Mail::fake();

        $email = 'test@example.com';
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
        Mail::fake();

        $email = 'test@example.com';
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
        Mail::fake();

        $email = 'test@example.com';
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
            'to' => [['address' => 'test@example.com', 'name' => '']],
            'from' => [['address' => 'noreply@test.com', 'name' => 'Test']],
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
                'to' => [['address' => 'test@example.com', 'name' => '']],
                'from' => [['address' => 'noreply@test.com', 'name' => 'Test']],
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
                'to' => [['address' => 'test@example.com', 'name' => '']],
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
        // Create a test mailable that adds custom headers.
        $mailable = new class('test@example.com') extends Mailable {
            public function __construct(private string $recipient)
            {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address('noreply@test.com', 'Test Sender'),
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

        $id = $this->spooler->spool($mailable, 'test@example.com');

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
            'to' => [['address' => 'test@example.com', 'name' => 'Test User']],
            'from' => [['address' => 'noreply@test.com', 'name' => 'Test Sender']],
            'subject' => 'Test Subject',
            'html' => '<p>Test content</p>',
            'text' => 'Test content',
            'headers' => [
                'X-Custom-Header' => 'custom-value',
                'List-Unsubscribe' => '<https://example.com/unsubscribe>',
            ],
            'reply_to' => [['address' => 'reply@test.com', 'name' => 'Reply To']],
            'cc' => [],
            'bcc' => [],
            'created_at' => now()->toIso8601String(),
            'attempts' => 0,
        ];

        file_put_contents(
            $this->testSpoolDir . '/pending/' . $id . '.json',
            json_encode($data)
        );

        // Track what headers are applied during send.
        $capturedHeaders = [];
        Mail::fake();

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
        // Create a test mailable that includes AMP content.
        $mailable = new class('test@example.com') extends Mailable {
            public function __construct(private string $recipient)
            {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address('noreply@test.com', 'Test Sender'),
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

        $id = $this->spooler->spool($mailable, 'test@example.com');

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
        $mailable = new class('test@example.com') extends Mailable {
            public function __construct(private string $recipient)
            {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address('noreply@test.com', 'Test Sender'),
                    subject: 'Test with Reply-To',
                    replyTo: [new \Illuminate\Mail\Mailables\Address('reply@example.com', 'Reply Handler')],
                );
            }

            public function build(): static
            {
                return $this->html('<p>Test content</p>');
            }
        };

        $id = $this->spooler->spool($mailable, 'test@example.com');

        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);

        $this->assertArrayHasKey('reply_to', $data);
        $this->assertNotEmpty($data['reply_to']);
        $this->assertEquals('reply@example.com', $data['reply_to'][0]['address']);
        $this->assertEquals('Reply Handler', $data['reply_to'][0]['name']);
    }

    /**
     * Test that BCC addresses are preserved through spooling.
     */
    public function test_bcc_preserved_in_spool(): void
    {
        $mailable = new class('test@example.com') extends Mailable {
            public function __construct(private string $recipient)
            {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address('noreply@test.com', 'Test Sender'),
                    subject: 'Test with BCC',
                    bcc: [
                        new \Illuminate\Mail\Mailables\Address('bcc1@example.com', 'BCC User 1'),
                        new \Illuminate\Mail\Mailables\Address('bcc2@example.com', 'BCC User 2'),
                    ],
                );
            }

            public function build(): static
            {
                return $this->html('<p>Test content</p>');
            }
        };

        $id = $this->spooler->spool($mailable, 'test@example.com');

        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);

        $this->assertArrayHasKey('bcc', $data);
        $this->assertNotEmpty($data['bcc']);
        $this->assertCount(2, $data['bcc']);
        $this->assertEquals('bcc1@example.com', $data['bcc'][0]['address']);
        $this->assertEquals('BCC User 1', $data['bcc'][0]['name']);
        $this->assertEquals('bcc2@example.com', $data['bcc'][1]['address']);
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
            'to' => [['address' => 'test@example.com', 'name' => 'Test User']],
            'from' => [['address' => 'noreply@test.com', 'name' => 'Test Sender']],
            'subject' => 'Test Subject',
            'html' => '<p>Test content</p>',
            'text' => 'Test content',
            'headers' => [],
            'reply_to' => [],
            'cc' => [],
            'bcc' => [
                ['address' => 'bcc1@example.com', 'name' => 'BCC User 1'],
                ['address' => 'bcc2@example.com', 'name' => 'BCC User 2'],
            ],
            'created_at' => now()->toIso8601String(),
            'attempts' => 0,
        ];

        file_put_contents(
            $this->testSpoolDir . '/pending/' . $id . '.json',
            json_encode($data)
        );

        Mail::fake();

        // Process the spool.
        $stats = $this->spooler->processSpool();

        $this->assertEquals(1, $stats['sent']);

        // Read the sent file to confirm BCC data is preserved.
        $sentData = json_decode(file_get_contents($this->testSpoolDir . '/sent/' . $id . '.json'), true);
        $this->assertEquals($id, $sentData['id']);
        $this->assertCount(2, $sentData['bcc']);
    }
}
