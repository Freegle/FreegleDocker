<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * Get user's email tracking records.
     */
    public function emailTracking(): HasMany
    {
        return $this->hasMany(EmailTracking::class, 'userid');
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

    /**
     * Get user's last known location.
     */
    public function lastLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'lastlocation');
    }

    /**
     * Get user's first name for personalization.
     */
    public function getFirstNameAttribute(): ?string
    {
        return $this->attributes['firstname'] ?? NULL;
    }

    /**
     * Check if this user is from TrashNothing.
     * TN users have email addresses ending in @user.trashnothing.com
     */
    public function isTN(): bool
    {
        $email = $this->email_preferred;
        return $email && str_ends_with($email, '@user.trashnothing.com');
    }

    /**
     * Check if a notification type is enabled for this user.
     *
     * @param string $type The notification type (email, emailmine, push)
     * @param int|null $groupId Optional group ID for mod-specific checks
     */
    public function notifsOn(string $type, ?int $groupId = NULL): bool
    {
        // Default values for notification types.
        $defaults = [
            'email' => TRUE,
            'emailmine' => FALSE,
            'push' => TRUE,
        ];

        $settings = $this->settings ?? [];
        $notifs = $settings['notifications'] ?? [];

        $result = isset($notifs[$type]) ? (bool) $notifs[$type] : ($defaults[$type] ?? TRUE);

        // For group-specific checks, verify user is an active mod.
        if ($result && $groupId) {
            $result = $this->isModeratorOf($groupId);
        }

        return $result;
    }

    /**
     * Notification type constants matching iznik-server.
     */
    public const NOTIFS_EMAIL = 'email';
    public const NOTIFS_EMAIL_MINE = 'emailmine';
    public const NOTIFS_PUSH = 'push';

    /**
     * Get user's latitude and longitude from their last location.
     *
     * @return array [lat, lng] or [null, null] if not available
     */
    public function getLatLng(): array
    {
        $location = $this->lastLocation;
        if (!$location) {
            return [NULL, NULL];
        }

        // Locations have a geometry column with a POINT.
        if ($location->lat && $location->lng) {
            return [$location->lat, $location->lng];
        }

        return [NULL, NULL];
    }

    /**
     * Get job ads for this user based on their location.
     *
     * @return array ['jobs' => Collection, 'location' => string|null]
     */
    public function getJobAds(): array
    {
        [$lat, $lng] = $this->getLatLng();

        if (!$lat || !$lng) {
            return [
                'jobs' => collect(),
                'location' => NULL,
            ];
        }

        $jobs = Job::nearLocation($lat, $lng, 4);

        return [
            'jobs' => $jobs,
            'location' => NULL,  // Not currently used.
        ];
    }

    /**
     * Get the user's profile image URL.
     *
     * Profile images are stored in users_images table with the image ID used in the URL.
     * Format: https://{IMAGE_DOMAIN}/tuimg_{image_id}.jpg (thumbnail)
     *
     * @param bool $thumbnail Whether to get thumbnail (tuimg_) or full size (uimg_)
     * @return string|null The profile image URL or null if no profile image
     */
    public function getProfileImageUrl(bool $thumbnail = TRUE): ?string
    {
        // Find the user's default profile image.
        $profileImage = \DB::table('users_images')
            ->where('userid', $this->id)
            ->where('default', 1)
            ->first(['id', 'url']);

        if (!$profileImage) {
            return NULL;
        }

        // If there's an external URL, use it directly.
        if (!empty($profileImage->url)) {
            return $profileImage->url;
        }

        // Build URL from image domain.
        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
        $prefix = $thumbnail ? 'tuimg_' : 'uimg_';

        return "{$imagesDomain}/{$prefix}{$profileImage->id}.jpg";
    }

    /**
     * Login type for one-click unsubscribe links.
     */
    public const LOGIN_LINK = 'Link';

    /**
     * Get user's key for one-click unsubscribe/login links.
     * Creates one if it doesn't exist.
     *
     * @return string The user key
     */
    public function getUserKey(): string
    {
        // Check for existing LOGIN_LINK credential.
        $login = \DB::table('users_logins')
            ->where('userid', $this->id)
            ->where('type', self::LOGIN_LINK)
            ->first(['credentials']);

        if ($login && $login->credentials) {
            return $login->credentials;
        }

        // Create a new key.
        $key = bin2hex(random_bytes(16));

        \DB::table('users_logins')->insert([
            'userid' => $this->id,
            'type' => self::LOGIN_LINK,
            'credentials' => $key,
        ]);

        return $key;
    }

    /**
     * Generate List-Unsubscribe header value for RFC 8058 one-click unsubscribe.
     *
     * @return string The unsubscribe URL in angle brackets
     */
    public function listUnsubscribe(): string
    {
        $key = $this->getUserKey();
        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');

        return "<{$userSite}/one-click-unsubscribe/{$this->id}/{$key}>";
    }
}
