<?php

namespace App\Console\Commands\Cleanup;

use App\Services\PurgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeduplicateChatMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cleanup:chat-duplicates
                            {--days=3 : Days back to check for duplicates}';

    /**
     * The console command description.
     */
    protected $description = 'Remove duplicate consecutive chat messages';

    /**
     * Execute the console command.
     */
    public function handle(PurgeService $purgeService): int
    {
        $days = (int) $this->option('days');

        Log::info('Starting chat message deduplication', ['days' => $days]);
        $this->info("Deduplicating chat messages from the last {$days} days...");

        $deleted = $purgeService->deduplicateChatMessages($days);

        $this->info("Deleted {$deleted} duplicate chat messages.");
        Log::info('Chat message deduplication complete', ['deleted' => $deleted]);

        return Command::SUCCESS;
    }
}
