<?php

namespace App\Console\Commands\Chat;

use App\Services\ChatMaintenanceService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateMessageCountsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'chats:update-counts';

    protected $description = 'Update chat room message counts and reopen closed User2Mod chats with unseen messages';

    public function handle(ChatMaintenanceService $service): int
    {
        $this->registerShutdownHandlers();

        return $this->runWithLogging(function () use ($service) {
            Log::info('Starting chat message count update');
            $this->info('Updating chat message counts...');

            $stats = $service->updateMessageCounts();

            $this->info("Updated {$stats['rooms_updated']} rooms, reopened {$stats['rooms_reopened']} closed chats.");
            Log::info('Chat message count update complete', $stats);

            return Command::SUCCESS;
        });
    }
}
