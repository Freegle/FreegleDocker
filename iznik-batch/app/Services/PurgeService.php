<?php

namespace App\Services;

use App\Models\ChatImage;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\EmailTracking;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Traits\ChunkedProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurgeService
{
    use ChunkedProcessing;

    /**
     * Chunk size for deletion operations.
     */
    protected int $chunkSize = 1000;

    /**
     * Purge spam chat messages older than specified days.
     */
    public function purgeSpamChatMessages(int $daysOld = 7): int
    {
        $cutoff = now()->subDays($daysOld);
        $total = 0;

        do {
            $deleted = ChatMessage::where('date', '<', $cutoff)
                ->where('reviewrejected', 1)
                ->limit($this->chunkSize)
                ->delete();

            $total += $deleted;

            if ($total % $this->logInterval === 0 && $total > 0) {
                Log::info("Purged {$total} spam chat messages");
            }
        } while ($deleted > 0);

        return $total;
    }

    /**
     * Purge empty chat rooms (no messages).
     */
    public function purgeEmptyChatRooms(): int
    {
        $total = 0;

        do {
            $emptyRooms = ChatRoom::leftJoin('chat_messages', 'chat_rooms.id', '=', 'chat_messages.chatid')
                ->whereNull('chat_messages.chatid')
                ->whereIn('chat_rooms.chattype', [ChatRoom::TYPE_USER2USER, ChatRoom::TYPE_USER2MOD])
                ->select('chat_rooms.id')
                ->limit($this->chunkSize)
                ->pluck('id');

            if ($emptyRooms->isEmpty()) {
                break;
            }

            ChatRoom::whereIn('id', $emptyRooms)->delete();
            $total += $emptyRooms->count();

            if ($total % $this->logInterval === 0) {
                Log::info("Purged {$total} empty chat rooms");
            }
        } while (true);

        return $total;
    }

    /**
     * Purge orphaned chat images.
     */
    public function purgeOrphanedChatImages(): int
    {
        $total = 0;

        do {
            $deleted = ChatImage::whereNull('chatmsgid')
                ->limit($this->chunkSize)
                ->delete();

            $total += $deleted;

            if ($total % $this->logInterval === 0 && $total > 0) {
                Log::info("Purged {$total} orphaned chat images");
            }
        } while ($deleted > 0);

        return $total;
    }

    /**
     * Purge old messages_history (spam checking data).
     */
    public function purgeOldMessagesHistory(int $daysOld = 90): int
    {
        $cutoff = now()->subDays($daysOld);
        $total = 0;

        do {
            $deleted = DB::table('messages_history')
                ->where('arrival', '<', $cutoff)
                ->limit($this->chunkSize)
                ->delete();

            $total += $deleted;

            if ($total % $this->logInterval === 0 && $total > 0) {
                Log::info("Purged {$total} messages_history records");
            }
        } while ($deleted > 0);

        return $total;
    }

    /**
     * Purge old pending messages.
     */
    public function purgePendingMessages(int $daysOld = 90): int
    {
        $cutoff = now()->subDays($daysOld);
        $total = 0;

        do {
            $pendingMsgIds = MessageGroup::where('collection', MessageGroup::COLLECTION_PENDING)
                ->where('arrival', '<', $cutoff)
                ->limit($this->chunkSize)
                ->pluck('msgid');

            if ($pendingMsgIds->isEmpty()) {
                break;
            }

            $this->retryOnDeadlock(fn () => Message::whereIn('id', $pendingMsgIds)->delete());
            $total += $pendingMsgIds->count();

            if ($total % $this->logInterval === 0) {
                Log::info("Purged {$total} pending messages");
            }
        } while (true);

        return $total;
    }

    /**
     * Purge old draft messages.
     */
    public function purgeOldDrafts(int $daysOld = 90): int
    {
        $cutoff = now()->subDays($daysOld);
        $total = 0;

        do {
            $draftMsgIds = DB::table('messages_drafts')
                ->where('timestamp', '<', $cutoff)
                ->limit($this->chunkSize)
                ->pluck('msgid');

            if ($draftMsgIds->isEmpty()) {
                break;
            }

            $this->retryOnDeadlock(fn () => Message::whereIn('id', $draftMsgIds)->delete());
            $total += $draftMsgIds->count();

            if ($total % $this->logInterval === 0) {
                Log::info("Purged {$total} old drafts");
            }
        } while (true);

        return $total;
    }

    /**
     * Purge messages from non-Freegle groups.
     */
    public function purgeNonFreegleMessages(int $daysOld = 90): int
    {
        $cutoff = now()->subDays($daysOld);
        $total = 0;

        do {
            $msgIds = MessageGroup::join('groups', 'messages_groups.groupid', '=', 'groups.id')
                ->where('messages_groups.arrival', '<=', $cutoff)
                ->where('groups.type', '!=', 'Freegle')
                ->limit($this->chunkSize)
                ->pluck('messages_groups.msgid');

            if ($msgIds->isEmpty()) {
                break;
            }

            $this->retryOnDeadlock(fn () => Message::whereIn('id', $msgIds)->delete());
            $total += $msgIds->count();

            if ($total % $this->logInterval === 0) {
                Log::info("Purged {$total} non-Freegle messages");
            }
        } while (true);

        return $total;
    }

    /**
     * Purge soft-deleted messages after retention period.
     */
    public function purgeDeletedMessages(int $retentionDays = 2): int
    {
        $cutoff = now()->subDays($retentionDays);
        $earliestDate = now()->subDays(90);
        $total = 0;

        do {
            $deleted = $this->retryOnDeadlock(fn () => Message::where('date', '>=', $earliestDate)
                ->whereNotNull('deleted')
                ->where('deleted', '<=', $cutoff)
                ->limit($this->chunkSize)
                ->delete());

            $total += $deleted;

            if ($total % $this->logInterval === 0 && $total > 0) {
                Log::info("Purged {$total} deleted messages");
            }
        } while ($deleted > 0);

        return $total;
    }

    /**
     * Purge stranded messages (not on any groups, no chat refs, no drafts).
     */
    public function purgeStrandedMessages(int $daysOld = 2): int
    {
        $cutoff = now()->subDays($daysOld);
        $total = 0;

        do {
            $strandedIds = Message::leftJoin('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
                ->leftJoin('chat_messages', 'chat_messages.refmsgid', '=', 'messages.id')
                ->leftJoin('messages_drafts', 'messages_drafts.msgid', '=', 'messages.id')
                ->where('messages.arrival', '<=', $cutoff)
                ->whereNull('messages_groups.msgid')
                ->whereNull('chat_messages.refmsgid')
                ->whereNull('messages_drafts.msgid')
                ->limit($this->chunkSize)
                ->pluck('messages.id');

            if ($strandedIds->isEmpty()) {
                break;
            }

            $this->retryOnDeadlock(fn () => Message::whereIn('id', $strandedIds)->delete());
            $total += $strandedIds->count();

            if ($total % $this->logInterval === 0) {
                Log::info("Purged {$total} stranded messages");
            }
        } while (true);

        return $total;
    }

    /**
     * Purge HTML body from old messages to save space.
     */
    public function purgeHtmlBody(int $daysOld = 2): int
    {
        $cutoff = now()->subDays($daysOld);
        $earliestDate = now()->subDays(90);
        $total = 0;

        do {
            $updated = $this->retryOnDeadlock(fn () => Message::where('arrival', '>=', $earliestDate)
                ->where('arrival', '<=', $cutoff)
                ->whereNotNull('htmlbody')
                ->limit($this->chunkSize)
                ->update(['htmlbody' => null]));

            $total += $updated;

            if ($total % $this->logInterval === 0 && $total > 0) {
                Log::info("Purged HTML body from {$total} messages");
            }
        } while ($updated > 0);

        return $total;
    }

    /**
     * Purge unvalidated email addresses.
     */
    public function purgeUnvalidatedEmails(int $daysOld = 7): int
    {
        $cutoff = now()->subDays($daysOld);

        return DB::table('users_emails')
            ->whereNull('userid')
            ->where('added', '<', $cutoff)
            ->delete();
    }

    /**
     * Purge old users_nearby data.
     */
    public function purgeUsersNearby(int $daysOld = 90): int
    {
        $cutoff = now()->subDays($daysOld);
        $total = 0;

        do {
            $deleted = DB::table('users_nearby')
                ->where('timestamp', '<=', $cutoff)
                ->limit($this->chunkSize)
                ->delete();

            $total += $deleted;

            if ($total % 100 === 0 && $total > 0) {
                Log::info("Purged {$total} users_nearby records");
            }
        } while ($deleted > 0);

        return $total;
    }

    /**
     * Purge old isochrones not linked to users.
     */
    public function purgeOrphanedIsochrones(): int
    {
        $orphanedIds = DB::table('isochrones')
            ->leftJoin('isochrones_users', 'isochrones_users.isochroneid', '=', 'isochrones.id')
            ->whereNull('isochrones_users.id')
            ->pluck('isochrones.id');

        $count = $orphanedIds->count();

        if ($count > 0) {
            DB::table('isochrones')->whereIn('id', $orphanedIds)->delete();
            Log::info("Purged {$count} orphaned isochrones");
        }

        return $count;
    }

    /**
     * Purge completed admin records.
     */
    public function purgeCompletedAdmins(int $daysOld = 90): int
    {
        $cutoff = now()->subDays($daysOld);
        $total = 0;

        $adminIds = DB::table('admins')
            ->join('admins_users', 'admins_users.adminid', '=', 'admins.id')
            ->where('complete', '<=', $cutoff)
            ->distinct()
            ->pluck('admins.id');

        foreach ($adminIds as $adminId) {
            do {
                $deleted = DB::table('admins_users')
                    ->where('adminid', $adminId)
                    ->limit(10000)
                    ->delete();

                $total += $deleted;
            } while ($deleted > 0);
        }

        Log::info("Purged {$total} admin_users records");

        return $total;
    }

    /**
     * Purge old email tracking data.
     *
     * Deletes tracking records older than the specified retention period.
     * Associated clicks and images are deleted via cascade.
     */
    public function purgeEmailTracking(int $daysOld = 90): int
    {
        $cutoff = now()->subDays($daysOld);
        $total = 0;

        do {
            $deleted = EmailTracking::where('sent_at', '<', $cutoff)
                ->limit($this->chunkSize)
                ->delete();

            $total += $deleted;

            if ($total % $this->logInterval === 0 && $total > 0) {
                Log::info("Purged {$total} email tracking records");
            }
        } while ($deleted > 0);

        return $total;
    }

    /**
     * Purge old message likes (older than 1 year).
     *
     * Migrated from purge_logs.php
     */
    public function purgeOldLikes(int $daysOld = 365): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        do {
            $count = DB::delete(
                "DELETE FROM messages_likes WHERE `timestamp` < ? LIMIT {$this->chunkSize}",
                [$cutoff]
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }

    /**
     * Purge old login/logout logs (older than 1 year).
     */
    public function purgeLoginLogoutLogs(int $daysOld = 365): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        do {
            $count = DB::delete(
                "DELETE FROM logs WHERE `type` = 'User' AND (`subtype` = 'Login' OR `subtype` = 'Logout') AND `timestamp` < ? LIMIT {$this->chunkSize}",
                [$cutoff]
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }

    /**
     * Purge user deletion logs (older than 31 days).
     */
    public function purgeUserDeletionLogs(int $daysOld = 31): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        do {
            $count = DB::delete(
                "DELETE FROM logs WHERE `type` = 'User' AND `subtype` = 'Deleted' AND `timestamp` < ? LIMIT {$this->chunkSize}",
                [$cutoff]
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }

    /**
     * Purge user creation logs (older than 31 days).
     */
    public function purgeUserCreationLogs(int $daysOld = 31): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        do {
            $count = DB::delete(
                "DELETE FROM logs WHERE `type` = 'User' AND `subtype` = 'Created' AND `timestamp` < ? LIMIT {$this->chunkSize}",
                [$cutoff]
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }

    /**
     * Purge logs with no subtype (older than 31 days).
     */
    public function purgeBlankSubtypeLogs(int $daysOld = 31): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        do {
            $count = DB::delete(
                "DELETE FROM logs WHERE (`type` = 'User' OR `type` = 'Group') AND `subtype` = '' AND `timestamp` < ? LIMIT {$this->chunkSize}",
                [$cutoff]
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }

    /**
     * Purge bounce logs (older than 90 days).
     */
    public function purgeBounceLogs(int $daysOld = 90): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        do {
            $count = DB::delete(
                "DELETE FROM logs WHERE `type` = 'User' AND `subtype` = 'Bounce' AND `timestamp` < ? LIMIT {$this->chunkSize}",
                [$cutoff]
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }

    /**
     * Purge old bounce emails (older than 31 days).
     */
    public function purgeOldBounceEmails(int $daysOld = 31): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        do {
            $count = DB::delete(
                "DELETE FROM bounces_emails WHERE `date` < ? LIMIT {$this->chunkSize}",
                [$cutoff]
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }

    /**
     * Purge old email logs (older than 25 hours or in the future).
     */
    public function purgeEmailLogs(): int
    {
        $cutoff = now()->subHours(25);
        $future = now()->startOfDay()->addDay();
        $total = 0;

        do {
            $count = DB::delete(
                "DELETE FROM logs_emails WHERE `timestamp` < ? OR `timestamp` > ? LIMIT {$this->chunkSize}",
                [$cutoff, $future]
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }

    /**
     * Purge logs for non-Freegle groups (older than 31 days).
     */
    public function purgeNonFreegleGroupLogs(int $daysOld = 31): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        $groups = DB::table('groups')
            ->where('type', '!=', 'Freegle')
            ->pluck('id');

        foreach ($groups as $groupId) {
            do {
                $count = DB::delete(
                    "DELETE FROM logs WHERE `timestamp` < ? AND groupid = ? LIMIT {$this->chunkSize}",
                    [$cutoff, $groupId]
                );
                $total += $count;
            } while ($count > 0);
        }

        return $total;
    }

    /**
     * Purge logs for messages that no longer exist (30-60 days old).
     */
    public function purgeOrphanedMessageLogs(): int
    {
        $start = now()->subDays(30)->startOfDay();
        $end = now()->subDays(60)->startOfDay();
        $total = 0;

        $logs = DB::select(
            "SELECT logs.id FROM logs LEFT JOIN messages ON messages.id = logs.msgid WHERE logs.msgid IS NOT NULL AND messages.id IS NULL AND logs.timestamp >= ? AND logs.timestamp < ?",
            [$end, $start]
        );

        foreach ($logs as $log) {
            DB::delete("DELETE FROM logs WHERE id = ?", [$log->id]);
            $total++;
        }

        return $total;
    }

    /**
     * Purge source logs (older than 1 year).
     */
    public function purgeSrcLogs(int $daysOld = 365): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        do {
            $count = DB::delete(
                "DELETE FROM logs_src WHERE `date` < ? LIMIT {$this->chunkSize}",
                [$cutoff]
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }

    /**
     * Purge JS error logs (older than 30 days).
     */
    public function purgeJsErrorLogs(int $daysOld = 30): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        do {
            $count = DB::delete(
                "DELETE FROM logs_errors WHERE `date` < ? LIMIT {$this->chunkSize}",
                [$cutoff]
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }

    /**
     * Purge plugin logs (older than 1 day).
     */
    public function purgePluginLogs(int $daysOld = 1): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        do {
            $count = DB::delete(
                "DELETE FROM logs WHERE `timestamp` < ? AND `type` = 'Plugin' LIMIT {$this->chunkSize}",
                [$cutoff]
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }

    /**
     * Purge SQL logs (older than 4 hours).
     */
    public function purgeSqlLogs(int $hoursOld = 4): int
    {
        $cutoff = now()->subHours($hoursOld);
        $total = 0;

        do {
            $count = DB::delete(
                "DELETE FROM logs_sql WHERE `date` < ? LIMIT {$this->chunkSize}",
                [$cutoff]
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }

    /**
     * Purge old user activity logs (older than 2 years).
     */
    public function purgeUserActivityLogs(int $daysOld = 730): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        do {
            $count = DB::delete(
                "DELETE FROM users_active WHERE `timestamp` < ? LIMIT {$this->chunkSize}",
                [$cutoff]
            );
            $total += $count;
        } while ($count > 0);

        return $total;
    }

    /**
     * Purge logs for users that no longer exist (older than 30 days).
     */
    public function purgeOrphanedUserLogs(int $daysOld = 30): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        do {
            $logs = DB::select(
                "SELECT logs.id FROM logs LEFT JOIN users ON users.id = logs.user WHERE `timestamp` < ? AND logs.user IS NOT NULL AND users.id IS NULL LIMIT {$this->chunkSize}",
                [$cutoff]
            );

            foreach ($logs as $log) {
                DB::delete("DELETE FROM logs WHERE id = ?", [$log->id]);
                $total++;
            }
        } while (count($logs) > 0);

        return $total;
    }

    /**
     * Run all log purge operations.
     *
     * Migrated from iznik-server/scripts/cron/purge_logs.php
     */
    public function purgeAllLogs(): array
    {
        $results = [];

        $results['old_likes'] = $this->purgeOldLikes();
        $results['login_logout_logs'] = $this->purgeLoginLogoutLogs();
        $results['user_deletion_logs'] = $this->purgeUserDeletionLogs();
        $results['user_creation_logs'] = $this->purgeUserCreationLogs();
        $results['blank_subtype_logs'] = $this->purgeBlankSubtypeLogs();
        $results['bounce_logs'] = $this->purgeBounceLogs();
        $results['old_bounce_emails'] = $this->purgeOldBounceEmails();
        $results['email_logs'] = $this->purgeEmailLogs();
        $results['non_freegle_group_logs'] = $this->purgeNonFreegleGroupLogs();
        $results['orphaned_message_logs'] = $this->purgeOrphanedMessageLogs();
        $results['src_logs'] = $this->purgeSrcLogs();
        $results['js_error_logs'] = $this->purgeJsErrorLogs();
        $results['plugin_logs'] = $this->purgePluginLogs();
        $results['sql_logs'] = $this->purgeSqlLogs();
        $results['user_activity_logs'] = $this->purgeUserActivityLogs();
        $results['orphaned_user_logs'] = $this->purgeOrphanedUserLogs();

        return $results;
    }

    /**
     * Purge old user sessions.
     *
     * Deletes sessions where lastactive is older than the specified days.
     * Uses chunked deletion to avoid locking the cluster.
     *
     * Migrated from purge_sessions.php
     */
    public function purgeSessions(int $daysOld = 31): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();
        $total = 0;

        do {
            $deleted = DB::table('sessions')
                ->where('lastactive', '<', $cutoff)
                ->limit($this->chunkSize)
                ->delete();

            $total += $deleted;

            if ($total % $this->logInterval === 0 && $total > 0) {
                Log::info("Purged {$total} sessions");
            }
        } while ($deleted > 0);

        return $total;
    }

    /**
     * Purge old login link credentials.
     *
     * Deletes users_logins entries of type 'Link' older than the specified days.
     *
     * Migrated from purge_sessions.php
     */
    public function purgeOldLoginLinks(int $daysOld = 31): int
    {
        $cutoff = now()->subDays($daysOld)->startOfDay();

        return DB::table('users_logins')
            ->where('lastaccess', '<', $cutoff)
            ->where('type', 'Link')
            ->delete();
    }

    /**
     * Remove duplicate consecutive search history entries.
     *
     * Finds searches from recent days where the same user searched the
     * same term/location/groups consecutively, and removes the duplicates.
     *
     * Migrated from searchdups.php
     */
    public function deduplicateSearchHistory(int $daysBack = 2): int
    {
        $cutoff = now()->subDays($daysBack)->startOfDay();
        $deleted = 0;

        $searches = DB::table('search_history')
            ->where('date', '>', $cutoff)
            ->orderBy('groups')
            ->orderBy('id')
            ->get(['id', 'term', 'locationid', 'groups']);

        $last = null;

        foreach ($searches as $search) {
            if ($last !== null) {
                $isDuplicate = $search->term === $last->term
                    && $search->locationid === $last->locationid
                    && $search->groups === $last->groups;

                if ($isDuplicate) {
                    DB::table('search_history')->where('id', $search->id)->delete();
                    $deleted++;
                }
            }

            $last = $search;
        }

        return $deleted;
    }

    /**
     * Remove duplicate consecutive chat messages.
     *
     * Finds chat messages from recent days where the same message text
     * and reference message were sent consecutively in a chat room.
     *
     * Migrated from chatdups.php
     */
    public function deduplicateChatMessages(int $daysBack = 3): int
    {
        $cutoff = now()->subDays($daysBack)->startOfDay();
        $deleted = 0;

        $duplicateChats = DB::table('chat_messages')
            ->select('chatid', 'message', 'refmsgid', DB::raw('COUNT(*) as count'))
            ->where('date', '>=', $cutoff)
            ->groupBy('chatid', 'message', 'refmsgid')
            ->having('count', '>', 1)
            ->get();

        foreach ($duplicateChats as $chat) {
            $messages = DB::table('chat_messages')
                ->where('date', '>', $cutoff)
                ->where('chatid', $chat->chatid)
                ->orderBy('id')
                ->get(['id', 'message', 'refmsgid']);

            $lastMessage = null;
            $lastRefmsgid = null;

            foreach ($messages as $msg) {
                if ($lastMessage !== null
                    && $lastMessage === $msg->message
                    && $lastRefmsgid === $msg->refmsgid) {
                    DB::table('chat_messages')->where('id', $msg->id)->delete();
                    $deleted++;
                } else {
                    $lastMessage = $msg->message;
                    $lastRefmsgid = $msg->refmsgid;
                }
            }
        }

        return $deleted;
    }

    /**
     * Run all purge operations.
     */
    public function runAll(): array
    {
        $results = [];

        $results['spam_chat_messages'] = $this->purgeSpamChatMessages();
        $results['empty_chat_rooms'] = $this->purgeEmptyChatRooms();
        $results['orphaned_chat_images'] = $this->purgeOrphanedChatImages();
        $results['messages_history'] = $this->purgeOldMessagesHistory();
        $results['pending_messages'] = $this->purgePendingMessages();
        $results['old_drafts'] = $this->purgeOldDrafts();
        $results['non_freegle_messages'] = $this->purgeNonFreegleMessages();
        $results['deleted_messages'] = $this->purgeDeletedMessages();
        $results['stranded_messages'] = $this->purgeStrandedMessages();
        $results['html_body'] = $this->purgeHtmlBody();
        $results['unvalidated_emails'] = $this->purgeUnvalidatedEmails();
        $results['users_nearby'] = $this->purgeUsersNearby();
        $results['orphaned_isochrones'] = $this->purgeOrphanedIsochrones();
        $results['completed_admins'] = $this->purgeCompletedAdmins();
        $results['email_tracking'] = $this->purgeEmailTracking();
        $results['sessions'] = $this->purgeSessions();
        $results['login_links'] = $this->purgeOldLoginLinks();

        return $results;
    }
}
