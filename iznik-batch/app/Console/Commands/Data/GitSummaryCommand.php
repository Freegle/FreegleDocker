<?php

namespace App\Console\Commands\Data;

use App\Services\GitSummaryService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to generate and send AI-powered git commit summaries.
 *
 * This replaces the PHP script at iznik-server/scripts/cron/git_summary_ai.php.
 * Summaries are sent to Discourse via email-to-forum integration.
 */
class GitSummaryCommand extends Command
{
    use GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'data:git-summary
                            {--since= : Override since date (YYYY-MM-DD or relative like "-3 days")}
                            {--email= : Override recipient email address}
                            {--dry-run : Generate report but do not send email or update timestamp}';

    /**
     * The console command description.
     */
    protected $description = 'Generate and send AI-powered git commit summary to Discourse';

    /**
     * Execute the console command.
     */
    public function handle(GitSummaryService $service): int
    {
        $this->registerShutdownHandlers();

        $sinceOverride = $this->option('since');
        $emailOverride = $this->option('email');
        $dryRun = $this->option('dry-run');

        Log::info('GitSummaryCommand: Starting', [
            'since_override' => $sinceOverride,
            'email_override' => $emailOverride,
            'dry_run' => $dryRun,
        ]);

        if ($dryRun) {
            $this->info('Dry run mode - will not send email or update timestamp');
            $report = $service->generateReport($sinceOverride);

            $this->info("Changes since: {$report['since_date']}");
            $this->info("Repositories with changes: " . count($report['changes']));

            $totalCommits = array_sum(array_map(fn($c) => count($c['commits']), $report['changes']));
            $this->info("Total commits: {$totalCommits}");

            if (isset($report['summary'])) {
                $this->newLine();
                $this->line('=== AI Summary ===');
                $this->line($report['summary']);
            }

            return Command::SUCCESS;
        }

        $result = $service->sendReport($sinceOverride, $emailOverride);

        if ($result['success']) {
            $this->info('Git summary report sent successfully.');
            $this->info("Recipient: " . ($emailOverride ?? config('freegle.git_summary.discourse_email')));
            $this->info("Changes since: {$result['report']['since_date']}");

            $totalCommits = array_sum(array_map(fn($c) => count($c['commits']), $result['report']['changes']));
            $this->info("Total commits: {$totalCommits}");

            return Command::SUCCESS;
        }

        $this->error('Failed to send git summary: ' . $result['message']);
        return Command::FAILURE;
    }
}
