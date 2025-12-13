<?php

namespace App\Console\Commands\Chat;

use App\Models\ChatRoom;
use App\Services\ChatNotificationService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NotifyUser2ModCommand extends Command
{
    use GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'freegle:chat:notify-user2mod
                            {--chat= : Process only a specific chat ID}
                            {--delay=30 : Delay in seconds before sending notification}
                            {--since=24 : How many hours back to look for messages}
                            {--force : Force sending even for already mailed messages}
                            {--max-iterations=120 : Maximum iterations before exiting}';

    /**
     * The console command description.
     */
    protected $description = 'Send email notifications for unread User2Mod chat messages';

    /**
     * Execute the console command.
     */
    public function handle(ChatNotificationService $notificationService): int
    {
        $chatId = $this->option('chat') ? (int) $this->option('chat') : null;
        $delay = (int) $this->option('delay');
        $sinceHours = (int) $this->option('since');
        $forceAll = (bool) $this->option('force');
        $maxIterations = (int) $this->option('max-iterations');

        $this->registerShutdownHandlers();

        Log::info('Starting User2Mod chat notification', [
            'chat_id' => $chatId,
            'delay' => $delay,
            'since_hours' => $sinceHours,
            'force' => $forceAll,
        ]);

        $this->info('Processing User2Mod chat notifications...');
        $totalNotified = 0;
        $iteration = 0;

        do {
            if ($this->shouldAbort()) {
                $this->warn('Aborting due to shutdown signal.');
                break;
            }

            $startTime = now();
            $this->line('Starting iteration ' . ($iteration + 1) . ' at ' . $startTime->format('Y-m-d H:i:s'));

            $count = $notificationService->notifyByEmail(
                ChatRoom::TYPE_USER2MOD,
                $chatId,
                $delay,
                $sinceHours,
                $forceAll
            );

            $totalNotified += $count;
            $iteration++;

            if ($count > 0) {
                $this->info("Sent {$count} notifications.");
            } else {
                // No messages to process, sleep before next iteration.
                sleep(1);
            }
        } while ($iteration < $maxIterations);

        $this->newLine();
        $this->info("User2Mod notification complete. Total notifications sent: {$totalNotified}");

        Log::info('User2Mod chat notification complete', [
            'total_notified' => $totalNotified,
            'iterations' => $iteration,
        ]);

        return Command::SUCCESS;
    }
}
