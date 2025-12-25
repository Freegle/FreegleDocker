<?php

namespace Tests\Unit\Services;

use App\Services\LokiService;
use Tests\TestCase;

class LokiServiceTest extends TestCase
{
    protected string $testLogPath;
    protected LokiService $lokiService;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a test-specific log path.
        $this->testLogPath = storage_path('logs/loki-test-' . uniqid());
        if (!is_dir($this->testLogPath)) {
            mkdir($this->testLogPath, 0755, true);
        }

        // Configure Loki to use test path.
        config([
            'freegle.loki.enabled' => true,
            'freegle.loki.log_path' => $this->testLogPath,
        ]);

        $this->lokiService = new LokiService();
    }

    protected function tearDown(): void
    {
        // Clean up test log path.
        if (is_dir($this->testLogPath)) {
            $this->recursiveDelete($this->testLogPath);
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

    public function test_is_enabled_returns_config_value(): void
    {
        config(['freegle.loki.enabled' => true]);
        $service = new LokiService();
        $this->assertTrue($service->isEnabled());

        config(['freegle.loki.enabled' => false]);
        $service = new LokiService();
        $this->assertFalse($service->isEnabled());
    }

    public function test_log_batch_job_does_nothing_when_disabled(): void
    {
        config(['freegle.loki.enabled' => false]);
        $service = new LokiService();

        $service->logBatchJob('TestJob', 'started', ['key' => 'value']);

        // File should not be created.
        $this->assertFileDoesNotExist($this->testLogPath . '/batch.log');
    }

    public function test_log_batch_job_writes_to_file_when_enabled(): void
    {
        $this->lokiService->logBatchJob('TestJob', 'started', ['items' => 10]);

        $logFile = $this->testLogPath . '/batch.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertArrayHasKey('labels', $entry);
        $this->assertArrayHasKey('message', $entry);

        $this->assertEquals('freegle', $entry['labels']['app']);
        $this->assertEquals('batch', $entry['labels']['source']);
        $this->assertEquals('TestJob', $entry['labels']['job_name']);
        $this->assertEquals('started', $entry['labels']['event']);

        $this->assertEquals('TestJob', $entry['message']['job']);
        $this->assertEquals('started', $entry['message']['event']);
        $this->assertEquals(10, $entry['message']['items']);
    }

    public function test_log_email_send_does_nothing_when_disabled(): void
    {
        config(['freegle.loki.enabled' => false]);
        $service = new LokiService();

        $service->logEmailSend('digest', 'test@example.com', 'Test Subject');

        $this->assertFileDoesNotExist($this->testLogPath . '/email.log');
    }

    public function test_log_email_send_writes_to_file_when_enabled(): void
    {
        $this->lokiService->logEmailSend(
            'digest',
            'test@example.com',
            'Test Subject',
            123,
            456,
            ['extra' => 'data']
        );

        $logFile = $this->testLogPath . '/email.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertEquals('freegle', $entry['labels']['app']);
        $this->assertEquals('email', $entry['labels']['source']);
        $this->assertEquals('digest', $entry['labels']['email_type']);
        $this->assertEquals('123', $entry['labels']['user_id']);
        $this->assertEquals('456', $entry['labels']['groupid']);

        $this->assertEquals('test@example.com', $entry['message']['recipient']);
        $this->assertEquals('Test Subject', $entry['message']['subject']);
        $this->assertEquals(123, $entry['message']['user_id']);
        $this->assertEquals(456, $entry['message']['group_id']);
        $this->assertEquals('data', $entry['message']['extra']);
    }

    public function test_log_email_send_omits_null_user_id_from_labels(): void
    {
        $this->lokiService->logEmailSend(
            'notification',
            'test@example.com',
            'Test Subject',
            null,
            null
        );

        $logFile = $this->testLogPath . '/email.log';
        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertArrayNotHasKey('user_id', $entry['labels']);
        $this->assertArrayNotHasKey('groupid', $entry['labels']);
    }

    public function test_log_event_does_nothing_when_disabled(): void
    {
        config(['freegle.loki.enabled' => false]);
        $service = new LokiService();

        $service->logEvent('purge', 'messages', ['count' => 100]);

        $this->assertFileDoesNotExist($this->testLogPath . '/batch_event.log');
    }

    public function test_log_event_writes_to_file_when_enabled(): void
    {
        $this->lokiService->logEvent('purge', 'messages', ['count' => 100]);

        $logFile = $this->testLogPath . '/batch_event.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertEquals('freegle', $entry['labels']['app']);
        $this->assertEquals('batch_event', $entry['labels']['source']);
        $this->assertEquals('purge', $entry['labels']['type']);
        $this->assertEquals('messages', $entry['labels']['subtype']);

        $this->assertEquals('purge', $entry['message']['type']);
        $this->assertEquals('messages', $entry['message']['subtype']);
        $this->assertEquals(100, $entry['message']['count']);
    }

    public function test_log_event_includes_groupid_in_labels_when_present(): void
    {
        $this->lokiService->logEvent('digest', 'sent', [
            'groupid' => 789,
            'recipients' => 50,
        ]);

        $logFile = $this->testLogPath . '/batch_event.log';
        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertEquals('789', $entry['labels']['groupid']);
    }

    public function test_multiple_log_entries_append_to_file(): void
    {
        $this->lokiService->logBatchJob('Job1', 'started');
        $this->lokiService->logBatchJob('Job1', 'completed');

        $logFile = $this->testLogPath . '/batch.log';
        $content = file_get_contents($logFile);
        $lines = array_filter(explode("\n", $content));

        $this->assertCount(2, $lines);
    }

    public function test_log_creates_directory_if_not_exists(): void
    {
        $nestedPath = $this->testLogPath . '/nested/deep/path';
        config(['freegle.loki.log_path' => $nestedPath]);

        $service = new LokiService();
        $service->logBatchJob('TestJob', 'started');

        $this->assertFileExists($nestedPath . '/batch.log');
    }

    public function test_log_entries_are_valid_json(): void
    {
        $this->lokiService->logBatchJob('TestJob', 'started', [
            'special' => 'chars "quotes" and \\backslash',
            'unicode' => '日本語',
        ]);

        $logFile = $this->testLogPath . '/batch.log';
        $content = file_get_contents($logFile);
        $entry = json_decode(trim($content), true);

        $this->assertNotNull($entry);
        $this->assertEquals('chars "quotes" and \\backslash', $entry['message']['special']);
        $this->assertEquals('日本語', $entry['message']['unicode']);
    }
}
