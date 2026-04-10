<?php

namespace App\Console\Commands\Chat;

use App\Services\ChatMaintenanceService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReopenClosedChatsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'chats:reopen-closed
                            {--dry-run : Show what would be reopened without actually changing}';

    protected $description = 'Reopen closed User2Mod chats where moderators have sent new messages';

    public function handle(ChatMaintenanceService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting reopen closed chats', ['dry_run' => $dryRun]);
            $this->info('Reopening closed User2Mod chats...');

            $stats = $service->updateMessageCounts($dryRun);

            $this->info("Reopened {$stats['rooms_reopened']} closed chats.");
            Log::info('Reopen closed chats complete', $stats);

            return Command::SUCCESS;
        });
    }
}
