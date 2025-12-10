<?php

namespace App\Console\Commands\Message;

use App\Services\MessageExpiryService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessExpiredMessagesCommand extends Command
{
    use GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'freegle:messages:process-expired
                            {--spatial : Also process spatial index expiry}';

    /**
     * The console command description.
     */
    protected $description = 'Process messages that have reached their deadline or expired';

    /**
     * Execute the console command.
     */
    public function handle(MessageExpiryService $expiryService): int
    {
        $this->registerShutdownHandlers();

        Log::info('Starting message expiry processing');
        $this->info('Processing expired messages...');

        // Process deadline-expired messages.
        $stats = $expiryService->processDeadlineExpired();

        $this->info("Deadline expired: {$stats['processed']} processed, {$stats['emails_sent']} emails sent");

        if ($stats['errors'] > 0) {
            $this->warn("Errors: {$stats['errors']}");
        }

        // Optionally process spatial index expiry.
        if ($this->option('spatial')) {
            if ($this->shouldAbort()) {
                $this->warn('Aborting due to shutdown signal.');
                return Command::SUCCESS;
            }

            $this->info('Processing spatial index expiry...');
            $spatialCount = $expiryService->processExpiredFromSpatialIndex();
            $this->info("Spatial index: {$spatialCount} messages processed");
        }

        Log::info('Message expiry processing complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
