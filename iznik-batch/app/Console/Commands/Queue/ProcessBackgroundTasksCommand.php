<?php

namespace App\Console\Commands\Queue;

use App\Console\Concerns\PreventsOverlapping;
use App\Mail\Donation\DonateExternalMail;
use App\Mail\Newsfeed\ChitchatReportMail;
use App\Mail\Session\ForgotPasswordMail;
use App\Mail\Session\UnsubscribeConfirmMail;
use App\Mail\Message\ModStdMessageMail;
use App\Models\ChatRoom;
use App\Models\User;
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

                // Report to Sentry so we get alerted to task failures.
                if (app()->bound('sentry')) {
                    app('sentry')->captureException($e);
                }

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
            'email_forgot_password' => $this->handleEmailForgotPassword($data, $spooler, $shouldSpool),
            'email_unsubscribe' => $this->handleEmailUnsubscribe($data, $spooler, $shouldSpool),
            'email_message_approved', 'email_message_rejected', 'email_message_reply'
                => $this->handleModStdMessage($taskType, $data, $spooler, $shouldSpool),
            'email_mod_stdmsg' => $this->handleModStdMessageForMember($data, $spooler, $shouldSpool),
            'message_outcome' => $this->handleMessageOutcome($data),
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
            $recipients = array_map('trim', explode(',', config('freegle.mail.chitchat_support_addr')));
            $spooler->spool($mail, $recipients);
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
            $spooler->spool($mail, config('freegle.mail.info_addr'));
        } else {
            Mail::send($mail);
        }

        Log::info('Sent external donation email', [
            'user_id' => $data['user_id'],
            'amount' => $data['amount'],
        ]);
    }

    /**
     * Send a forgot-password email with auto-login link.
     */
    protected function handleEmailForgotPassword(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $required = ['user_id', 'email', 'reset_url'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("email_forgot_password requires {$field}");
            }
        }

        $mail = new ForgotPasswordMail(
            userId: (int) $data['user_id'],
            email: $data['email'],
            resetUrl: $data['reset_url'],
        );

        if ($shouldSpool) {
            $spooler->spool($mail, $data['email']);
        } else {
            Mail::send($mail);
        }

        Log::info('Sent forgot password email', [
            'user_id' => $data['user_id'],
        ]);
    }

    /**
     * Send an unsubscribe confirmation email with auto-login link.
     */
    protected function handleEmailUnsubscribe(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $required = ['user_id', 'email', 'unsub_url'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("email_unsubscribe requires {$field}");
            }
        }

        $mail = new UnsubscribeConfirmMail(
            userId: (int) $data['user_id'],
            email: $data['email'],
            unsubUrl: $data['unsub_url'],
        );

        if ($shouldSpool) {
            $spooler->spool($mail, $data['email']);
        } else {
            Mail::send($mail);
        }

        Log::info('Sent unsubscribe confirmation email', [
            'user_id' => $data['user_id'],
        ]);
    }

    /**
     * Handle mod standard message emails (approve, reject, reply).
     *
     * Looks up the message poster, group, and mod info, then sends the stdmsg email
     * and creates a User2Mod chat message for the mod log.
     */
    protected function handleModStdMessage(
        string $taskType,
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $msgId = (int) ($data['msgid'] ?? 0);
        $byUser = (int) ($data['byuser'] ?? 0);
        $groupId = (int) ($data['groupid'] ?? 0);
        $subject = $data['subject'] ?? '';
        $body = $data['body'] ?? '';

        // Fall back to looking up group from messages_groups if not provided.
        if ($groupId === 0 && $msgId > 0) {
            $groupId = (int) (DB::table('messages_groups')->where('msgid', $msgId)->value('groupid') ?? 0);
        }

        if ($msgId === 0 || $byUser === 0) {
            throw new \RuntimeException("{$taskType} requires msgid and byuser");
        }

        // No subject/body means no stdmsg to send (e.g. plain approve without message).
        if ($subject === '' && $body === '') {
            Log::info("Mod action {$taskType} without stdmsg content, skipping email", [
                'msgid' => $msgId,
                'byuser' => $byUser,
            ]);
            return;
        }

        // Look up the poster and their preferred email.
        $posterId = (int) DB::table('messages')->where('id', $msgId)->value('fromuser');

        if (! $posterId) {
            Log::warning("No poster found for message {$msgId}");
            return;
        }

        $poster = User::find($posterId);
        $posterEmail = $poster?->email_preferred;

        if (! $posterEmail) {
            Log::warning("No email found for poster of message {$msgId}");
            return;
        }

        // Look up the group info.
        $groupName = '';
        $groupNameShort = '';
        $groupContactMail = null;
        if ($groupId > 0) {
            $group = DB::table('groups')->where('id', $groupId)->first();
            if ($group) {
                $groupName = $group->namefull ?: $group->nameshort ?? '';
                $groupNameShort = $group->nameshort ?? '';
                $groupContactMail = $group->contactmail ?: null;
            }
        }

        // Look up the mod's display name.
        $modName = DB::table('users')->where('id', $byUser)->value('fullname') ?? 'A volunteer';

        // Look up the message subject for context.
        $messageSubject = DB::table('messages')->where('id', $msgId)->value('subject') ?? '';

        $mail = new ModStdMessageMail(
            modName: $modName,
            groupName: $groupName,
            groupNameShort: $groupNameShort,
            stdSubject: $subject,
            stdBody: $body,
            messageSubject: $messageSubject,
            msgId: $msgId,
            recipientUserId: $posterId,
            recipientEmail: $posterEmail,
            groupContactMail: $groupContactMail,
        );

        if ($shouldSpool) {
            $spooler->spool($mail, $posterEmail);
        } else {
            Mail::to($posterEmail)->send($mail);
        }

        // Create a User2Mod chat message so the conversation appears in modtools chats.
        if ($groupId > 0) {
            $chatRoom = ChatRoom::getOrCreateUser2Mod($posterId, $groupId);

            if ($chatRoom) {
                DB::table('chat_messages')->insert([
                    'chatid' => $chatRoom->id,
                    'userid' => $byUser,
                    'message' => "{$subject}\r\n\r\n{$body}",
                    'type' => 'ModMail',
                    'refmsgid' => $msgId,
                    'date' => now(),
                    'reviewrequired' => 0,
                    'processingrequired' => 0,
                    'processingsuccessful' => 1,
                ]);
            }
        }

        Log::info("Sent mod stdmsg email ({$taskType})", [
            'msgid' => $msgId,
            'byuser' => $byUser,
            'groupid' => $groupId,
            'recipient' => $posterEmail,
        ]);
    }

    /**
     * Handle mod standard message emails sent to a member (not related to a message).
     *
     * V1 parity with User::mail() + User::maybeMail():
     * 1. Send email to the member.
     * 2. Create a User2Mod chat message for the mod log.
     */
    protected function handleModStdMessageForMember(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $userId = (int) ($data['userid'] ?? 0);
        $byUser = (int) ($data['byuser'] ?? 0);
        $groupId = (int) ($data['groupid'] ?? 0);
        $subject = $data['subject'] ?? '';
        $body = $data['body'] ?? '';
        $stdmsgId = (int) ($data['stdmsgid'] ?? 0);

        if ($userId === 0 || $byUser === 0) {
            throw new \RuntimeException('email_mod_stdmsg requires userid and byuser');
        }

        if ($subject === '' && $body === '') {
            Log::info('Mod stdmsg for member without content, skipping email', [
                'userid' => $userId,
                'byuser' => $byUser,
            ]);
            return;
        }

        // Look up the member's preferred email.
        $member = User::find($userId);
        $memberEmail = $member?->email_preferred;

        if (! $memberEmail) {
            Log::warning("No email found for member {$userId}");
            return;
        }

        // Look up group info.
        $groupName = '';
        $groupNameShort = '';
        $groupContactMail = null;
        if ($groupId > 0) {
            $group = DB::table('groups')->where('id', $groupId)->first();
            if ($group) {
                $groupName = $group->namefull ?: $group->nameshort ?? '';
                $groupNameShort = $group->nameshort ?? '';
                $groupContactMail = $group->contactmail ?: null;
            }
        }

        // Look up the mod's display name.
        $modName = DB::table('users')->where('id', $byUser)->value('fullname') ?? 'A volunteer';

        $mail = new ModStdMessageMail(
            modName: $modName,
            groupName: $groupName,
            groupNameShort: $groupNameShort,
            stdSubject: $subject,
            stdBody: $body,
            messageSubject: '',
            msgId: 0,
            recipientUserId: $userId,
            recipientEmail: $memberEmail,
            groupContactMail: $groupContactMail,
        );

        if ($shouldSpool) {
            $spooler->spool($mail, $memberEmail);
        } else {
            Mail::to($memberEmail)->send($mail);
        }

        // Create a User2Mod chat message so the conversation appears in modtools chats.
        if ($groupId > 0) {
            $chatRoom = ChatRoom::getOrCreateUser2Mod($userId, $groupId);

            if ($chatRoom) {
                DB::table('chat_messages')->insert([
                    'chatid' => $chatRoom->id,
                    'userid' => $byUser,
                    'message' => "{$subject}\r\n\r\n{$body}",
                    'type' => 'ModMail',
                    'date' => now(),
                    'reviewrequired' => 0,
                    'processingrequired' => 0,
                    'processingsuccessful' => 1,
                ]);
            }
        }

        Log::info('Sent mod stdmsg email to member', [
            'userid' => $userId,
            'byuser' => $byUser,
            'groupid' => $groupId,
            'recipient' => $memberEmail,
        ]);
    }

    /**
     * Handle message outcome background processing.
     *
     * V1 parity with Message::backgroundMark():
     * 1. Log the outcome to the logs table for each group the message is on.
     * 2. Notify interested users (who replied but didn't get the item) by creating
     *    TYPE_COMPLETED chat messages in their User2User chat rooms.
     */
    protected function handleMessageOutcome(array $data): void
    {
        $msgId = (int) ($data['msgid'] ?? 0);
        $byUser = (int) ($data['byuser'] ?? 0);
        $outcome = $data['outcome'] ?? '';
        $happiness = $data['happiness'] ?? '';
        $comment = $data['comment'] ?? '';
        $userid = (int) ($data['userid'] ?? 0);
        $messageForOthers = $data['message'] ?? '';

        if ($msgId === 0) {
            throw new \RuntimeException('message_outcome requires msgid');
        }

        // Get the message poster.
        $fromUser = (int) (DB::table('messages')->where('id', $msgId)->value('fromuser') ?? 0);

        // 1. Log the outcome for each group (V1: Log::TYPE_MESSAGE, Log::SUBTYPE_OUTCOME).
        $groups = DB::table('messages_groups')->where('msgid', $msgId)->pluck('groupid');

        foreach ($groups as $groupId) {
            DB::table('logs')->insert([
                'timestamp' => now(),
                'type' => 'Message',
                'subtype' => 'Outcome',
                'msgid' => $msgId,
                'user' => $fromUser ?: null,
                'byuser' => $byUser ?: null,
                'groupid' => $groupId,
                'text' => trim("{$outcome} {$comment}"),
            ]);
        }

        // 2. Notify interested users who replied but didn't get the item.
        // Find User2User chat rooms with INTERESTED messages referencing this message,
        // excluding users who are in messages_by (i.e. who got the item).
        $replies = DB::select(
            "SELECT DISTINCT chatid FROM chat_messages
             INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid AND chat_rooms.chattype = 'User2User'
             LEFT JOIN messages_by ON messages_by.msgid = chat_messages.refmsgid
                 AND messages_by.userid IN (chat_rooms.user1, chat_rooms.user2)
             WHERE refmsgid = ? AND chat_messages.type = 'Interested'
                 AND reviewrejected = 0 AND messages_by.id IS NULL",
            [$msgId]
        );

        foreach ($replies as $reply) {
            // Check if this message was unpromised in this chat (TYPE_RENEGED).
            // If so, don't send the generic message as it may not be appropriate.
            $unpromised = DB::table('chat_messages')
                ->where('chatid', $reply->chatid)
                ->where('refmsgid', $msgId)
                ->where('type', 'Reneged')
                ->exists();

            DB::table('chat_messages')->insert([
                'chatid' => $reply->chatid,
                'userid' => $fromUser,
                'message' => $unpromised ? null : ($messageForOthers ?: null),
                'type' => 'Completed',
                'refmsgid' => $msgId,
                'date' => now(),
                'reviewrequired' => 0,
                'processingrequired' => 0,
                'processingsuccessful' => 1,
            ]);

            // Mark the poster as up-to-date in this chat so it doesn't appear as unread to them.
            if ($fromUser) {
                DB::table('chat_roster')->updateOrInsert(
                    ['chatid' => $reply->chatid, 'userid' => $fromUser],
                    ['lastmsgseen' => DB::raw('(SELECT MAX(id) FROM chat_messages WHERE chatid = ' . (int) $reply->chatid . ')'), 'date' => now()]
                );
            }
        }

        Log::info('Processed message outcome', [
            'msgid' => $msgId,
            'outcome' => $outcome,
            'groups' => $groups->count(),
            'notified_chats' => count($replies),
        ]);
    }
}
