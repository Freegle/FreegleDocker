<?php

namespace App\Console\Commands\Purge;

use App\Services\PurgeService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeSessionsCommand extends Command
{
    use GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'purge:sessions
                            {--days=31 : Days to keep sessions}';

    /**
     * The console command description.
     */
    protected $description = 'Purge old sessions and expired login links';

    /**
     * Execute the console command.
     */
    public function handle(PurgeService $purgeService): int
    {
        $this->registerShutdownHandlers();

        Log::info('Starting session purge');
        $this->info('Purging session data...');

        $results = [];

        $days = (int) $this->option('days');

        $this->line('Purging old sessions...');
        $results['sessions'] = $purgeService->purgeSessions($days);
        $this->info("  Purged {$results['sessions']} sessions");

        if ($this->shouldAbort()) {
            $this->warn('Aborting due to shutdown signal.');

            return Command::SUCCESS;
        }

        $this->line('Purging old login links...');
        $results['login_links'] = $purgeService->purgeOldLoginLinks($days);
        $this->info("  Purged {$results['login_links']} login links");

        $this->newLine();
        $this->info('Session purge complete.');
        $this->table(
            ['Category', 'Purged'],
            collect($results)->map(fn ($count, $key) => [str_replace('_', ' ', ucfirst($key)), $count])->toArray()
        );

        Log::info('Session purge complete', $results);

        return Command::SUCCESS;
    }
}
