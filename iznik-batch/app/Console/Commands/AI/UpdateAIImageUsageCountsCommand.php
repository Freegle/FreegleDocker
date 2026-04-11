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
        // Step 1: Precompute all AI attachment counts into a temp table (one scan).
        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_ai_usage_counts');
        DB::statement("
            CREATE TEMPORARY TABLE tmp_ai_usage_counts (
                externaluid VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY,
                cnt INT NOT NULL
            ) AS
            SELECT ma.externaluid, COUNT(*) AS cnt
            FROM messages_attachments ma
            WHERE JSON_EXTRACT(ma.externalmods, '$.ai') = TRUE
              AND ma.externaluid IS NOT NULL
              AND ma.externaluid != ''
            GROUP BY ma.externaluid
        ");

        // Step 2: Apply counts in batched JOIN updates to avoid deadlocking
        // with concurrent writers while keeping round-trips low.
        $batchSize = 500;
        $lastId = 0;
        $totalUpdated = 0;

        try {
            do {
                // Find the upper bound of this batch.
                $maxRow = DB::selectOne("
                    SELECT MAX(id) AS max_id FROM (
                        SELECT id FROM ai_images
                        WHERE id > ?
                          AND externaluid IS NOT NULL
                          AND externaluid != ''
                        ORDER BY id
                        LIMIT ?
                    ) batch
                ", [$lastId, $batchSize]);

                if (!$maxRow || !$maxRow->max_id) {
                    break;
                }

                $batchMaxId = $maxRow->max_id;

                // Batched JOIN update — one query per batch instead of per row.
                $affected = DB::update("
                    UPDATE ai_images ai
                    LEFT JOIN tmp_ai_usage_counts t ON t.externaluid = ai.externaluid
                    SET ai.usage_count = COALESCE(t.cnt, 0)
                    WHERE ai.id > ? AND ai.id <= ?
                      AND ai.externaluid IS NOT NULL
                      AND ai.externaluid != ''
                ", [$lastId, $batchMaxId]);

                $totalUpdated += $affected;
                $lastId = $batchMaxId;
            } while (true);
        } finally {
            DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_ai_usage_counts');
        }

        $this->info("Updated usage counts for {$totalUpdated} AI images.");

        return Command::SUCCESS;
    }
}
