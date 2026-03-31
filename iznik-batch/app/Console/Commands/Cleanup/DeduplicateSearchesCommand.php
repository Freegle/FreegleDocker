<?php

namespace App\Console\Commands\Cleanup;

use App\Services\PurgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeduplicateSearchesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cleanup:search-duplicates
                            {--days=2 : Days back to check for duplicates}';

    /**
     * The console command description.
     */
    protected $description = 'Remove duplicate consecutive search history entries';

    /**
     * Execute the console command.
     */
    public function handle(PurgeService $purgeService): int
    {
        $days = (int) $this->option('days');

        Log::info('Starting search deduplication', ['days' => $days]);
        $this->info("Deduplicating searches from the last {$days} days...");

        $deleted = $purgeService->deduplicateSearchHistory($days);

        $this->info("Deleted {$deleted} duplicate search entries.");
        Log::info('Search deduplication complete', ['deleted' => $deleted]);

        return Command::SUCCESS;
    }
}
