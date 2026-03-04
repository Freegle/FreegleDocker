# Fundraising Appeal System - Reworked Design Plan

## Context

Freegle needs to send fundraising appeal emails to all members. Rather than building a separate bulk email system, we combine this with the **existing centralised/suggested admin system** — which already handles central creation, per-group copying, mod approval/editing, and de-duplication across groups. The admin sending needs to be migrated from V1 PHP to iznik-batch (Laravel), and the email templates upgraded from the basic Twig template to MJML with proper responsive layout, marketing opt-out, and campaign tracking.

**Consent basis**: We rely on the 2026 UK soft opt-in law change (Data Protection and Digital Information Act) which allows charities to email existing supporters without explicit prior consent. Comment this in code.

**Key numbers** (from production DB):
- Total active users: 2,672,784
- Non-bouncing with email: 2,007,681
- simplemail=None (opted out): 12,045
- Target: complete send in ~3 days via spool parallelisation

## How the Existing Admin System Works (V1)

1. Support/Admin creates a **suggested admin** (`groupid=NULL`) with subject, text, CTA
2. Cron (`admins.php`) creates **per-group copies** for each Freegle group (unless `autoadmins=0`)
3. Each group's mod sees the copy in their **Pending** tab, can **edit** text/subject/CTA, then **Approve and Send**
4. `mailMembers()` sends to group members using Twig HTML template + plain text
5. `admins_users` table prevents duplicate sends to users on multiple groups
6. 10 parallel spoolers for throughput

**Key files**:
- `iznik-server/include/group/Admin.php` — Admin class, `mailMembers()`, `constructMessage()`
- `iznik-server/scripts/cron/admins.php` — copy suggested admins, process approved
- `iznik-server/mailtemplates/twig/admin.html` — basic Twig template
- `iznik-server-go/admin/admin.go` — Go API for CRUD/hold/release
- `iznik-nuxt3/modtools/pages/admins.vue` — ModTools admin page (3 tabs: Pending/Create/Previous)
- `iznik-nuxt3/modtools/components/ModAdmin.vue` — individual admin card with edit/approve/delete
- `iznik-nuxt3/modtools/stores/admins.js` — admin store
- `iznik-nuxt3/api/AdminsAPI.js` — API client

---

## Business Rules from V1 Admin Tests (`iznik-server/test/ut/php/api/adminAPITest.php`)

The V1 test suite has 4 tests that define the canonical business rules. All must be replicated in iznik-batch.

### testBasic — Auth, CRUD, Hold/Release, Approval, Send
- **Auth**: Not logged in → ret=1. Logged in non-mod → ret=2. Mod → ret=0.
- **New admins created as pending** (`pending=1`), not held (`heldby=NULL`).
- **Hold/Release**: Hold sets `heldby` to current user. Release clears it.
- **Process while pending**: Returns 0 emails (pending=1 blocks sending).
- **Approve via PATCH** (`pending=0`), then process → sends 1 email.
- **Email validation**: Users with blackhole/invalid email domains silently skipped (process returns 0).
- **Delete**: Removes admin record.

### testSuggested — Site-wide Admins, Copying, De-duplication
- **Only support role** can create suggested admins (no groupid). Mod role alone → ret=2.
- **copyForGroup()**: Creates per-group copy with `parentid` pointing to original.
- **De-duplication**: `admins_users` table tracks (userid, adminid) for parentid. User in multiple groups receives email once per parent admin, not once per group copy.
- **Sequence**: Approve group1 copy → sends to group1 members → records in admins_users. Approve group2 copy → skips users already in admins_users for that parentid.

### testActiveOnly — Inactive User Filtering
- **Threshold**: `lastaccess` older than 7 days (`Engage::USER_INACTIVE`).
- **Suggested admins auto-set activeonly=TRUE**.
- User with lastaccess 1 year ago → skipped (process returns 0).
- User with recent lastaccess → sent (process returns 1).

### testNonessential — Opt-out Filtering
- **essential=FALSE + member with relevantallowed=0** → skipped.
- **essential=FALSE + moderator** → always sent (mods can't opt out).
- **essential=TRUE** → sent regardless of relevantallowed.
- Filter check: `if (!$admin['essential'] && !$member['relevantallowed']) skip`.

### Key DB Schema (from tests)
- `admins`: id, groupid, subject, text, ctatext, ctalink, pending, complete, parentid, heldby, heldat, activeonly, sendafter, essential, createdby, editedat, editedby
- `admins_users`: userid, adminid (de-dup tracking for suggested admins)
- **Settable via API PATCH**: subject, text, pending, ctatext, ctalink, sendafter, essential

---

## Plan Overview

### Phase 1: Migrate admin sending to iznik-batch (this PR)
### Phase 2: MJML templates + marketing opt-out
### Phase 3: Campaign tracking + MT dashboard
### Phase 4: AMP support (if needed)

---

## Phase 1: Migrate Admin Sending to iznik-batch

### 1.1 Artisan Command: `app/Console/Commands/Mail/SendAdminCommand.php`

Replaces V1's `Admin::process()` and `Admin::mailMembers()`.

**Signature**: `mail:admin:send`

**Options**:
- `--limit=0` : Max emails per run (0 = unlimited)
- `--spool` : Spool via EmailSpoolerService for parallel sending
- `--dry-run` : Count what would be sent
- `--id=` : Send a specific admin by ID (for testing)

**Traits**: `GracefulShutdown`, `PreventsOverlapping`, `ChunkedProcessing`, `FeatureFlags`

**Feature flag**: `Admin` in `FREEGLE_MAIL_ENABLED_TYPES`

**Logic** (mirrors V1's `process()` + `mailMembers()`):
1. Find approved admins: `pending=0, complete IS NULL, (created >= 7 days ago OR editedat recent), sendafter passed`
2. For each admin's group, query members
3. Apply filters:
   - `activeonly` → skip users not accessed in 7 days (`Engage::USER_INACTIVE`)
   - `essential=0` → skip users with `relevantallowed=0`
   - `parentid` set → check `admins_users` to prevent duplicate sends
   - Skip bouncing users, deleted users, TN users, no preferred email
   - **New**: Skip `simplemail=None` for non-essential admins
4. Construct and spool email via `AdminMail` mailable
5. Record in `admins_users` for suggested admin de-dup
6. Mark admin `complete` when done

### 1.2 Artisan Command: `app/Console/Commands/Mail/CopyAdminsCommand.php`

Replaces V1's admin copying logic from `admins.php` cron.

**Signature**: `mail:admin:copy`

**Logic**:
1. Delete pending admins older than 31 days
2. Find suggested admins (`groupid IS NULL, complete IS NULL`)
3. For each Freegle group with `autoadmins` enabled, create per-group copy via DB insert
4. Mark suggested admin as complete

### 1.3 Mailable: `app/Mail/Admin/AdminMail.php`

Extends `MjmlMailable`, uses `TrackableEmail` + `LoggableEmail`.

**Constructor**: Takes admin record + User recipient + group info.

**Properties**: Subject, text body, CTA text/link, group name, mods email, campaign (nullable), marketing opt-out URL.

**Template rendering**: Renders `text` field into MJML template. Text is plain text — template uses `nl2br` for HTML conversion (same as V1 Twig). Mods edit text content only; MJML structure is fixed and protected.

**Two output parts** (auto-generated from the same source content):
- **Plain text**: `text` + CTA appended + footer with opt-out URL
- **HTML**: MJML template renders `text` into responsive HTML with header, CTA button, footer, tracking pixel

### 1.4 MJML Template: `resources/views/emails/mjml/admin/admin.blade.php`

Replaces `iznik-server/mailtemplates/twig/admin.html`. Based on existing MJML patterns.

**Structure** (mods edit text nodes, not structure):
```
Header (@include components/header)
Subject line (green, from admin.subject)
Body text (from admin.text, nl2br)
CTA button (if admin.ctatext/ctalink set)
Sponsors section (from group sponsorships)
Footer:
  - Group name + unsubscribe link
  - Settings link
  - Marketing opt-out link (for non-essential/fundraising admins)
  - Charity registration info
Tracking pixel
```

### 1.5 Text Template: `resources/views/emails/text/admin/admin.blade.php`

Plain text version — subject, body, CTA link, footer.

### 1.6 Mod Editing Model

**Current**: Mods edit `subject`, `text`, `ctatext`, `ctalink` via plain text fields in ModTools. This continues unchanged.

**Why this is safe**: The MJML template receives these values as data and renders them into fixed structural positions. Mods can change the WORDS but cannot alter the HTML/MJML structure, add tags, or break the layout. The template handles all formatting.

**What mods can edit** (via existing ModAdmin.vue fields):
- `subject` — email subject line
- `text` — body content (plain text, rendered via `nl2br` into MJML body section)
- `ctatext` — button text (e.g., "Donate now")
- `ctalink` — button URL (e.g., "/donate/campaign/2026-march")

**What mods cannot edit** (controlled by template):
- Email header/branding
- Layout structure
- Footer content (opt-out, charity info)
- Tracking pixel
- HTML/CSS styling

This gives mods full control over the MESSAGE while protecting the EMAIL STRUCTURE from breakages.

---

## Phase 2: Fundraising-Specific Features

### 2.1 Schema: Add `campaign` to `admins` table

New migration:
```php
$table->string('campaign', 50)->nullable()->after('essential');
```

This links a suggested admin to a fundraising campaign for attribution.

### 2.2 Schema: Add `campaign` to `users_donations` table

```php
$table->string('campaign', 50)->nullable()->index()->after('source');
```

Permanent donation attribution (email_tracking purged after 90 days).

### 2.3 Go API: Extend admin endpoints

- `PostAdmin` / `PatchAdmin`: Accept `campaign` field
- `ListAdmins`: Return `campaign` in response

### 2.4 ModTools: Add campaign field to admin creation

In `modtools/pages/admins.vue` Create tab:
- New optional field: "Campaign slug" (`b-form-input`)
- Only visible when creating suggested (system-wide) admins
- Populates `campaign` field on admin record

### 2.5 Marketing Opt-Out

- `User::marketingOptOutUrl()` method (using existing `getUserKey()`)
- Footer link in MJML template: "Don't want fundraising emails? [Click here to opt out]"
- Only shown when admin has `campaign` set (fundraising) or `essential=0` (newsletter)
- Nuxt3 handler at `/marketing-optout/{id}/{key}` sets `marketingconsent=0` (separate PR)

### 2.6 Campaign Landing Page

`pages/donate/campaign/[slug].vue` in iznik-nuxt3 (separate PR):
- Reuses `StripeDonate` + `DonationButton` components
- Passes campaign slug to Stripe metadata
- Wording: "Any funds raised above £5,000 will be used for general Freegle work"

---

## Phase 3: MT Fundraising Dashboard

### 3.1 New page: `modtools/pages/fundraising.vue`

- **Campaign dropdown**: `b-form-select` populated from distinct `campaign` values in `users_donations`
- **Amount raised over time**: Google Charts `LineChart` (same pattern as `ActivityGraph.vue`)
- **Summary stats**: Total raised, donors, average, target, % reached

### 3.2 Go API endpoints

- Extend dashboard with `CampaignDonations` component (daily sums filtered by campaign)
- New endpoint: `GET /api/v2/donations/campaigns` — list all campaigns with totals

---

## Phase 4: AMP Support (Future — Non-Payment Only)

**One-click donate via AMP form is NOT possible.** Stripe requires client-side JavaScript (Stripe.js) for PCI compliance — payment card details never touch Freegle servers. AMP emails cannot load external JS (only AMP components). AMP `amp-form` can POST and show inline results but cannot redirect to external payment pages, so even Stripe Checkout hosted sessions won't work from within email.

**What AMP CAN do** for admin emails (non-payment interactivity):
- One-click opt-out (amp-form POST to set marketingconsent=0)
- Inline feedback/survey (amp-form POST with response)
- Live content (amp-list to show current donation total)

**What AMP CANNOT do**:
- Payment processing (requires Stripe.js/PayPal SDK)
- Redirect to external payment pages

**Donate button approach**: All fundraising emails use a **tracked link** to the campaign landing page (`/donate/campaign/[slug]`), where full Stripe/PayPal JS flow runs in browser. The CTA link field mods edit (`ctalink`) points here.

If AMP admin template is added later:
- `resources/views/emails/amp/admin/admin.blade.php`
- Uses existing `AmpEmail` trait for token generation
- Mods still only edit text nodes — AMP structure is template-controlled
- AMP version adds opt-out button + live donation counter, NOT payment

---

## Implementation Order

### PR 1 — iznik-batch: Migrate admin sending (Phase 1)
Files to create:
- `app/Console/Commands/Mail/SendAdminCommand.php`
- `app/Console/Commands/Mail/CopyAdminsCommand.php`
- `app/Mail/Admin/AdminMail.php`
- `resources/views/emails/mjml/admin/admin.blade.php`
- `resources/views/emails/text/admin/admin.blade.php`
- `tests/Unit/Mail/AdminMailTest.php`
- `tests/Feature/Mail/AdminCommandTest.php`

Files to modify:
- `app/Models/User.php` — add `marketingOptOutUrl()`

### PR 2 — Schema + Go API: Campaign tracking (Phase 2)
Files to create:
- `database/migrations/2026_03_XX_add_campaign_to_admins.php`
- `database/migrations/2026_03_XX_add_campaign_to_users_donations.php`

Files to modify:
- `iznik-server-go/admin/admin.go` — add campaign field to struct + CRUD
- `iznik-server-go/dashboard/dashboard.go` — add CampaignDonations component

### PR 3 — iznik-nuxt3: ModTools updates (Phase 2-3)
Files to create:
- `modtools/pages/fundraising.vue`
- `modtools/components/ModFundraisingChart.vue`
- `pages/donate/campaign/[slug].vue`
- `pages/marketing-optout/[id]/[key].vue`

Files to modify:
- `modtools/pages/admins.vue` — add campaign field to Create tab
- `modtools/components/ModAdmin.vue` — show campaign badge

---

## Patterns Reused

| Pattern | Source File |
|---------|------------|
| Admin model + approval flow | `iznik-server/include/group/Admin.php` |
| Admin cron (copy + process) | `iznik-server/scripts/cron/admins.php` |
| Admin UI (approve/edit/hold) | `iznik-nuxt3/modtools/components/ModAdmin.vue` |
| MjmlMailable + MJML rendering | `iznik-batch/app/Mail/MjmlMailable.php` |
| AskForDonation mailable | `iznik-batch/app/Mail/Donation/AskForDonation.php` |
| TrackableEmail + LoggableEmail | `iznik-batch/app/Mail/Traits/` |
| EmailSpoolerService | `iznik-batch/app/Services/EmailSpoolerService.php` |
| GracefulShutdown + ChunkedProcessing | `iznik-batch/app/Traits/` |
| ActivityGraph (Google Charts) | `iznik-nuxt3/components/ActivityGraph.vue` |
| User.getUserKey() | `iznik-batch/app/Models/User.php` |
| Dashboard API | `iznik-server-go/dashboard/dashboard.go` |

---

## Verification

### Unit tests: `tests/Unit/Mail/AdminMailTest.php`
- Mailable construction with subject, text, CTA, group info
- From address = noreply, reply-to = group mods email
- Subject line from admin record
- TrackableEmail tracking pixel + tracked URLs
- LoggableEmail headers (X-Freegle-Email-Type: Admin)
- Marketing opt-out URL present when `essential=0` or `campaign` set
- Marketing opt-out URL absent when `essential=1` and no campaign
- Plain text alternative includes CTA link + footer

### Feature tests: `tests/Feature/Mail/SendAdminCommandTest.php`
Mirror V1's 4 tests, plus new filters:

**testBasic** (mirrors V1 testBasic):
- Approved admin (`pending=0`) sends to group members
- Pending admin (`pending=0` not set) sends 0 emails
- Users with invalid/no preferred email silently skipped
- Admin marked `complete` after all members processed

**testSuggested** (mirrors V1 testSuggested):
- De-duplication via `admins_users` with `parentid`
- User in 2 groups, both copies approved → receives 1 email total
- Records (userid, parentid) in admins_users after send

**testActiveOnly** (mirrors V1 testActiveOnly):
- `activeonly=1`: user with lastaccess >7 days ago → skipped
- `activeonly=1`: user with recent lastaccess → sent
- `activeonly=0`: inactive user still receives email

**testNonessential** (mirrors V1 testNonessential):
- `essential=0` + member `relevantallowed=0` → skipped
- `essential=0` + moderator → always sent
- `essential=1` → sent regardless of relevantallowed

**New batch-specific tests**:
- Skip bouncing users (bouncing=1)
- Skip deleted users (deleted IS NOT NULL)
- Skip TN users (User::isTN())
- Skip `simplemail=None` for non-essential admins
- `--dry-run` counts without sending
- `--limit=N` stops after N emails
- `--spool` spools via EmailSpoolerService
- GracefulShutdown respects SIGTERM mid-batch
- Feature flag: disabled type → no-op

### Feature tests: `tests/Feature/Mail/CopyAdminsCommandTest.php`
- Suggested admin copied to each Freegle group
- Groups with `autoadmins=0` skipped
- Per-group copy has `parentid` set to original
- Pending admins older than 31 days deleted
- Suggested admin marked complete after copying

### Integration
- Existing ModTools UI (Create → Pending → Edit → Approve) unchanged — same Go API
- Campaign attribution: donation with campaign field from landing page
