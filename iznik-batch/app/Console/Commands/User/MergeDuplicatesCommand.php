<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MergeDuplicatesCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'users:merge-duplicates
                            {--dry-run : Show what would be merged without actually merging}';

    protected $description = 'Merge duplicate user accounts that share the same email address';

    public function handle(UserManagementService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting duplicate user merge', ['dry_run' => $dryRun]);
            $this->info('Merging duplicate users...');

            $stats = $service->mergeDuplicates($dryRun);

            $this->info("Found {$stats['duplicates_found']} duplicates, merged {$stats['users_merged']}, errors {$stats['errors']}.");
            Log::info('Duplicate user merge complete', $stats);

            return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
        });
    }
}
