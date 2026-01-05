<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    protected $table = 'memberships';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    public const ROLE_MEMBER = 'Member';
    public const ROLE_MODERATOR = 'Moderator';
    public const ROLE_OWNER = 'Owner';

    public const COLLECTION_APPROVED = 'Approved';
    public const COLLECTION_PENDING = 'Pending';
    public const COLLECTION_BANNED = 'Banned';

    public const EMAIL_FREQUENCY_NEVER = 0;
    public const EMAIL_FREQUENCY_IMMEDIATE = -1;
    public const EMAIL_FREQUENCY_HOURLY = 1;
    public const EMAIL_FREQUENCY_DAILY = 24;

    // Aliases for digest context.
    public const EMAIL_DIGEST_IMMEDIATE = self::EMAIL_FREQUENCY_IMMEDIATE;
    public const EMAIL_DIGEST_HOURLY = self::EMAIL_FREQUENCY_HOURLY;
    public const EMAIL_DIGEST_DAILY = self::EMAIL_FREQUENCY_DAILY;

    protected $casts = [
        'added' => 'datetime',
        'settings' => 'array',
        'eventsallowed' => 'boolean',
        'emailfrequency' => 'integer',
        'reviewrequestedat' => 'datetime',
        'reviewedat' => 'datetime',
    ];

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }

    /**
     * Get the group.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupid');
    }

    /**
     * Get the mod config.
     */
    public function config(): BelongsTo
    {
        return $this->belongsTo(ModConfig::class, 'configid');
    }

    /**
     * Scope to approved members.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('collection', self::COLLECTION_APPROVED);
    }

    /**
     * Scope to pending members.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('collection', self::COLLECTION_PENDING);
    }

    /**
     * Scope to moderators.
     */
    public function scopeModerators(Builder $query): Builder
    {
        return $query->whereIn('role', [self::ROLE_MODERATOR, self::ROLE_OWNER]);
    }

    /**
     * Scope to owners.
     */
    public function scopeOwners(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_OWNER);
    }

    /**
     * Scope by email frequency.
     */
    public function scopeWithEmailFrequency(Builder $query, int $frequency): Builder
    {
        return $query->where('emailfrequency', $frequency);
    }

    /**
     * Scope to members who want digests at a specific frequency.
     */
    public function scopeDigestSubscribers(Builder $query, int $frequency): Builder
    {
        return $query->approved()
            ->withEmailFrequency($frequency)
            ->where('emailfrequency', '>', 0);
    }

    /**
     * Check if this is a moderator membership.
     */
    public function isModerator(): bool
    {
        return in_array($this->role, [self::ROLE_MODERATOR, self::ROLE_OWNER]);
    }

    /**
     * Check if this is an owner membership.
     */
    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    /**
     * Get a setting value.
     */
    public function getSetting(string $key, mixed $default = NULL): mixed
    {
        $settings = $this->settings ?? [];
        return $settings[$key] ?? $default;
    }

    /**
     * Check if this moderator is "active" (not a backup mod).
     *
     * Backup mods have settings['active'] = false. Active mods either have
     * no settings, no 'active' key, or settings['active'] = true.
     *
     * Members are always considered active (backup status only applies to mods).
     */
    public function isActiveMod(): bool
    {
        // Members don't have backup status - they're always "active".
        if (!$this->isModerator()) {
            return TRUE;
        }

        // No settings means active by default.
        if ($this->settings === NULL) {
            return TRUE;
        }

        // No 'active' key means active by default.
        if (!array_key_exists('active', $this->settings)) {
            return TRUE;
        }

        // Explicitly check the active flag.
        return (bool) $this->settings['active'];
    }

    /**
     * Scope to active moderators (not backup mods).
     */
    public function scopeActiveModerators(Builder $query): Builder
    {
        return $query->whereIn('role', [self::ROLE_MODERATOR, self::ROLE_OWNER])
            ->where(function ($q) {
                $q->whereNull('settings')
                    ->orWhereRaw("JSON_EXTRACT(settings, '$.active') IS NULL")
                    ->orWhereRaw("JSON_EXTRACT(settings, '$.active') = true")
                    ->orWhereRaw("JSON_EXTRACT(settings, '$.active') = 1");
            });
    }
}
