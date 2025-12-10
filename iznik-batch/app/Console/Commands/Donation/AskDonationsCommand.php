<?php

namespace App\Console\Commands\Donation;

use App\Services\DonationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AskDonationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'freegle:donations:ask';

    /**
     * The console command description.
     */
    protected $description = 'Ask users who received items for donations';

    /**
     * Execute the console command.
     */
    public function handle(DonationService $donationService): int
    {
        Log::info('Starting donation ask process');
        $this->info('Asking for donations...');

        $stats = $donationService->askForDonations();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Processed', $stats['processed']],
                ['Emails sent', $stats['emails_sent']],
                ['Skipped (recent ask)', $stats['skipped_recent_ask']],
                ['Errors', $stats['errors']],
            ]
        );

        Log::info('Donation ask process complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
