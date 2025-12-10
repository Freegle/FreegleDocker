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
    protected $signature = 'freegle:users:retention-stats';

    /**
     * The console command description.
     */
    protected $description = 'Calculate and display user retention statistics';

    /**
     * Execute the console command.
     */
    public function handle(UserManagementService $userService): int
    {
        $this->info('Calculating user retention statistics...');

        $stats = $userService->updateRetentionStats();

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Active users (30 days)', number_format($stats['active_users_30d'])],
                ['Active users (90 days)', number_format($stats['active_users_90d'])],
                ['New users (30 days)', number_format($stats['new_users_30d'])],
                ['Churned users (90-180 days)', number_format($stats['churned_users'])],
            ]
        );

        Log::info('Retention stats calculated', $stats);

        return Command::SUCCESS;
    }
}
