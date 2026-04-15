<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateKudosCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'users:update-kudos
                            {--dry-run : Show what would be updated without actually changing}';

    /**
     * The console command description.
     */
    protected $description = 'Update user kudos scores based on activity';

    /**
     * Execute the console command.
     */
    public function handle(UserManagementService $userService): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($userService, $dryRun) {
            Log::info('Starting kudos update', ['dry_run' => $dryRun]);
            $this->info('Updating user kudos...');

            $updated = $userService->updateKudos($dryRun);

            $this->info("Updated kudos for {$updated} users.");

            Log::info('Kudos update complete', ['updated' => $updated]);

            return Command::SUCCESS;
        });
    }
}
