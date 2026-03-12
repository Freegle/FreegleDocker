<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    public const TYPE_FREEGLE = 'Freegle';
    public const TYPE_REUSE = 'Reuse';
    public const TYPE_OTHER = 'Other';

    public const DEFAULT_SETTINGS = [
        'showchat' => 1,
        'communityevents' => 1,
        'volunteering' => 1,
        'stories' => 1,
        'includearea' => 1,
        'includepc' => 1,
        'moderated' => 0,
        'allowedits' => [
            'moderated' => 1,
            'group' => 1,
        ],
        'autoapprove' => [
            'members' => 0,
            'messages' => 0,
        ],
        'duplicates' => [
            'check' => 1,
            'offer' => 14,
            'taken' => 14,
            'wanted' => 14,
            'received' => 14,
        ],
        'spammers' => [
            'chatreview' => 1,
            'messagereview' => 1,
        ],
        'joiners' => [
            'check' => 1,
            'threshold' => 5,
        ],
        'keywords' => [
            'OFFER' => 'OFFER',
            'TAKEN' => 'TAKEN',
            'WANTED' => 'WANTED',
            'RECEIVED' => 'RECEIVED',
        ],
        'reposts' => [
            'offer' => 3,
            'wanted' => 7,
            'max' => 5,
            'chaseups' => 5,
        ],
        'relevant' => 1,
        'newsfeed' => 1,
        'newsletter' => 1,
        'businesscards' => 1,
        'autoadmins' => 1,
        'mentored' => 0,
        'nearbygroups' => 5,
        'showjoin' => 0,
        'engagement' => 1,
    ];

    protected $table = 'groups';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    protected static function boot(): void
    {
        parent::boot();

        // Auto-generate polyindex geometry from lat/lng when creating.
        static::creating(function (Group $group) {
            if (!empty($group->lat) && !empty($group->lng) && empty($group->polyindex)) {
                $srid = config('freegle.srid');
                $group->polyindex = \DB::raw("ST_GeomFromText('POINT({$group->lng} {$group->lat})', {$srid})");
            }
        });
    }

    protected $casts = [
        'settings' => 'array',
        'microvolunteeringoptions' => 'array',
        'rules' => 'array',
        'lat' => 'decimal:6',
        'lng' => 'decimal:6',
        'founded' => 'date',
        'onhere' => 'boolean',
        'publish' => 'boolean',
        'listable' => 'boolean',
        'onmap' => 'boolean',
        'mentored' => 'boolean',
        'seekingmods' => 'boolean',
        'privategroup' => 'boolean',
        'microvolunteering' => 'boolean',
        'onlovejunk' => 'boolean',
    ];

    /**
     * Get group's memberships.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'groupid');
    }

    /**
     * Get group's messages via message_groups pivot.
     */
    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'messages_groups', 'groupid', 'msgid')
            ->withPivot(['collection', 'arrival', 'approved_by', 'deleted']);
    }

    /**
     * Get group's digest records.
     */
    public function digests(): HasMany
    {
        return $this->hasMany(GroupDigest::class, 'groupid');
    }

    /**
     * Scope to only Freegle groups.
     */
    public function scopeFreegle(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_FREEGLE);
    }

    /**
     * Scope to only groups that are on the platform.
     */
    public function scopeOnHere(Builder $query): Builder
    {
        return $query->where('onhere', 1);
    }

    /**
     * Scope to only published groups.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publish', 1);
    }

    /**
     * Scope to active Freegle groups (excludes closed groups).
     */
    public function scopeActiveFreegle(Builder $query): Builder
    {
        return $query->freegle()->onHere()->published()->notClosed();
    }

    /**
     * Scope to exclude closed groups.
     *
     * Note: Must check for both boolean false AND integer 0, as some groups
     * have "closed": 0 (integer) rather than "closed": false (boolean).
     * In MySQL JSON comparisons, 0 != false.
     */
    public function scopeNotClosed(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('settings')
                ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') IS NULL")
                ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') = false")
                ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') = 0");
        });
    }

    /**
     * Check if the group is closed.
     */
    public function isClosed(): bool
    {
        $settings = $this->settings ?? [];
        return !empty($settings['closed']);
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
     * Get approved members.
     */
    public function approvedMembers(): HasMany
    {
        return $this->memberships()->where('collection', 'Approved');
    }

    /**
     * Get moderators.
     */
    public function moderators(): HasMany
    {
        return $this->memberships()->whereIn('role', ['Moderator', 'Owner']);
    }

    /**
     * Get the automated sender address for this group.
     *
     * Returns contactmail if set, otherwise {nameshort}-auto@{group_domain}.
     */
    public function getAutoEmail(): string
    {
        if (!empty($this->contactmail)) {
            return $this->contactmail;
        }

        return $this->nameshort . '-auto@' . config('freegle.mail.group_domain');
    }

    /**
     * Get the moderators contact address for this group.
     *
     * Returns contactmail if set, otherwise {nameshort}-volunteers@{group_domain}.
     */
    public function getModsEmail(): string
    {
        if (!empty($this->contactmail)) {
            return $this->contactmail;
        }

        return $this->nameshort . '-volunteers@' . config('freegle.mail.group_domain');
    }

    /**
     * Get the group's posting address.
     */
    public function getGroupEmail(): string
    {
        return $this->nameshort . '@' . config('freegle.mail.group_domain');
    }

    // Fields exposed by getPublic() - mirrors iznik-server Group::$publicatts.
    private const PUBLIC_ATTS = [
        'id', 'nameshort', 'namefull', 'nameabbr', 'namedisplay', 'settings', 'rules', 'type', 'region', 'logo', 'publish',
        'onhere', 'ontn', 'membercount', 'modcount', 'lat', 'lng',
        'profile', 'cover', 'onmap', 'tagline', 'legacyid', 'external', 'welcomemail', 'description',
        'contactmail', 'fundingtarget', 'affiliationconfirmed', 'affiliationconfirmedby', 'mentored', 'privategroup', 'defaultlocation',
        'moderationstatus', 'maxagetoshow', 'nearbygroups', 'microvolunteering', 'microvolunteeringoptions', 'autofunctionoverride', 'overridemoderation', 'precovidmoderated', 'onlovejunk',
    ];

    /**
     * Get the public representation of this group.
     *
     * Ported from iznik-server Group::getPublic().
     *
     * @param  bool  $summary  If true, omits settings, description, and welcomemail.
     */
    public function getPublic(bool $summary = FALSE): array
    {
        $atts = $this->only(self::PUBLIC_ATTS);

        // Email addresses.
        $atts['modsemail'] = $this->getModsEmail();
        $atts['autoemail'] = $this->getAutoEmail();
        $atts['groupemail'] = $this->getGroupEmail();

        // Derived display name.
        $atts['namedisplay'] = !empty($atts['namefull']) ? $atts['namefull'] : $atts['nameshort'];

        // Merge settings with defaults.
        $settings = $atts['settings'] ?? [];
        $atts['settings'] = array_replace_recursive(self::DEFAULT_SETTINGS, $settings ?: []);

        // ISO date fields.
        $atts['founded'] = $this->founded ? Carbon::parse($this->founded)->toIso8601String() : NULL;

        $atts['affiliationconfirmed'] = !empty($atts['affiliationconfirmed'])
            ? Carbon::parse($atts['affiliationconfirmed'])->toIso8601String()
            : NULL;

        # Images.  We pass those ids in to get the paths.  This removes the DB operations for constructing the
        # Attachment, which is valuable for people on many groups.
        $img = new GroupAttachment();
        $atts['profile'] = $atts['profile'] ? $img->getPath(false, (int) $atts['profile']) : NULL;
        $atts['cover']   = $atts['cover']   ? $img->getPath(false, (int) $atts['cover'])   : NULL;

        // Group URL.
        $userSite = config('freegle.sites.user');
        $atts['url'] = $this->onhere
            ? ($userSite . '/explore/' . $atts['nameshort'])
            : ('https://groups.yahoo.com/neo/groups/' . $atts['nameshort'] . '/info');

        if ($summary) {
            unset($atts['settings'], $atts['description'], $atts['welcomemail']);
        } else {
            if (!empty($atts['defaultlocation'])) {
                $location = Location::find($atts['defaultlocation']);
                $atts['defaultlocation'] = $location ? $location->getPublic() : NULL;
            }
        }

        // Microvolunteering options - already cast to array by Eloquent; apply defaults if absent.
        $atts['microvolunteeringoptions'] = $atts['microvolunteeringoptions'] ?? [
            'approvedmessages' => 1,
            'wordmatch' => 1,
            'photorotate' => 1,
        ];

        return $atts;
    }
}
