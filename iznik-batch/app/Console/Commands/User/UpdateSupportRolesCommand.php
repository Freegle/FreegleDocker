<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateSupportRolesCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'users:update-support-roles';

    protected $description = 'Grant or remove Support Tools access based on team membership';

    public function handle(UserManagementService $service): int
    {
        $this->registerShutdownHandlers();

        return $this->runWithLogging(function () use ($service) {
            Log::info('Starting support roles update');
            $this->info('Updating support roles...');

            $stats = $service->updateSupportRoles();

            $this->info("Granted: {$stats['granted']}, Removed: {$stats['removed']}.");
            Log::info('Support roles update complete', $stats);

            return Command::SUCCESS;
        });
    }
}
