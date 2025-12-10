<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserEmail;
use App\Traits\ChunkedProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserManagementService
{
    use ChunkedProcessing;

    /**
     * Chunk size for batch operations.
     */
    protected int $chunkSize = 1000;

    /**
     * Merge duplicate user accounts.
     * Users with the same email should be merged.
     */
    public function mergeDuplicates(): array
    {
        $stats = [
            'duplicates_found' => 0,
            'users_merged' => 0,
            'errors' => 0,
        ];

        // Find email addresses linked to multiple users.
        $duplicates = UserEmail::select('email')
            ->groupBy('email')
            ->havingRaw('COUNT(DISTINCT userid) > 1')
            ->get();

        $stats['duplicates_found'] = $duplicates->count();

        foreach ($duplicates as $duplicate) {
            try {
                $this->mergeUsersForEmail($duplicate->email);
                $stats['users_merged']++;
            } catch (\Exception $e) {
                Log::error("Error merging users for email {$duplicate->email}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Merge all users associated with an email into the oldest account.
     */
    protected function mergeUsersForEmail(string $email): void
    {
        $userIds = UserEmail::where('email', $email)
            ->whereNotNull('userid')
            ->orderBy('userid')
            ->pluck('userid')
            ->unique();

        if ($userIds->count() < 2) {
            return;
        }

        // Keep the oldest (lowest ID) user.
        $keepUserId = $userIds->first();
        $mergeUserIds = $userIds->slice(1);

        foreach ($mergeUserIds as $mergeUserId) {
            $this->mergeUser($keepUserId, $mergeUserId);
        }
    }

    /**
     * Merge one user into another.
     */
    protected function mergeUser(int $keepUserId, int $mergeUserId): void
    {
        DB::transaction(function () use ($keepUserId, $mergeUserId) {
            // Update foreign keys pointing to merged user.
            $tables = [
                'memberships' => 'userid',
                'chat_rooms' => 'user1',
                'chat_rooms' => 'user2',
                'chat_messages' => 'userid',
                'messages' => 'fromuser',
                'users_donations' => 'userid',
                'users_emails' => 'userid',
            ];

            foreach ($tables as $table => $column) {
                try {
                    DB::table($table)
                        ->where($column, $mergeUserId)
                        ->update([$column => $keepUserId]);
                } catch (\Exception $e) {
                    // May fail on unique constraints, which is fine.
                    Log::debug("Could not update {$table}.{$column}: " . $e->getMessage());
                }
            }

            // Soft delete the merged user.
            User::where('id', $mergeUserId)
                ->update(['deleted' => now()]);

            Log::info("Merged user {$mergeUserId} into {$keepUserId}");
        });
    }

    /**
     * Check and update user email validity via bounce tracking.
     * Emails that have bounced (bounced timestamp is set) and were validated
     * are marked as invalid (validated set to NULL).
     */
    public function processBouncedEmails(): array
    {
        $stats = [
            'processed' => 0,
            'marked_invalid' => 0,
        ];

        // Get validated emails that have bounced.
        $bouncedEmails = DB::table('users_emails')
            ->whereNotNull('bounced')
            ->whereNotNull('validated')
            ->limit($this->chunkSize)
            ->get();

        foreach ($bouncedEmails as $email) {
            UserEmail::where('id', $email->id)
                ->update(['validated' => NULL]);

            $stats['marked_invalid']++;
            $stats['processed']++;
        }

        return $stats;
    }

    /**
     * Update user kudos based on their activity.
     */
    public function updateKudos(): int
    {
        $updated = 0;

        // Calculate kudos based on:
        // - Number of items given away.
        // - Number of items received.
        // - Time on platform.
        // - Positive ratings.

        $users = DB::table('users')
            ->select('users.id')
            ->leftJoin('messages_outcomes', function ($join) {
                $join->on('messages_outcomes.msgid', '=', DB::raw('(SELECT id FROM messages WHERE fromuser = users.id LIMIT 1)'))
                    ->whereIn('messages_outcomes.outcome', ['Taken', 'Received']);
            })
            ->whereNull('users.deleted')
            ->groupBy('users.id')
            ->havingRaw('COUNT(messages_outcomes.id) > 0')
            ->limit($this->chunkSize)
            ->get();

        foreach ($users as $userData) {
            $user = User::find($userData->id);
            if ($user) {
                $kudos = $this->calculateKudos($user);
                $user->update(['kudos' => $kudos]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Calculate kudos score for a user.
     */
    protected function calculateKudos(User $user): int
    {
        $kudos = 0;

        // Points for items given.
        $given = DB::table('messages')
            ->join('messages_outcomes', 'messages_outcomes.msgid', '=', 'messages.id')
            ->where('messages.fromuser', $user->id)
            ->where('messages_outcomes.outcome', 'Taken')
            ->count();
        $kudos += $given * 10;

        // Points for items received.
        $received = DB::table('messages_by')
            ->where('userid', $user->id)
            ->count();
        $kudos += $received * 5;

        // Points for ratings.
        $positiveRatings = DB::table('ratings')
            ->where('ratee', $user->id)
            ->where('rating', 'Up')
            ->count();
        $kudos += $positiveRatings * 3;

        // Points for tenure (1 point per month).
        $monthsOnPlatform = $user->added
            ? now()->diffInMonths($user->added)
            : 0;
        $kudos += $monthsOnPlatform;

        return $kudos;
    }

    /**
     * Update user retention statistics.
     */
    public function updateRetentionStats(): array
    {
        $stats = [
            'active_users_30d' => 0,
            'active_users_90d' => 0,
            'new_users_30d' => 0,
            'churned_users' => 0,
        ];

        // Active in last 30 days.
        $stats['active_users_30d'] = User::where('lastaccess', '>=', now()->subDays(30))
            ->whereNull('deleted')
            ->count();

        // Active in last 90 days.
        $stats['active_users_90d'] = User::where('lastaccess', '>=', now()->subDays(90))
            ->whereNull('deleted')
            ->count();

        // New users in last 30 days.
        $stats['new_users_30d'] = User::where('added', '>=', now()->subDays(30))
            ->whereNull('deleted')
            ->count();

        // Churned (active 90-180 days ago but not since).
        $stats['churned_users'] = User::where('lastaccess', '<', now()->subDays(90))
            ->where('lastaccess', '>=', now()->subDays(180))
            ->whereNull('deleted')
            ->count();

        Log::info('User retention stats updated', $stats);

        return $stats;
    }

    /**
     * Clean up inactive user data for GDPR compliance.
     */
    public function cleanupInactiveUsers(int $yearsInactive = 3): int
    {
        $cutoff = now()->subYears($yearsInactive);
        $cleaned = 0;

        $inactiveUsers = User::where('lastaccess', '<', $cutoff)
            ->whereNull('deleted')
            ->limit($this->chunkSize)
            ->get();

        foreach ($inactiveUsers as $user) {
            // Anonymize rather than delete.
            $user->update([
                'firstname' => 'Deleted',
                'lastname' => 'User',
                'fullname' => 'Deleted User',
                'deleted' => now(),
            ]);

            // Remove email addresses.
            UserEmail::where('userid', $user->id)->delete();

            $cleaned++;
        }

        Log::info("Cleaned up {$cleaned} inactive users");

        return $cleaned;
    }
}
