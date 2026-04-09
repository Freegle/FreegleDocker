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
        // Process in batches to avoid deadlocking on 32k+ rows.
        $batchSize = 500;
        $lastId = 0;
        $totalUpdated = 0;

        do {
            $ids = DB::table('ai_images')
                ->where('id', '>', $lastId)
                ->whereNotNull('externaluid')
                ->where('externaluid', '!=', '')
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            DB::update("
                UPDATE ai_images ai
                SET usage_count = (
                    SELECT COUNT(*)
                    FROM messages_attachments ma
                    WHERE ma.externaluid = ai.externaluid
                      AND JSON_EXTRACT(ma.externalmods, '$.ai') = TRUE
                )
                WHERE ai.id IN (" . $ids->implode(',') . ")
            ");

            $totalUpdated += $ids->count();
            $lastId = $ids->last();
        } while ($ids->count() === $batchSize);

        $this->info("Updated usage counts for {$totalUpdated} AI images.");

        return Command::SUCCESS;
    }
}
