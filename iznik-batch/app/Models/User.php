<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Model
{
    protected $table = 'users';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    protected $casts = [
        'added' => 'datetime',
        'lastaccess' => 'datetime',
        'deleted' => 'datetime',
        'settings' => 'array',
    ];

    /**
     * Get user's email addresses.
     */
    public function emails(): HasMany
    {
        return $this->hasMany(UserEmail::class, 'userid');
    }

    /**
     * Get user's memberships.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'userid');
    }

    /**
     * Get chat rooms where user is user1.
     */
    public function chatRoomsAsUser1(): HasMany
    {
        return $this->hasMany(ChatRoom::class, 'user1');
    }

    /**
     * Get chat rooms where user is user2.
     */
    public function chatRoomsAsUser2(): HasMany
    {
        return $this->hasMany(ChatRoom::class, 'user2');
    }

    /**
     * Get user's donations.
     */
    public function donations(): HasMany
    {
        return $this->hasMany(UserDonation::class, 'userid');
    }

    /**
     * Get user's gift aid declaration.
     */
    public function giftAid(): HasOne
    {
        return $this->hasOne(GiftAid::class, 'userid');
    }

    /**
     * Get user's messages.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'fromuser');
    }

    /**
     * Get user's notifications.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'userid');
    }

    /**
     * Get user's chat messages.
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'userid');
    }

    /**
     * Get the user's preferred email address.
     */
    public function getEmailPreferredAttribute(): ?string
    {
        return $this->emails()
            ->orderByRaw('preferred DESC, validated DESC')
            ->value('email');
    }

    /**
     * Get full name or display name.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->fullname) {
            return $this->fullname;
        }
        if ($this->firstname || $this->lastname) {
            return trim("{$this->firstname} {$this->lastname}");
        }
        return 'Freegle User';
    }

    /**
     * Check if user is a moderator of any group.
     */
    public function isModerator(): bool
    {
        return $this->memberships()
            ->whereIn('role', ['Moderator', 'Owner'])
            ->exists();
    }

    /**
     * Check if user is a moderator of a specific group.
     */
    public function isModeratorOf(int $groupId): bool
    {
        return $this->memberships()
            ->where('groupid', $groupId)
            ->whereIn('role', ['Moderator', 'Owner'])
            ->exists();
    }
}
