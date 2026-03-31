<?php

namespace App\Console\Commands\Group;

use App\Services\GroupMaintenanceService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixSkewedLocationsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'locations:fix-skewed';

    protected $description = 'Fix locations where latitude and longitude are swapped';

    public function handle(GroupMaintenanceService $service): int
    {
        $this->registerShutdownHandlers();

        return $this->runWithLogging(function () use ($service) {
            Log::info('Starting skewed locations fix');

            $stats = $service->fixSkewedLocations();

            $this->info("Fixed {$stats['locations_fixed']} locations, {$stats['messages_fixed']} messages.");
            Log::info('Skewed locations fix complete', $stats);

            return Command::SUCCESS;
        });
    }
}
