<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupInactiveUsersCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'users:cleanup-inactive
                            {--years=3 : Number of years of inactivity before cleanup}
                            {--dry-run : Show what would be cleaned up without actually changing}';

    protected $description = 'Clean up inactive user data for GDPR compliance';

    public function handle(UserManagementService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');
        $years = (int) $this->option('years');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun, $years) {
            Log::info('Starting inactive user cleanup', ['dry_run' => $dryRun, 'years' => $years]);
            $this->info("Cleaning up users inactive for {$years}+ years...");

            $cleaned = $service->cleanupInactiveUsers($years, $dryRun);

            $this->info("Cleaned up {$cleaned} inactive users.");
            Log::info('Inactive user cleanup complete', ['cleaned' => $cleaned]);

            return Command::SUCCESS;
        });
    }
}
