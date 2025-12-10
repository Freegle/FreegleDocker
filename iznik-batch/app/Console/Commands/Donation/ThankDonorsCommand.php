<?php

namespace App\Console\Commands\Donation;

use App\Services\DonationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ThankDonorsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'freegle:donations:thank';

    /**
     * The console command description.
     */
    protected $description = 'Send thank you emails to recent donors';

    /**
     * Execute the console command.
     */
    public function handle(DonationService $donationService): int
    {
        Log::info('Starting donor thank you process');
        $this->info('Thanking donors...');

        $stats = $donationService->thankDonors();

        $this->info("Processed: {$stats['processed']}");
        $this->info("Emails sent: {$stats['emails_sent']}");

        if ($stats['errors'] > 0) {
            $this->warn("Errors: {$stats['errors']}");
        }

        Log::info('Donor thank you process complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
