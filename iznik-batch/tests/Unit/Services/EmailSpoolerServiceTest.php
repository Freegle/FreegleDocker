<?php

namespace Tests\Unit\Services;

use App\Mail\Welcome\WelcomeMail;
use App\Services\EmailSpoolerService;
use Illuminate\Support\Facades\Mail;
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
        $this->assertEquals([$email], $data['to']);
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
            'to' => ['test@example.com'],
            'subject' => 'Test',
            'html' => '<p>Test</p>',
            'from' => ['address' => 'noreply@test.com', 'name' => 'Test'],
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
                'to' => ['test@example.com'],
                'subject' => 'Test ' . $i,
                'html' => '<p>Test</p>',
                'from' => ['address' => 'noreply@test.com', 'name' => 'Test'],
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
                'to' => ['test@example.com'],
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
}
