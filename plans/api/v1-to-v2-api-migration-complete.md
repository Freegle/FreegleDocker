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

## Phase 1: FD Migration

### Core Authentication & Session
- [ ] Migrate `/session` endpoint
  - [ ] GET /session - Get current session
  - [ ] POST /session - Login
  - [ ] DELETE /session - Logout
- [ ] Migrate `/user` authentication operations
  - [ ] PUT /user - Sign up
  - [ ] POST /user?action=LostPassword
  - [ ] POST /user?action=Unsubscribe
- [ ] Update auth store to use v2 endpoints
- [ ] Test authentication flow end-to-end

### User Profile Management
- [ ] Migrate remaining `/user` operations
  - [ ] GET /user - Fetch profile
  - [ ] PATCH /user - Update profile
  - [ ] DELETE /user - Delete account
  - [ ] POST /user?action=Rate
  - [ ] POST /user?action=AddEmail
  - [ ] POST /user?action=RemoveEmail
- [ ] Update user store for v2
- [ ] Test profile management features

### Core Messaging (Posts)
- [ ] Migrate `/message` endpoint
  - [ ] GET /message - Fetch messages
  - [ ] PUT /message - Create draft
  - [ ] POST /message - Submit message
  - [ ] PATCH /message - Update message
  - [ ] DELETE /message - Delete message
  - [ ] POST /message?action=UpdatePublic
  - [ ] POST /message?action=Outcome
- [ ] Migrate `/messages` bulk operations (FD portion only)
  - [ ] POST /messages?action=MarkSeen
- [ ] Update message store
- [ ] Test posting/browsing functionality

### Chat System
- [ ] Migrate `/chatrooms` endpoint
  - [ ] GET /chatrooms - List chats
  - [ ] POST /chatrooms - Create chat
- [ ] Migrate `/chatmessages` endpoint
  - [ ] GET /chatmessages - Get messages
  - [ ] POST /chatmessages - Send message
  - [ ] DELETE /chatmessages - Delete message
- [ ] Update chat store
- [ ] Test chat functionality

### Groups & Memberships
- [ ] Migrate `/group` endpoint
  - [ ] GET /group - Fetch group
  - [ ] PATCH /group - Update group
- [ ] Migrate `/memberships` endpoint (FD operations only)
  - [ ] GET /memberships - Get memberships
  - [ ] POST /memberships - Join group
  - [ ] DELETE /memberships - Leave group
- [ ] Update group/membership stores
- [ ] Test group features

### Social Features
- [ ] Migrate `/newsfeed` endpoint
- [ ] Migrate `/stories` endpoint
- [ ] Migrate `/comment` - Comments system
- [ ] Migrate `/mentions` - User mentions
- [ ] Migrate `/socialactions` - Social media actions
- [ ] Migrate `/noticeboard` - Notice board
- [ ] Migrate `/profile` - User profiles
- [ ] Migrate `/tryst` - Meeting arrangements
- [ ] Update newsfeed/stories stores
- [ ] Test social features

### Additional Services
- [ ] Migrate `/communityevent` endpoint
- [ ] Migrate `/volunteering` endpoint
- [ ] Migrate `/locations` endpoint
- [ ] Migrate `/address` endpoint
- [ ] Migrate `/notification` endpoint
- [ ] Migrate `/image` - Image handling
- [ ] Migrate `/shortlink` - URL shortening
- [ ] Migrate `/export` - Data export
- [ ] Migrate `/jobs` - Job listings
- [ ] Migrate `/isochrone` - Geographic isochrone maps

### Financial/Donations
- [ ] Migrate `/donations` - Donation handling
- [ ] Migrate `/giftaid` - UK Gift Aid
- [ ] Migrate `/stripecreateintent` - Stripe payment intents
- [ ] Migrate `/stripecreatesubscription` - Stripe subscriptions

### Tracking & Analytics
- [x] ~~Migrate `/src` endpoint~~ **COMPLETED 2025-09-29**
  - [x] POST /src - Record traffic source (FD only)
- [ ] Migrate `/abtest` - A/B testing
- [ ] Migrate `/visualise` - Data visualization
- [ ] Migrate `/poll` - Polling mechanism

### System/Utility
- [ ] Migrate `/error` - Error reporting
- [ ] Migrate `/changes` - Change tracking
- [ ] Migrate `/logs` - System logs
- [ ] Migrate `/status` - System status
- [ ] Migrate `/config` - Configuration
- [ ] Migrate `/dashboard` - Dashboard data
- [ ] Migrate `/usersearch` - User search
- [ ] Migrate `/merge` - User/account merging
- [ ] Migrate `/invitation` - Invitations
- [ ] Migrate `/request` - Generic requests
- [ ] Migrate `/stdmsg` - Standard messages
- [ ] Migrate `/team` - Team management
- [ ] Migrate `/microvolunteering` - Micro-volunteering
- [ ] Migrate `/authority` - Authority/permissions
- [ ] Migrate `/domains` - Domain management
- [ ] Migrate `/groups` - Groups listing
- [ ] Migrate `/item` - Item management
- [ ] Migrate `/logo` - Logo management
- [ ] Migrate `/alert` - System alerts
- [ ] Migrate `/bulkop` - Bulk operations

### Integration Testing
- [ ] Full FD integration testing

## Phase 2: MT Migration

### Core Moderation
- [ ] Migrate `/messages` MT-specific operations
  - [ ] GET /messages - List/search messages (MT only)
- [ ] Migrate message moderation actions
  - [ ] POST /message?action=Approve
  - [ ] POST /message?action=Reject
  - [ ] POST /message?action=Hold
  - [ ] POST /message?action=Release
  - [ ] POST /message?action=Spam
- [ ] Update MT message store
- [ ] Test moderation workflow

### Week 12: Member Management
- [ ] Migrate membership moderation
  - [ ] POST /memberships?action=Ban
  - [ ] POST /memberships?action=Unban
  - [ ] POST /memberships?action=Hold
  - [ ] POST /memberships?action=Release
- [ ] Migrate `/spammers` endpoint
- [ ] Test member management

### Week 13: Admin Features
- [ ] Migrate `/admin` endpoint
- [ ] Migrate `/modconfig` endpoint
- [ ] Update admin/modconfig stores
- [ ] Test admin features

### Week 14: Final MT Features
- [ ] Migrate remaining MT-specific endpoints
- [ ] Full MT integration testing
- [ ] Performance testing
- [ ] Rollback planning

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

## Next Steps

1. **Start Phase 1**: Begin with core authentication and session management
2. **Set up monitoring**: Track v1 vs v2 performance metrics
3. **Create feature flags**: Enable gradual rollout and quick rollback
4. **Document v2 API**: Create OpenAPI specification as you migrate
5. **Test continuously**: Run the analysis script weekly to track progress

## Supporting Files

The following scripts are available in `plans/scripts/`:
- `analyze-all-api-calls.js` - jscodeshift transformer for finding API calls
- `run-parallel-api-analysis.sh` - Parallel analysis runner script
- `api-analysis-readme.md` - Detailed documentation on running the analysis