# API Migration Review Log (Phase 6A)

This file tracks the per-endpoint migration checklist from the v2 API migration plan.
Each migrated endpoint must complete ALL items before it is considered done.

Updated on master only (coordination document).

---

## /comment (POST, PATCH, DELETE)

**Branch**: feature/v2-comment-writes
**Go handler**: comment/comment.go
**PHP source**: http/api/comment.php

### Functional Completeness
- [x] Every `case` in PHP `switch($action)` has a corresponding Go handler
- [x] Every PHP method/function called within the endpoint has been accounted for
- [x] All query parameters accepted by v1 are accepted by v2
- [x] All POST/PATCH body fields accepted by v1 are accepted by v2 (including `flag`)
- [x] Default values match
- [x] Return value structure matches
- [x] Empty/null return handling matches

### Authentication & Authorization
- [x] Anonymous access: same behaviour (401 for unauthenticated)
- [x] Logged-in user: same permissions required
- [x] Admin/support checks replicated
- [x] Moderator checks replicated with correct group scope
- [x] Owner-only checks replicated

### Database Operations
- [x] Every write operation exists in Go
- [x] Every read operation exists in Go
- [ ] Transaction boundaries match (PHP has no explicit transactions here)
- [x] Auto-increment ID return matches

### Side Effects
- [x] Email sends replicated via email queue (no emails in comment endpoint)
- [x] Logging replicated (no logging in comment endpoint)
- [x] Cache invalidation replicated (no cache invalidation)
- [x] External API calls replicated (none)
- [x] Notification creation replicated (none)
- [x] Activity logging replicated (none)

### Data Transformation & Privacy
- [x] Date/time formats match
- [x] Null vs missing field handling matches
- [x] Privacy filtering (emails/phones/addresses) - N/A for comments
- [x] HTML encoding/escaping matches
- [x] Pagination parameters match (no pagination)

### Error Handling
- [x] Error HTTP status codes match (401, 403, 404, 200)
- [x] Error response body format matches
- [x] Specific error strings match if clients depend on them

### Client Integration
- [ ] FD client switched to v2 (deferred - client switch phase)
- [ ] MT client switched (or documented as deferred) - MT only user
- [ ] No remaining v1 calls to this endpoint in client code

### Testing
- [x] Go unit tests cover happy path, auth failure, validation errors, edge cases
- [ ] Go test coverage >= 90% on new handler code (need to measure)
- [ ] At least one Playwright E2E test exercises v2 codepath (deferred)
- [ ] Swagger annotations present and generate-swagger.sh run (pending)

### Deprecation
- [ ] PHP file has deprecation comment with date and v2 endpoint paths (pending)
- [x] PHP code is NOT deleted

### Review Notes
**Adversarial review (2026-02-08):**
- **CRITICAL fix**: Added `flagOthers()` function - when a comment is flagged, PHP sets `reviewreason` and `reviewrequestedat` on the user's memberships in other groups. This was completely missing.
- **Tests added**: TestCommentCreateWithFlagOthers, TestCommentEditWithFlagOthers
- **Commit**: 92399ab on feature/v2-comment-writes
- **No GET endpoint**: PHP has GET for single comments but v2 doesn't implement it. Documented as acceptable since comments are fetched via user endpoint.

---

## /address (POST, PATCH, DELETE)

**Branch**: feature/v2-address-writes
**Go handler**: address/address.go
**PHP source**: http/api/isochrone.php (address handling)

### Functional Completeness
- [x] Every `case` in PHP `switch($action)` has a corresponding Go handler
- [x] Every PHP method/function called within the endpoint has been accounted for
- [x] All query parameters accepted by v1 are accepted by v2
- [x] All POST/PATCH body fields accepted by v1 are accepted by v2
- [x] Default values match
- [x] Return value structure matches
- [x] Empty/null return handling matches

### Authentication & Authorization
- [x] Anonymous access: same behaviour
- [x] Logged-in user: same permissions required
- [x] Admin/support checks replicated
- [x] Moderator checks replicated (N/A for address)
- [x] Owner-only checks replicated

### Database Operations
- [x] Every write operation exists in Go
- [x] Every read operation exists in Go
- [ ] Transaction boundaries match
- [x] Auto-increment ID return matches

### Side Effects
- [x] Email sends replicated (none)
- [x] Logging replicated
- [x] Cache invalidation replicated (none)
- [x] External API calls replicated (none)
- [x] Notification creation replicated (none)
- [x] Activity logging replicated

### Data Transformation & Privacy
- [x] Date/time formats match
- [x] Null vs missing field handling matches
- [x] Privacy filtering - addresses are user-owned
- [x] HTML encoding/escaping matches
- [x] Pagination parameters match

### Error Handling
- [x] Error HTTP status codes match
- [x] Error response body format matches
- [x] Specific error strings match

### Client Integration
- [ ] FD client switched to v2 (deferred - client switch phase)
- [ ] MT client switched (deferred)
- [ ] No remaining v1 calls (deferred)

### Testing
- [x] Go unit tests cover happy path, auth failure, validation errors, edge cases
- [ ] Go test coverage >= 90% (need to measure)
- [ ] At least one Playwright E2E test exercises v2 codepath (deferred)
- [ ] Swagger annotations present and generate-swagger.sh run (pending)

### Deprecation
- [ ] PHP file has deprecation comment with date and v2 endpoint paths (pending)
- [x] PHP code is NOT deleted

### Review Notes
**Adversarial review (2026-02-08):**
- No CRITICAL or HIGH issues found. Address endpoint is straightforward CRUD with owner-only access.
- CI passing (#1804)

---

## /communityevent (POST, PATCH, DELETE)

**Branch**: feature/v2-communityevent-writes
**Go handler**: communityevent/communityEvent.go
**PHP source**: http/api/communityevent.php

### Functional Completeness
- [x] Every `case` in PHP `switch($action)` has a corresponding Go handler (Create, Update w/ AddGroup/RemoveGroup/AddDate/RemoveDate/SetPhoto/Renew/Expire, Delete, Hold/Release)
- [x] Every PHP method/function called within the endpoint has been accounted for
- [x] All query parameters accepted by v1 are accepted by v2
- [x] All POST/PATCH body fields accepted by v1 are accepted by v2
- [x] Default values match
- [x] Return value structure matches
- [x] Empty/null return handling matches

### Authentication & Authorization
- [x] Anonymous access: same behaviour (401 for unauthenticated)
- [x] Logged-in user: same permissions required
- [x] Admin/support checks replicated
- [x] Moderator checks replicated with correct group scope
- [x] Owner-only checks replicated

### Database Operations
- [x] Every write operation exists in Go
- [x] Every read operation exists in Go
- [ ] Transaction boundaries match
- [x] Auto-increment ID return matches

### Side Effects
- [x] Email sends replicated via email queue (none in this endpoint)
- [x] Logging replicated
- [x] Cache invalidation replicated (none)
- [x] External API calls replicated (none)
- [ ] Notification creation replicated - **DEFERRED**: AddGroup creates newsfeed entry + pushNotification to group mods in PHP. Complex (requires Pheanstalk queue). Documented as future work.
- [x] Activity logging replicated

### Data Transformation & Privacy
- [x] Date/time formats match
- [x] Null vs missing field handling matches
- [x] Privacy filtering - N/A
- [x] HTML encoding/escaping matches
- [x] Pagination parameters match

### Error Handling
- [x] Error HTTP status codes match
- [x] Error response body format matches
- [x] Specific error strings match

### Client Integration
- [ ] FD client switched to v2 (deferred - client switch phase)
- [ ] MT client switched (deferred)
- [ ] No remaining v1 calls (deferred)

### Testing
- [x] Go unit tests cover happy path, auth failure, validation errors, edge cases
- [ ] Go test coverage >= 90% (need to measure)
- [ ] At least one Playwright E2E test exercises v2 codepath (deferred)
- [ ] Swagger annotations present and generate-swagger.sh run (pending)

### Deprecation
- [ ] PHP file has deprecation comment with date and v2 endpoint paths (pending)
- [x] PHP code is NOT deleted

### Review Notes
**Adversarial review (2026-02-08):**
- **Fix**: Single() handler was filtering by `heldby IS NULL`, preventing moderators from viewing held items. Fixed to `deleted = 0` only (matches PHP behaviour).
- **Deferred**: AddGroup side effects (newsfeed entry + push notification to group mods). Push notifications use Pheanstalk job queue which doesn't have a Go equivalent yet.
- **Commit**: a8a2af1 on feature/v2-communityevent-writes

---

## /messages/markseen (POST)

**Branch**: feature/v2-messages-markseen
**Go handler**: message/markseen.go
**PHP source**: http/api/messages.php (MarkSeen action)

### Functional Completeness
- [x] Every `case` in PHP `switch($action)` has a corresponding Go handler (MarkSeen only)
- [x] Every PHP method/function called within the endpoint has been accounted for
- [x] All query parameters accepted by v1 are accepted by v2
- [x] All POST/PATCH body fields accepted by v1 are accepted by v2
- [x] Default values match
- [x] Return value structure matches
- [x] Empty/null return handling matches

### Authentication & Authorization
- [x] Anonymous access: same behaviour
- [x] Logged-in user: same permissions required
- [x] Admin/support checks replicated (N/A)
- [x] Moderator checks replicated (N/A)
- [x] Owner-only checks replicated

### Database Operations
- [x] Every write operation exists in Go
- [x] Every read operation exists in Go
- [ ] Transaction boundaries match
- [x] Auto-increment ID return matches (N/A - no insert)

### Side Effects
- [x] Email sends replicated (none)
- [x] Logging replicated
- [x] Cache invalidation replicated (none)
- [x] External API calls replicated (none)
- [x] Notification creation replicated (none)
- [x] Activity logging replicated

### Data Transformation & Privacy
- [x] Date/time formats match
- [x] Null vs missing field handling matches
- [x] Privacy filtering - N/A
- [x] HTML encoding/escaping - N/A
- [x] Pagination parameters - N/A

### Error Handling
- [x] Error HTTP status codes match
- [x] Error response body format matches
- [x] Specific error strings match

### Client Integration
- [ ] FD client switched to v2 (deferred - client switch phase)
- [ ] MT client switched (deferred)
- [ ] No remaining v1 calls (deferred)

### Testing
- [x] Go unit tests cover happy path, auth failure, validation errors, edge cases
- [ ] Go test coverage >= 90% (need to measure)
- [ ] At least one Playwright E2E test exercises v2 codepath (deferred)
- [ ] Swagger annotations present and generate-swagger.sh run (pending)

### Deprecation
- [ ] PHP file has deprecation comment with date and v2 endpoint paths (pending)
- [x] PHP code is NOT deleted

### Review Notes
**Adversarial review (2026-02-08):**
- No issues found. Simple single-action endpoint (MarkSeen updates messages_groups.seentype).
- CI passing (#1803)

---

## /newsfeed (POST, PATCH, DELETE)

**Branch**: feature/v2-newsfeed-writes
**Go handler**: newsfeed/newsfeed.go
**PHP source**: http/api/newsfeed.php

### Functional Completeness
- [x] Every `case` in PHP `switch($action)` has a corresponding Go handler (Love, Unlove, Seen, Follow, Unfollow, Report, Hide, Unhide, ReferToWanted/Offer/Taken/Received, AttachToThread, Create post/reply)
- [x] Every PHP method/function called within the endpoint has been accounted for
- [x] All query parameters accepted by v1 are accepted by v2
- [x] All POST/PATCH body fields accepted by v1 are accepted by v2
- [x] Default values match
- [x] Return value structure matches
- [x] Empty/null return handling matches

### Authentication & Authorization
- [x] Anonymous access: same behaviour (401 for unauthenticated)
- [x] Logged-in user: same permissions required
- [x] Admin/support checks replicated
- [x] Moderator checks replicated with correct group scope (canHidePost for Hide/Unhide)
- [x] Owner-only checks replicated (canModifyPost for Edit/Delete)

### Database Operations
- [x] Every write operation exists in Go
- [x] Every read operation exists in Go
- [ ] Transaction boundaries match (PHP has no explicit transactions)
- [x] Auto-increment ID return matches (createPost returns id)

### Side Effects
- [ ] Email sends replicated via email queue - **DEFERRED**: Report action sends email to ChitChat support in PHP. Deferred until email queue (Phase 0A).
- [x] Logging replicated
- [x] Cache invalidation replicated (none)
- [x] External API calls replicated (none)
- [x] Notification creation replicated (LOVED_POST/LOVED_COMMENT, COMMENT_ON_YOUR_POST for replies and refers)
- [x] Activity logging replicated

### Data Transformation & Privacy
- [x] Date/time formats match
- [x] Null vs missing field handling matches
- [x] Privacy filtering - N/A for newsfeed
- [x] HTML encoding/escaping matches
- [x] Pagination parameters match

### Error Handling
- [x] Error HTTP status codes match (400, 401, 403, 200)
- [x] Error response body format matches
- [x] Specific error strings match

### Client Integration
- [ ] FD client switched to v2 (deferred - client switch phase)
- [ ] MT client switched (deferred)
- [ ] No remaining v1 calls (deferred)

### Testing
- [x] Go unit tests cover happy path, auth failure, validation errors, edge cases (20+ tests)
- [ ] Go test coverage >= 90% (need to measure)
- [ ] At least one Playwright E2E test exercises v2 codepath (deferred)
- [ ] Swagger annotations present and generate-swagger.sh run (pending)

### Deprecation
- [ ] PHP file has deprecation comment with date and v2 endpoint paths (pending)
- [x] PHP code is NOT deleted

### Review Notes
**Adversarial review (2026-02-08):**
- **CRITICAL security fix**: Hide/Unhide was using canModifyPost (any group mod can hide). Fixed to canHidePost (Admin/Support OR ChitChat Moderation team member only).
- **CRITICAL feature gap**: Create post/reply (the primary write operation!) was completely missing. Added createPost() with spam check, suppression, location, duplicate prevention, thread bumping, and thread contributor notifications.
- **Added**: Love notification (LOVED_POST/LOVED_COMMENT to post author)
- **Added**: ReferToWanted/Offer/Taken/Received actions with createRefer()
- **Added**: AttachToThread action (mod-only)
- **Added**: bumpThread() for reply chain timestamp updates
- **Added**: notifyThreadContributors() for COMMENT_ON_YOUR_POST notifications
- **Fixed**: Unknown actions now return 400 instead of silent 200
- **Deferred**: ConvertToStory (mod action, rarely used)
- **Deferred**: Report email to ChitChat support (requires email queue)
- **Tests added**: 7 new tests (HidePermissionDenied, HideChitChatTeam, CreatePost, CreateReply, LoveNotification, UnknownAction)
- **Commit**: b41e97b on feature/v2-newsfeed-writes

---

## /volunteering (POST, PATCH, DELETE)

**Branch**: feature/v2-volunteering-writes
**Go handler**: volunteering/volunteering.go
**PHP source**: http/api/volunteering.php

### Functional Completeness
- [x] Every `case` in PHP `switch($action)` has a corresponding Go handler (Create, Update w/ AddGroup/RemoveGroup/AddDate/RemoveDate/SetPhoto/Renew/Expire/Hold/Release, Delete, pending field)
- [x] Every PHP method/function called within the endpoint has been accounted for
- [x] All query parameters accepted by v1 are accepted by v2
- [x] All POST/PATCH body fields accepted by v1 are accepted by v2 (including `pending`)
- [x] Default values match
- [x] Return value structure matches
- [x] Empty/null return handling matches

### Authentication & Authorization
- [x] Anonymous access: same behaviour (401 for unauthenticated)
- [x] Logged-in user: same permissions required
- [x] Admin/support checks replicated
- [x] Moderator checks replicated with correct group scope (isModerator for Hold/Release)
- [x] Owner-only checks replicated

### Database Operations
- [x] Every write operation exists in Go
- [x] Every read operation exists in Go
- [ ] Transaction boundaries match
- [x] Auto-increment ID return matches

### Side Effects
- [x] Email sends replicated (none in this endpoint)
- [x] Logging replicated
- [x] Cache invalidation replicated (none)
- [x] External API calls replicated (none)
- [ ] Notification creation replicated - **DEFERRED**: AddGroup creates newsfeed entry + pushNotification to group mods. Complex (requires job queue).
- [x] Activity logging replicated

### Data Transformation & Privacy
- [x] Date/time formats match
- [x] Null vs missing field handling matches
- [x] Privacy filtering - N/A
- [x] HTML encoding/escaping matches
- [x] Pagination parameters match

### Error Handling
- [x] Error HTTP status codes match
- [x] Error response body format matches
- [x] Specific error strings match

### Client Integration
- [ ] FD client switched to v2 (deferred - client switch phase)
- [ ] MT client switched (deferred)
- [ ] No remaining v1 calls (deferred)

### Testing
- [x] Go unit tests cover happy path, auth failure, validation errors, edge cases
- [ ] Go test coverage >= 90% (need to measure)
- [ ] At least one Playwright E2E test exercises v2 codepath (deferred)
- [ ] Swagger annotations present and generate-swagger.sh run (pending)

### Deprecation
- [ ] PHP file has deprecation comment with date and v2 endpoint paths (pending)
- [x] PHP code is NOT deleted

### Review Notes
**Adversarial review (2026-02-08):**
- **CRITICAL feature gap**: Hold/Release actions were completely missing. Added isModerator() helper and Hold/Release case handlers.
- **Missing field**: `pending` field was not in PatchRequest struct. Added it so mods can approve pending volunteering.
- **Fix**: Single() handler was filtering by `heldby IS NULL`, preventing moderators from viewing held items. Fixed to match PHP behaviour.
- **Deferred**: AddGroup side effects (newsfeed entry + push notification to group mods)
- **Tests added**: TestVolunteeringHold, TestVolunteeringRelease, TestVolunteeringHoldNonModerator, TestVolunteeringPending
- **Commit**: dacd158 on feature/v2-volunteering-writes
