<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /**
     * Get work counts for a set of groups.
     *
     * Ported from iznik-server Group::getWorkCounts().
     *
     * @param  array  $mysettings  Per-group settings indexed by groupid; each entry may have an 'active' boolean.
     * @param  array  $groupids    Group IDs to get counts for.
     * @return array  Work counts indexed by groupid.
     */
    public static function getWorkCounts(array $mysettings, array $groupids): array
    {
        $ret = [];

        if (empty($groupids)) {
            return $ret;
        }

        $groupq = "(" . implode(',', $groupids) . ")";
        $earliestmsg = date('Y-m-d', strtotime('Midnight 31 days ago'));
        $eventsqltime = date('Y-m-d H:i:s');

        # Exclude messages routed to system, for which there must be a good reason.
        $pendingspamcounts = DB::select("
            SELECT messages_groups.groupid, COUNT(*) AS count, messages_groups.collection,
                   messages_groups.heldby IS NOT NULL AS held
            FROM messages
            INNER JOIN messages_groups ON messages.id = messages_groups.msgid
                AND messages_groups.groupid IN ($groupq)
                AND messages_groups.collection IN (?)
                AND messages_groups.deleted = 0
                AND messages.deleted IS NULL
                AND messages.fromuser IS NOT NULL
                AND messages_groups.arrival >= ?
                AND (messages.lastroute IS NULL OR messages.lastroute != ?)
            GROUP BY messages_groups.groupid, messages_groups.collection, held
        ", [MessageGroup::COLLECTION_PENDING, $earliestmsg, 'ToSystem']);

        # No need to check spam_users as those will be auto-removed by the check_spammers job (in earlier times
        # this wasn't the case for all groups).
        $spammembercounts = DB::select("
            SELECT memberships.groupid, COUNT(*) AS count, memberships.heldby IS NOT NULL AS held
            FROM memberships
            WHERE (reviewrequestedat IS NOT NULL AND (reviewedat IS NULL OR DATE(reviewedat) < DATE_SUB(NOW(), INTERVAL 31 DAY)))
                AND groupid IN ($groupq)
            GROUP BY memberships.groupid, held
        ");

        // Pending community event counts.
        $pendingeventcounts = DB::select("
            SELECT groupid, COUNT(DISTINCT communityevents.id) AS count
            FROM communityevents
            INNER JOIN communityevents_dates ON communityevents_dates.eventid = communityevents.id
            INNER JOIN communityevents_groups ON communityevents.id = communityevents_groups.eventid
            INNER JOIN `groups` ON groups.id = communityevents_groups.groupid
            WHERE communityevents_groups.groupid IN ($groupq)
                AND (groups.settings IS NULL OR JSON_EXTRACT(groups.settings, '$.communityevents') IS NULL OR JSON_EXTRACT(groups.settings, '$.communityevents') = 1)
                AND communityevents.pending = 1
                AND communityevents.deleted = 0
                AND end >= ?
            GROUP BY groupid
        ", [$eventsqltime]);

        // Pending volunteering counts.
        $pendingvolunteercounts = DB::select("
            SELECT groupid, COUNT(DISTINCT volunteering.id) AS count
            FROM volunteering
            LEFT JOIN volunteering_dates ON volunteering_dates.volunteeringid = volunteering.id
            INNER JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid
            INNER JOIN `groups` ON groups.id = volunteering_groups.groupid
            WHERE volunteering_groups.groupid IN ($groupq)
                AND volunteering.pending = 1
                AND volunteering.deleted = 0
                AND volunteering.expired = 0
                AND (groups.settings IS NULL OR JSON_EXTRACT(groups.settings, '$.volunteering') IS NULL OR JSON_EXTRACT(groups.settings, '$.volunteering') = 1)
                AND (applyby IS NULL OR applyby >= ?)
                AND (end IS NULL OR end >= ?)
            GROUP BY groupid
        ", [$eventsqltime, $eventsqltime]);

        // Pending admin counts.
        $pendingadmins = DB::select("
            SELECT groupid, COUNT(DISTINCT admins.id) AS count
            FROM admins
            WHERE admins.groupid IN ($groupq)
                AND admins.complete IS NULL
                AND admins.pending = 1
                AND heldby IS NULL
                AND admins.created >= ?
            GROUP BY groupid
        ", [$earliestmsg]);

        // Related members (possible duplicate accounts, not yet notified).
        $relatedmembers = DB::select("
            SELECT COUNT(*) AS count, groupid FROM (
                SELECT user1, memberships.groupid,
                       (SELECT COUNT(*) FROM users_logins WHERE userid = memberships.userid) AS logincount
                FROM users_related
                INNER JOIN memberships ON users_related.user1 = memberships.userid
                INNER JOIN users u1 ON users_related.user1 = u1.id AND u1.deleted IS NULL AND u1.systemrole = 'User'
                INNER JOIN users u2 ON users_related.user2 = u2.id AND u2.deleted IS NULL AND u2.systemrole = 'User'
                WHERE user1 < user2 AND notified = 0 AND memberships.groupid IN ($groupq)
                HAVING logincount > 0
                UNION
                SELECT user1, memberships.groupid,
                       (SELECT COUNT(*) FROM users_logins WHERE userid = memberships.userid) AS logincount
                FROM users_related
                INNER JOIN memberships ON users_related.user2 = memberships.userid
                INNER JOIN users u3 ON users_related.user2 = u3.id AND u3.deleted IS NULL AND u3.systemrole = 'User'
                INNER JOIN users u4 ON users_related.user1 = u4.id AND u4.deleted IS NULL AND u4.systemrole = 'User'
                WHERE user1 < user2 AND notified = 0 AND memberships.groupid IN ($groupq)
                HAVING logincount > 0
            ) t GROUP BY groupid
        ");

        # We only want to show edit reviews upto 7 days old - after that assume they're ok.
        $mysqltime7 = date('Y-m-d', strtotime('Midnight 7 days ago'));
        $editreviewcounts = DB::select("
            SELECT groupid, COUNT(DISTINCT messages_edits.msgid) AS count
            FROM messages_edits
            INNER JOIN messages_groups ON messages_edits.msgid = messages_groups.msgid
            WHERE timestamp > ?
                AND reviewrequired = 1
                AND messages_groups.groupid IN ($groupq)
                AND messages_groups.deleted = 0
            GROUP BY groupid
        ", [$mysqltime7]);

        # We only want to show happiness upto 31 days old - after that just let it slide.  We're only interested
        # in ones with interesting comments.
        #
        # This code matches the feedback code on the client.
        $happinesscounts = DB::select("
            SELECT messages_groups.groupid, COUNT(DISTINCT messages_groups.msgid) AS count
            FROM messages_outcomes
            INNER JOIN messages_groups ON messages_groups.msgid = messages_outcomes.msgid
            INNER JOIN messages ON messages.id = messages_outcomes.msgid
            WHERE messages_outcomes.timestamp > ?
                AND messages_groups.arrival > ?
                AND groupid IN ($groupq)
                " . self::getHappinessFilter() . "
                AND reviewed = 0
            GROUP BY groupid
        ", [$earliestmsg, $earliestmsg]);

        // TODO Finnbarr: Port ChatMessage::getReviewCountByGroup() — requires User::widerReview(),
        // User::getModeratorships(), and User::activeModForGroup() which are not yet
        // implemented in iznik-batch.
        $reviewcounts = [];
        $reviewcountsother = [];

        # We might be returned counts for groups we were not expecting, because we are using the wider chat
        # review function.  So add any groupids from $reviewcountsother into $groupids so that we process
        # the results below.
        foreach ($reviewcountsother as $count) {
            if (!in_array($count['groupid'], $groupids)) {
                $groupids[] = $count['groupid'];
            }
        }

        foreach ($groupids as $groupid) {
            # Depending on our group settings we might not want to show this work as primary; "other" work is displayed
            # less prominently in the client.
            #
            # If we have the active flag use that; otherwise assume that the legacy showmessages flag tells us.  Default
            # to active.
            # TODO Retire showmessages entirely and remove from user configs.
            $active = $mysettings[$groupid]['active'] ?? FALSE;

            $thisone = [
                'pending'             => 0,
                'pendingother'        => 0,
                'spam'                => 0,
                'pendingmembers'      => 0,
                'pendingmembersother' => 0,
                'pendingevents'       => 0,
                'pendingvolunteering' => 0,
                'spammembers'         => 0,
                'spammembersother'    => 0,
                'editreview'          => 0,
                'pendingadmins'       => 0,
                'happiness'           => 0,
                'relatedmembers'      => 0,
                'chatreview'          => 0,
                'chatreviewother'     => 0,
            ];

            if ($active) {
                foreach ($pendingspamcounts as $count) {
                    if ($count->groupid == $groupid) {
                        if ($count->collection == MessageGroup::COLLECTION_PENDING) {
                            if ($count->held) {
                                $thisone['pendingother'] = $count->count;
                            } else {
                                $thisone['pending'] = $count->count;
                            }
                        } else {
                            $thisone['spam'] = $count->count;
                        }
                    }
                }

                foreach ($spammembercounts as $count) {
                    if ($count->groupid == $groupid) {
                        if ($count->held) {
                            $thisone['spammembersother'] = $count->count;
                        } else {
                            $thisone['spammembers'] = $count->count;
                        }
                    }
                }

                foreach ($pendingeventcounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['pendingevents'] = $count->count;
                    }
                }

                foreach ($pendingvolunteercounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['pendingvolunteering'] = $count->count;
                    }
                }

                foreach ($editreviewcounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['editreview'] = $count->count;
                    }
                }

                foreach ($pendingadmins as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['pendingadmins'] = $count->count;
                    }
                }

                foreach ($happinesscounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['happiness'] = $count->count;
                    }
                }

                foreach ($relatedmembers as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['relatedmembers'] = $count->count;
                    }
                }

                foreach ($reviewcounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['chatreview'] = $count->count;
                    }
                }

                foreach ($reviewcountsother as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['chatreviewother'] = $count->count;
                    }
                }
            } else {
                foreach ($pendingspamcounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['pendingother'] = $count->count;
                    }
                }

                foreach ($spammembercounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['spammembersother'] = $count->count;
                    }
                }

                foreach ($reviewcounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['chatreviewother'] += $count->count;
                    }
                }

                foreach ($reviewcountsother as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['chatreviewother'] += $count->count;
                    }
                }
            }

            $ret[$groupid] = $thisone;
        }

        return $ret;
    }

    /**
     * SQL fragment to filter out auto-generated/boilerplate happiness comments.
     *
     * Ported from iznik-server Group::getHappinessFilter().
     */
    private static function getHappinessFilter(): string
    {
        return " AND messages_outcomes.comments IS NOT NULL
              AND messages_outcomes.comments != 'Sorry, this is no longer available.'
              AND messages_outcomes.comments != 'Thanks, this has now been taken.'
              AND messages_outcomes.comments != 'Thanks, I\\'m no longer looking for this.'
              AND messages_outcomes.comments != 'Sorry, this has now been taken.'
              AND messages_outcomes.comments != 'Thanks for the interest, but this has now been taken.'
              AND messages_outcomes.comments != 'Thanks, these have now been taken.'
              AND messages_outcomes.comments != 'Thanks, this has now been received.'
              AND messages_outcomes.comments != 'Withdrawn on user unsubscribe'
              AND messages_outcomes.comments != 'Auto-Expired'";
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
