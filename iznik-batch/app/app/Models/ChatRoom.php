<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatRoom extends Model
{
    protected $table = 'chat_rooms';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    public const TYPE_MOD2MOD = 'Mod2Mod';
    public const TYPE_USER2MOD = 'User2Mod';
    public const TYPE_USER2USER = 'User2User';
    public const TYPE_GROUP = 'Group';

    protected $casts = [
        'created' => 'datetime',
        'latestmessage' => 'datetime',
        'flaggedspam' => 'boolean',
        'msgvalid' => 'integer',
        'msginvalid' => 'integer',
    ];

    /**
     * Get user1.
     */
    public function user1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user1');
    }

    /**
     * Get user2.
     */
    public function user2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user2');
    }

    /**
     * Get the group (for mod chats).
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupid');
    }

    /**
     * Get chat messages.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chatid');
    }

    /**
     * Get chat roster entries.
     */
    public function roster(): HasMany
    {
        return $this->hasMany(ChatRoster::class, 'chatid');
    }

    /**
     * Get chat images.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ChatImage::class, 'chatid');
    }

    /**
     * Scope to user2user chats.
     */
    public function scopeUser2User(Builder $query): Builder
    {
        return $query->where('chattype', self::TYPE_USER2USER);
    }

    /**
     * Scope to user2mod chats.
     */
    public function scopeUser2Mod(Builder $query): Builder
    {
        return $query->where('chattype', self::TYPE_USER2MOD);
    }

    /**
     * Scope to mod2mod chats.
     */
    public function scopeMod2Mod(Builder $query): Builder
    {
        return $query->where('chattype', self::TYPE_MOD2MOD);
    }

    /**
     * Scope to chats not flagged as spam.
     */
    public function scopeNotFlaggedSpam(Builder $query): Builder
    {
        return $query->where('flaggedspam', 0);
    }

    /**
     * Scope to recent chats.
     */
    public function scopeRecent(Builder $query, int $days = 31): Builder
    {
        return $query->where('latestmessage', '>=', now()->subDays($days));
    }

    /**
     * Get the other user in a user2user chat.
     */
    public function getOtherUser(int $userId): ?User
    {
        if ($this->getAttributeValue('user1') === $userId) {
            return $this->user2()->first();
        }
        if ($this->getAttributeValue('user2') === $userId) {
            return $this->user1()->first();
        }
        return NULL;
    }

    /**
     * Check if chat is a DM (user2user).
     */
    public function isDm(): bool
    {
        return $this->chattype === self::TYPE_USER2USER;
    }

    /**
     * Check if chat involves a specific user.
     */
    public function involvesUser(int $userId): bool
    {
        return $this->getAttributeValue('user1') === $userId || $this->getAttributeValue('user2') === $userId;
    }
}
