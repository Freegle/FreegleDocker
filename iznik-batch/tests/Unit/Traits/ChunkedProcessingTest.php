<?php

namespace Tests\Unit\Traits;

use App\Models\User;
use App\Traits\ChunkedProcessing;
use Illuminate\Database\Eloquent\Builder;
use Tests\TestCase;

class ChunkedProcessingTest extends TestCase
{
    use ChunkedProcessing;

    protected array $infoMessages = [];

    /**
     * Mock info() method for testing logProgress.
     */
    public function info($message, $verbosity = null): void
    {
        $this->infoMessages[] = $message;
    }

    public function test_process_in_chunks_processes_all_items(): void
    {
        // Create some test users.
        for ($i = 0; $i < 5; $i++) {
            User::create([
                'firstname' => 'Test',
                'lastname' => 'User' . $i,
                'fullname' => 'Test User' . $i,
                'added' => now(),
            ]);
        }

        $processed = [];
        $count = $this->processInChunks(User::query(), function ($user) use (&$processed) {
            $processed[] = $user->id;
        });

        $this->assertGreaterThanOrEqual(5, $count);
        $this->assertGreaterThanOrEqual(5, count($processed));
    }

    public function test_set_chunk_size(): void
    {
        $result = $this->setChunkSize(500);

        $this->assertSame($this, $result);
        $this->assertEquals(500, $this->chunkSize);
    }

    public function test_set_log_interval(): void
    {
        $result = $this->setLogInterval(100);

        $this->assertSame($this, $result);
        $this->assertEquals(100, $this->logInterval);
    }

    public function test_log_progress_logs_message(): void
    {
        // Call log progress - it should not throw an exception.
        $this->logProgress('Test message');

        // If we get here, the method executed successfully.
        $this->assertTrue(true);
    }

    public function test_process_in_chunks_with_empty_query(): void
    {
        // Start with a clean query that matches nothing.
        $count = $this->processInChunks(
            User::where('id', '<', 0),
            function ($user) {
                // This should never be called.
            }
        );

        $this->assertEquals(0, $count);
    }

    public function test_process_and_delete_removes_items(): void
    {
        // Create a user that will be deleted.
        $user = User::create([
            'firstname' => 'Delete',
            'lastname' => 'Me',
            'fullname' => 'Delete Me',
            'added' => now(),
        ]);

        $userId = $user->id;

        $count = $this->processAndDelete(
            User::where('id', $userId),
            function ($user) {
                // Process the user.
            }
        );

        $this->assertEquals(1, $count);
        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_process_and_delete_with_empty_query(): void
    {
        $count = $this->processAndDelete(
            User::where('id', '<', 0),
            function ($user) {
                // This should never be called.
            }
        );

        $this->assertEquals(0, $count);
    }

    public function test_process_in_chunks_logs_at_interval(): void
    {
        // Create test users.
        for ($i = 0; $i < 5; $i++) {
            User::create([
                'firstname' => 'Test',
                'lastname' => 'Interval' . $i,
                'fullname' => 'Test Interval' . $i,
                'added' => now(),
            ]);
        }

        $this->infoMessages = [];
        $this->setLogInterval(2);

        $count = $this->processInChunks(User::query(), function ($user) {
            // Process the user.
        });

        // Should have logged at least twice (at 2 and 4 items).
        $this->assertGreaterThanOrEqual(2, count($this->infoMessages));
    }

    public function test_process_and_delete_logs_at_interval(): void
    {
        // Create users that will be deleted.
        for ($i = 0; $i < 4; $i++) {
            User::create([
                'firstname' => 'Delete',
                'lastname' => 'Log' . $i,
                'fullname' => 'Delete Log' . $i,
                'added' => now(),
            ]);
        }

        $this->infoMessages = [];
        $this->setLogInterval(2);

        $count = $this->processAndDelete(User::query(), function ($user) {
            // Process the user.
        });

        // Should have logged at least once (at 2 items).
        $this->assertGreaterThanOrEqual(1, count($this->infoMessages));
    }

    public function test_log_progress_uses_info_method(): void
    {
        $this->infoMessages = [];

        $this->logProgress('Test progress message');

        $this->assertCount(1, $this->infoMessages);
        $this->assertEquals('Test progress message', $this->infoMessages[0]);
    }
}
