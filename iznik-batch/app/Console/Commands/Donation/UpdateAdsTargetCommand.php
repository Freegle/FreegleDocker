<?php

namespace App\Console\Commands\Donation;

use App\Services\DonationService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateAdsTargetCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'donations:update-ads-target';

    protected $description = 'Update the ads-off donation target based on recent donations';

    public function handle(DonationService $service): int
    {
        $this->registerShutdownHandlers();

        return $this->runWithLogging(function () use ($service) {
            Log::info('Starting ads target update');

            $stats = $service->updateAdsTarget();

            $this->info("Target: {$stats['target_max']}, Donated 24h: {$stats['donated_24h']}, Remaining: {$stats['remaining']}, Ads enabled: {$stats['ads_enabled']}");
            Log::info('Ads target update complete', $stats);

            return Command::SUCCESS;
        });
    }
}
