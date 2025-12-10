<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatRoster extends Model
{
    protected $table = 'chat_roster';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    public const STATUS_ONLINE = 'Online';
    public const STATUS_AWAY = 'Away';
    public const STATUS_OFFLINE = 'Offline';
    public const STATUS_CLOSED = 'Closed';
    public const STATUS_BLOCKED = 'Blocked';

    protected $casts = [
        'date' => 'datetime',
        'lastemailed' => 'datetime',
        'lasttype' => 'datetime',
    ];

    /**
     * Get the chat room.
     */
    public function chatRoom(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'chatid');
    }

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }

    /**
     * Scope to online users.
     */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ONLINE);
    }

    /**
     * Scope to users not blocked.
     */
    public function scopeNotBlocked(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_BLOCKED);
    }

    /**
     * Scope to users with unread messages.
     */
    public function scopeWithUnreadMessages(Builder $query): Builder
    {
        return $query->whereHas('chatRoom.messages', function ($q) {
            $q->whereColumn('chat_messages.id', '>', 'chat_roster.lastmsgseen');
        });
    }

    /**
     * Check if user has been emailed about new messages.
     */
    public function needsEmail(int $latestMessageId): bool
    {
        return $this->lastmsgemailed === NULL || $this->lastmsgemailed < $latestMessageId;
    }
}
