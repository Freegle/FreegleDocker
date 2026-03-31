<?php

namespace Tests\Feature\Cleanup;

use App\Models\ChatMessage;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeduplicateCommandsTest extends TestCase
{
    public function test_deduplicate_searches_command_runs_successfully(): void
    {
        $this->artisan('cleanup:search-duplicates')
            ->assertExitCode(0);
    }

    public function test_deduplicate_searches_displays_output(): void
    {
        $this->artisan('cleanup:search-duplicates')
            ->expectsOutputToContain('Deduplicating searches')
            ->expectsOutputToContain('duplicate search entries')
            ->assertExitCode(0);
    }

    public function test_deduplicate_searches_with_custom_days(): void
    {
        $this->artisan('cleanup:search-duplicates', ['--days' => 7])
            ->assertExitCode(0);
    }

    public function test_deduplicate_searches_removes_duplicates(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        for ($i = 0; $i < 3; $i++) {
            DB::table('search_history')->insert([
                'userid' => $user->id,
                'date' => now()->subHour(),
                'term' => 'sofa',
                'locationid' => null,
                'groups' => (string) $group->id,
            ]);
        }

        $this->artisan('cleanup:search-duplicates')
            ->expectsOutputToContain('Deleted 2 duplicate search entries')
            ->assertExitCode(0);
    }

    public function test_deduplicate_chat_messages_command_runs_successfully(): void
    {
        $this->artisan('cleanup:chat-duplicates')
            ->assertExitCode(0);
    }

    public function test_deduplicate_chat_messages_displays_output(): void
    {
        $this->artisan('cleanup:chat-duplicates')
            ->expectsOutputToContain('Deduplicating chat messages')
            ->expectsOutputToContain('duplicate chat messages')
            ->assertExitCode(0);
    }

    public function test_deduplicate_chat_messages_with_custom_days(): void
    {
        $this->artisan('cleanup:chat-duplicates', ['--days' => 7])
            ->assertExitCode(0);
    }

    public function test_deduplicate_chat_messages_removes_duplicates(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = $this->createTestChatRoom($user1, $user2);

        $this->createTestChatMessage($room, $user1, [
            'message' => 'Duplicate msg',
            'date' => now()->subHour(),
        ]);
        $this->createTestChatMessage($room, $user1, [
            'message' => 'Duplicate msg',
            'date' => now()->subMinutes(59),
        ]);

        $this->artisan('cleanup:chat-duplicates')
            ->expectsOutputToContain('Deleted 1 duplicate chat messages')
            ->assertExitCode(0);
    }
}
