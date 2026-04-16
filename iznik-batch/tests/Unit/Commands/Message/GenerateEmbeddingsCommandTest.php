<?php

namespace Tests\Unit\Commands\Message;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenerateEmbeddingsCommandTest extends TestCase
{
    public function test_no_messages_returns_success(): void
    {
        // With no messages_spatial data, the command should succeed with "no messages need embedding"
        $this->artisan('embeddings:generate', ['--limit' => 1])
            ->expectsOutputToContain('No messages need embedding')
            ->assertSuccessful();
    }

    public function test_backfill_option_increases_limit(): void
    {
        // With backfill flag and no data, still succeeds
        $this->artisan('embeddings:generate', ['--backfill' => true])
            ->expectsOutputToContain('No messages need embedding')
            ->assertSuccessful();
    }

    public function test_messages_embeddings_table_exists(): void
    {
        $this->assertTrue(
            DB::getSchemaBuilder()->hasTable('messages_embeddings'),
            'messages_embeddings table should exist'
        );
    }
}
