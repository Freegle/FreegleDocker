<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $table = 'messages';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    public const TYPE_OFFER = 'Offer';
    public const TYPE_WANTED = 'Wanted';

    public const OUTCOME_TAKEN = 'Taken';
    public const OUTCOME_RECEIVED = 'Received';
    public const OUTCOME_WITHDRAWN = 'Withdrawn';
    public const OUTCOME_EXPIRED = 'Expired';

    protected $casts = [
        'arrival' => 'datetime',
        'date' => 'datetime',
        'deadline' => 'date',
        'deleted' => 'datetime',
        'lat' => 'decimal:6',
        'lng' => 'decimal:6',
        'availablenow' => 'boolean',
    ];

    /**
     * Get the message's groups.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'messages_groups', 'msgid', 'groupid')
            ->withPivot(['collection', 'arrival', 'approved_by', 'deleted']);
    }

    /**
     * Get the message's outcomes.
     */
    public function outcomes(): HasMany
    {
        return $this->hasMany(MessageOutcome::class, 'msgid');
    }

    /**
     * Get the message's attachments.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class, 'msgid');
    }

    /**
     * Get the user who posted this message.
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fromuser');
    }

    /**
     * Get the location.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'locationid');
    }

    /**
     * Get message likes/views.
     */
    public function likes(): HasMany
    {
        return $this->hasMany(MessageLike::class, 'msgid');
    }

    /**
     * Get message history.
     */
    public function history(): HasMany
    {
        return $this->hasMany(MessageHistory::class, 'msgid');
    }

    /**
     * Get chat messages referencing this message.
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'refmsgid');
    }

    /**
     * Scope to approved messages.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereHas('groups', function ($q) {
            $q->wherePivot('collection', 'Approved');
        });
    }

    /**
     * Scope to non-deleted messages.
     */
    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->whereNull('deleted');
    }

    /**
     * Scope to messages with location data.
     */
    public function scopeWithLocation(Builder $query): Builder
    {
        return $query->whereNotNull('lat')->whereNotNull('lng');
    }

    /**
     * Scope to offers.
     */
    public function scopeOffers(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_OFFER);
    }

    /**
     * Scope to wanted posts.
     */
    public function scopeWanted(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_WANTED);
    }

    /**
     * Scope to messages that have reached their deadline.
     */
    public function scopeDeadlineReached(Builder $query): Builder
    {
        return $query->whereNotNull('deadline')
            ->whereDate('deadline', '<', now());
    }

    /**
     * Scope to recent messages.
     */
    public function scopeRecent(Builder $query, int $days = 31): Builder
    {
        return $query->where('arrival', '>=', now()->subDays($days));
    }

    /**
     * Check if message is an offer.
     */
    public function isOffer(): bool
    {
        return $this->type === self::TYPE_OFFER;
    }

    /**
     * Check if message is a wanted post.
     */
    public function isWanted(): bool
    {
        return $this->type === self::TYPE_WANTED;
    }

    /**
     * Check if message has been taken/received.
     */
    public function hasSuccessfulOutcome(): bool
    {
        return $this->outcomes()
            ->whereIn('outcome', [self::OUTCOME_TAKEN, self::OUTCOME_RECEIVED])
            ->exists();
    }
}
