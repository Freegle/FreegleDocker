<?php

namespace App\Services;

use App\Mail\Housekeeper\HousekeeperResultsMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Processes housekeeping task results from the Chrome extension.
 *
 * Currently handles:
 * - facebook-deletion: looks up Facebook user IDs, puts matching Freegle users into limbo
 */
class HousekeeperService
{
    /**
     * Process a housekeeping notification.
     */
    public function process(array $data, EmailSpoolerService $spooler, bool $shouldSpool): void
    {
        $task = $data['task'] ?? NULL;
        $status = $data['status'] ?? NULL;
        $summary = $data['summary'] ?? '';
        $email = $data['email'] ?? NULL;
        $taskData = $data['data'] ?? [];

        Log::info("Housekeeper: processing {$task} ({$status}): {$summary}");

        // Record this task run.
        if ($task) {
            DB::table('housekeeper_tasks')->updateOrInsert(
                ['task_key' => $task],
                [
                    'name' => $task,
                    'last_run_at' => now(),
                    'last_status' => $status,
                    'last_summary' => $summary,
                    'updated_at' => now(),
                ]
            );
        }

        $results = [];

        if ($task === 'facebook-deletion' && $status === 'success') {
            $results = $this->processFacebookDeletion($taskData);
        }

        // Send notification email if configured.
        if ($email) {
            $this->sendNotification($email, $task, $status, $summary, $results, $spooler, $shouldSpool);
        }
    }

    /**
     * Process Facebook deletion user IDs.
     *
     * For each Facebook user ID, find the corresponding Freegle user and put them
     * into limbo (14-day grace period). This mirrors the logic in
     * iznik-server/http/facebook/facebook_unsubscribe.php.
     */
    protected function processFacebookDeletion(array $taskData): array
    {
        $ids = $taskData['ids'] ?? [];
        $results = [];

        foreach ($ids as $fbId) {
            $fbId = (string) $fbId;

            // Look up Freegle user by Facebook login.
            $userId = DB::table('users_logins')
                ->where('type', 'Facebook')
                ->where('uid', $fbId)
                ->value('userid');

            if (! $userId) {
                $results[] = [
                    'facebook_id' => $fbId,
                    'status' => 'not_found',
                ];
                Log::info("Housekeeper: Facebook ID {$fbId} not found");
                continue;
            }

            // Check if already deleted.
            $deleted = DB::table('users')
                ->where('id', $userId)
                ->value('deleted');

            if ($deleted) {
                $results[] = [
                    'facebook_id' => $fbId,
                    'freegle_id' => $userId,
                    'status' => 'already_deleted',
                ];
                Log::info("Housekeeper: Facebook ID {$fbId} -> user {$userId} already deleted");
                continue;
            }

            // Put user into limbo (14-day grace period).
            DB::table('users')
                ->where('id', $userId)
                ->update(['deleted' => now()]);

            $results[] = [
                'facebook_id' => $fbId,
                'freegle_id' => $userId,
                'status' => 'limbo',
            ];

            Log::info("Housekeeper: Facebook ID {$fbId} -> user {$userId} marked for deletion (limbo)");
        }

        return $results;
    }

    /**
     * Send a notification email summarising what happened.
     */
    protected function sendNotification(
        string $toEmail,
        string $task,
        string $status,
        string $summary,
        array $results,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        try {
            $mailable = new HousekeeperResultsMail($task, $status, $summary, $results);
            $mailable->to($toEmail);

            if ($shouldSpool) {
                $spooler->spool($mailable, $toEmail);
            } else {
                Mail::send($mailable);
            }

            Log::info("Housekeeper: notification sent to {$toEmail}");
        } catch (\Exception $e) {
            Log::error("Housekeeper: failed to send notification: {$e->getMessage()}");
        }
    }
}
