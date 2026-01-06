<?php

namespace App\Console\Commands\Mail;

use App\Mail\Traits\FeatureFlags;
use App\Services\UnifiedDigestService;
use Illuminate\Console\Command;

class SendUnifiedDigestCommand extends Command
{
    use FeatureFlags;

    /**
     * The name and signature of the console command.
     */
    protected $signature = "mail:digest:unified
                            {--mode=daily : Digest mode - 'daily' or 'immediate'}
                            {--user= : Process only this user ID (for testing)}
                            {--limit=1000 : Maximum users to process per run}
                            {--dry-run : Show what would be sent without actually sending}";

    /**
     * The console command description.
     */
    protected $description = "Send unified Freegle digests containing posts from all user's communities";

    private const EMAIL_TYPE = "UnifiedDigest";

    /**
     * Execute the console command.
     */
    public function handle(UnifiedDigestService $service): int
    {
        // Check if UnifiedDigest emails are enabled for this batch system.
        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            $this->info("UnifiedDigest emails are not enabled in iznik-batch. Set FREEGLE_MAIL_ENABLED_TYPES in .env to include 'UnifiedDigest'.");
            return Command::SUCCESS;
        }

        $mode = $this->option("mode");
        $userId = $this->option("user") ? (int) $this->option("user") : null;
        $limit = (int) $this->option("limit");
        $dryRun = $this->option("dry-run");

        // Validate mode.
        if (!in_array($mode, [UnifiedDigestService::MODE_DAILY, UnifiedDigestService::MODE_IMMEDIATE])) {
            $this->error("Invalid mode '{$mode}'. Must be 'daily' or 'immediate'.");
            return Command::FAILURE;
        }

        $this->info("Sending unified digests (mode: {$mode}, limit: {$limit})...");

        if ($userId) {
            $this->info("Processing single user ID: {$userId}");
        }

        if ($dryRun) {
            $this->warn("Dry run mode - no emails will actually be sent.");
            // In dry run mode, we just show what would happen.
            // The service doesn't support dry-run internally, so we just report.
            $this->info("Would process digests for users with {$mode} mode.");
            return Command::SUCCESS;
        }

        // Run the service.
        $stats = $service->sendDigests($mode, $userId);

        $this->newLine();
        $this->table(
            ["Metric", "Count"],
            [
                ["Users Processed", $stats["users_processed"]],
                ["Emails Sent", $stats["emails_sent"]],
                ["No New Posts", $stats["no_new_posts"]],
                ["Errors", $stats["errors"]],
            ]
        );

        if ($stats["errors"] > 0) {
            $this->warn("There were {$stats['errors']} errors. Check logs for details.");
        }

        return Command::SUCCESS;
    }
}
