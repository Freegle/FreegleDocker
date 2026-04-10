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
                externaluid VARCHAR(255) PRIMARY KEY,
                cnt INT NOT NULL
            ) AS
            SELECT ma.externaluid, COUNT(*) AS cnt
            FROM messages_attachments ma
            WHERE JSON_EXTRACT(ma.externalmods, '$.ai') = TRUE
              AND ma.externaluid IS NOT NULL
              AND ma.externaluid != ''
            GROUP BY ma.externaluid
        ");

        // Step 2: Apply counts in small batches of single-row UPDATEs
        // to avoid deadlocking with concurrent writers.
        $batchSize = 500;
        $lastId = 0;
        $totalUpdated = 0;

        do {
            $rows = DB::select("
                SELECT ai.id, COALESCE(t.cnt, 0) AS cnt
                FROM ai_images ai
                LEFT JOIN tmp_ai_usage_counts t ON t.externaluid = ai.externaluid
                WHERE ai.id > ?
                  AND ai.externaluid IS NOT NULL
                  AND ai.externaluid != ''
                ORDER BY ai.id
                LIMIT ?
            ", [$lastId, $batchSize]);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                DB::update(
                    'UPDATE ai_images SET usage_count = ? WHERE id = ?',
                    [$row->cnt, $row->id]
                );
            }

            $totalUpdated += count($rows);
            $lastId = end($rows)->id;
        } while (count($rows) === $batchSize);

        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_ai_usage_counts');

        $this->info("Updated usage counts for {$totalUpdated} AI images.");

        return Command::SUCCESS;
    }
}
