<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageGroup extends Model
{
    protected $table = 'messages_groups';
    protected $primaryKey = 'msgid';
    public $incrementing = FALSE;
    protected $guarded = [];
    public $timestamps = FALSE;

    public const COLLECTION_INCOMING = 'Incoming';
    public const COLLECTION_PENDING = 'Pending';
    public const COLLECTION_APPROVED = 'Approved';
    public const COLLECTION_SPAM = 'Spam';
    public const COLLECTION_QUEUED_YAHOO = 'QueuedYahooUser';
    public const COLLECTION_REJECTED = 'Rejected';
    public const COLLECTION_QUEUED_USER = 'QueuedUser';

    protected $casts = [
        'arrival' => 'datetime',
        'lastautopostwarning' => 'datetime',
        'lastchaseup' => 'datetime',
        'approvedat' => 'datetime',
        'rejectedat' => 'datetime',
        'deleted' => 'boolean',
        'senttoyahoo' => 'boolean',
        'autoreposts' => 'integer',
    ];

    /**
     * Get the message.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'msgid');
    }

    /**
     * Get the group.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupid');
    }

    /**
     * Get the approver.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Scope to approved messages.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('collection', self::COLLECTION_APPROVED);
    }

    /**
     * Scope to pending messages.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('collection', self::COLLECTION_PENDING);
    }

    /**
     * Scope to non-deleted messages.
     */
    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where('deleted', 0);
    }

    /**
     * Check if this message is approved on this group.
     */
    public function isApproved(): bool
    {
        return $this->collection === self::COLLECTION_APPROVED;
    }
}
