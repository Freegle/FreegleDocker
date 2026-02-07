<?php

namespace App\Console\Commands\Mail;

use App\Models\EmailQueueItem;
use App\Models\User;
use App\Services\EmailSpoolerService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Processes the email_queue table, building and spooling Mailables for each
 * pending row. The Go v2 API inserts rows; this command reads and dispatches.
 *
 * Usage:
 *   php artisan mail:queue:process           # Process up to 50 pending items
 *   php artisan mail:queue:process --limit=10
 *   php artisan mail:queue:process --stats   # Show queue statistics only
 */
class ProcessEmailQueueCommand extends Command
{
    protected $signature = 'mail:queue:process
                            {--limit=50 : Maximum items to process per run}
                            {--stats : Show queue statistics only}';

    protected $description = 'Process pending emails from the email_queue table';

    /**
     * Map of email_type values to the method that builds and spools the Mailable.
     * Each handler receives (EmailQueueItem, EmailSpoolerService) and returns TRUE on success.
     */
    private const TYPE_HANDLERS = [
        'forgot_password' => 'handleForgotPassword',
        'verify_email' => 'handleVerifyEmail',
        'welcome' => 'handleWelcome',
        'unsubscribe' => 'handleUnsubscribe',
    ];

    public function handle(EmailSpoolerService $spooler): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        return $this->processBatch($spooler);
    }

    private function processBatch(EmailSpoolerService $spooler): int
    {
        $limit = (int) $this->option('limit');

        $items = EmailQueueItem::pending()
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($items->isEmpty()) {
            $this->info('No pending email queue items.');

            return self::SUCCESS;
        }

        $this->info("Processing {$items->count()} pending email queue items...");

        $processed = 0;
        $failed = 0;

        foreach ($items as $item) {
            try {
                $handler = self::TYPE_HANDLERS[$item->email_type] ?? NULL;

                if ($handler === NULL) {
                    throw new \RuntimeException("Unknown email type: {$item->email_type}");
                }

                $this->{$handler}($item, $spooler);

                $item->update(['processed_at' => Carbon::now()]);
                $processed++;
            } catch (\Throwable $e) {
                $item->update([
                    'failed_at' => Carbon::now(),
                    'error_message' => substr($e->getMessage(), 0, 65535),
                ]);
                $failed++;

                $this->error("Failed to process queue item {$item->id} ({$item->email_type}): {$e->getMessage()}");
            }
        }

        $this->info("Done. Processed: {$processed}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function showStats(): int
    {
        $pending = EmailQueueItem::pending()->count();
        $processed = EmailQueueItem::whereNotNull('processed_at')->count();
        $failed = EmailQueueItem::whereNotNull('failed_at')->count();

        $this->table(
            ['Status', 'Count'],
            [
                ['Pending', $pending],
                ['Processed', $processed],
                ['Failed', $failed],
            ]
        );

        return self::SUCCESS;
    }

    private function handleForgotPassword(EmailQueueItem $item, EmailSpoolerService $spooler): void
    {
        $user = $this->requireUser($item);
        $extra = $item->extra_data ?? [];
        $email = $extra['email'] ?? $user->email_preferred;

        if (! $email) {
            throw new \RuntimeException("No email address for forgot_password (user {$item->user_id})");
        }

        // ForgotPassword Mailable will be created in a future PR.
        // For now, throw so we don't silently drop emails.
        throw new \RuntimeException("ForgotPassword Mailable not yet implemented");
    }

    private function handleVerifyEmail(EmailQueueItem $item, EmailSpoolerService $spooler): void
    {
        $user = $this->requireUser($item);
        $extra = $item->extra_data ?? [];
        $email = $extra['email'] ?? NULL;

        if (! $email) {
            throw new \RuntimeException("No email address in extra_data for verify_email (user {$item->user_id})");
        }

        throw new \RuntimeException("VerifyEmail Mailable not yet implemented");
    }

    private function handleWelcome(EmailQueueItem $item, EmailSpoolerService $spooler): void
    {
        $user = $this->requireUser($item);
        $email = $user->email_preferred;

        if (! $email) {
            throw new \RuntimeException("No email address for welcome (user {$item->user_id})");
        }

        $mailable = new \App\Mail\Welcome\WelcomeMail(
            recipientEmail: $email,
            userId: (int) $item->user_id,
        );

        $spooler->spool($mailable, $email, 'WelcomeMail');
    }

    private function handleUnsubscribe(EmailQueueItem $item, EmailSpoolerService $spooler): void
    {
        $user = $this->requireUser($item);
        $email = $user->email_preferred;

        if (! $email) {
            throw new \RuntimeException("No email address for unsubscribe (user {$item->user_id})");
        }

        throw new \RuntimeException("Unsubscribe Mailable not yet implemented");
    }

    private function requireUser(EmailQueueItem $item): User
    {
        if (! $item->user_id) {
            throw new \RuntimeException("Queue item {$item->id} has no user_id");
        }

        $user = User::find($item->user_id);

        if (! $user) {
            throw new \RuntimeException("User {$item->user_id} not found for queue item {$item->id}");
        }

        return $user;
    }
}
