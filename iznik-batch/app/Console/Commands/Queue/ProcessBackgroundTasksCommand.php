<?php

namespace App\Console\Commands\Queue;

use App\Console\Concerns\PreventsOverlapping;
use App\Mail\Donation\DonateExternalMail;
use App\Mail\Invitation\InvitationMail;
use App\Mail\Newsfeed\ChitchatReportMail;
use App\Services\EmailSpoolerService;
use App\Services\PushNotificationService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Processes background tasks queued by the Go API server.
 *
 * Go writes rows to the `background_tasks` table with a task_type and JSON data.
 * This command polls that table and dispatches each task to the appropriate handler.
 *
 * Runs as a daemon via supervisor or the Laravel scheduler.
 */
class ProcessBackgroundTasksCommand extends Command
{
    use GracefulShutdown;
    use PreventsOverlapping;

    protected $signature = 'queue:background-tasks
                            {--limit=50 : Maximum tasks to process per iteration}
                            {--max-iterations=60 : Maximum iterations before exiting (0 = infinite)}
                            {--sleep=5 : Seconds to sleep between iterations}
                            {--spool : Spool emails instead of sending directly}';

    protected $description = 'Process background tasks queued by the Go API server';

    private const MAX_ATTEMPTS = 3;

    public function handle(PushNotificationService $pushService, EmailSpoolerService $spooler): int
    {
        if (! $this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        try {
            return $this->doHandle($pushService, $spooler);
        } finally {
            $this->releaseLock();
        }
    }

    protected function doHandle(PushNotificationService $pushService, EmailSpoolerService $spooler): int
    {
        $limit = (int) $this->option('limit');
        $maxIterations = (int) $this->option('max-iterations');
        $sleepSeconds = (int) $this->option('sleep');
        $shouldSpool = (bool) $this->option('spool');
        $iteration = 0;

        $this->registerShutdownHandlers();
        $this->info("Processing background tasks (limit={$limit}, max-iterations={$maxIterations})");

        while (TRUE) {
            if ($this->shouldStop()) {
                $this->info('Shutdown signal received, stopping gracefully.');
                break;
            }

            $iteration++;
            if ($maxIterations > 0 && $iteration > $maxIterations) {
                $this->info("Reached max iterations ({$maxIterations}), exiting.");
                break;
            }

            $processed = $this->processIteration($limit, $pushService, $spooler, $shouldSpool);

            if ($processed === 0) {
                sleep($sleepSeconds);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Process one iteration of pending tasks.
     */
    protected function processIteration(
        int $limit,
        PushNotificationService $pushService,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): int {
        $tasks = DB::select(
            'SELECT * FROM background_tasks WHERE processed_at IS NULL AND failed_at IS NULL AND attempts < ? ORDER BY created_at ASC LIMIT ?',
            [self::MAX_ATTEMPTS, $limit]
        );

        $processed = 0;

        foreach ($tasks as $task) {
            if ($this->shouldStop()) {
                break;
            }

            try {
                DB::table('background_tasks')
                    ->where('id', $task->id)
                    ->increment('attempts');

                $data = json_decode($task->data, TRUE);

                $this->dispatchTask($task->task_type, $data, $pushService, $spooler, $shouldSpool);

                DB::table('background_tasks')
                    ->where('id', $task->id)
                    ->update(['processed_at' => now()]);

                $processed++;
            } catch (\Throwable $e) {
                Log::error('Background task failed', [
                    'task_id' => $task->id,
                    'task_type' => $task->task_type,
                    'error' => $e->getMessage(),
                    'attempts' => $task->attempts + 1,
                ]);

                $update = ['error_message' => substr($e->getMessage(), 0, 65535)];

                if ($task->attempts + 1 >= self::MAX_ATTEMPTS) {
                    $update['failed_at'] = now();
                }

                DB::table('background_tasks')
                    ->where('id', $task->id)
                    ->update($update);
            }
        }

        if ($processed > 0) {
            $this->info("Processed {$processed} task(s).");
        }

        return $processed;
    }

    /**
     * Dispatch a task to the appropriate handler.
     */
    protected function dispatchTask(
        string $taskType,
        array $data,
        PushNotificationService $pushService,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        match ($taskType) {
            'push_notify_group_mods' => $this->handlePushNotifyGroupMods($data, $pushService),
            'email_chitchat_report' => $this->handleEmailChitchatReport($data, $spooler, $shouldSpool),
            'email_donate_external' => $this->handleEmailDonateExternal($data, $spooler, $shouldSpool),
            'email_invitation' => $this->handleEmailInvitation($data, $spooler, $shouldSpool),
            default => throw new \RuntimeException("Unknown task type: {$taskType}"),
        };
    }

    /**
     * Send push notifications to all moderators of a group.
     */
    protected function handlePushNotifyGroupMods(array $data, PushNotificationService $pushService): void
    {
        $groupId = $data['group_id'] ?? NULL;

        if (! $groupId) {
            throw new \RuntimeException('push_notify_group_mods requires group_id');
        }

        $count = $pushService->notifyGroupMods((int) $groupId);
        Log::info('Notified group mods', ['group_id' => $groupId, 'notified' => $count]);
    }

    /**
     * Send a ChitChat report email to support.
     */
    protected function handleEmailChitchatReport(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $required = ['user_id', 'user_name', 'user_email', 'newsfeed_id', 'reason'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("email_chitchat_report requires {$field}");
            }
        }

        $mail = new ChitchatReportMail(
            reporterName: $data['user_name'],
            reporterId: (int) $data['user_id'],
            reporterEmail: $data['user_email'],
            newsfeedId: (int) $data['newsfeed_id'],
            reason: $data['reason'],
        );

        if ($shouldSpool) {
            $spooler->spool($mail);
        } else {
            Mail::send($mail);
        }

        Log::info('Sent ChitChat report email', [
            'reporter_id' => $data['user_id'],
            'newsfeed_id' => $data['newsfeed_id'],
        ]);
    }

    /**
     * Send an external donation notification email to the info address.
     */
    protected function handleEmailDonateExternal(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $required = ['user_id', 'user_name', 'user_email', 'amount'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("email_donate_external requires {$field}");
            }
        }

        $mail = new DonateExternalMail(
            userName: $data['user_name'],
            userId: (int) $data['user_id'],
            userEmail: $data['user_email'],
            amount: (float) $data['amount'],
        );

        if ($shouldSpool) {
            $spooler->spool($mail);
        } else {
            Mail::send($mail);
        }

        Log::info('Sent external donation email', [
            'user_id' => $data['user_id'],
            'amount' => $data['amount'],
        ]);
    }

    /**
     * Send an invitation email to a new user on behalf of an existing user.
     */
    protected function handleEmailInvitation(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $required = ['invite_id', 'sender_name', 'sender_email', 'to_email'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("email_invitation requires {$field}");
            }
        }

        $mail = new InvitationMail(
            inviteId: (int) $data['invite_id'],
            senderName: $data['sender_name'],
            senderEmail: $data['sender_email'],
            toEmail: $data['to_email'],
        );

        if ($shouldSpool) {
            $spooler->spool($mail);
        } else {
            Mail::send($mail);
        }

        Log::info('Sent invitation email', [
            'invite_id' => $data['invite_id'],
            'to_email' => $data['to_email'],
        ]);
    }
}
