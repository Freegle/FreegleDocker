<?php

namespace App\Console\Commands\Group;

use App\Services\GroupMaintenanceService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateMemberCountsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'groups:update-counts';

    protected $description = 'Update member and moderator counts for all groups';

    public function handle(GroupMaintenanceService $service): int
    {
        $this->registerShutdownHandlers();

        return $this->runWithLogging(function () use ($service) {
            Log::info('Starting member count update');
            $this->info('Updating group member counts...');

            $stats = $service->updateMemberCounts();

            $this->info("Updated counts for {$stats['groups_updated']} groups.");
            Log::info('Member count update complete', $stats);

            return Command::SUCCESS;
        });
    }
}
