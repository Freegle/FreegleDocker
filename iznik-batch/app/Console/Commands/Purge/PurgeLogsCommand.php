<?php

namespace App\Console\Commands\Purge;

use App\Services\PurgeService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeLogsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'purge:logs';

    protected $description = 'Purge old log entries from various tables';

    public function handle(PurgeService $purgeService): int
    {
        $this->registerShutdownHandlers();

        return $this->runWithLogging(function () use ($purgeService) {
            Log::info('Starting log purge');
            $this->info('Purging log data...');

            $results = $purgeService->purgeAllLogs();

            $this->newLine();
            $this->info('Log purge complete.');
            $this->table(
                ['Category', 'Purged'],
                collect($results)->map(fn ($count, $key) => [str_replace('_', ' ', ucfirst($key)), $count])->toArray()
            );

            Log::info('Log purge complete', $results);

            return Command::SUCCESS;
        });
    }
}
