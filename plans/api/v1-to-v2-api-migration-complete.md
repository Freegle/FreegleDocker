# V1 to V2 API Migration - Complete Analysis and Plan

## Executive Summary

This document consolidates all API migration planning, analysis, and tracking into a single reference. It covers the migration from the PHP v1 API to the Go v2 API for both FD (Freegle main site) and MT (ModTools).

### Key Statistics
- **FD API usage**: 70 unique endpoints, 235 total API calls
- **MT API usage**: 70 unique endpoints, 266 total API calls
- **FD-only endpoints**: 1 (/logs)
- **MT-only endpoints**: 22+ (mostly moderation)
- **Shared endpoints**: 201

### Migration Strategy
- **Phase 1**: Migrate FD - only 1 FD-exclusive endpoint, focus on shared endpoints
- **Phase 2**: Migrate MT - requires 22+ additional moderation endpoints
- **Architecture**: Maintain clean store separation, no merging

---

## Table of Contents

<details>
<summary><strong>📊 Comprehensive API Usage Analysis</strong></summary>

### Summary Statistics

- **FD Files Analyzed**: 391
- **MT Files Analyzed**: 396
- **Unique API Endpoints in FD**: 70
- **Unique API Endpoints in MT**: 70
- **Total FD API Calls**: 235
- **Total MT API Calls**: 266

### API Endpoints by Usage

#### FD-Only API Calls
- **/logs (src)** (1 call)
  - `iznik-nuxt3/stores/misc.js:45`

#### MT-Only API Calls (22+ endpoints)
- **/message (fetchMT)** (3 calls) - `iznik-nuxt3-modtools/stores/message.js:410`
- **/chat (listChatsMT)** (2 calls) - `iznik-nuxt3-modtools/stores/chat.js:55`
- **/chat (fetchChatMT)** (2 calls) - `iznik-nuxt3-modtools/stores/chat.js:69`
- **/config (fetchAdminv2)** (2 calls) - `iznik-nuxt3-modtools/modtools/stores/systemconfig.js:26`
- **/message (approve)** (1 call) - Moderation action
- **/message (reject)** (1 call) - Moderation action
- **/message (hold)** (1 call) - Moderation action
- **/message (release)** (1 call) - Moderation action
- **/message (spam)** (1 call) - Moderation action
- **/message (delete)** (1 call) - Moderation action
- **/message (reply)** (1 call) - Moderation action
- **/message (approveEdits)** (1 call) - Moderation action
- **/message (revertEdits)** (1 call) - Moderation action
- **/chat (sendMT)** (1 call)
- **/chat (fetchMessagesMT)** (1 call)
- **/chat (fetchReviewChatsMT)** (1 call)
- **/chat (unseenCountMT)** (1 call)
- **/user (merge)** (1 call)
- **/config (addSpamKeywordv2)** (1 call)
- **/config (deleteSpamKeywordv2)** (1 call)
- **/config (addWorrywordv2)** (1 call)
- **/config (deleteWorrywordv2)** (1 call)

#### Shared API Calls (Top 20)
Total: 201 endpoints

- **/messages (fetchMessages)**: FD (3 calls), MT (6 calls)
- **/message (update)**: FD (3 calls), MT (6 calls)
- **/noticeboard (action)**: FD (4 calls), MT (4 calls)
- **/news/{id} (fetch)**: FD (3 calls), MT (3 calls)
- **/group (patch)**: FD (3 calls), MT (3 calls)
- **/spammers (add)**: FD (3 calls), MT (3 calls)
- **/groups (list)**: FD (2 calls), MT (2 calls)
- **/user (addEmail)**: FD (2 calls), MT (2 calls)
- **/chat (markRead)**: FD (2 calls), MT (2 calls)
- **/chat (send)**: FD (2 calls), MT (2 calls)
- **/user (save)**: FD (2 calls), MT (2 calls)
- **/image (post)**: FD (2 calls), MT (2 calls)

### Messages Endpoint Usage Pattern

The /messages endpoint shows different usage patterns between FD and MT:

- **GET /messages** - Used only by MT for searching/listing messages for moderation
- **POST /messages?action=MarkSeen** - Used by both FD and MT for marking messages as seen

This reflects the different needs: MT requires message search capabilities for moderation, while FD only needs to mark messages as seen.

### Key Insights

1. **Moderation Actions are MT-Only**: All message moderation actions (approve, reject, hold, release, spam) are exclusively in MT

2. **MT-Specific Chat Operations**: MT has specialized chat operations for moderation (listChatsMT, fetchChatMT, sendMT, fetchReviewChatsMT)

3. **Configuration Management**: System and spam configuration is MT-only (fetchAdminv2, spam keywords, worry words)

4. **Shared Core Functionality**: Both FD and MT share core user operations (profile, messaging, chat, groups)

</details>

<details>
<summary><strong>📋 Migration Task Tracking</strong></summary>

## ⚠️ Email Dependency Constraint

**CRITICAL**: Go v2 API cannot send emails. APIs that send emails must remain in PHP until either:
1. Email sending capability is added to Go, OR
2. A separate email service is created that Go can call

### APIs That Send Emails (Deferred to Phase 3+)

**Must Stay in PHP Until Email Solution Implemented:**
- `/session` - Password reset, verification emails
- `/user` - Welcome, verification, password reset emails
- `/memberships` - Group join notifications
- `/message` - Outcome notifications, reply notifications
- `/chatmessages` - Chat notifications
- `/communityevent` - Event notifications
- `/volunteering` - Volunteer opportunity notifications
- `/invitation` - Invitation emails
- `/team` - Team notifications
- `/admin` - Admin notifications
- `/group` - Group update notifications
- `/merge` - Account merge notifications
- `/profile` - Profile update notifications
- `/donations` - Donation receipts
- `/stripecreatesubscription` - Subscription confirmations
- `/logs` - Log notifications
- `/dashboard` - Dashboard alerts

## Phase 0: Non-Email Endpoints (Priority Migration)

These endpoints can be safely migrated to Go as they don't send emails.

**Migration Strategy**: Prioritize GET verbs first for quick wins, then migrate other verbs.

### Migration Status Summary

**Fully Migrated (No v1 usage in FD or MT):**
- ✅ ~~`/job`~~ - GET, POST - Completed 2025-09-30
- ✅ ~~`/donations`~~ - GET - Completed 2025-10-01

**Partially Migrated (FD uses v2, MT still uses v1):**
- 🔄 `/chat` (chatrooms) - FD uses v2 for GET, MT still uses v1 for all operations
- 🔄 `/config` - FD uses v2 for GET, MT still uses v1 for PATCH
- 🔄 `/location` (locations) - FD uses v2 for GET, MT still uses v1 for GET/PUT/PATCH/POST
- 🔄 `/story` (stories) - FD uses v2 for GET, MT still uses v1 for GET/PUT/POST

**Partially Migrated (FD uses both v1 and v2):**
- 🔄 `/address` - FD uses v2 for GET, v1 for PATCH/PUT
- 🔄 `/authority` - FD uses v2 for GET, v1 for other operations
- 🔄 `/communityevent` - FD uses v2 for GET, v1 for POST/PATCH/DELETE
- 🔄 `/group` - FD uses v2 for GET, v1 for POST/PATCH
- 🔄 `/isochrone` - FD uses v2 for GET, v1 for PUT/POST
- 🔄 `/message` - FD uses v2 for GET, v1 for POST/PATCH/DELETE
- 🔄 `/newsfeed` - FD uses v2 for GET, v1 for POST
- 🔄 `/notification` - FD uses v2 for GET, v1 for POST/DELETE
- 🔄 `/user` - FD uses v2 for GET, v1 for PUT/PATCH/POST
- 🔄 `/volunteering` - FD uses v2 for GET, v1 for POST/PATCH/DELETE

### Phase 0.1: Read-Only GET Endpoints (First Priority)

**Note**: Only listing endpoints **actually used by FD** (found via jscodeshift analysis of Pinia stores). MT-only endpoints are in Phase 2.

**Analysis Method**: Used jscodeshift to find all v1 API calls in FD Pinia stores (stores/*.js) that are imported/used by FD components (components/*, pages/*).

#### GET Endpoints Used by FD:
- [x] ~~`/giftaid` - GET - Gift Aid data (GiftAidAPI.get)~~ **COMPLETED 2025-10-13**
- [x] ~~`/logo` - GET - Logo retrieval (LogoAPI.fetch)~~ **COMPLETED 2025-10-13**
- [ ] `/microvolunteering` - GET - Micro-volunteering challenges (MicroVolunteeringAPI.challenge)
- [ ] `/user` - GET - User data by email, MT user data (UserAPI.fetchByEmail, fetchMT)

**Note**: Several endpoints have GET operations already in v2 (like `/newsfeed`, `/group`, `/message`) but FD still uses some v1 methods for these - see "Partially Migrated" section above.

### Phase 0.2: Write Operations (Second Priority)

**Note**: Only listing endpoints **actually used by FD** (found via jscodeshift analysis).

#### POST/PATCH/PUT/DELETE Endpoints Used by FD (Non-Email):
- [ ] `/image` - POST - Image upload (ImageAPI.post) - Requires file upload support in v2
- [ ] `/messages` - POST (action: MarkSeen) - Mark messages as seen (MessageAPI.markSeen) - Database write only, no email

**Note**: The following write operations are used by FD but likely send emails (deferred to Phase 3+):
- `/group` - PATCH - Group updates (GroupAPI.patch) - Likely sends group update notifications
- `/newsfeed` - POST - Multiple actions: seen, unfollow, unhide, hide, convertToStory, referto, report (NewsAPI.*) - Likely sends notifications
- `/team` - PATCH - Add/Remove team members (TeamAPI.add, remove) - Likely sends team membership notifications

## Phase 1: FD Migration (Email-Dependent - DEFERRED)

**⚠️ ALL PHASE 1 ENDPOINTS SEND EMAILS - DEFERRED UNTIL EMAIL SOLUTION IMPLEMENTED**

These endpoints cannot be migrated to Go until email sending capability is added. When ready to migrate, follow the same GET-first strategy as Phase 0.

### Phase 1.1: Read Operations (GET verbs only) - DEFERRED

When email solution is ready, migrate these GET operations first:

- [ ] `/session` - GET - Check login status (DEFERRED - related to email endpoints)
- [ ] `/user` - GET - Fetch user profile (DEFERRED - related to email endpoints)
- [ ] `/message` - GET - Fetch message details (DEFERRED - related to email endpoints)
- [ ] `/messages` - GET - List messages (DEFERRED - related to email endpoints)
- [ ] `/chatrooms` - GET, GET /chatrooms/{id} - List/fetch chats (DEFERRED - sends notifications)
- [ ] `/chatmessages` - GET - Fetch chat messages (DEFERRED - sends notifications)
- [ ] `/group` - GET - Fetch group details (DEFERRED - related to email endpoints)
- [ ] `/memberships` - GET - List memberships (DEFERRED - related to email endpoints)
- [ ] `/communityevent` - GET - Event details (DEFERRED - sends notifications)
- [ ] `/volunteering` - GET - Volunteer opportunities (DEFERRED - sends notifications)
- [ ] `/team` - GET - Team details (DEFERRED - sends notifications)
- [ ] `/profile` - GET - Profile details (DEFERRED - sends notifications)
- [ ] `/donations` - GET - Donation history (DEFERRED - sends receipts)
- [ ] `/giftaid` - GET - Gift aid status (review for email dependencies)
- [ ] `/logs` - GET - Log retrieval (DEFERRED - sends notifications)
- [ ] `/dashboard` - GET - Dashboard data (DEFERRED - sends alerts)
- [ ] `/notification` - GET - Notification list (DEFERRED - notification system)
- [ ] `/alert` - GET - Alert details (review for email dependencies)

### Phase 1.2: Write Operations - DEFERRED

After GET operations are stable AND email solution implemented, migrate write operations:

#### Core Authentication & Session
**⚠️ DEFERRED - SENDS EMAILS** (Password reset, verification emails)
- [ ] `/session` - POST, DELETE, PATCH - Login/logout/session updates (DEFERRED)
  - POST /session?action=LostPassword - Password reset emails
  - POST /session?action=Verify - Email verification
  - POST /session?action=Confirm - Account confirmation

#### User Profile Management
**⚠️ DEFERRED - SENDS EMAILS** (Email verification, notifications)
- [ ] `/user` - PUT, PATCH, POST - User CRUD and actions (DEFERRED)
  - PUT /user - Register user (welcome emails)
  - PATCH /user - Update profile (verification emails)
  - POST /user?action=Rate - Rate user
  - POST /user?action=AddEmail - Add email (verification)
  - POST /user?action=RemoveEmail - Remove email
  - POST /user?action=Unbounce - Clear bounce status

#### Core Messaging (Posts)
**⚠️ DEFERRED - SENDS EMAILS** (Outcome notifications, replies)
- [ ] `/message` - POST, PATCH, DELETE - Message CRUD (DEFERRED)
  - POST /message - Create/update (notifications)
  - POST /message?action=Outcome - Mark outcome (notifications)
  - POST /message?action=Promise - Promise item
  - POST /message?action=Renege - Renege on promise
- [ ] `/messages` - POST - Bulk operations (DEFERRED)
  - POST /messages?action=MarkSeen - Mark messages seen

#### Chat System
**⚠️ DEFERRED - SENDS EMAILS** (Chat notifications)
- [ ] `/chatrooms` - PUT, POST - Chat room CRUD (DEFERRED)
  - PUT /chatrooms - Create chat room
  - POST /chatrooms?action=Block - Block chat
  - POST /chatrooms?action=Report - Report chat
- [ ] `/chatmessages` - POST, PATCH, DELETE - Chat message CRUD (DEFERRED)
  - POST /chatmessages - Send message (notifications)
  - POST /chatmessages?action=MarkSeen - Mark seen
  - PATCH /chatmessages - Edit message
  - DELETE /chatmessages - Delete message

#### Groups & Memberships
**⚠️ DEFERRED - SENDS EMAILS**
- [ ] `/group` - POST, PATCH - Group management (DEFERRED)
  - POST /group - Create group
  - PATCH /group - Update group (notifications)
- [ ] `/memberships` - POST, PUT, DELETE - Membership CRUD (DEFERRED)
  - POST /memberships - Join group (welcome emails)
  - DELETE /memberships - Leave group

#### Community Features
**⚠️ DEFERRED - SENDS EMAILS** (Event/volunteer notifications)
- [ ] `/communityevent` - POST, PATCH, DELETE - Event CRUD (DEFERRED)
- [ ] `/volunteering` - POST, PATCH, DELETE - Volunteer CRUD (DEFERRED)
- [ ] `/invitation` - POST - Send invitations (DEFERRED - invitation emails)
- [ ] `/team` - POST, PATCH, DELETE - Team CRUD (DEFERRED)
- [ ] `/profile` - POST, PATCH - Profile updates (DEFERRED - notifications)

#### Financial/Donations
**⚠️ DEFERRED - SENDS EMAILS** (Receipts, confirmations)
- [ ] `/donations` - POST - Create donation (DEFERRED - sends receipts)
- [ ] `/giftaid` - POST, PATCH - Gift aid management (review for emails)
- [ ] `/stripecreateintent` - POST - Create payment intent (review for emails)
- [ ] `/stripecreatesubscription` - POST - Create subscription (DEFERRED - confirmation emails)

#### System/Utility (Email-Dependent)
**⚠️ DEFERRED - SENDS EMAILS**
- [ ] `/logs` - POST - Create log entry (DEFERRED - notifications)
- [ ] `/dashboard` - POST - Dashboard actions (DEFERRED - alerts)
- [ ] `/merge` - POST - Merge accounts (DEFERRED - merge notifications)
- [ ] `/admin` - POST, PATCH - Admin operations (DEFERRED - admin notifications)
- [ ] `/notification` - POST, DELETE - Notification management (DEFERRED)
- [ ] `/alert` - POST, PATCH - Alert management (review for emails)

## Phase 2: MT Migration

**Note**: MT migration depends on Phase 1 completion. Most MT endpoints also send emails and are DEFERRED.

### Phase 2.1: MT Read Operations (GET verbs) - DEFERRED

MT-specific GET operations (depends on Phase 1.1 completion):

- [ ] `/messages` - GET - List/search messages for moderation (MT only) (DEFERRED - related to email endpoints)
- [ ] `/chatrooms` - GET /chatrooms?action=ListForReview - Chats for review (MT only) (DEFERRED)
- [ ] `/config` - GET - Fetch admin config (MT only) (DEFERRED - related to config updates)
- [ ] `/modconfig` - GET - Moderation configuration (MT only)
- [ ] `/spammers` - GET - List spammers (MT only)
- [ ] `/status` - GET - System status (MT only)

### Phase 2.2: MT Write Operations - DEFERRED

After Phase 1.2 completion AND email solution implemented:

#### Core Moderation Actions
**⚠️ DEFERRED - SENDS EMAILS** (Moderation notifications)
- [ ] `/message` - POST - MT-specific moderation actions (DEFERRED)
  - POST /message?action=Approve - Approve message (notifications)
  - POST /message?action=Reject - Reject message (notifications)
  - POST /message?action=Hold - Hold message
  - POST /message?action=Release - Release message (notifications)
  - POST /message?action=Spam - Mark as spam
  - POST /message?action=Delete - Delete message
  - POST /message?action=Reply - Reply to message (notifications)
  - POST /message?action=ApproveEdits - Approve edits (notifications)
  - POST /message?action=RevertEdits - Revert edits

#### Member Management
**⚠️ DEFERRED - SENDS EMAILS** (Ban/moderation notifications)
- [ ] `/memberships` - POST - MT-specific membership actions (DEFERRED)
  - POST /memberships?action=Ban - Ban member (notifications)
  - POST /memberships?action=Unban - Unban member (notifications)
  - POST /memberships?action=Hold - Hold membership
  - POST /memberships?action=Release - Release membership (notifications)
- [ ] `/user` - POST - MT-specific user actions (DEFERRED)
  - POST /user?action=Merge - Merge users (MT only) (notifications)
  - POST /user?action=Block - Block user (notifications)
  - POST /user?action=Unblock - Unblock user (notifications)
- [ ] `/spammers` - POST, DELETE - Spammer management (MT only)

#### Chat Moderation
**⚠️ DEFERRED - SENDS EMAILS** (Chat moderation notifications)
- [ ] `/chatrooms` - POST - MT-specific chat actions (DEFERRED)
  - POST /chatrooms?action=Block - Block chat (notifications)
  - POST /chatrooms?action=Report - Report chat (notifications)
- [ ] `/chatmessages` - POST - MT-specific chat message actions (DEFERRED)
  - POST /chatmessages?action=sendMT - Send as moderator (notifications)

#### Configuration Management
- [ ] `/config` - POST, PATCH, DELETE - Admin configuration (MT only)
  - POST /config?action=AddSpamKeywordv2 - Add spam keyword
  - DELETE /config?action=DeleteSpamKeywordv2 - Delete spam keyword
  - POST /config?action=AddWorrywordv2 - Add worry word
  - DELETE /config?action=DeleteWorrywordv2 - Delete worry word
- [ ] `/modconfig` - POST, PATCH - Moderation configuration (MT only)

## Phase 3: Cleanup (Week 15)

### Final Tasks
- [ ] Remove v1 API fallback code
- [ ] Update all API documentation
- [ ] Archive PHP API code
- [ ] Update deployment scripts
- [ ] Final production deployment

</details>

<details>
<summary><strong>🔍 Detailed V1 API Endpoint Inventory</strong></summary>

## Complete PHP Endpoint List

All 58 endpoints found in `/iznik-server/http/api/`:

```
abtest.php          changes.php         error.php           logs.php           poll.php
activity.php        chatmessages.php    export.php          memberships.php    profile.php
address.php         chatrooms.php       giftaid.php         mentions.php       request.php
admin.php           comment.php         group.php           merge.php          session.php
alert.php           communityevent.php  groups.php          message.php        shortlink.php
api.php             config.php          image.php           messages.php       socialactions.php
authority.php       dashboard.php       invitation.php      microvolunteering.php spammers.php
bulkop.php          domains.php         isochrone.php       modconfig.php      src.php
donations.php       item.php            jobs.php            newsfeed.php       status.php
locations.php       logo.php            noticeboard.php     notification.php   stdmsg.php
                                                                               stories.php
                                                                               stripecreateintent.php
                                                                               stripecreatesubscription.php
                                                                               team.php
                                                                               tryst.php
                                                                               user.php
                                                                               usersearch.php
                                                                               visualise.php
                                                                               volunteering.php
```

## Key Endpoint Details

### /session endpoint (session.php)
- `GET /session` - Check login status
- `POST /session` - Login
- `DELETE /session` - Logout
- `POST /session?action=LostPassword` - Password reset
- `PATCH /session` - Update session settings
- `POST /session?action=Verify` - Verify email
- `POST /session?action=Confirm` - Confirm account
**Used in:** Both FD and MT

### /message endpoint (message.php)
- `GET /message` - Fetch message details
- `POST /message` - Create/update message
- `PATCH /message` - Update message fields
- `DELETE /message` - Delete message
- `POST /message?action=View` - Mark message viewed
- `POST /message?action=AddBy` - Add interested user
- `POST /message?action=RemoveBy` - Remove interested user
- `POST /message?action=Promise` - Promise item
- `POST /message?action=Renege` - Renege on promise
- `POST /message?action=Outcome` - Mark outcome
**MT-only operations:**
- `POST /message?action=Approve` - Approve message
- `POST /message?action=Reject` - Reject message
- `POST /message?action=Hold` - Hold message
- `POST /message?action=Release` - Release message
- `POST /message?action=Spam` - Mark as spam

### /messages endpoint (messages.php)
- `GET /messages` - List messages
  - Used by: **MT ONLY** - message store (fetchMessagesMT, searchMT, searchMember)
- `POST /messages` - Bulk operations
  - Used by: message store (markSeen) in **both FD and MT**
**Used in:** GET: MT only, POST: Both FD and MT

### /user endpoint (user.php)
- `GET /user` - Fetch user profile
- `PUT /user` - Create/register user
- `PATCH /user` - Update user profile
- `POST /user?action=Rate` - Rate user
- `POST /user?action=AddEmail` - Add email address
- `POST /user?action=RemoveEmail` - Remove email
- `POST /user?action=Unbounce` - Clear bounce status
**MT-only operations:**
- `POST /user?action=Merge` - Merge users
- `POST /user?action=Block` - Block user
- `POST /user?action=Unblock` - Unblock user

### /chatrooms endpoint (chatrooms.php)
- `GET /chatrooms` - List chats
- `PUT /chatrooms` - Create chat room
- `GET /chatrooms/{id}` - Fetch specific chat
**MT-only operations:**
- `GET /chatrooms?action=ListForReview` - Chats for review
- `POST /chatrooms?action=Block` - Block chat
- `POST /chatrooms?action=Report` - Report chat

### /chatmessages endpoint (chatmessages.php)
- `GET /chatmessages` - Fetch chat messages
- `POST /chatmessages` - Send message
- `PATCH /chatmessages` - Edit message
- `DELETE /chatmessages` - Delete message
- `POST /chatmessages?action=MarkSeen` - Mark as seen

</details>

<details>
<summary><strong>🛠️ How to Run the Analysis</strong></summary>

## Overview
This folder contains scripts for analyzing v1 API usage across the FD (Freegle) and MT (ModTools) codebases using jscodeshift for semantic JavaScript/Vue analysis.

## How the Analysis Works

### 1. Tool: jscodeshift
- **Why jscodeshift?** Unlike grep/search, it understands JavaScript AST (Abstract Syntax Tree)
- **Semantic understanding**: Tracks API calls regardless of variable names or destructuring patterns
- **Handles Vue files**: Extracts `<script>` sections from Vue files for analysis

### 2. Analysis Process

#### Step 1: Install jscodeshift (if not already installed)
```bash
npm install -g jscodeshift
```

#### Step 2: Run the parallel analysis script
```bash
cd /home/edward/FreegleDockerWSL
./plans/scripts/run-parallel-api-analysis.sh
```

This script:
1. Processes all JS files in `iznik-nuxt3` (FD) and `iznik-nuxt3-modtools` (MT)
2. Extracts script sections from Vue files for analysis
3. Runs up to 10 parallel analysis jobs for performance
4. Combines results into comprehensive reports
5. Generates a markdown summary

### 3. Output Files
- Individual results per file (deleted after combining)
- Combined JSON for FD and MT (temporary)
- Final markdown report with statistics and insights

## What Gets Analyzed
- **FD**: All files in `iznik-nuxt3/` excluding node_modules and .nuxt
- **MT**: All files in `iznik-nuxt3-modtools/` excluding node_modules and .nuxt
- **File types**: .js and .vue files

## Expected Runtime
- ~8-10 minutes for full analysis
- Processes ~900+ files total
- Uses parallel processing for efficiency

## Repeating the Analysis

### When to Re-run
- After significant code changes
- Before major migration phases
- To verify migration progress
- To find remaining v1 API calls

### Steps to Repeat
1. Ensure you're in the FreegleDockerWSL directory
2. Run: `./plans/scripts/run-parallel-api-analysis.sh`
3. Review the generated report
4. Update migration documentation as needed

</details>

<details>
<summary><strong>📈 Migration Recommendations</strong></summary>

## Architecture Findings
1. **Clean Architecture**: All API calls go through the store layer - no direct API calls in components
2. **Code Reuse**: MT reuses most of FD's codebase with additional moderation features
3. **Store Usage**: Most stores marked as "unused" are actually used in components
4. **API Coverage**: Comprehensive v1 API usage across ~170 unique endpoint operations
5. **Separation of Concerns**: Clear separation between public (FD) and moderation (MT) functionality

## Recommendations
1. **Maintain Store Structure**: Don't merge stores during migration
2. **RESTful Conversion**: Convert action-based endpoints to proper REST
3. **Prioritize User Impact**: Focus on high-traffic endpoints first
4. **Test Thoroughly**: Each migrated endpoint needs comprehensive testing
5. **Backwards Compatibility**: May need to maintain v1 during transition
6. **Performance Monitoring**: Track performance improvements from v2

## Technical Considerations
1. **Authentication**: Ensure v2 maintains same session/auth mechanism
2. **Parameter Format**: v1 uses form-encoded, v2 should use JSON
3. **Error Handling**: Standardize error responses in v2
4. **Rate Limiting**: Implement in v2 from the start
5. **Caching**: Consider caching strategy for v2 endpoints

## Testing Requirements

### For Each Migrated Endpoint
- [ ] Unit tests in Go
- [ ] Integration tests
- [ ] Frontend store tests
- [ ] E2E Playwright tests
- [ ] Performance benchmarks
- [ ] Error handling validation

## Rollback Plan

### Per Endpoint
- [ ] Feature flag for v1/v2 switching
- [ ] Monitor error rates
- [ ] Quick rollback procedure documented
- [ ] Data consistency checks

## Success Metrics

### Track for Each Migration
- [ ] Response time improvement
- [ ] Error rate reduction
- [ ] Memory usage reduction
- [ ] User satisfaction metrics
- [ ] Support ticket reduction

</details>

<details>
<summary><strong>📊 Migration Implications</strong></summary>

## Phase 1 (FD Migration) - Simpler Than Expected
- Only 1 FD-only endpoint (/logs)
- No need to migrate GET /messages for FD
- Focus on shared endpoints used by both
- Estimated effort: 10 weeks

## Phase 2 (MT Migration) - Significant Additional Work
- 22+ MT-only endpoints for moderation
- Specialized chat operations
- Configuration management system
- All message moderation actions
- Estimated effort: 4 weeks

## Shared Endpoints - Need Careful Migration
- 201 endpoints used by both systems
- Must maintain compatibility during transition
- Consider feature flags for gradual rollout
- Requires coordination between FD and MT teams

</details>

---

## Migration Procedure for Each Endpoint

When migrating an endpoint from v1 (PHP) to v2 (Go), follow these steps **in order**:

### 1. Implement v2 Go API
- Create or update handler in `iznik-server-go/{domain}/{domain}.go`
- Add route in `iznik-server-go/router/routes.go` with Swagger annotations
- Ensure proper error handling and return format matches v1

### 2. Add or Update Tests
- Add test functions to `iznik-server-go/test/{domain}_test.go`
- Test all HTTP methods (GET, POST, PATCH, DELETE, PUT)
- Test error cases (missing params, invalid IDs, etc.)
- **Run tests in container**: `docker exec freegle-apiv2 go test ./test/{domain}_test.go ./test/main_test.go ./test/testUtils.go -v`
- Verify tests pass before proceeding

### 3. Update FD Client Code
- Check if endpoint is used in FD: `grep -r "api/{endpoint}\|/{endpoint}" iznik-nuxt3/stores iznik-nuxt3/api --include="*.js"`
- Update API wrapper in `iznik-nuxt3/api/{Domain}API.js`:
  - Change `$get('/endpoint')` to `$getv2('/endpoint')`
  - Change `$post('/endpoint')` to `$postv2('/endpoint')`
  - Change `$patch('/endpoint')` to `$patchv2('/endpoint')`
- Update store if needed (usually already uses v2 method names)
- **Stage changes**: `cd iznik-nuxt3 && git add api/{Domain}API.js`

### 4. Update MT Client Code (if applicable)
- Check if endpoint is used in MT: `grep -r "api/{endpoint}\|/{endpoint}" iznik-nuxt3-modtools --include="*.js" --include="*.vue"`
- Update API wrapper in `iznik-nuxt3-modtools/api/{Domain}API.js` (same process as FD)
- **Stage changes**: `cd iznik-nuxt3-modtools && git add api/{Domain}API.js`

### 5. Mark v1 PHP API as Deprecated
**CONSISTENT FORMAT REQUIRED** - Add deprecation comment at top of function:
```php
// TODO: DEPRECATED - This endpoint has been migrated to v2 Go API
// Can be retired once all FD/MT clients are using v2
// Migrated: YYYY-MM-DD
// V2 endpoints: <list of v2 endpoints>
// Used by: <FD only | MT only | Both FD and MT> for <purpose>
```
- Leave the implementation intact (for backwards compatibility)
- See `src.php` and `jobs.php` for examples
- **Stage changes**: `cd iznik-server && git add http/api/{endpoint}.php`

### 6. Update Migration Document
- Mark endpoint as completed with date: `[x] ~~\`/endpoint\` - VERB - Description~~ **COMPLETED YYYY-MM-DD**`
- Move from pending to completed section if needed
- **Stage changes**: `cd FreegleDockerWSL && git add plans/api/v1-to-v2-api-migration-complete.md`

### 7. Stage All Changes in Git
```bash
# In iznik-server-go submodule
cd iznik-server-go
git add {domain}/*.go router/routes.go test/{domain}_test.go
git status

# In iznik-server submodule
cd ../iznik-server
git add http/api/{endpoint}.php
git status

# In iznik-nuxt3 submodule
cd ../iznik-nuxt3
git add api/{Domain}API.js
git status

# In main repository
cd ..
git add plans/api/v1-to-v2-api-migration-complete.md
git status
```

### 8. Verify Before Commit
- [ ] All Go tests pass
- [ ] v2 API handler implemented
- [ ] Client code updated to use v2
- [ ] v1 API marked as deprecated with proper format
- [ ] Migration document updated
- [ ] All changes staged in git

## Next Steps

1. **Continue Phase 0**: Migrate remaining non-email endpoints (GET verbs first)
2. **Set up monitoring**: Track v1 vs v2 performance metrics
3. **Create feature flags**: Enable gradual rollout and quick rollback
4. **Document v2 API**: Swagger documentation is auto-generated from annotations
5. **Test continuously**: Run the analysis script weekly to track progress

## Supporting Files

The following scripts are available in `plans/scripts/`:
- `analyze-all-api-calls.js` - jscodeshift transformer for finding API calls
- `run-parallel-api-analysis.sh` - Parallel analysis runner script
- `api-analysis-readme.md` - Detailed documentation on running the analysis