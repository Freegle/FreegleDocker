<?php

namespace App\Console\Commands\AI;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateAIImageUsageCountsCommand extends Command
{
    protected $signature = 'ai:usage-counts:update';

    protected $description = 'Update usage_count on ai_images from messages_attachments';

    public function handle(): int
    {
        $updated = DB::update("
            UPDATE ai_images ai
            SET usage_count = (
                SELECT COUNT(*)
                FROM messages_attachments ma
                WHERE ma.externaluid = ai.externaluid
                  AND JSON_EXTRACT(ma.externalmods, '$.ai') = TRUE
            )
            WHERE ai.externaluid IS NOT NULL
              AND ai.externaluid != ''
        ");

        $this->info("Updated usage counts for {$updated} AI images.");

        return Command::SUCCESS;
    }
}
