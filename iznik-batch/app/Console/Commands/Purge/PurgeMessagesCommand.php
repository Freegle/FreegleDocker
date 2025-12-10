<?php

namespace App\Console\Commands\Purge;

use App\Services\PurgeService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeMessagesCommand extends Command
{
    use GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'freegle:purge:messages
                            {--history-days=90 : Days to keep messages_history}
                            {--pending-days=90 : Days to keep pending messages}
                            {--draft-days=90 : Days to keep drafts}
                            {--deleted-retention=2 : Days to keep deleted messages}';

    /**
     * The console command description.
     */
    protected $description = 'Purge old message data';

    /**
     * Execute the console command.
     */
    public function handle(PurgeService $purgeService): int
    {
        $this->registerShutdownHandlers();

        Log::info('Starting message purge');
        $this->info('Purging message data...');

        $results = [];

        // Purge messages_history.
        $this->line('Purging messages_history...');
        $historyDays = (int) $this->option('history-days');
        $results['messages_history'] = $purgeService->purgeOldMessagesHistory($historyDays);
        $this->info("  Purged {$results['messages_history']} history records");

        if ($this->shouldAbort()) {
            return $this->abortWithResults($results);
        }

        // Purge pending messages.
        $this->line('Purging pending messages...');
        $pendingDays = (int) $this->option('pending-days');
        $results['pending_messages'] = $purgeService->purgePendingMessages($pendingDays);
        $this->info("  Purged {$results['pending_messages']} pending messages");

        if ($this->shouldAbort()) {
            return $this->abortWithResults($results);
        }

        // Purge old drafts.
        $this->line('Purging old drafts...');
        $draftDays = (int) $this->option('draft-days');
        $results['old_drafts'] = $purgeService->purgeOldDrafts($draftDays);
        $this->info("  Purged {$results['old_drafts']} drafts");

        if ($this->shouldAbort()) {
            return $this->abortWithResults($results);
        }

        // Purge non-Freegle messages.
        $this->line('Purging non-Freegle messages...');
        $results['non_freegle'] = $purgeService->purgeNonFreegleMessages();
        $this->info("  Purged {$results['non_freegle']} non-Freegle messages");

        if ($this->shouldAbort()) {
            return $this->abortWithResults($results);
        }

        // Purge deleted messages.
        $this->line('Purging deleted messages...');
        $deletedRetention = (int) $this->option('deleted-retention');
        $results['deleted_messages'] = $purgeService->purgeDeletedMessages($deletedRetention);
        $this->info("  Purged {$results['deleted_messages']} deleted messages");

        if ($this->shouldAbort()) {
            return $this->abortWithResults($results);
        }

        // Purge stranded messages.
        $this->line('Purging stranded messages...');
        $results['stranded_messages'] = $purgeService->purgeStrandedMessages();
        $this->info("  Purged {$results['stranded_messages']} stranded messages");

        if ($this->shouldAbort()) {
            return $this->abortWithResults($results);
        }

        // Purge HTML body.
        $this->line('Purging HTML body from old messages...');
        $results['html_body'] = $purgeService->purgeHtmlBody();
        $this->info("  Purged HTML body from {$results['html_body']} messages");

        $this->displayResults($results);

        Log::info('Message purge complete', $results);

        return Command::SUCCESS;
    }

    protected function abortWithResults(array $results): int
    {
        $this->warn('Aborting due to shutdown signal.');
        $this->displayResults($results);
        return Command::SUCCESS;
    }

    protected function displayResults(array $results): void
    {
        $this->newLine();
        $this->info('Message purge complete.');
        $this->table(
            ['Category', 'Purged'],
            collect($results)->map(fn ($count, $key) => [str_replace('_', ' ', ucfirst($key)), $count])->toArray()
        );
    }
}
