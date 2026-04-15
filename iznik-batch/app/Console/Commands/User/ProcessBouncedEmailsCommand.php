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
    protected $description = 'Process bounced emails and mark them as invalid';

    /**
     * Execute the console command.
     */
    public function handle(UserManagementService $userService): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        Log::info('Starting bounced email processing');
        $this->info('Processing bounced emails...');

        $stats = $userService->processBouncedEmails($dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Processed: {$stats['processed']}");
        $this->info("{$prefix}Marked invalid: {$stats['marked_invalid']}");

        Log::info('Bounced email processing complete', $stats);

        return Command::SUCCESS;
    }
}
