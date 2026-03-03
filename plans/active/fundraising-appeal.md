# Fundraising Appeal System - Design Plan

## Context

Freegle needs to send fundraising appeal emails to all members. This is the first of what will be recurring fundraising campaigns, so the infrastructure must be reusable. Marketing consent was previously only collected at login (in iznik-nuxt3), meaning most of the 2.7M user base has `marketingconsent=0` simply because they were never asked. We are relying on the 2026 UK soft opt-in law change (Data Protection and Digital Information Act) which allows charities to email existing supporters without explicit prior consent.

**Key numbers** (from production DB):
- Total active users: 2,672,784
- Non-bouncing: 2,315,746
- With non-bounced email: 2,007,681
- simplemail=None (opted out): 12,045
- Target: complete send in ~3 days (~670K/day, ~28K/hr via spool parallelisation)

This plan covers 4 areas:
1. **Bulk email infrastructure** (iznik-batch) - reusable for future campaigns
2. **Campaign-specific landing page** (iznik-nuxt3) - with donation attribution
3. **Donation attribution** - tracking which donations came from which campaign
4. **AMP email assessment** - whether to use AMP for fundraising

---

## 1. Bulk Email Infrastructure (iznik-batch)

### Branch: `feature/fundraising-appeal` on FreegleDocker

### 1.1 Artisan Command: `app/Console/Commands/Mail/SendFundraisingAppealCommand.php`

**Signature**: `mail:fundraising:send`

**Options**:
- `--campaign=` : **Required**. Campaign slug (e.g., "2026-march"). Used for progress file, donation attribution, and email tracking.
- `--limit=0` : Max emails to send (0 = unlimited)
- `--chunk=1000` : Chunk size for DB queries
- `--spool` : Spool emails via EmailSpoolerService (required for production)
- `--dry-run` : Count eligible users, don't send
- `--resume` : Resume from last progress file position

**Traits**: `GracefulShutdown`, `PreventsOverlapping`, `ChunkedProcessing`, `FeatureFlags`

**Feature flag**: `FundraisingAppeal` in `FREEGLE_MAIL_ENABLED_TYPES`

**Eligibility query** (no schema changes):
```php
// We do NOT check marketingconsent. We are relying on the 2026 UK soft opt-in
// law change (Data Protection and Digital Information Act) which allows charities
// to email existing supporters without explicit prior consent. Marketing consent
// is collected at login in iznik-nuxt3; users who haven't logged in since that
// was introduced have marketingconsent=0 simply because they were never asked.
User::whereNull('deleted')
    ->where('bouncing', 0)
    ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.simplemail')), '') != 'None'")
    ->orderBy('id')
```

Plus per-user checks:
- Has non-bounced preferred email (`$user->email_preferred` not null)
- Not a TrashNothing user (`!$user->isTN()`)

**Progress tracking via local file** (no schema change):
- Path: `storage/fundraising/{campaign}.json`
- Contents: `{ campaign, last_processed_id, total_sent, total_skipped, total_errors, started_at, last_updated_at }`
- Updated every chunk
- `--resume` reads this file and continues from `last_processed_id`

**Flow**:
1. Check feature flag
2. Validate `--campaign` is provided
3. Load/create progress file
4. Query users in chunks (after `last_processed_id` if resuming)
5. For each eligible user: create `FundraisingAppealMail` with campaign slug, spool via `EmailSpoolerService`
6. Update progress file after each chunk
7. Log summary on completion/interruption
8. Graceful shutdown on SIGTERM/SIGINT

### 1.2 Mailable: `app/Mail/Fundraising/FundraisingAppealMail.php`

Based on `AskForDonation` pattern. Extends `MjmlMailable`, uses `TrackableEmail` + `LoggableEmail`.

**Constructor**: `User $user, string $campaign`

**Properties**:
- `$user` - recipient
- `$campaign` - campaign slug for attribution
- `$userSite` - from config
- `$donateUrl` - campaign landing page: `{userSite}/donate/campaign/{campaign}`
- `$marketingOptOutUrl` - one-click opt-out: `{userSite}/marketing-optout/{userId}/{userKey}`

**Email tracking metadata**: `{ campaign: $campaign }` — stored in `email_tracking.metadata` JSON field for attribution queries.

**Key methods**:
- `getSubject()` - placeholder subject (to be filled in with real content later)
- `envelope()` - from noreply address
- `build()` - renders MJML view with tracked URLs, adds `List-Unsubscribe` header for marketing opt-out
- `getRecipientUserId()` - returns `$user->id`

**One-click marketing opt-out**: Uses existing `$user->getUserKey()`. Generates URL `{userSite}/marketing-optout/{id}/{key}`. Nuxt3 handler (separate scope) sets `marketingconsent=0`. This is distinct from `listUnsubscribeUrl()` which unsubscribes from ALL mail.

### 1.3 MJML Template: `resources/views/emails/mjml/fundraising/appeal.blade.php`

Placeholder structure:
- Header (`@include('emails.mjml.components.header')`)
- Greeting: "Hi {{ $user->displayname ?? 'there' }},"
- Content placeholder: `<!-- FUNDRAISING APPEAL CONTENT - TO BE WRITTEN -->`
- Donate button → tracked campaign landing page URL
- Footer with:
  - Standard settings/unsubscribe links (reuse footer partial)
  - **One-click marketing opt-out link**: "Don't want fundraising emails from Freegle? [Click here to opt out]({{ $marketingOptOutUrl }})"
- Tracking pixel

### 1.4 Text Template: `resources/views/emails/text/fundraising/appeal.blade.php`

Plain text version with same placeholders and marketing opt-out URL.

### 1.5 User Model: `app/Models/User.php`

Add method:
```php
public function marketingOptOutUrl(): string
{
    $key = $this->getUserKey();
    $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');
    return "{$userSite}/marketing-optout/{$this->id}/{$key}";
}
```

### 1.6 Tests

**Unit tests** (`tests/Unit/Mail/FundraisingAppealMailTest.php`):
- Construction with user and campaign
- Donate URL contains campaign slug
- Marketing opt-out URL contains user ID and key
- Build returns self (MJML renders)
- Correct subject and from address
- Email tracking metadata includes campaign
- Recipient user ID set for tracking

**Feature tests** (`tests/Feature/Mail/FundraisingAppealCommandTest.php`):
- Command requires feature flag (exits cleanly when disabled)
- Command requires --campaign option
- Dry run counts eligible users
- Skips: deleted, bouncing, simplemail=None, TN users, no-email users
- Sends to eligible user (Mail::fake)
- Progress file created and updated
- Resume skips already-processed users
- --limit option respected
- Does NOT check marketingconsent (user with marketingconsent=0 receives email)

---

## 2. Campaign Landing Page (iznik-nuxt3)

### Branch: `feature/fundraising-landing` on iznik-nuxt3

### 2.1 New Page: `pages/donate/campaign/[slug].vue`

A campaign-specific donation landing page at `/donate/campaign/{slug}`.

**Design**:
- Accepts `slug` route parameter (e.g., "2026-march")
- Reuses existing `StripeDonate` and `DonationButton` components
- Passes campaign slug through to payment intents for attribution
- Custom messaging area (can be campaign-specific via config or hardcoded for first campaign)
- **Wording about excess funds**: Include text like: "We're aiming to raise £5,000 to cover our core running costs. Any funds raised above this target will be used for general Freegle work — helping keep the service running and growing for everyone."
- Shows fundraising progress bar if available
- Falls back to standard donate page if campaign slug not recognised

**Key differences from `/donate`**:
- Campaign branding/messaging section at top
- Campaign slug passed as metadata to Stripe/PayPal
- Excess funds disclaimer
- Tracked source for attribution

### 2.2 Marketing Opt-Out Page: `pages/marketing-optout.vue` (or route handler)

Handles `/marketing-optout/{id}/{key}`:
- Validates user ID and key (via existing `users_logins` Link type)
- Sets `marketingconsent=0` via API call
- Shows confirmation: "You've been opted out of fundraising emails. You'll still receive your regular Freegle notifications."
- No login required (key-based auth)

---

## 3. Donation Attribution

### Current state
The `users_donations` table has no campaign field. Donations are attributed to users but not to campaigns.

### Approach: UTM-style campaign parameter

**No schema change needed** for initial tracking. Use existing mechanisms:

1. **Email tracking metadata**: `FundraisingAppealMail` stores `{ campaign: "2026-march" }` in `email_tracking.metadata`. We can query: "how many users who received campaign X subsequently donated?"

2. **Donate URL with campaign parameter**: The email links to `/donate/campaign/2026-march`. The landing page passes `campaign` as metadata to Stripe `PaymentIntent`:
   ```js
   stripe.confirmPayment({
     metadata: { campaign: '2026-march', user_id: userId }
   })
   ```
   This is queryable in the Stripe dashboard.

3. **Future enhancement** (out of scope for v1): Add `campaign` column to `users_donations` table and populate from Stripe webhook metadata.

**Attribution query** (using existing tables):
```sql
-- Users who received fundraising email AND donated within 30 days
SELECT et.userid, ud.GrossAmount, ud.timestamp
FROM email_tracking et
JOIN users_donations ud ON et.userid = ud.userid
  AND ud.timestamp BETWEEN et.sent_at AND DATE_ADD(et.sent_at, INTERVAL 30 DAY)
WHERE JSON_UNQUOTE(JSON_EXTRACT(et.metadata, '$.campaign')) = '2026-march'
```

---

## 4. AMP Email Assessment

### Recommendation: Do NOT use AMP for fundraising emails

**Reasons**:
1. **No interactivity needed**: Fundraising emails are one-way appeals with a CTA link. AMP's value is in-email actions (reply, form submit). A donate button just links to the landing page.
2. **Limited client support**: AMP email only works in Gmail (web + mobile) and Yahoo Mail. Apple Mail, Outlook, and most others show the HTML fallback anyway.
3. **Complexity vs benefit**: AMP requires separate template, HMAC token generation, API endpoints. The `AmpEmail` trait is built for chat reply use cases, not static content.
4. **Deliverability risk**: AMP emails are larger (3 MIME parts) and some spam filters treat them differently. For a 2M-recipient send, deliverability is critical.
5. **Testing overhead**: AMP validation is strict (no forbidden CSS, no external resources). Would need separate QA pass for marginal benefit.

**Conclusion**: Use MJML (responsive HTML) + plain text only. This is the same approach as the existing `AskForDonation` email, which works well.

---

## 5. Reusability for Future Campaigns

The design supports future fundraising campaigns with minimal changes:

1. **New campaign = new `--campaign` slug**: Same command, different campaign parameter. Each campaign gets its own progress file.
2. **Campaign-specific content**: Future campaigns update the MJML/text templates or use a configurable template path.
3. **Campaign landing pages**: The `[slug].vue` dynamic route handles any campaign.
4. **Attribution**: Each campaign is tracked separately via `email_tracking.metadata`.
5. **Eligibility**: May evolve (e.g., skip users who donated recently), easily added as command options.

---

## Production Usage

```bash
# Dry run to check counts
docker exec freegle-batch-prod php artisan mail:fundraising:send \
  --campaign=2026-march --dry-run

# Full send with nohup, spooling for parallel delivery
nohup docker exec freegle-batch-prod php artisan mail:fundraising:send \
  --campaign=2026-march --spool --chunk=1000 > /tmp/fundraising.log 2>&1 &

# Resume after interruption
nohup docker exec freegle-batch-prod php artisan mail:fundraising:send \
  --campaign=2026-march --spool --resume > /tmp/fundraising.log 2>&1 &

# Check progress
docker exec freegle-batch-prod cat storage/fundraising/2026-march.json

# Spool processor runs separately (already scheduled every minute)
# It will pick up spooled emails and send them in parallel
```

---

## Patterns Reused

| Pattern | Source File |
|---------|------------|
| Mailable structure | `app/Mail/Donation/AskForDonation.php` |
| MjmlMailable base | `app/Mail/MjmlMailable.php` |
| TrackableEmail trait | `app/Mail/Traits/TrackableEmail.php` |
| LoggableEmail trait | `app/Mail/Traits/LoggableEmail.php` |
| FeatureFlags trait | `app/Mail/Traits/FeatureFlags.php` |
| GracefulShutdown | `app/Traits/GracefulShutdown.php` |
| ChunkedProcessing | `app/Traits/ChunkedProcessing.php` |
| PreventsOverlapping | `app/Console/Concerns/PreventsOverlapping.php` |
| EmailSpoolerService | `app/Services/EmailSpoolerService.php` |
| User.getUserKey() | `app/Models/User.php:425` |
| User.email_preferred | `app/Models/User.php:109` |
| User.isTN() | `app/Models/User.php:233` |
| Footer partial | `resources/views/emails/mjml/partials/footer.blade.php` |
| Donate page | `iznik-nuxt3/pages/donate.vue` |
| StripeDonate | `iznik-nuxt3/components/StripeDonate.vue` |
| DonationButton | `iznik-nuxt3/components/DonationButton.vue` |

---

## Scope Summary

### In scope (this PR):
- Artisan command with chunked processing, progress files, graceful shutdown
- Mailable with tracking, campaign attribution, marketing opt-out URL
- MJML + text templates (placeholder content)
- Unit + feature tests
- User model `marketingOptOutUrl()` method

### Separate PRs (later):
- Campaign landing page in iznik-nuxt3 (`pages/donate/campaign/[slug].vue`)
- Marketing opt-out handler in iznik-nuxt3 (`pages/marketing-optout.vue`)
- Stripe metadata campaign attribution
- Actual email content/copy
- `campaign` column on `users_donations` table (future)
