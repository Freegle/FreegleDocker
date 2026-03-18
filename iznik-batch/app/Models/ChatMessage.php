<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ChatMessage extends Model
{
    protected $table = 'chat_messages';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    public const TYPE_DEFAULT = 'Default';
    public const TYPE_SYSTEM = 'System';
    public const TYPE_MODMAIL = 'ModMail';
    public const TYPE_INTERESTED = 'Interested';
    public const TYPE_PROMISED = 'Promised';
    public const TYPE_RENEGED = 'Reneged';
    public const TYPE_COMPLETED = 'Completed';
    public const TYPE_IMAGE = 'Image';
    public const TYPE_ADDRESS = 'Address';
    public const TYPE_NUDGE = 'Nudge';
    public const TYPE_REMINDER = 'Reminder';
    public const TYPE_REPORTEDUSER = 'ReportedUser';

    // Review reason values (reportreason column).
    public const REVIEW_USER = 'User';

    protected $casts = [
        'date' => 'datetime',
        'seenbyall' => 'boolean',
        'mailedtoall' => 'boolean',
        'reviewrequired' => 'boolean',
        'reviewrejected' => 'boolean',
        'replyexpected' => 'boolean',
        'replyreceived' => 'boolean',
        'processingrequired' => 'boolean',
        'processingsuccessful' => 'boolean',
        'confirmrequired' => 'boolean',
        'deleted' => 'boolean',
        'platform' => 'boolean',
    ];

    /**
     * Get the chat room.
     */
    public function chatRoom(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'chatid');
    }

    /**
     * Get the sender.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }

    /**
     * Get the referenced message.
     */
    public function refMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'refmsgid');
    }

    /**
     * Get the reviewer.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewedby');
    }

    /**
     * Get the image if this is an image message.
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(ChatImage::class, 'imageid');
    }

    /**
     * Get images attached to this message.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ChatImage::class, 'chatmsgid');
    }

    /**
     * Scope to visible messages (not review-rejected).
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('reviewrejected', 0)
            ->where('reviewrequired', 0)
            ->where('processingsuccessful', 1);
    }

    /**
     * Scope to messages requiring review.
     */
    public function scopeRequiringReview(Builder $query): Builder
    {
        return $query->where('reviewrequired', 1);
    }

    /**
     * Scope to messages not seen by all.
     */
    public function scopeUnseen(Builder $query): Builder
    {
        return $query->where('seenbyall', 0);
    }

    /**
     * Scope to messages not mailed to all.
     */
    public function scopeUnmailed(Builder $query): Builder
    {
        return $query->where('mailedtoall', 0);
    }

    /**
     * Scope to messages expecting a reply.
     */
    public function scopeExpectingReply(Builder $query): Builder
    {
        return $query->where('replyexpected', 1)
            ->where('replyreceived', 0);
    }

    /**
     * Scope to recent messages.
     */
    public function scopeRecent(Builder $query, int $days = 31): Builder
    {
        return $query->where('date', '>=', now()->subDays($days));
    }

    /**
     * Check if this message is visible to users.
     */
    public function isVisible(): bool
    {
        return !$this->reviewrejected
            && !$this->reviewrequired
            && $this->processingsuccessful;
    }

    /**
     * Check if this is a system message.
     */
    public function isSystemMessage(): bool
    {
        return $this->type === self::TYPE_SYSTEM;
    }

    /**
     * Check if this message was sent from the platform (not email).
     */
    public function isFromPlatform(): bool
    {
        return (bool) $this->platform;
    }

    /**
     * Return per-group counts of chat messages pending review for the given moderator.
     *
     * Ported from iznik-server/include/chat/ChatMessage.php::getReviewCountByGroup().
     *
     * @param User|null $me   The moderator. NULL returns an empty array.
     * @param bool      $other When TRUE, count held messages instead of unreviewed ones.
     * @return array<array{groupid: int, count: int}>
     */
    public function getReviewCountByGroup(?User $me, bool $other = false): array
    {
        $showcounts = [];

        if ($me) {
            // See getMessagesForReview for logic comments.
            $widerreview = $me->widerReview();
            $wideq = '';

            if ($widerreview) {
                // We want all messages for review on groups which are also enrolled in this scheme
                $wideq = " AND JSON_EXTRACT(groups.settings, '$.widerchatreview') = 1 ";
            }

            $allmods = $me->getModeratorships();
            $groupids = [];

            foreach ($allmods as $mod) {
                if ($me->activeModForGroup($mod)) {
                    $groupids[] = $mod;
                }
            }

            // If the user has no moderator groups, they can't review any messages.
            if (empty($groupids)) {
                return $showcounts;
            }

            $groupq1 = "AND memberships.groupid IN (" . implode(',', $groupids) . ")";
            $groupq2 = "AND m2.groupid IN (" . implode(',', $groupids) . ") ";

            $holdq = $other ? "AND chat_messages_held.userid IS NOT NULL" : "AND chat_messages_held.userid IS NULL";

            if ($widerreview || count($groupids)) {
                // We want the messages for review for any group where we are a mod and the recipient of the chat message
                // is a member. Put a backstop time on it to avoid getting too many or an inefficient query.
                $mysqltime = date("Y-m-d", strtotime("Midnight 31 days ago"));

                $sql = "SELECT chat_messages.id, memberships.groupid FROM chat_messages
    LEFT JOIN chat_messages_held ON chat_messages_held.msgid = chat_messages.id
    INNER JOIN chat_rooms ON reviewrequired = 1 AND reviewrejected = 0 AND chat_rooms.id = chat_messages.chatid
    INNER JOIN memberships ON memberships.userid = (CASE WHEN chat_messages.userid = chat_rooms.user1 THEN chat_rooms.user2 ELSE chat_rooms.user1 END) $groupq1
    INNER JOIN `groups` ON memberships.groupid = groups.id AND groups.type = ? WHERE chat_messages.date > '$mysqltime' $holdq
    UNION
    SELECT chat_messages.id, m2.groupid FROM chat_messages
    LEFT JOIN chat_messages_held ON chat_messages_held.msgid = chat_messages.id
    INNER JOIN chat_rooms ON reviewrequired = 1 AND reviewrejected = 0 AND chat_rooms.id = chat_messages.chatid
    LEFT JOIN memberships m1 ON m1.userid = (CASE WHEN chat_messages.userid = chat_rooms.user1 THEN chat_rooms.user2 ELSE chat_rooms.user1 END)
    LEFT JOIN `groups` ON m1.groupid = groups.id AND groups.type = ?
    INNER JOIN memberships m2 ON m2.userid = chat_messages.userid $groupq2
    WHERE chat_messages.date > '$mysqltime' AND m1.id IS NULL $holdq";
                $params = [
                    Group::TYPE_FREEGLE,
                    Group::TYPE_FREEGLE,
                ];

                if ($wideq && $other) {
                    $sql .= " UNION
    SELECT chat_messages.id, memberships.groupid FROM chat_messages
    INNER JOIN chat_rooms ON reviewrequired = 1 AND reviewrejected = 0 AND chat_rooms.id = chat_messages.chatid
    LEFT JOIN chat_messages_held ON chat_messages.id = chat_messages_held.msgid
    INNER JOIN memberships ON memberships.userid = (CASE WHEN chat_messages.userid = chat_rooms.user1 THEN chat_rooms.user2 ELSE chat_rooms.user1 END)
    INNER JOIN `groups` ON memberships.groupid = groups.id AND groups.type = ? WHERE chat_messages.date > '$mysqltime' $wideq AND chat_messages_held.id IS NULL
    AND chat_messages.reportreason NOT IN (?)";
                    $params[] = Group::TYPE_FREEGLE;
                    $params[] = self::REVIEW_USER;
                }

                $sql .= "    ORDER BY groupid;";

                $counts = DB::select($sql, $params);

                // The same message might appear in the query results multiple times if the recipient is on multiple
                // groups that we mod. We only want to count it once. The order here matches that in
                // ChatRoom::getMessagesForReview.
                $showcounts = [];
                $usedmsgs = [];
                $groupids = [];

                foreach ($counts as $count) {
                    $usedmsgs[$count->id] = $count->groupid;
                    $groupids[$count->groupid] = $count->groupid;
                }

                foreach ($groupids as $groupid) {
                    $count = 0;

                    foreach ($usedmsgs as $usedmsg => $msggrp) {
                        if ($msggrp == $groupid) {
                            $count++;
                        }
                    }

                    $showcounts[] = [
                        'groupid' => $groupid,
                        'count' => $count,
                    ];
                }
            }
        }

        return $showcounts;
    }
}
