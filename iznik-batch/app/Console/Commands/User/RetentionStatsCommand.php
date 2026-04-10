<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetentionStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'users:retention-stats
                            {--dry-run : Count what would be affected without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Calculate user retention statistics and perform user cleanup (Yahoo users, inactive users, GDPR forgets)';

    /**
     * Execute the console command.
     */
    public function handle(UserManagementService $userService): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE — no data will be modified.');
        }

        $this->info('Calculating user retention statistics and performing cleanup...');

        $stats = $userService->updateRetentionStats($dryRun);

        $prefix = $dryRun ? 'Would ' : '';

        $this->newLine();
        $this->info('Retention Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Active users (30 days)', number_format($stats['active_users_30d'])],
                ['Active users (90 days)', number_format($stats['active_users_90d'])],
                ['New users (30 days)', number_format($stats['new_users_30d'])],
                ['Churned users (90-180 days)', number_format($stats['churned_users'])],
            ]
        );

        $this->newLine();
        $this->info('Cleanup Operations:');
        $this->table(
            ['Operation', 'Count'],
            [
                [$prefix . 'Delete Yahoo Groups users', number_format($stats['yahoo_users_deleted'])],
                [$prefix . 'Forget inactive users', number_format($stats['inactive_users_forgotten'])],
                [$prefix . 'Process GDPR forgets', number_format($stats['gdpr_forgets_processed'])],
                [$prefix . 'Delete fully forgotten users', number_format($stats['forgotten_users_deleted'])],
            ]
        );

        Log::info('Retention stats and cleanup completed', $stats);

        return Command::SUCCESS;
    }
}
