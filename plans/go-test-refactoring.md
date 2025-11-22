# Go Test Refactoring Plan

**Goal**: Remove dependency on testenv.php by having each Go test create its own test data.

**Benefits**:
- Tests are self-contained and independent
- Tests can run in parallel
- Easier to debug failures
- No shared state between tests

---

## Iterative Testing Workflow

### Local Docker Environment Commands

```bash
# Rebuild and restart the apiv2 container after code changes
docker-compose build apiv2 && docker-compose up -d apiv2

# Run ALL Go tests via status API
curl -X POST http://localhost:8081/api/tests/go

# Watch apiv2 container logs for test output
docker logs -f apiv2

# Run tests directly inside the container (for faster iteration)
docker exec -it apiv2 go test -v ./test/... -run TestName

# Run a single test file
docker exec -it apiv2 go test -v ./test/user_test.go ./test/main_test.go ./test/testUtils.go

# Run a single test function
docker exec -it apiv2 go test -v ./test/... -run TestDeleted

# Run tests with verbose output showing all logs
docker exec -it apiv2 go test -v ./test/... 2>&1 | tee /tmp/test-output.log
```

### Iterative Development Process

For each factory function or test refactor:

1. **Make the change** in `testUtils.go` or the test file
2. **Copy to container** (changes in submodule are mounted, but verify):
   ```bash
   # If needed, rebuild container to pick up changes
   docker-compose build apiv2 && docker-compose up -d apiv2
   ```
3. **Run the specific test** to verify:
   ```bash
   docker exec -it apiv2 go test -v ./test/... -run TestName
   ```
4. **Check logs** for DEBUG output to verify data creation
5. **If it fails**, check the error, fix, and repeat from step 1
6. **If it passes**, commit the change and move to next item

### Test-by-Test Refactoring Order

Refactor tests in this order (simplest first, each building on previous):

```
1. TestDeleted (user_test.go)           - Just needs: deleted user
2. TestGetUserByEmail (user_test.go)    - Just needs: user with email
3. TestJobs (jobs_test.go)              - Just needs: job at coordinates
4. TestJobClick (jobs_test.go)          - Just needs: job ID
5. TestListGroups (group_test.go)       - Just needs: groups
6. TestVolunteering (volunteering_test.go) - Needs: volunteering + dates + group
7. TestCommunityEvent (communityevent_test.go) - Needs: event + dates + group
8. TestAddress (address_test.go)        - Needs: user + address
9. TestAuth (auth_test.go)              - Needs: user + session + membership
10. TestIsochrones (isochrone_test.go)  - Needs: user + isochrone
11. TestFeed (newsfeed_test.go)         - Needs: user + newsfeed + location
12. TestListChats (chat_test.go)        - Needs: full user setup
13. TestMessages (message_test.go)      - Needs: group + messages
14. TestAPISearch (search_test.go)      - Needs: message with indexed words
```

### Verification Checkpoints

After each phase, run the full test suite to ensure nothing is broken:

```bash
# Run all tests
docker exec -it apiv2 go test -v ./test/... 2>&1 | tee /tmp/go-tests-phase-N.log

# Check for failures
grep -E "(FAIL|PASS)" /tmp/go-tests-phase-N.log

# Count results
grep -c "PASS" /tmp/go-tests-phase-N.log
grep -c "FAIL" /tmp/go-tests-phase-N.log
```

### Rollback Strategy

If a refactored test breaks:

1. **Check git diff** to see what changed:
   ```bash
   git -C iznik-server-go diff test/
   ```

2. **Revert specific file** if needed:
   ```bash
   git -C iznik-server-go checkout -- test/specific_test.go
   ```

3. **Keep factory functions** even if test refactor fails - they're additive

4. **Maintain backwards compatibility** - keep old `GetUserWithToken()` working until all tests are migrated

### Debugging Failed Tests

When a test fails:

1. **Run with verbose output**:
   ```bash
   docker exec -it apiv2 go test -v ./test/... -run TestName 2>&1
   ```

2. **Check DEBUG log lines** for data creation issues

3. **Query database directly** to verify data was created:
   ```bash
   docker exec -it percona mysql -u root -ppassword iznik -e "SELECT * FROM users WHERE fullname LIKE 'Test%' ORDER BY id DESC LIMIT 5"
   ```

4. **Check for constraint violations** in error messages

5. **Verify foreign key relationships** exist before creating dependent data

### Keeping testenv.php Working During Migration

During the migration, both approaches must work:

1. **Don't delete testenv.php** until ALL tests are refactored
2. **Keep SetupTestEnvironment()** calling for tests not yet migrated
3. **Factory functions should be additive** - they create new data, not modify existing
4. **Use unique prefixes** so factory-created data doesn't conflict with testenv.php data

### Progress Tracking

After completing each test refactor, update this file:
- Change `[ ]` to `[x]` for completed items
- Add any notes about issues encountered
- Record the date of completion

---

## Analysis Summary

### Current State

**testenv.php creates:**
- Groups: FreeglePlayground, FreeglePlayground2
- Users: Test User, Moderator, Test User 2, Deleted user, Support, Admin (each with emails, memberships)
- Addresses, isochrones for test users
- Chat rooms (User2User, User2Mod) with messages
- Messages with spatial indexes
- Newsfeed items
- Jobs at specific coordinates
- Volunteering opportunities with dates
- Community events with dates
- Sessions, lookup data (spam_keywords, weights, etc.)

**The complex dependency is `GetUserWithToken()`** which requires a user with ALL of:
- An isochrone
- An address
- A User2User chat as user1 with a message
- A User2Mod chat as user1 with a message
- A group membership
- A volunteering opportunity linked to their group
- A community event linked to their group

### Test Files and Data Dependencies

| Test File | Key Data Needed |
|-----------|-----------------|
| chat_test.go | Full user, mod-to-group chat, message with spaces |
| message_test.go | FreeglePlayground group, 2+ messages, user with message |
| group_test.go | Multiple groups with volunteers |
| user_test.go | Deleted user, user with email |
| address_test.go | User with address, Support/Admin users |
| newsfeed_test.go | User with location, newsfeed entries |
| volunteering_test.go | Volunteering with dates and group |
| communityevent_test.go | Event with dates and group |
| search_test.go | Message with spaces in subject |
| jobs_test.go | Jobs near specific coordinates |
| isochrone_test.go | User with isochrone, messages in area |
| auth_test.go | User with session |

---

## TODO List

### Phase 1: Create Test Data Factory Functions

- [ ] Create `uniquePrefix()` helper function
- [ ] Create `CreateTestGroup(t, prefix)` function
- [ ] Create `CreateTestUser(t, prefix, role)` function
- [ ] Create `CreateTestMembership(t, userID, groupID, role)` function
- [ ] Create `CreateTestAddress(t, userID)` function
- [ ] Create `CreateTestIsochrone(t, userID, lat, lng)` function
- [ ] Create `CreateTestChatRoom(t, user1ID, user2ID, groupID, chatType)` function
- [ ] Create `CreateTestChatMessage(t, chatID, userID, message)` function
- [ ] Create `CreateTestMessage(t, userID, groupID, subject, lat, lng)` function
- [ ] Create `CreateTestVolunteering(t, userID, groupID)` function
- [ ] Create `CreateTestCommunityEvent(t, userID, groupID)` function
- [ ] Create `CreateTestNewsfeed(t, userID, lat, lng)` function
- [ ] Create `CreateTestJob(t, lat, lng)` function
- [ ] Create `CreateTestSession(t, userID)` function

### Phase 2: Create Composite Helper Functions

- [ ] Create `CreateFullTestUser(t, prefix)` - user with all relationships
- [ ] Create test environment structs for complex scenarios

### Phase 3: Refactor Simple Tests First

- [ ] Refactor `user_test.go` - TestDeleted, TestGetUserByEmail
- [ ] Refactor `group_test.go` - TestListGroups
- [ ] Refactor `jobs_test.go` - TestJobs, TestJobClick
- [ ] Refactor `location_test.go` (if exists)

### Phase 4: Refactor Medium Complexity Tests

- [ ] Refactor `address_test.go` - TestAddress, TestAddressModeratorAccess
- [ ] Refactor `auth_test.go` - TestAuth, TestPersistent, TestSearches, TestPublicLocation
- [ ] Refactor `isochrone_test.go` - TestIsochrones
- [ ] Refactor `newsfeed_test.go` - TestFeed
- [ ] Refactor `volunteering_test.go` - TestVolunteering
- [ ] Refactor `communityevent_test.go` - TestCommunityEvent

### Phase 5: Refactor Complex Tests

- [ ] Refactor `chat_test.go` - TestListChats, TestCreateChatMessage, TestCreateChatMessageLoveJunk
- [ ] Refactor `message_test.go` - TestMessages, TestBounds, TestMyGroups, TestMessagesByUser, TestCount, TestActivity
- [ ] Refactor `search_test.go` - TestGetWords, TestSearchExact, TestSearchTypo, TestSearchSounds, TestSearchStarts, TestAPISearch

### Phase 6: Cleanup

- [ ] Remove `SetupTestEnvironment()` call from `main_test.go`
- [ ] Remove `setupComprehensiveTestDataLegacy()` function from `testUtils.go`
- [ ] Remove old `GetUserWithToken()` function (or simplify it)
- [ ] Delete `testenv.php` from FreegleDocker
- [ ] Update status container to not call testenv.php before Go tests
- [ ] Run full test suite and verify all tests pass
- [ ] Update CLAUDE.md if needed

---

## Implementation Details

### Unique Identifier Strategy

Each test will use a unique prefix combining:
- Test name
- Timestamp (nanoseconds)

```go
func uniquePrefix(testName string) string {
    return fmt.Sprintf("%s_%d", testName, time.Now().UnixNano())
}
```

This ensures:
- Tests can run in parallel
- No collisions between test runs
- Easy to identify test-created data in logs

### Debug Logging Pattern

All factory functions should include debug logging:

```go
func CreateTestUser(t *testing.T, prefix string, role string) uint64 {
    email := fmt.Sprintf("%s@test.com", prefix)
    t.Logf("DEBUG: Creating test user prefix=%s role=%s email=%s", prefix, role, email)

    // ... creation code ...

    if result.Error != nil {
        t.Logf("ERROR: Failed to create user: %v", result.Error)
        t.FailNow()
    }

    t.Logf("DEBUG: Created user id=%d email=%s", userID, email)
    return userID
}
```

### Example Refactored Test

```go
func TestListChats(t *testing.T) {
    prefix := uniquePrefix("chat_list")

    // Create test data
    groupID := CreateTestGroup(t, prefix)
    userID := CreateTestUser(t, prefix, "User")
    otherUserID := CreateTestUser(t, prefix+"_other", "User")
    CreateTestMembership(t, userID, groupID, "Member")

    // Create chat with message
    chatID := CreateTestChatRoom(t, userID, &otherUserID, nil, "User2User")
    CreateTestChatMessage(t, chatID, userID, "Test message")

    // Create mod chat
    modChatID := CreateTestChatRoom(t, userID, nil, &groupID, "User2Mod")
    CreateTestChatMessage(t, modChatID, userID, "Test mod message")

    // Create required related data
    CreateTestAddress(t, userID)
    CreateTestIsochrone(t, userID, 55.9533, -3.1883)
    CreateTestVolunteering(t, userID, groupID)
    CreateTestCommunityEvent(t, userID, groupID)

    _, token := CreateTestSession(t, userID)

    t.Logf("DEBUG: Created test environment - user=%d, group=%d, chat=%d", userID, groupID, chatID)

    // Actual test assertions...
    resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/chat?jwt="+token, nil))
    assert.Equal(t, 200, resp.StatusCode)
    // ... rest of test
}
```

### Factory Function Templates

#### CreateTestGroup

```go
func CreateTestGroup(t *testing.T, prefix string) uint64 {
    db := database.DBConn
    name := fmt.Sprintf("TestGroup_%s", prefix)

    t.Logf("DEBUG: Creating test group name=%s", name)

    result := db.Exec(`INSERT INTO `+"`groups`"+` (nameshort, namefull, type, onhere, polyindex, lat, lng)
        VALUES (?, ?, 'Freegle', 1, ST_GeomFromText('POINT(-3.1883 55.9533)', 3857), 55.9533, -3.1883)`,
        name, "Test Group "+prefix)

    if result.Error != nil {
        t.Fatalf("ERROR: Failed to create group: %v", result.Error)
    }

    var groupID uint64
    db.Raw("SELECT id FROM `groups` WHERE nameshort = ? ORDER BY id DESC LIMIT 1", name).Scan(&groupID)

    t.Logf("DEBUG: Created group id=%d name=%s", groupID, name)
    return groupID
}
```

#### CreateTestUser

```go
func CreateTestUser(t *testing.T, prefix string, role string) uint64 {
    db := database.DBConn
    email := fmt.Sprintf("%s@test.com", prefix)
    fullname := fmt.Sprintf("Test User %s", prefix)

    t.Logf("DEBUG: Creating test user prefix=%s role=%s email=%s", prefix, role, email)

    // Create user
    result := db.Exec(`INSERT INTO users (firstname, lastname, fullname, systemrole)
        VALUES ('Test', ?, ?, ?)`, prefix, fullname, role)

    if result.Error != nil {
        t.Fatalf("ERROR: Failed to create user: %v", result.Error)
    }

    var userID uint64
    db.Raw("SELECT id FROM users WHERE fullname = ? ORDER BY id DESC LIMIT 1", fullname).Scan(&userID)

    // Add email
    db.Exec("INSERT INTO users_emails (userid, email) VALUES (?, ?)", userID, email)

    t.Logf("DEBUG: Created user id=%d email=%s role=%s", userID, email, role)
    return userID
}
```

---

## Files to Modify

1. `iznik-server-go/test/testUtils.go` - Add all factory functions
2. `iznik-server-go/test/main_test.go` - Remove SetupTestEnvironment call
3. `iznik-server-go/test/user_test.go` - Refactor tests
4. `iznik-server-go/test/group_test.go` - Refactor tests
5. `iznik-server-go/test/jobs_test.go` - Refactor tests
6. `iznik-server-go/test/address_test.go` - Refactor tests
7. `iznik-server-go/test/auth_test.go` - Refactor tests
8. `iznik-server-go/test/isochrone_test.go` - Refactor tests
9. `iznik-server-go/test/newsfeed_test.go` - Refactor tests
10. `iznik-server-go/test/volunteering_test.go` - Refactor tests
11. `iznik-server-go/test/communityevent_test.go` - Refactor tests
12. `iznik-server-go/test/chat_test.go` - Refactor tests
13. `iznik-server-go/test/message_test.go` - Refactor tests
14. `iznik-server-go/test/search_test.go` - Refactor tests
15. `testenv.php` - Delete

## Files to Delete

1. `/home/edward/FreegleDocker/testenv.php`

---

## Environment Prerequisites

Before starting, ensure the Docker environment is running:

```bash
# Check all containers are running
docker-compose ps

# Ensure database is accessible
docker exec -it percona mysql -u root -ppassword iznik -e "SELECT 1"

# Ensure apiv2 container is running
docker logs apiv2 --tail 20

# Verify current tests pass (baseline)
curl -X POST http://localhost:8081/api/tests/go
```

### Required Containers

- `percona` - MySQL database
- `apiv2` - Go API server (where tests run)
- `apiv1` - PHP API server (for testenv.php during migration)

### Database Schema Reference

For creating test data, reference the schema at:
https://github.com/Freegle/iznik-server/blob/master/install/schema.sql

Key tables and required columns:
- `groups` - nameshort, type, onhere, polyindex (spatial)
- `users` - firstname, lastname, fullname, systemrole
- `users_emails` - userid, email
- `memberships` - userid, groupid, role
- `users_addresses` - userid, pafid
- `isochrones_users` - userid, isochroneid
- `chat_rooms` - user1, user2, groupid, chattype, latestmessage
- `chat_messages` - chatid, userid, message
- `messages` - fromuser, subject, textbody, type, locationid
- `messages_groups` - msgid, groupid, collection, arrival
- `messages_spatial` - msgid, point (spatial), successful
- `volunteering` - title, pending, deleted, heldby
- `volunteering_dates` - volunteeringid, start, end
- `volunteering_groups` - volunteeringid, groupid
- `communityevents` - title, pending, deleted, heldby
- `communityevents_dates` - eventid, start, end
- `communityevents_groups` - eventid, groupid
- `newsfeed` - userid, message, type, position (spatial)
- `jobs` - title, geometry (spatial), cpc, visible, category
- `sessions` - userid, series, token

---

## Quick Start

1. Read this plan fully
2. Ensure Docker environment is running
3. Run baseline tests to confirm current state
4. Start with Phase 1: Create factory functions
5. Test each function as you create it
6. Move to test refactoring only after factory functions are solid
