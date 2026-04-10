<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessBouncedEmailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mail:bounced {--dry-run : Show what would be processed without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Suspend mail for users with excessive bounces (matches V1 bounce_users.php)';

    /**
     * Execute the console command.
     */
    public function handle(UserManagementService $userService): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        Log::info('Starting bounce suspension processing');
        $this->info('Processing bounce suspensions...');

        $stats = $userService->processBouncedEmails($dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Suspended (permanent bounces >= 3): {$stats['permanent_suspended']}");
        $this->info("{$prefix}Suspended (total bounces >= 50): {$stats['total_suspended']}");

        Log::info('Bounce suspension processing complete', $stats);

        return Command::SUCCESS;
    }
}
