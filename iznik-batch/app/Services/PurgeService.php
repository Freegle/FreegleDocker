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

            Message::whereIn('id', $pendingMsgIds)->delete();
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

            Message::whereIn('id', $draftMsgIds)->delete();
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

            Message::whereIn('id', $msgIds)->delete();
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
            $deleted = Message::where('date', '>=', $earliestDate)
                ->whereNotNull('deleted')
                ->where('deleted', '<=', $cutoff)
                ->limit($this->chunkSize)
                ->delete();

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

            Message::whereIn('id', $strandedIds)->delete();
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
            $updated = Message::where('arrival', '>=', $earliestDate)
                ->where('arrival', '<=', $cutoff)
                ->whereNotNull('htmlbody')
                ->limit($this->chunkSize)
                ->update(['htmlbody' => null]);

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

        return $results;
    }
}
