<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class User extends Model
{
    protected $table = 'users';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    // Group membership roles.
    public const ROLE_NONMEMBER = 'Non-member';
    public const ROLE_MEMBER = 'Member';
    public const ROLE_MODERATOR = 'Moderator';
    public const ROLE_OWNER = 'Owner';

    // System-wide roles.
    public const SYSTEMROLE_USER = 'User';
    public const SYSTEMROLE_MODERATOR = 'Moderator';
    public const SYSTEMROLE_SUPPORT = 'Support';
    public const SYSTEMROLE_ADMIN = 'Admin';

    // Login types.
    public const LOGIN_NATIVE = 'Native';

    // Gift aid period weights for merge comparison (lower = more favourable).
    public const GIFTAID_PERIOD_PAST_4_YEARS_AND_FUTURE = 'Past4YearsAndFuture';
    public const GIFTAID_PERIOD_SINCE = 'Since';
    public const GIFTAID_PERIOD_FUTURE = 'Future';
    public const GIFTAID_PERIOD_THIS = 'This';
    public const GIFTAID_PERIOD_DECLINED = 'Declined';

    protected $casts = [
        'added' => 'datetime',
        'lastaccess' => 'datetime',
        'deleted' => 'datetime',
        'forgotten' => 'datetime',
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
     *
     * Excludes internal Freegle domains (users.ilovefreegle.org, groups.ilovefreegle.org, etc.)
     * and Yahoo Groups addresses, matching iznik-server's getEmailPreferred() behavior.
     */
    public function getEmailPreferredAttribute(): ?string
    {
        $emails = $this->emails()
            ->orderByRaw('preferred DESC, validated DESC')
            ->pluck('email');

        foreach ($emails as $email) {
            if (!self::isInternalEmail($email)) {
                return $email;
            }
        }

        return NULL;
    }

    /**
     * Check if an email address is an internal Freegle domain that shouldn't receive external mail.
     *
     * Matches iznik-server's Mail::ourDomain() + GROUP_DOMAIN + yahoogroups filtering.
     */
    public static function isInternalEmail(string $email): bool
    {
        $email = strtolower($email);

        // Check against internal domains (users.ilovefreegle.org, groups.ilovefreegle.org, etc.)
        $internalDomains = config('freegle.mail.internal_domains', [
            'users.ilovefreegle.org',
            'groups.ilovefreegle.org',
            'direct.ilovefreegle.org',
            'republisher.freegle.in',
        ]);

        foreach ($internalDomains as $domain) {
            if (str_contains($email, '@' . strtolower($domain))) {
                return TRUE;
            }
        }

        // Check against excluded domain patterns (e.g., @yahoogroups.)
        $excludedPatterns = config('freegle.mail.excluded_domain_patterns', [
            '@yahoogroups.',
        ]);

        foreach ($excludedPatterns as $pattern) {
            if (str_contains($email, strtolower($pattern))) {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Get full name or display name.
     * Strips the "-gXXX" suffix from TrashNothing user names.
     */
    public function getDisplayNameAttribute(): string
    {
        $name = null;

        if ($this->fullname) {
            $name = $this->fullname;
        } elseif ($this->firstname || $this->lastname) {
            $name = trim("{$this->firstname} {$this->lastname}");
        }

        if (!$name) {
            return 'Freegle User';
        }

        // Strip the "-gXXX" suffix from TrashNothing user names.
        return self::removeTNGroup($name);
    }

    /**
     * Remove TrashNothing group suffix from a name.
     * TN users often have names like "Alice-g298" - we hide the "-gXXX" part.
     */
    public static function removeTNGroup(string $name): string
    {
        return preg_replace('/^([\s\S]+?)-g[0-9]+$/', '$1', $name);
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
     * Simple mail setting constants matching iznik-server.
     *
     * SIMPLE_MAIL_NONE: Completely disables all emails.
     * SIMPLE_MAIL_BASIC: Daily digest, chat replies only.
     * SIMPLE_MAIL_FULL: Immediate notifications, all email types.
     */
    public const SIMPLE_MAIL_NONE = 'None';
    public const SIMPLE_MAIL_BASIC = 'Basic';
    public const SIMPLE_MAIL_FULL = 'Full';

    /**
     * Get the user's simple mail setting.
     *
     * @return string|null One of SIMPLE_MAIL_* constants or null if not set
     */
    public function getSimpleMail(): ?string
    {
        $settings = $this->settings ?? [];
        return $settings['simplemail'] ?? null;
    }

    /**
     * Determine if user wants digest emails based on their simplemail setting.
     * Falls back to checking per-group emailfrequency if simplemail is not set.
     *
     * @return bool True if user should receive digest emails
     */
    public function wantsDigestEmails(): bool
    {
        $simpleMail = $this->getSimpleMail();

        if ($simpleMail !== null) {
            // User has a simplemail preference.
            return $simpleMail !== self::SIMPLE_MAIL_NONE;
        }

        // Fall back to checking if any membership has a non-zero email frequency.
        return $this->memberships()
            ->where('emailfrequency', '!=', 0)
            ->exists();
    }

    /**
     * Determine if user wants immediate notifications based on their simplemail setting.
     *
     * @return bool True if user should receive immediate notifications
     */
    public function wantsImmediateNotifications(): bool
    {
        $simpleMail = $this->getSimpleMail();

        if ($simpleMail !== null) {
            return $simpleMail === self::SIMPLE_MAIL_FULL;
        }

        // Fall back to checking if any membership has immediate frequency (-1).
        return $this->memberships()
            ->where('emailfrequency', -1)
            ->exists();
    }

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
        // Find the user's profile image, preferring the default one.
        $profileImage = \DB::table('users_images')
            ->where('userid', $this->id)
            ->orderByDesc('default')
            ->orderBy('id')
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
        return "<{$this->listUnsubscribeUrl()}>";
    }

    /**
     * Generate the one-click unsubscribe URL for use in email links.
     *
     * @return string The unsubscribe URL
     */
    public function listUnsubscribeUrl(): string
    {
        $key = $this->getUserKey();
        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');

        return "{$userSite}/one-click-unsubscribe/{$this->id}/{$key}";
    }

    /**
     * Check if this user allows merging.
     * Users can set canmerge=false in their settings to prevent being merged.
     */
    public function canMerge(): bool
    {
        $settings = $this->settings ?? [];
        return $settings['canmerge'] ?? TRUE;
    }

    /**
     * Return the highest privilege group role between two roles.
     * Order: Owner > Moderator > Member > Non-member
     */
    public static function roleMax(string $role1, string $role2): string
    {
        $role = self::ROLE_NONMEMBER;

        if ($role1 === self::ROLE_MEMBER || $role2 === self::ROLE_MEMBER) {
            $role = self::ROLE_MEMBER;
        }

        if ($role1 === self::ROLE_MODERATOR || $role2 === self::ROLE_MODERATOR) {
            $role = self::ROLE_MODERATOR;
        }

        if ($role1 === self::ROLE_OWNER || $role2 === self::ROLE_OWNER) {
            $role = self::ROLE_OWNER;
        }

        return $role;
    }

    /**
     * Return the highest system role between two roles.
     * Order: Admin > Support > Moderator > User
     */
    public static function systemRoleMax(string $role1, string $role2): string
    {
        $role = self::SYSTEMROLE_USER;

        if ($role1 === self::SYSTEMROLE_MODERATOR || $role2 === self::SYSTEMROLE_MODERATOR) {
            $role = self::SYSTEMROLE_MODERATOR;
        }

        if ($role1 === self::SYSTEMROLE_SUPPORT || $role2 === self::SYSTEMROLE_SUPPORT) {
            $role = self::SYSTEMROLE_SUPPORT;
        }

        if ($role1 === self::SYSTEMROLE_ADMIN || $role2 === self::SYSTEMROLE_ADMIN) {
            $role = self::SYSTEMROLE_ADMIN;
        }

        return $role;
    }

    /**
     * Merge two user accounts, consolidating $id2 into $id1.
     *
     * Merges memberships (taking highest role, oldest join date), emails,
     * chat rooms, user attributes, logs, gift aid, and 40+ foreign key tables.
     * The secondary user ($id2) is deleted after a successful merge.
     *
     * Ported from iznik-server/include/user/User.php::merge().
     *
     * @param int $id1 The user ID to keep (merge target)
     * @param int $id2 The user ID to absorb and delete
     * @param string $reason Human-readable reason for the merge
     * @param bool $forceMerge If TRUE, bypass canMerge() checks
     * @param int|null $byUserId The user performing the merge (for logging)
     * @return bool TRUE on success, FALSE on failure or if merge is blocked
     */
    public static function merge(int $id1, int $id2, string $reason, bool $forceMerge = FALSE, ?int $byUserId = NULL): bool
    {
        Log::info("Merge {$id2} into {$id1}, {$reason}");

        if ($id1 === $id2) {
            return FALSE;
        }

        $u1 = self::find($id1);
        $u2 = self::find($id2);

        if (!$u1 || !$u2) {
            return FALSE;
        }

        if (!$forceMerge && (!$u1->canMerge() || !$u2->canMerge())) {
            return FALSE;
        }

        try {

            # We want to merge two users.  At present we just merge the memberships, comments, emails and logs; we don't try to
            # merge any conflicting settings.
            #
            # Both users might have membership of the same group, including at different levels.
            #
            # A useful query to find foreign key references is of this form:
            #
            # USE information_schema; SELECT * FROM KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'iznik' AND REFERENCED_TABLE_NAME = 'users';
            #
            # We avoid too much use of quoting in preQuery/preExec because quoted numbers can't use a numeric index and therefore
            # perform slowly.

            DB::beginTransaction();

            // --- Merge memberships ---
            $id2Memberships = DB::table('memberships')->where('userid', $id2)->get();

            # Merge the top-level memberships
            foreach ($id2Memberships as $id2Memb) {
                $id1Memb = DB::table('memberships')
                    ->where('userid', $id1)
                    ->where('groupid', $id2Memb->groupid)
                    ->first();

                if (!$id1Memb) {
                    // id1 is not already a member — just reassign the membership.
                    DB::table('memberships')
                        ->where('userid', $id2)
                        ->where('groupid', $id2Memb->groupid)
                        ->update(['userid' => $id1]);
                } else {
                    // Both are members — merge: take highest role, oldest date, non-NULL attributes.
                    $role = self::roleMax($id1Memb->role, $id2Memb->role);

                    if ($role !== $id1Memb->role) {
                        DB::table('memberships')
                            ->where('userid', $id1)
                            ->where('groupid', $id2Memb->groupid)
                            ->update(['role' => $role]);
                    }

                    // Keep the older added date.
                    $date = min(strtotime($id1Memb->added), strtotime($id2Memb->added));
                    DB::table('memberships')
                        ->where('userid', $id1)
                        ->where('groupid', $id2Memb->groupid)
                        ->update(['added' => date('Y-m-d H:i:s', $date)]);

                    // Take non-NULL values from id2 for these attributes.
                    foreach (['configid', 'settings', 'heldby'] as $key) {
                        if ($id2Memb->$key !== NULL) {
                            DB::table('memberships')
                                ->where('userid', $id1)
                                ->where('groupid', $id2Memb->groupid)
                                ->update([$key => $id2Memb->$key]);
                        }
                    }

                    // Remove the now-redundant id2 membership.
                    DB::table('memberships')
                        ->where('userid', $id2)
                        ->where('groupid', $id2Memb->groupid)
                        ->delete();
                }
            }

            // --- Merge emails ---
            // Both might have a primary (preferred) address; id1 wins.
            $primary = NULL;
            $foundPrim = FALSE;

            $id2PrimaryEmail = DB::table('users_emails')
                ->where('userid', $id2)
                ->where('preferred', 1)
                ->first();

            if ($id2PrimaryEmail) {
                $primary = $id2PrimaryEmail->id;
                $foundPrim = TRUE;
            }

            $id1PrimaryEmail = DB::table('users_emails')
                ->where('userid', $id1)
                ->where('preferred', 1)
                ->first();

            if ($id1PrimaryEmail) {
                $primary = $id1PrimaryEmail->id;
                $foundPrim = TRUE;
            }

            if (!$foundPrim) {
                // No primary — use whatever getEmailPreferred would choose for id1.
                $preferredEmail = $u1->email_preferred;
                if ($preferredEmail) {
                    $emailRow = DB::table('users_emails')
                        ->where('email', $preferredEmail)
                        ->first();
                    if ($emailRow) {
                        $primary = $emailRow->id;
                    }
                }
            }

            // Move all id2 emails to id1, clearing preferred.
            DB::table('users_emails')
                ->where('userid', $id2)
                ->update(['userid' => $id1, 'preferred' => 0]);

            if ($primary) {
                DB::table('users_emails')
                    ->where('id', $primary)
                    ->update(['preferred' => 1]);
            }

            // --- Merge foreign keys (less critical — use IGNORE equivalent) ---
            // For tables with unique constraints, we delete id2 rows that would conflict.
            $ignoreUpdates = [
                ['table' => 'locations_excluded', 'column' => 'userid'],
                ['table' => 'chat_roster', 'column' => 'userid'],
                ['table' => 'sessions', 'column' => 'userid'],
                ['table' => 'spam_users', 'column' => 'userid'],
                ['table' => 'spam_users', 'column' => 'byuserid'],
                ['table' => 'users_addresses', 'column' => 'userid'],
                ['table' => 'users_donations', 'column' => 'userid'],
                ['table' => 'users_images', 'column' => 'userid'],
                ['table' => 'users_invitations', 'column' => 'userid'],
                ['table' => 'users_nearby', 'column' => 'userid'],
                ['table' => 'users_notifications', 'column' => 'fromuser'],
                ['table' => 'users_notifications', 'column' => 'touser'],
                ['table' => 'users_nudges', 'column' => 'fromuser'],
                ['table' => 'users_nudges', 'column' => 'touser'],
                ['table' => 'users_push_notifications', 'column' => 'userid'],
                ['table' => 'users_requests', 'column' => 'userid'],
                ['table' => 'users_requests', 'column' => 'completedby'],
                ['table' => 'users_searches', 'column' => 'userid'],
                ['table' => 'newsfeed', 'column' => 'userid'],
                ['table' => 'messages_reneged', 'column' => 'userid'],
                ['table' => 'users_stories', 'column' => 'userid'],
                ['table' => 'users_stories_likes', 'column' => 'userid'],
                ['table' => 'users_stories_requested', 'column' => 'userid'],
                ['table' => 'users_thanks', 'column' => 'userid'],
                ['table' => 'modnotifs', 'column' => 'userid'],
                ['table' => 'teams_members', 'column' => 'userid'],
                ['table' => 'users_aboutme', 'column' => 'userid'],
                ['table' => 'ratings', 'column' => 'rater'],
                ['table' => 'ratings', 'column' => 'ratee'],
                ['table' => 'users_replytime', 'column' => 'userid'],
                ['table' => 'messages_promises', 'column' => 'userid'],
                ['table' => 'messages_by', 'column' => 'userid'],
                ['table' => 'trysts', 'column' => 'user1'],
                ['table' => 'trysts', 'column' => 'user2'],
                ['table' => 'isochrones_users', 'column' => 'userid'],
                ['table' => 'microactions', 'column' => 'userid'],
            ];

            foreach ($ignoreUpdates as $upd) {
                // UPDATE IGNORE equivalent: try update, silently skip constraint violations.
                DB::statement(
                    "UPDATE IGNORE `{$upd['table']}` SET `{$upd['column']}` = ? WHERE `{$upd['column']}` = ?",
                    [$id1, $id2]
                );
            }

            // Non-IGNORE updates (no unique constraint conflicts expected).
            DB::table('users_comments')->where('userid', $id2)->update(['userid' => $id1]);
            DB::table('users_comments')->where('byuserid', $id2)->update(['byuserid' => $id1]);
            DB::table('users_logins')->where('userid', $id2)->update(['userid' => $id1]);

            // Update Native login uid to match new userid.
            DB::statement(
                "UPDATE IGNORE users_logins SET uid = ? WHERE userid = ? AND `type` = ?",
                [$id1, $id1, self::LOGIN_NATIVE]
            );

            // --- Handle bans ---
            DB::statement("UPDATE IGNORE users_banned SET userid = ? WHERE userid = ?", [$id1, $id2]);
            DB::statement("UPDATE IGNORE users_banned SET byuser = ? WHERE byuser = ?", [$id1, $id2]);

            // Remove memberships for groups the merged user is banned from.
            $bans = DB::table('users_banned')->where('userid', $id1)->get();
            foreach ($bans as $ban) {
                DB::table('memberships')
                    ->where('userid', $id1)
                    ->where('groupid', $ban->groupid)
                    ->delete();
            }

            // --- Merge chat rooms ---
            $rooms = DB::table('chat_rooms')
                ->where(function ($q) use ($id2) {
                    $q->where('user1', $id2)->orWhere('user2', $id2);
                })
                ->whereIn('chattype', [ChatRoom::TYPE_USER2MOD, ChatRoom::TYPE_USER2USER])
                ->get();

            foreach ($rooms as $room) {
                $existing = NULL;

                if ($room->chattype === ChatRoom::TYPE_USER2MOD) {
                    $existing = DB::table('chat_rooms')
                        ->where('user1', $id1)
                        ->where('groupid', $room->groupid)
                        ->first();
                } elseif ($room->chattype === ChatRoom::TYPE_USER2USER) {
                    $other = ($room->user1 == $id2) ? $room->user2 : $room->user1;
                    $existing = DB::table('chat_rooms')
                        ->where(function ($q) use ($id1, $other) {
                            $q->where(function ($q2) use ($id1, $other) {
                                $q2->where('user1', $id1)->where('user2', $other);
                            })->orWhere(function ($q2) use ($id1, $other) {
                                $q2->where('user2', $id1)->where('user1', $other);
                            });
                        })
                        ->first();
                }

                if ($existing) {
                    // Room already exists for id1 — move messages into it.
                    DB::table('chat_messages')
                        ->where('chatid', $room->id)
                        ->update(['chatid' => $existing->id]);

                    // Keep the latest message timestamp.
                    DB::statement(
                        "UPDATE chat_rooms SET latestmessage = GREATEST(latestmessage, ?) WHERE id = ?",
                        [$room->latestmessage, $existing->id]
                    );
                } else {
                    // No existing room — just reassign user reference.
                    $col = ($room->user1 == $id2) ? 'user1' : 'user2';
                    DB::table('chat_rooms')
                        ->where('id', $room->id)
                        ->update([$col => $id1]);
                }
            }

            // Move all remaining chat messages from id2.
            DB::table('chat_messages')->where('userid', $id2)->update(['userid' => $id1]);

            // --- Merge user attributes (keep non-NULL from id2 if id1 is NULL) ---
            // Refresh models after membership changes.
            $u1->refresh();
            $u2->refresh();

            foreach (['fullname', 'firstname', 'lastname', 'yahooid'] as $att) {
                $id2Value = $u2->$att;
                if ($id2Value === NULL) {
                    continue;
                }

                // Clear id2's attribute first (unique key safety for yahooid).
                DB::table('users')->where('id', $id2)->update([$att => NULL]);

                if ($u1->$att === NULL) {
                    if ($att !== 'fullname') {
                        DB::table('users')
                            ->where('id', $id1)
                            ->whereNull($att)
                            ->update([$att => $id2Value]);
                    } elseif (stripos($id2Value, 'fbuser') === FALSE && stripos($id2Value, '-owner') === FALSE) {
                        // Don't overwrite a name with FBUser or a -owner address.
                        DB::table('users')
                            ->where('id', $id1)
                            ->update([$att => $id2Value]);
                    }
                }

                $u1->refresh();
            }

            // --- Merge logs ---
            DB::table('logs')->where('user', $id2)->update(['user' => $id1]);
            DB::table('logs')->where('byuser', $id2)->update(['byuser' => $id1]);

            // --- Merge messages ---
            DB::table('messages')->where('fromuser', $id2)->update(['fromuser' => $id1]);

            // --- Merge history ---
            DB::table('messages_history')->where('fromuser', $id2)->update(['fromuser' => $id1]);
            DB::table('memberships_history')->where('userid', $id2)->update(['userid' => $id1]);

            // --- Merge system role (take highest) ---
            $u1->refresh();
            $u2->refresh();

            $mergedSystemRole = self::systemRoleMax($u1->systemrole, $u2->systemrole);
            DB::table('users')->where('id', $id1)->update(['systemrole' => $mergedSystemRole]);

            // --- Merge added date (keep oldest) ---
            $earlierAdded = ($u1->added < $u2->added) ? $u1->added : $u2->added;
            DB::table('users')->where('id', $id1)->update([
                'added' => $earlierAdded,
                'lastupdated' => now(),
            ]);

            // --- Merge TN user ID ---
            $tnId1 = $u1->tnuserid;
            $tnId2 = $u2->tnuserid;

            if (!$tnId1 && $tnId2) {
                DB::table('users')->where('id', $id2)->update(['tnuserid' => NULL]);
                DB::table('users')->where('id', $id1)->update(['tnuserid' => $tnId2]);
            }

            // --- Merge gift aid (keep most favourable declaration) ---
            $giftAids = DB::table('giftaid')
                ->whereIn('userid', [$id1, $id2])
                ->orderBy('id')
                ->get();

            if ($giftAids->isNotEmpty()) {
                $weights = [
                    self::GIFTAID_PERIOD_PAST_4_YEARS_AND_FUTURE => 0,
                    self::GIFTAID_PERIOD_SINCE => 1,
                    self::GIFTAID_PERIOD_FUTURE => 2,
                    self::GIFTAID_PERIOD_THIS => 3,
                    self::GIFTAID_PERIOD_DECLINED => 4,
                ];

                $best = NULL;
                foreach ($giftAids as $giftAid) {
                    $weight = $weights[$giftAid->period] ?? 999;
                    $bestWeight = $best ? ($weights[$best->period] ?? 999) : 999;

                    if ($best === NULL || $weight < $bestWeight) {
                        $best = $giftAid;
                    }
                }

                // Delete all except the best.
                foreach ($giftAids as $giftAid) {
                    if ($giftAid->id !== $best->id) {
                        DB::table('giftaid')->where('id', $giftAid->id)->delete();
                    }
                }

                // Assign the best to id1.
                DB::table('giftaid')->where('id', $best->id)->update(['userid' => $id1]);
            }

            // --- Log the merge (before deleting id2) ---
            $mergeText = "Merged {$id2} into {$id1} ({$reason})";

            DB::table('logs')->insert([
                'timestamp' => now(),
                'type' => 'User',
                'subtype' => 'Merged',
                'user' => $id2,
                'byuser' => $byUserId,
                'text' => $mergeText,
            ]);

            DB::table('logs')->insert([
                'timestamp' => now(),
                'type' => 'User',
                'subtype' => 'Merged',
                'user' => $id1,
                'byuser' => $byUserId,
                'text' => $mergeText,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Merge exception: " . $e->getMessage());
            return FALSE;
        }

        # Finally, delete id2.  We used to this inside the transaction, but the result was that
        # fromuser sometimes got set to NULL on messages owned by id2, despite them having been set to
        # id1 earlier on.  Either we're dumb, or there's a subtle interaction between transactions,
        # foreign keys and Percona clusters.  This is safer and proves to be more reliable.
        #
        # Make sure we don't pick up an old cached version, as we've just changed it quite a bit.
        try {
            DB::table('memberships')->where('userid', $id2)->delete();
            DB::table('users')->where('id', $id2)->delete();
            Log::info("Merged {$id1} < {$id2}, {$reason}");
        } catch (\Exception $e) {
            Log::error("Failed to delete merged user {$id2}: " . $e->getMessage());
            // The merge itself succeeded — the user data is consolidated in id1.
            // A dangling id2 row is less harmful than rolling back the entire merge.
        }

        return TRUE;
    }

    /**
     * Wipe a user of personal data for the GDPR right to be forgotten.
     *
     * The user record itself is retained (marked as forgotten) so that message
     * statistics remain accurate.  All identifiable content is removed:
     * - Name, settings and Yahoo ID cleared
     * - External email addresses deleted (internal Freegle addresses kept)
     * - All login credentials deleted
     * - Message content cleared; messages without an outcome are withdrawn
     * - Chat message content cleared
     * - Community events, volunteering, newsfeed posts, stories, searches,
     *   about-me entries and ratings deleted
     * - All group memberships removed
     * - Postal addresses and profile images deleted
     * - Message promises deleted
     * - Sessions deleted
     * - Deletion logged
     *
     * Ported from iznik-server/include/user/User.php::forget().
     *
     * @param string $reason Human-readable reason for the deletion (e.g. 'GDPR request')
     */
    public function forget(string $reason): void
    {
        // --- Clear personal attributes ---
        DB::table('users')->where('id', $this->id)->update([
            'firstname' => NULL,
            'lastname' => NULL,
            'fullname' => 'Deleted User #' . $this->id,
            'settings' => NULL,
            'yahooid' => NULL,
        ]);

        // --- Delete external emails (keep internal Freegle addresses) ---
        foreach ($this->emails()->get() as $email) {
            if (!self::isInternalEmail($email->email)) {
                $email->delete();
            }
        }

        // --- Delete all login credentials ---
        DB::table('users_logins')->where('userid', $this->id)->delete();

        // --- Clear message content and withdraw messages without an outcome ---
        $msgIds = DB::table('messages')
            ->where('fromuser', $this->id)
            ->whereIn('type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->pluck('id');

        foreach ($msgIds as $msgId) {
            DB::table('messages')->where('id', $msgId)->update([
                'fromip' => NULL,
                'message' => NULL,
                'envelopefrom' => NULL,
                'fromname' => NULL,
                'fromaddr' => NULL,
                'messageid' => NULL,
                'textbody' => NULL,
                'htmlbody' => NULL,
                'deleted' => now(),
            ]);

            DB::table('messages_groups')->where('msgid', $msgId)->update(['deleted' => 1]);

            // Clear any outcome comments that might contain personal data.
            DB::table('messages_outcomes')->where('msgid', $msgId)->update(['comments' => NULL]);

            $m = Message::find($msgId);

            // Withdraw if no outcome has been recorded yet.
            if (!$m->hasOutcome()) {
                $m->withdraw('Withdrawn on user unsubscribe', NULL);
            }
        }

        // --- Clear chat message content ---
        DB::table('chat_messages')->where('userid', $this->id)->update(['message' => NULL]);

        // --- Delete user-generated content ---
        DB::table('communityevents')->where('userid', $this->id)->delete();
        DB::table('volunteering')->where('userid', $this->id)->delete();
        DB::table('newsfeed')->where('userid', $this->id)->delete();
        DB::table('users_stories')->where('userid', $this->id)->delete();
        DB::table('users_searches')->where('userid', $this->id)->delete();
        DB::table('users_aboutme')->where('userid', $this->id)->delete();
        DB::table('ratings')->where('rater', $this->id)->delete();
        DB::table('ratings')->where('ratee', $this->id)->delete();

        // --- Remove all group memberships ---
        // TODO Finnbarr: properly port over getMemberships and removeMembership methods
        DB::table('memberships')->where('userid', $this->id)->delete();

        // --- Delete postal addresses and profile images ---
        DB::table('users_addresses')->where('userid', $this->id)->delete();
        DB::table('users_images')->where('userid', $this->id)->delete();

        // --- Delete message promises ---
        DB::table('messages_promises')->where('userid', $this->id)->delete();

        // --- Mark user as forgotten ---
        DB::table('users')->where('id', $this->id)->update([
            'forgotten' => now(),
            'tnuserid' => NULL,
        ]);

        // --- Delete sessions ---
        DB::table('sessions')->where('userid', $this->id)->delete();

        // --- Log the deletion ---
        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'User',
            'subtype' => 'Deleted',
            'user' => $this->id,
            'text' => $reason,
        ]);
    }
}
