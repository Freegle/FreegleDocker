# Unified Freegle Digest - Proposed Changes

## Summary

We're consolidating email notifications from per-group digests into a single unified "Freegle Digest". This simplifies the user experience and removes duplicate content.

This change is crucial for two reasons:
1. **Simplifying the codebase** - The per-group digest system is complex and hard to maintain
2. **Enabling rippling out** - The unified structure is a necessary foundation for the future rippling out algorithm, which will show more relevant posts to more freeglers

## What's Changing

### 1. One Digest Instead of Many

**Current**: Users receive a separate digest email for each group they're in.

**New**: Users receive a single "Freegle Digest" containing posts from all their communities.

### 2. Simplified Email Settings

**Current**:
- Settings page shows a dropdown for each community you're in
- Each community can have different email frequency
- Options include: Immediately, Daily, Never (plus legacy hourly options)

**New**:
- One simple dropdown in Settings that applies to all your communities
- Three options: No emails, Basic (daily digest), Full (immediate)
- Same choice shown in the popup after joining a new community

### 3. No More Duplicate Posts

**Current**: Cross-posted items appear multiple times (once per group).

**New**: Each item shown once, with indication of which groups it's posted to.

### 4. New Subject Line Format

**Current**: `[GroupName] What's New (3 messages) - Sofa, Table...`

**New**:
- **From**: `Freegle <noreply@ilovefreegle.org>`
- **Subject**: `5 new posts near you - Sofa, Coffee table, Books...`

No community name in subject (user may be in multiple communities). Item names provide preview to encourage opens.

### 5. Isochrone-Based Post Selection

Posts are selected based on travel time from your location, not just community membership. This ensures you see items you can realistically collect.

This change is a necessary step towards the future "rippling out" system, where posts will gradually expand their reach over time if they're not getting enough interest. The unified digest structure makes rippling out possible.

### 6. Other Per-Community Emails

The same consolidation applies to other email types that are currently sent per-community:

- **Community Event Roundup** - Currently `[CommunityName] Community Event Roundup`, will become a single unified events digest
- **Volunteer Opportunity Roundup** - Currently `[CommunityName] Volunteer Opportunity Roundup`, will become a single unified volunteering digest

These follow the same pattern: one email containing relevant content from all communities, with no duplicates, and no community name in the subject line.

---

## Cross-Post Deduplication Logic

Two posts are considered duplicates when ALL of the following match:

| Criterion | Rationale |
|-----------|-----------|
| Same `fromuser` | Must be same poster |
| Same item name (from subject) | Core identifier |
| Same location | Prevents matching items in different areas |
| Posted within 7 days of each other | Handles repeated posts over months |
| Same `tnpostid` (if present) | Definitive match for Trashnothing cross-posts |

Two posts are **NOT duplicates** if:
- Different message body text (e.g., "I have 3 of these" vs "Another one available")
- Different photos attached
- Posted more than 7 days apart

When duplicates are found, show the item once with note: "Posted to: Community A, Community B".

---

## Benefits

### For Members

1. **Fewer emails**: One digest instead of one per community
2. **No duplicates**: Cross-posted items shown once
3. **More relevant**: Posts selected by distance, not arbitrary community boundaries
4. **Simpler settings**: One choice instead of per-community configuration

### For Freegle

1. **Lower unsubscribe rates**: Less email volume, more relevance
2. **Simpler codebase**: One digest system instead of per-group logic
3. **Better data**: Unified tracking of email engagement

---

## Migration Plan

### Settings Migration

Users' existing settings will be migrated based on their `simplemail` value:

| Current simplemail | New behaviour |
|-------------------|---------------|
| None | No emails |
| Basic | Daily digest |
| Full | Individual emails |
| Not set | Check per-group settings, use most engaged option |

For users without `simplemail` set, we'll examine their per-group `emailfrequency` values and choose:
- If any group = Immediate (-1) → Full
- If any group = Daily (24) → Basic
- Otherwise → None

### Database Changes

No schema changes required. We simply:
1. Read `simplemail` from user settings
2. Ignore per-group `emailfrequency` values
3. Send unified digest based on user preference

---

## Moderator Explanation

### How Email Notifications Are Changing

We're consolidating email notifications from per-group digests into a single unified "Freegle Digest".

**What's Changing**

1. **One Digest Instead of Many**
   Members receive a single digest containing posts from all their communities, not one email per community.

2. **No More Duplicates**
   Cross-posted items appear once, not multiple times.

3. **Simpler Settings**
   One simple choice (None/Basic/Full) instead of per-group configuration.

**Why We're Making These Changes**

The old per-group system was built when Freegle was organised purely around groups. It required complex code to:
- Track digest progress separately for each group
- Handle different email frequencies per group
- Process memberships individually

This complexity made the code hard to maintain and debug. The new unified approach is much simpler to understand and maintain, with less code and fewer edge cases.

For members, the change means fewer emails and no duplicates. For developers, it means a codebase that's easier to work with.

**What Moderators Need to Know**

- Per-group email settings are no longer used
- Members' settings migrate automatically
- You can't control email settings for individual communities anymore

**Changes to ModTools**

Currently when viewing a member, ModTools shows:
- Email frequency dropdown for this community (Immediately/Daily/Never)
- Community events toggle
- Volunteering opportunities toggle

After this change, ModTools will show:
- The member's overall email setting (None/Basic/Full)
- This applies to all their communities, not just yours
- You can still see the setting but changes affect all their communities

---

## Help Section Entry

**How are posts chosen for my emails?**

We show you posts within a reasonable travel time from your location. You get one digest with posts from all your communities - no duplicates if something's posted to multiple communities.

[Change email settings →]

**Get fewer emails**

You can choose No emails, Basic (daily digest), or Full (immediate). Change this in Settings under 'Mail Settings'.

[Go to Settings →]

---

<details>
<summary><strong>Implementation Plan</strong> (click to expand)</summary>

### Phase 1: Settings Migration

1. Run SQL analysis to understand current usage patterns
2. Migrate users without `simplemail` set based on their per-group settings
3. Update Settings UI to show single dropdown
4. Update join popup to use simplified options
5. Update ModTools `SettingsGroup` component to show `simplemail` instead of per-group frequency

### Phase 2: Unified Digest

1. Modify `DigestService` to process users (not groups)
2. For each user, gather posts from all member communities
3. Apply isochrone-based filtering
4. Apply deduplication logic
5. Build single digest email
6. Track progress per-user (not per-group)

### Phase 3: Deduplication

1. Group posts by `tnpostid` (Trashnothing cross-posts)
2. For remaining posts, group by fromuser + item name + location + time window
3. Compare message body text and photos to rule out false positives
4. Show deduplicated posts with "Posted to: Community A, Community B" note

### Fallback Strategy

If issues arise with isochrone-based post selection, the code can fall back to showing all posts from member communities by changing the post selection query. This is a simple code change that doesn't require database migration.

### Database Changes

No schema changes required. We:
1. Read `simplemail` from user settings
2. Ignore per-group `emailfrequency` values
3. Track digest progress per-user instead of per-group

</details>

<details>
<summary><strong>Technical Investigation Notes</strong> (click to expand)</summary>

### Current Email Settings Model

#### Storage Locations

**Per-Group Settings (memberships table):**
- `emailfrequency` (int): Hours between emails
- `eventsallowed` (tinyint): Event notifications (0/1)
- `volunteeringallowed` (bigint): Volunteering notifications (0/1)

**User-Wide Settings (users table):**
- `simplemail` in user settings JSON - controls overall email behaviour

#### Email Frequency Constants (Digest.php)

| Constant | Value | Meaning |
|----------|-------|---------|
| NEVER | 0 | Never send emails |
| IMMEDIATE | -1 | Send immediately |
| HOUR1-8 | 1,2,4,8 | Legacy options |
| DAILY | 24 | Once per day |

#### Simple Mail Settings (User.php)

| Constant | Behaviour |
|----------|-----------|
| SIMPLE_MAIL_NONE | Completely disables all emails |
| SIMPLE_MAIL_BASIC | Daily digest, chat replies only |
| SIMPLE_MAIL_FULL | Immediate notifications, all email types |

### Current Digest Generation

**Cron Job:** `/scripts/cron/digest.php`
- Runs for each frequency interval
- Processes groups separately
- No cross-post deduplication

**Tracking Table:** `groups_digests`
- Tracks last sent message per group/frequency

### Cross-Post Identification

**Trashnothing Detection:**
- `tnpostid` field stores TN's unique post ID
- `x-trash-nothing-user-id` header identifies TN users

**Freegle Cross-Posts:**
- Same message can appear in `messages_groups` for multiple groups
- `messageid` field modified to include groupid

### iznik-batch Infrastructure

Already has:
- `DigestService` for sending digests
- `SingleDigest` and `MultipleDigest` mailables
- MJML templates
- Email tracking
- Spooler for reliable delivery

### Current Subject Line Format

```
[GroupName] What's New (N messages) - item1, item2, item3...
```

Item names extracted from message subjects, max 25 chars each, max 50 chars total teaser.

### SQL Query for Current Usage Analysis

```sql
-- Simple mail setting distribution
SELECT
    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.simplemail')), 'Not Set') AS setting,
    COUNT(DISTINCT id) AS users
FROM users
WHERE lastaccess > DATE_SUB(NOW(), INTERVAL 90 DAY)
  AND deleted IS NULL
GROUP BY setting;

-- Per-group frequency distribution
SELECT
    emailfrequency,
    COUNT(*) AS memberships,
    COUNT(DISTINCT userid) AS users
FROM memberships m
INNER JOIN users u ON u.id = m.userid
WHERE u.lastaccess > DATE_SUB(NOW(), INTERVAL 90 DAY)
  AND u.deleted IS NULL
GROUP BY emailfrequency;

-- Users with mixed settings across groups
SELECT COUNT(DISTINCT userid) AS mixed_settings_users
FROM (
    SELECT userid, COUNT(DISTINCT emailfrequency) AS freq_count
    FROM memberships
    GROUP BY userid
    HAVING freq_count > 1
) mixed;
```

</details>
