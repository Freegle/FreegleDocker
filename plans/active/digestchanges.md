# Unified Freegle Digest - Proposed Changes

## Summary

We're consolidating email notifications from per-group digests into a single unified "Freegle Digest". This simplifies the user experience and removes duplicate content.

This change is crucial for two reasons:
1. **Simplifying the codebase** - The per-group digest system is complex and hard to maintain
2. **Enabling rippling out** - The unified structure is a necessary foundation for the future rippling out algorithm, which will show more relevant posts to more freeglers

**Important**: This change focuses on collapsing per-group emails into a single email. Changes to which posts are shown within those mails will come later with the "rippling out" work.

## What's Changing

### 1. One Digest Instead of Many

**Current**: Users receive a separate digest email for each group they're in. In busy areas like London, users might be on 4-5 groups and receive 4-5 emails per day.

**New**: Users receive a single "Freegle Digest" containing posts from all their communities, with duplicates removed.

### 2. Same Posts, Just Deduplicated

**Current**: Cross-posted items appear multiple times (once per group). In areas where cross-posting is allowed, you might see the same post five times.

**New**: The same posts you would currently see across all your group emails, but each item shown only once, with indication of which groups it's posted to.

**Note**: The website shows posts based on isochrones.  We're deliberately NOT changing to this in emails at the moment, as it will change further anyway with rippling out.

### 3. Remove Email Frequency Popup

**Current**: After joining a group, users see a popup asking them to choose their email frequency.

**New**: No popup. Based on data analysis, 97% of users in the last 30 days have chosen the daily default. The popup adds friction without value.

Users can still change their preference in Settings, but we won't interrupt them with a question when the default is almost universally accepted.

### 4. Simplified Email Settings

**Current**:
- Settings page shows a dropdown for each community you're in
- Each community can have different email frequency
- Options include: Immediately, Daily, Never (plus legacy hourly options)

**New**:
- One simple dropdown in Settings that applies to all your communities
- Three options: No emails, Basic (daily digest), Full (immediate)

**Legacy Settings**: Some users may have intermediate settings (e.g., emails every 4 hours) from when those options were visible. These will be migrated to the nearest equivalent.

### 5. New Subject Line Format

**Current**: `[GroupName] What's New (3 messages) - Sofa, Table...`

**New**:
- **From**: `Freegle <noreply@ilovefreegle.org>`
- **Subject**: `5 new posts near you - Sofa, Coffee table, Books...`

No community name in subject (user may be in multiple communities). Item names provide preview to encourage opens.

### 6. Unified Sender Address

**Current**: Emails sent from per-recipient encoded addresses (e.g., `bounce-12345@ilovefreegle.org`) to enable bounce tracking.

**New**: All emails sent from `noreply@ilovefreegle.org` with a reply-to header set appropriately.

**Why**:
- Enables AMP email whitelisting (Google/Yahoo only allow a single sender address)
- Allows users to add a single address to their address book or whitelist
- Per-recipient encoding is less necessary now as bounce messages have improved
- BT used to block high-volume senders using single addresses, but this is less of an issue now

### 7. Other Per-Community Emails

The same consolidation applies to other email types that are currently sent per-community:

- **Community Event Roundup** - Currently `[CommunityName] Community Event Roundup`, will become a single unified events digest
- **Volunteer Opportunity Roundup** - Currently `[CommunityName] Volunteer Opportunity Roundup`, will become a single unified volunteering digest

These follow the same pattern: one email containing relevant content from all communities, with no duplicates.

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
3. **Simpler settings**: One choice instead of per-community configuration
4. **Easier whitelisting**: Single sender address to add to address book (though this may not in practice help much)

### For Freegle

1. **Lower email volume**: Significant reduction in outgoing emails (one per user instead of one per membership)
2. **Simpler codebase**: One digest system instead of per-group logic
3. **Better data**: Unified tracking of email engagement
4. **Reduced infrastructure burden**: Lower volume moves us closer to potentially using external email providers

### Long-term Infrastructure Goal

Currently we maintain our own high-volume email infrastructure, which requires specialised skills that are increasingly rare. By reducing email volume through:
1. This consolidation (one email per user, not per membership)
2. Future adaptive sending (reducing frequency for unengaged users)

We may eventually reach a volume where external email providers become affordable, eliminating the need to maintain our own email servers.

---

## Website/Email Consistency Note

After this change, there will still be some inconsistency between what users see on the website (isochrone-based, showing posts from non-member groups) and what they receive by email (posts from their member groups only).

This inconsistency will be resolved later when we implement rippling out, which will change post selection in both places simultaneously.

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

4. **No More Email Frequency Popup**
   New members won't be asked to choose their email frequency - 97% take the default anyway.

**Why We're Making These Changes**

The old per-group system was built when Freegle was organised purely around groups. It required complex code to:
- Track digest progress separately for each group
- Handle different email frequencies per group
- Process memberships individually

This complexity made the code hard to maintain and debug. The new unified approach is much simpler to understand and maintain, with less code and fewer edge cases.

For members, the change means fewer emails and no duplicates. The content of emails (which posts are shown) remains the same for now - that will change later with rippling out.

**What Moderators Need to Know**

- Per-group email settings are no longer used
- Members' settings migrate automatically
- You can't control email settings for individual communities anymore
- The posts shown in emails remain the same as before (just deduplicated)

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

**Why has my digest email changed?**

We've improved how we send digest emails to give you a better experience:

- **One email instead of many** - You now receive a single Freegle Digest containing posts from all your communities, rather than a separate email for each community. This means fewer emails in your inbox.

- **No more duplicates** - If someone posts the same item to multiple communities, you'll only see it once. Previously you might have seen the same sofa three times if it was posted to three nearby communities.

- **Simpler settings** - Instead of choosing email settings for each community separately, there's now one simple choice that applies everywhere.

These changes help you find what you need faster, with less inbox clutter.

[Change email settings →]

---

**How are posts chosen for my emails?**

You see posts from all the communities you're a member of, with duplicates removed if something's posted to multiple communities.

[Change email settings →]

---

**Get fewer emails**

You can choose No emails, Basic (daily digest), or Full (immediate). Change this in Settings under 'Mail Settings'.

[Go to Settings →]

---

## Measuring Impact

### Key Metric: Digest Clicks

The simplest way to measure impact is to count clicks from digest emails. Both old and new emails include `?src=digest` in their links, and page views are already logged to Loki with query parameters.

**How it works:**
- All digest email links include `?src=digest` (e.g., `ilovefreegle.org/message/12345?src=digest`)
- Page views are logged to Loki with query params via `sentry.client.ts`
- No code changes needed - we can compare before/after using existing data

### Scroll Depth Tracking

We're attempting to measure how far down emails people scroll by using post images as tracking pixels. If email clients don't render images until they scroll into the viewport, we can measure engagement depth.

This data will inform future decisions about digest length and content ordering.

### Loki Query

**Daily digest clicks:**
```
sum by (day) (
  count_over_time(
    {job="freegle"} |= "page_view" | json | query_params_src="digest" [24h]
  )
)
```

**Weekly comparison (run before and after rollout):**
```
count_over_time(
  {job="freegle"} |= "page_view" | json | query_params_src="digest" [7d]
)
```

### Success Criteria

The unified digest will be considered successful if digest clicks remain stable or increase after rollout. A significant drop would indicate users are less engaged with the new format.

### A/B Testing Decision

We considered running an A/B test comparing per-group and unified digests, but decided against it because:
- Hard to distinguish novelty effect from genuine improvement
- Would need to run for a very long time to get meaningful results
- Extra coding effort not justified given the clear simplification benefits

---

## Future Considerations (Not Part of This Work)

The following ideas were discussed but are explicitly NOT part of this change:

### Adaptive Email Frequency
Reducing frequency for unengaged users (daily → weekly → monthly) to avoid training people to ignore emails. This requires engagement tracking to be in place first.

### Post Selection Changes
Moving to isochrone-based or rippling-out-based post selection. This will be tackled separately to avoid fighting the same battle with volunteers twice.

### Category/Tag Filtering
Allowing users to filter by category was rejected because:
- Items often fit multiple categories (is it kitchen or electrical?)
- Freegle doesn't have enough user activity to build good recommendation models
- We're a "store with mostly empty shelves" compared to sites like eBay

### Push Notifications
Once the app has new post notifications, we may be able to reduce email frequency for app users. The app currently only has chat notifications.

---

<details>
<summary><strong>Implementation Plan</strong> (click to expand)</summary>

### Prerequisites

Before implementing the unified digest:
1. **Move bounce handling to Laravel** - Better monitoring of bounces with new sender address
2. **Move incoming email processing to Laravel** - Infrastructure modernisation

### Phase 1: Remove Email Frequency Popup

1. Remove the modal that asks new members about email frequency
2. Keep the setting accessible in Settings page
3. Default all new users to daily digest

### Phase 2: Settings Migration

1. Run SQL analysis to understand current usage patterns
2. Migrate users without `simplemail` set based on their per-group settings
3. Update Settings UI to show single dropdown
4. Update ModTools `SettingsGroup` component to show `simplemail` instead of per-group frequency

### Phase 3: Unified Digest

1. Modify `DigestService` to process users (not groups)
2. For each user, gather posts from all member communities
3. Apply deduplication logic
4. Build single digest email with mobile-first design
5. Track progress per-user (not per-group)

### Phase 4: Individual Emails First

Start with users on "Full" (immediate) settings before tackling daily digests:
- Lower volume, easier to shake out issues
- Same deduplication logic applies

### Phase 5: Deduplication

1. Group posts by `tnpostid` (Trashnothing cross-posts)
2. For remaining posts, group by fromuser + item name + location + time window
3. Compare message body text and photos to rule out false positives
4. Show deduplicated posts with "Posted to: Community A, Community B" note

### Email Generation Performance

The new Laravel-based generation may need:
- Parallel queue workers for high-volume digest generation
- MJML compiled on-the-fly (previously manually converted)
- Write to disk first, then spool to Postfix (same as old system)

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

### Email Frequency Analysis (Last 30 Days)

Based on actual data:
- **97%** of new users choose daily (the default)
- **3%** choose immediate or none

This strongly supports removing the email frequency popup.

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
- MJML templates (now compiled on-the-fly instead of manually)
- Email tracking
- Spooler for reliable delivery
- SpamAssassin/SpamD testing in unit tests

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

### BT Email Deliverability

BT has historically been problematic for email delivery. If issues arise with the unified sender address:
- Email postmaster@ directly - they typically respond quickly
- Monitor bounce queues closely after rollout

</details>
