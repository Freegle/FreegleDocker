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

    private LokiService $lokiService;

    public function __construct(LokiService $lokiService)
    {
        $this->lokiService = $lokiService;
    }

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
                Log::error("Error merging users for email {$duplicate->email}: ".$e->getMessage());
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
                    Log::debug("Could not update {$table}.{$column}: ".$e->getMessage());
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
                ->update(['validated' => null]);

            $this->lokiService->logBounceEvent(
                $email->email ?? '',
                $email->userid ?? 0,
                true,
                'Bounced email marked invalid',
            );

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
     * Fallback update of user lastaccess timestamps.
     *
     * Finds users whose lastaccess is more than 10 minutes behind their latest
     * chat message or membership join, and updates accordingly.
     *
     * Migrated from iznik-server/scripts/cron/lastaccess.php
     */
    public function updateLastAccess(): array
    {
        $stats = [
            'candidates' => 0,
            'updated' => 0,
        ];

        // Find users whose lastaccess is > 600 seconds behind their latest chat message or membership join.
        $users = DB::select("
            SELECT DISTINCT(userid) FROM (
                SELECT DISTINCT(userid) FROM users
                INNER JOIN chat_messages ON chat_messages.userid = users.id
                WHERE users.lastaccess < chat_messages.date
                    AND TIMESTAMPDIFF(SECOND, users.lastaccess, chat_messages.date) > 600
                UNION
                SELECT DISTINCT(userid) FROM memberships
                INNER JOIN users ON users.id = memberships.userid
                WHERE TIMESTAMPDIFF(SECOND, users.lastaccess, memberships.added) > 600
            ) t
        ");

        $stats['candidates'] = count($users);

        foreach ($users as $user) {
            // Find the latest activity timestamp from chat messages or memberships.
            $result = DB::selectOne("
                SELECT GREATEST(
                    COALESCE((SELECT MAX(date) FROM chat_messages WHERE userid = ?), '1970-01-01'),
                    COALESCE((SELECT MAX(added) FROM memberships WHERE userid = ?), '1970-01-01')
                ) AS max
            ", [$user->userid, $user->userid]);

            if ($result && $result->max && $result->max !== '1970-01-01') {
                $currentAccess = DB::table('users')
                    ->where('id', $user->userid)
                    ->value('lastaccess');

                $diff = strtotime($result->max) - strtotime($currentAccess);

                if ($diff > 600) {
                    DB::table('users')
                        ->where('id', $user->userid)
                        ->update(['lastaccess' => $result->max]);

                    $stats['updated']++;
                }
            }

            if (($stats['candidates']) % 1000 === 0) {
                Log::info("Processed {$stats['candidates']} lastaccess candidates");
            }
        }

        return $stats;
    }

    /**
     * Update support tools access based on team membership.
     *
     * Grants SYSTEMROLE_SUPPORT to users who are members of teams with supporttools=1.
     * Removes the role from users who no longer qualify (downgrading to Moderator).
     * Never touches Admin users.
     *
     * Migrated from iznik-server/scripts/cron/supporttools.php
     */
    public function updateSupportRoles(): array
    {
        $stats = [
            'granted' => 0,
            'removed' => 0,
        ];

        // Users who currently have Support or Admin role.
        $currentSupport = DB::table('users')
            ->whereIn('systemrole', ['Support', 'Admin'])
            ->pluck('id')
            ->all();

        // Users who should have support tools access (in teams with supporttools=1).
        $needSupport = DB::table('teams_members')
            ->join('teams', 'teams.id', '=', 'teams_members.teamid')
            ->where('teams.supporttools', 1)
            ->distinct()
            ->pluck('teams_members.userid')
            ->all();

        // Grant support role to users who need it but don't have it.
        foreach ($needSupport as $userId) {
            if (!in_array($userId, $currentSupport)) {
                DB::table('users')
                    ->where('id', $userId)
                    ->update(['systemrole' => 'Support']);

                $stats['granted']++;
                Log::info("Granted support role to user #{$userId}");
            }
        }

        // Remove support role from users who have it but shouldn't.
        // Don't touch Admin users - only downgrade Support to Moderator.
        $removeFrom = array_diff($currentSupport, $needSupport);

        foreach ($removeFrom as $userId) {
            $currentRole = DB::table('users')
                ->where('id', $userId)
                ->value('systemrole');

            // Only downgrade Support, never Admin.
            if ($currentRole === 'Support') {
                DB::table('users')
                    ->where('id', $userId)
                    ->update(['systemrole' => 'Moderator']);

                $stats['removed']++;
                Log::info("Removed support role from user #{$userId}");
            }
        }

        return $stats;
    }

    /**
     * Validate all non-bouncing emails and delete invalid ones.
     *
     * Uses the same regex as iznik-server Message::EMAIL_REGEXP.
     *
     * Migrated from iznik-server/scripts/cron/email_validate.php
     */
    public function validateEmails(): array
    {
        $stats = [
            'total' => 0,
            'invalid' => 0,
        ];

        $emails = DB::table('users_emails')
            ->join('users', 'users.id', '=', 'users_emails.userid')
            ->whereNull('users_emails.bounced')
            ->select('users_emails.id', 'users_emails.email', 'users_emails.userid')
            ->get();

        $stats['total'] = $emails->count();

        foreach ($emails as $email) {
            if (!preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $email->email)) {
                DB::table('users_emails')->where('id', $email->id)->delete();
                $stats['invalid']++;
                Log::info("Deleted invalid email: {$email->email} for user #{$email->userid}");
            }

            if ($stats['total'] > 0 && ($stats['invalid'] + ($stats['total'] - $stats['invalid'])) % 1000 === 0) {
                Log::info("Validated {$stats['total']} emails so far, {$stats['invalid']} invalid");
            }
        }

        return $stats;
    }

    /**
     * Update rating visibility based on chat interactions.
     *
     * A rating is visible if the rater and ratee have had meaningful chat interaction:
     * - At least one message from each in the same chat room, OR
     * - The ratee replied to a post (refmsgid is set).
     *
     * This prevents frivolous ratings from users who haven't actually interacted.
     *
     * Migrated from iznik-server User::ratingVisibility()
     */
    public function updateRatingVisibility(string $since = '1 hour ago'): array
    {
        $stats = [
            'processed' => 0,
            'made_visible' => 0,
            'made_hidden' => 0,
        ];

        $cutoff = date('Y-m-d', strtotime($since));

        $ratings = DB::table('ratings')
            ->where('timestamp', '>=', $cutoff)
            ->get();

        foreach ($ratings as $rating) {
            $visible = false;

            $chats = DB::table('chat_rooms')
                ->where(function ($q) use ($rating) {
                    $q->where('user1', $rating->rater)->where('user2', $rating->ratee);
                })
                ->orWhere(function ($q) use ($rating) {
                    $q->where('user2', $rating->rater)->where('user1', $rating->ratee);
                })
                ->pluck('id');

            foreach ($chats as $chatId) {
                // Check if both users have sent messages (excluding system/refmsg-only).
                $distinctUsers = DB::table('chat_messages')
                    ->where('chatid', $chatId)
                    ->whereNull('refmsgid')
                    ->whereNotNull('message')
                    ->distinct()
                    ->count('userid');

                if ($distinctUsers >= 2) {
                    $visible = true;
                    break;
                }

                // Check if ratee replied to a post.
                $replies = DB::table('chat_messages')
                    ->where('chatid', $chatId)
                    ->where('userid', $rating->ratee)
                    ->whereNotNull('refmsgid')
                    ->whereNotNull('message')
                    ->count();

                if ($replies > 0) {
                    $visible = true;
                    break;
                }
            }

            $oldVisible = (bool) $rating->visible;

            if ($visible !== $oldVisible) {
                DB::table('ratings')
                    ->where('id', $rating->id)
                    ->update([
                        'visible' => $visible,
                        'timestamp' => now(),
                    ]);

                if ($visible) {
                    $stats['made_visible']++;
                } else {
                    $stats['made_hidden']++;
                }
            }

            $stats['processed']++;
        }

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
