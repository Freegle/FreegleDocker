<?php

namespace App\Console\Commands\Mail;

use App\Services\EmailSpoolerService;
use Illuminate\Console\Command;

class ProcessSpoolCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = "mail:spool:process
                            {--limit=100 : Maximum emails to process per run}
                            {--cleanup : Clean up old sent emails}
                            {--cleanup-days=7 : Days to keep sent emails when cleaning up}
                            {--retry-failed : Retry all failed emails (invalid format only)}
                            {--stats : Show backlog statistics only}
                            {--daemon : Run continuously with 1 second sleep between batches}";

    /**
     * The console command description.
     */
    protected $description = "Process spooled emails from the file-based queue";

    /**
     * Flag to indicate shutdown has been requested.
     */
    protected bool $shouldShutdown = false;

    /**
     * Execute the console command.
     */
    public function handle(EmailSpoolerService $spooler): int
    {
        // Stats only mode.
        if ($this->option("stats")) {
            return $this->showStats($spooler);
        }

        // Cleanup mode.
        if ($this->option("cleanup")) {
            return $this->cleanup($spooler);
        }

        // Retry failed mode.
        if ($this->option("retry-failed")) {
            return $this->retryFailed($spooler);
        }

        // Daemon mode - run continuously.
        if ($this->option("daemon")) {
            return $this->runDaemon($spooler);
        }

        // Normal processing.
        return $this->processBatch($spooler);
    }

    /**
     * Process a single batch of emails.
     */
    protected function processBatch(EmailSpoolerService $spooler): int
    {
        $limit = (int) $this->option("limit");

        $this->info("Processing email spool (limit: {$limit})...");

        $stats = $spooler->processSpool($limit);

        $this->table(
            ["Metric", "Count"],
            [
                ["Processed", $stats["processed"]],
                ["Sent", $stats["sent"]],
                ["Retried", $stats["retried"]],
                ["Stuck Alerts", $stats["stuck_alerts"]],
            ]
        );

        if ($stats["stuck_alerts"] > 0) {
            $this->error("SMTP delivery issues detected - check logs for details.");
        }

        return Command::SUCCESS;
    }

    /**
     * Run in daemon mode.
     */
    protected function runDaemon(EmailSpoolerService $spooler): int
    {
        $limit = (int) $this->option("limit");

        // Register signal handlers for graceful shutdown.
        $this->registerSignalHandlers();

        $this->info("Running in daemon mode. Press Ctrl+C to stop.");

        while (!$this->shouldShutdown) {
            $stats = $spooler->processSpool($limit);

            if ($stats["processed"] > 0) {
                $this->line(sprintf(
                    "[%s] Processed: %d, Sent: %d, Retried: %d",
                    now()->toTimeString(),
                    $stats["processed"],
                    $stats["sent"],
                    $stats["retried"]
                ));

                if ($stats["stuck_alerts"] > 0) {
                    $this->error(sprintf(
                        "[%s] ALERT: %d emails stuck for 5+ minutes - SMTP issue!",
                        now()->toTimeString(),
                        $stats["stuck_alerts"]
                    ));
                }
            }

            // Sleep before next batch.
            sleep(1);

            // Dispatch any pending signals.
            if (function_exists("pcntl_signal_dispatch")) {
                pcntl_signal_dispatch();
            }
        }

        $this->info("Shutting down gracefully...");

        return Command::SUCCESS;
    }

    /**
     * Register signal handlers for graceful shutdown.
     */
    protected function registerSignalHandlers(): void
    {
        if (!function_exists("pcntl_signal")) {
            $this->warn("PCNTL not available - graceful shutdown disabled.");
            return;
        }

        $handler = function (int $signal): void {
            $this->info("Received signal {$signal}, initiating graceful shutdown...");
            $this->shouldShutdown = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGHUP, $handler);
    }

    /**
     * Show backlog statistics.
     */
    protected function showStats(EmailSpoolerService $spooler): int
    {
        $stats = $spooler->getBacklogStats();

        $this->info("Email Spool Statistics");
        $this->newLine();

        $statusColor = match ($stats["status"]) {
            "healthy" => "green",
            "warning" => "yellow",
            "critical" => "red",
            default => "white",
        };

        $statusValue = $stats['status'];
        $this->table(
            ["Metric", "Value"],
            [
                ["Pending", $stats["pending_count"]],
                ["Sending", $stats["sending_count"]],
                ["Failed", $stats["failed_count"]],
                ["Oldest Pending", $stats["oldest_pending_at"] ?? "N/A"],
                ["Oldest Age (minutes)", $stats["oldest_pending_age_minutes"] ?? "N/A"],
                ["Status", "<fg={$statusColor}>{$statusValue}</>"],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Clean up old sent emails.
     */
    protected function cleanup(EmailSpoolerService $spooler): int
    {
        $days = (int) $this->option("cleanup-days");

        $this->info("Cleaning up sent emails older than {$days} days...");

        $deleted = $spooler->cleanupSent($days);

        $this->info("Deleted {$deleted} old sent emails.");

        return Command::SUCCESS;
    }

    /**
     * Retry all failed emails.
     */
    protected function retryFailed(EmailSpoolerService $spooler): int
    {
        $this->info("Retrying all failed emails...");

        $count = $spooler->retryAllFailed();

        $this->info("Moved {$count} emails back to pending queue.");

        return Command::SUCCESS;
    }
}
