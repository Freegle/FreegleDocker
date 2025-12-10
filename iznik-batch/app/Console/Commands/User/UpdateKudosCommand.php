<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateKudosCommand extends Command
{
    use GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'freegle:users:update-kudos';

    /**
     * The console command description.
     */
    protected $description = 'Update user kudos scores based on activity';

    /**
     * Execute the console command.
     */
    public function handle(UserManagementService $userService): int
    {
        $this->registerShutdownHandlers();

        Log::info('Starting kudos update');
        $this->info('Updating user kudos...');

        $updated = $userService->updateKudos();

        $this->info("Updated kudos for {$updated} users.");

        Log::info('Kudos update complete', ['updated' => $updated]);

        return Command::SUCCESS;
    }
}
