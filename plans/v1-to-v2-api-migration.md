# V1 to V2 API Migration

## Task Status

This is the master tracking table for API migration. Each row is designed as a single Ralph task.

| # | Endpoint | Verbs | Status | Notes |
|---|----------|-------|--------|-------|
| 1 | /job | GET, POST | ✅ Complete | Completed 2025-09-30 |
| 2 | /donations | GET | ✅ Complete | Completed 2025-10-01 |
| 3 | /giftaid | GET | ✅ Complete | Completed 2025-10-13 |
| 4 | /logo | GET | ✅ Complete | Completed 2025-10-13 |
| 5 | /microvolunteering | GET | ✅ Complete | Completed 2025-10-14 |
| 6 | /user/byemail | GET | ✅ Complete | Completed 2025-10-17 |
| 7 | /authority | GET | 🔄 Partial | FD uses v2 for /authority/{id}/message, v1 for /authority |
| 8 | /address | PATCH, PUT | ⬜ Pending | 5 FD usages, no email |
| 9 | /isochrone | PUT, POST, PATCH | ⬜ Pending | 2 FD usages, no email |
| 10 | /newsfeed | POST | ⬜ Pending | 10 FD usages, no email |
| 11 | /notification | POST | ⬜ Pending | 3 FD usages, no email |
| 12 | /volunteering | POST, PATCH, DELETE | ⬜ Pending | 5 FD usages, no email |
| 13 | /image | POST | ⬜ Pending | File upload support needed |
| 14 | /messages | POST (MarkSeen) | ⬜ Pending | Database write only, no email |
| 15 | /chat | All verbs | 🔄 Partial | FD uses v2 for GET, MT still uses v1 |
| 16 | /config | PATCH | 🔄 Partial | FD uses v2 for GET, MT still uses v1 |
| 17 | /location | All verbs | 🔄 Partial | FD uses v2 for GET, MT still uses v1 |
| 18 | /story | All verbs | 🔄 Partial | FD uses v2 for GET, MT still uses v1 |

### Deferred (Send Emails)

These endpoints cannot be migrated until Go has email capability:

| Endpoint | Reason |
|----------|--------|
| /session | Password reset, verification emails |
| /user | Welcome, verification, password reset emails |
| /memberships | Group join notifications |
| /message | Outcome notifications, reply notifications |
| /chatmessages | Chat notifications |
| /communityevent | Event notifications |
| /volunteering | Volunteer opportunity notifications |
| /invitation | Invitation emails |
| /team | Team notifications |
| /group | Group update notifications |

---

## Overview

Migration from PHP v1 API to Go v2 API for Freegle (FD) and ModTools (MT).

### Key Statistics
- **FD API usage**: 70 unique endpoints, 235 total API calls
- **MT API usage**: 70 unique endpoints, 266 total API calls
- **FD-only endpoints**: 1 (/logs)
- **MT-only endpoints**: 22+ (mostly moderation)
- **Shared endpoints**: 201

### Strategy
- **Phase 0**: Non-email endpoints (current focus)
- **Phase 1**: FD email-dependent endpoints (after email solution)
- **Phase 2**: MT-specific endpoints
- **Phase 3**: Cleanup and retirement

---

## How to Use with Ralph

Run Ralph with a specific endpoint:
```bash
./ralph.sh -t "Migrate /address endpoint write operations to v2 API"
```

Each migration task should:
1. Analyse the v1 PHP implementation
2. Implement v2 Go handler with tests
3. Update FD client code to use v2
4. Update MT client code if applicable
5. Mark v1 as deprecated

---

## Migration Procedure

### 1. Implement v2 Go API
- Create handler in `iznik-server-go/{domain}/{domain}.go`
- Add route in `iznik-server-go/router/routes.go` with Swagger annotations
- Ensure proper error handling

### 2. Add Tests
- Add test functions to `iznik-server-go/test/{domain}_test.go`
- Test all HTTP methods and error cases
- Run: `docker exec freegle-apiv2 go test ./test/{domain}_test.go ./test/main_test.go ./test/testUtils.go -v`

### 3. Update FD Client Code
- Update API wrapper in `iznik-nuxt3/api/{Domain}API.js`:
  - `$get('/endpoint')` → `$getv2('/endpoint')`
  - `$post('/endpoint')` → `$postv2('/endpoint')`

### 4. Update MT Client Code (if applicable)
- Same process in `iznik-nuxt3-modtools/api/{Domain}API.js`

### 5. Mark v1 PHP as Deprecated
Add at top of function:
```php
// TODO: DEPRECATED - Migrated to v2 Go API
// Migrated: YYYY-MM-DD
// V2 endpoints: <list>
```

### 6. Update This Document
- Change status from ⬜ Pending to ✅ Complete
- Add completion date

---

## Email Dependency Constraint

**CRITICAL**: Go v2 API cannot send emails. APIs that send emails must remain in PHP until:
1. Email sending capability is added to Go, OR
2. A separate email service is created that Go can call

---

## API Analysis

### FD-Only API Calls
- **/logs** (1 call) - `iznik-nuxt3/stores/misc.js:45`

### MT-Only API Calls (22+ endpoints)
Moderation actions, MT-specific chat operations, configuration management.

### Shared API Calls (Top endpoints)
- **/messages (fetchMessages)**: FD (3), MT (6)
- **/message (update)**: FD (3), MT (6)
- **/noticeboard (action)**: FD (4), MT (4)
- **/news/{id} (fetch)**: FD (3), MT (3)
- **/group (patch)**: FD (3), MT (3)

---

## Analysis Scripts

Scripts for analysing v1 API usage are in `plans/scripts/`:
- `analyze-all-api-calls.js` - jscodeshift transformer
- `run-parallel-api-analysis.sh` - Parallel analysis runner

To re-run analysis:
```bash
cd /home/edward/FreegleDockerWSL
./plans/scripts/run-parallel-api-analysis.sh
```

---

## Complete PHP Endpoint List

All 58 endpoints in `/iznik-server/http/api/`:

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
stories.php         stripecreateintent.php  stripecreatesubscription.php
team.php            tryst.php           user.php            usersearch.php
visualise.php       volunteering.php
```
