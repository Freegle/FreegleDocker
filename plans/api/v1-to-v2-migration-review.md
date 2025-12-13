# V1 to V2 API Migration Plan Review

Based on reviewing actual implementations in git history and current code patterns.

## Summary

The existing plan documents (`v1-to-v2-api-migration-complete.md` and `v1-to-v2-migration-process.md`) are comprehensive and well-structured. This review identifies improvements based on **what actually worked** in practice.

---

## What's Working Well

### 1. Email Constraint Documentation ✅
The plan correctly identifies that Go v2 cannot send emails and properly defers those endpoints. This is critical and well-documented.

### 2. Phased Approach ✅
- Phase 0: Non-email endpoints (GET first, then writes)
- Phase 1: FD email-dependent (deferred)
- Phase 2: MT migration (deferred)

This prioritization is correct and being followed.

### 3. Deprecation Format ✅
Consistent format used across all migrated PHP files:
```php
// TODO: DEPRECATED - This endpoint has been migrated to v2 Go API
// Can be retired once all FD/MT clients are using v2
// Migrated: YYYY-MM-DD
```

### 4. Progress Tracking ✅
Completed migrations are marked with dates and strikethrough, making it easy to see progress.

---

## Improvements Based on Actual Practice

### 1. Test Factory Functions (Update Plan)

**Current Plan** describes mocking and >90% coverage requirements.

**Actual Practice** uses factory functions in `testUtils.go`:

```go
// Example from actual codebase
prefix := uniquePrefix("msg")
groupID := CreateTestGroup(t, prefix)
userID := CreateTestUser(t, prefix, "User")
CreateTestMembership(t, userID, groupID, "Member")
mid := CreateTestMessage(t, userID, groupID, "Test Offer Item", 55.9533, -3.1883)
```

**Recommendation**: Update the plan's testing section to reference these factory functions:
- `CreateTestGroup(t, prefix)` - Creates isolated group
- `CreateTestUser(t, prefix, role)` - Creates user with email
- `CreateTestMembership(t, userID, groupID, role)` - Links user to group
- `CreateTestSession(t, userID)` - Returns (sessionID, JWT token)
- `CreateTestMessage(t, userID, groupID, subject, lat, lng)` - Creates offer message
- `CreateTestChatRoom(t, user1ID, user2ID, groupID, chatType)` - Creates chat
- `CreateTestAddress(t, userID)` - Creates address
- `CreateTestIsochrone(t, userID, lat, lng)` - Creates isochrone with polygon

### 2. Coverage Requirement (Relax)

**Current Plan** states ">90% coverage required."

**Actual Practice**: Tests focus on:
- Happy path
- Error paths (404, 400)
- Edge cases (invalid params, too many items)
- Auth scenarios (with/without JWT)

**Recommendation**: Change from ">90% coverage" to:
> Test all user-facing scenarios: success, not found, invalid input, auth required/forbidden.

### 3. Client-Side Migration Pattern (Add Detail)

**Missing from Plan**: Clear pattern for updating client code.

**Actual Pattern** (from logo, giftaid, etc.):

```javascript
// In api/{Domain}API.js - Change method from v1 to v2
// OLD:
async fetch() {
  return await this.$get('/logo', params)
}

// NEW:
async fetch() {
  return await this.$getv2('/logo', params)
}
```

**Important**: The store code often doesn't need changes if the API wrapper handles v1/v2.

### 4. Response Format Difference (Emphasize)

**Critical Detail** mentioned but should be highlighted:

```javascript
// v1 API returns:
{ "ret": 0, "status": "Success", "logo": { ... } }

// v2 API returns data directly:
{ "logo": { ... } }
```

Client code checking `ret.ret === 0` needs updating to just check `if (ret && ret.data)`.

### 5. Parallel Test Data (New Section)

**Add to Plan**: Tests should use unique prefixes to allow parallel execution:

```go
func TestMessages(t *testing.T) {
    prefix := uniquePrefix("msg")  // "msg_1702489234567890"
    groupID := CreateTestGroup(t, prefix)
    // All test data isolated by prefix
}
```

### 6. Container Commands (Update)

**Current Plan** shows generic docker commands.

**Actual Commands** from practice:

```bash
# Run Go tests in container
docker exec freegle-apiv2 go test ./test/{domain}_test.go ./test/main_test.go ./test/testUtils.go -v

# Check container name from docker-compose.yml, not docker ps
grep "container_name.*percona" docker-compose.yml
```

### 7. Missing: Swagger Annotation Pattern

**Add to Plan**: Every new endpoint needs Swagger annotations:

```go
// GetDonations returns donation target and amount raised
// @Summary Get donations summary
// @Description Returns the donation target and amount raised for the current month
// @Tags donations
// @Accept json
// @Produce json
// @Param groupid query int false "Group ID to filter donations"
// @Success 200 {object} map[string]interface{} "Donation summary"
// @Router /donations [get]
func GetDonations(c *fiber.Ctx) error {
```

Then regenerate: `./generate-swagger.sh`

---

## Migration Progress Update

Based on git history, these are confirmed migrated:

| Endpoint | Status | Date | Notes |
|----------|--------|------|-------|
| `/jobs` | ✅ Complete | 2025-09-30 | GET, POST |
| `/donations` | ✅ Complete | 2025-10-01 | GET only |
| `/giftaid` | ✅ Complete | 2025-10-13 | GET own record |
| `/logo` | ✅ Complete | 2025-10-13 | GET |
| `/microvolunteering` | ✅ Complete | 2025-10-14 | GET (challenge) |
| `/user/byemail` | ✅ Complete | 2025-10-17 | GET by email |
| `/src` | ✅ Complete | ~2025-09 | POST (source tracking) |

**Still Pending in Phase 0.2** (non-email writes):
- `/address` - PATCH/PUT (5 FD usages)
- `/isochrone` - PUT/POST/PATCH (2 FD usages)
- `/newsfeed` - POST actions (10 FD usages)
- `/notification` - POST (3 FD usages)
- `/volunteering` - POST/PATCH/DELETE (5 FD usages)

---

## Recommended Plan Updates

### 1. Update Testing Section

Replace the mocking/coverage section with:

```markdown
### Testing Pattern

1. **Use factory functions** from `testUtils.go`:
   - Always use `uniquePrefix(testName)` for data isolation
   - Use `CreateTestUser`, `CreateTestGroup`, etc.
   - Never hardcode IDs - create fresh test data

2. **Required test coverage**:
   - Success case (200)
   - Not found (404)
   - Invalid params (400)
   - Auth required (401)
   - Auth with valid token

3. **Run tests in container**:
   ```bash
   docker exec freegle-apiv2 go test ./test/{endpoint}_test.go \
       ./test/main_test.go ./test/testUtils.go -v
   ```
```

### 2. Add Client Migration Checklist

```markdown
### Client Migration Steps

1. **Update API wrapper** (`api/{Domain}API.js`):
   - Change `$get` to `$getv2`
   - Change `$post` to `$postv2`

2. **Update response handling** in stores/components:
   - Remove `ret.ret === 0` checks
   - v2 returns data directly, no wrapper

3. **Test both FD and MT** if endpoint is shared

4. **Stage changes in submodule**:
   ```bash
   cd iznik-nuxt3
   git add api/{Domain}API.js stores/{domain}.js
   ```
```

### 3. Add Swagger Section

```markdown
### Swagger Documentation

Every v2 endpoint MUST have Swagger annotations:

```go
// @Summary Brief description
// @Description Detailed description
// @Tags domain-name
// @Accept json
// @Produce json
// @Param name path/query type required "description"
// @Success 200 {object} ResponseType
// @Failure 400 {object} ErrorResponse
// @Router /endpoint [get]
```

Regenerate after changes: `./generate-swagger.sh`
```

---

## Conclusion

The migration plan is solid and being followed consistently. The main improvements are:
1. Document the test factory functions that are actually being used
2. Relax the coverage requirement to focus on user scenarios
3. Add clearer client-side migration patterns
4. Emphasize the v1 vs v2 response format difference

The email constraint is well-handled and the phased approach is appropriate.
