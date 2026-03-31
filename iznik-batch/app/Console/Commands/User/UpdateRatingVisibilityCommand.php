<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateRatingVisibilityCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'users:update-ratings';

    protected $description = 'Update rating visibility based on chat interactions';

    public function handle(UserManagementService $service): int
    {
        $this->registerShutdownHandlers();

        return $this->runWithLogging(function () use ($service) {
            Log::info('Starting rating visibility update');

            $stats = $service->updateRatingVisibility();

            $this->info("Processed {$stats['processed']} ratings: {$stats['made_visible']} made visible, {$stats['made_hidden']} hidden.");
            Log::info('Rating visibility update complete', $stats);

            return Command::SUCCESS;
        });
    }
}
