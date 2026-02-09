# Freegle Coding Standards

These rules apply to all development work on the Freegle codebase.

## Testing Requirements

- **NEVER skip or make coverage optional in tests.**
- Run local tests BEFORE committing anything.
- All four test suites must pass: Go, PHPUnit, Laravel, Playwright.
- ALWAYS run tests via the Status Container - never run tests manually.
- **ALL test failures must be fixed** - never dismiss failures as "pre-existing" or "unrelated to current change". If tests fail, they must be resolved before proceeding.
- Test commands via status container:
  - Go tests: `curl -X POST http://localhost:8081/api/tests/go`
  - PHPUnit tests: `curl -X POST http://localhost:8081/api/tests/php`
  - Laravel tests: `curl -X POST http://localhost:8081/api/tests/laravel`
  - Playwright tests: `curl -X POST http://localhost:8081/api/tests/playwright`

## Commit Rules

- NEVER commit unless local tests have passed.
- NEVER add "Claude Code" to commit messages.
- NEVER push unless explicitly told to by the user.
- Put full stops at the end of commit message sentences.

## Deployment Dependencies

When changes span backend and frontend:

1. **Backend first** - Backend changes must be deployed to production before dependent frontend changes can be merged.
2. **Split into multiple PRs** - Create separate PRs for backend and frontend work when there are deployment dependencies.
3. **Link related PRs** - Reference related PRs in the description (e.g., "Depends on Freegle/iznik-server-go#123").
4. **Wait for deploy** - Mark frontend work as ⏳ Waiting until backend is confirmed live.
5. **Verify before proceeding** - Check production (e.g., Swagger docs, API responses) to confirm backend deployment before merging frontend changes.

This applies to:
- API v2 migrations (Go backend → Nuxt frontend)
- Database schema changes (PHP/migrations → frontend)
- Any cross-submodule dependencies

## Container Architecture

- Dev containers (freegle-dev-local, modtools-dev-local) pick up changes via file sync automatically.
- Production containers require full rebuild: `docker-compose build <container> && docker-compose up -d <container>`
- Go API (apiv2) requires rebuild after code changes.
- After status container changes, restart it: `docker restart status`

## API v2 Development

When adding or modifying v2 API (Go) features:
- Always update Swagger annotations and regenerate documentation.
- Run `./generate-swagger.sh` after changes to update the docs.
- Verify new endpoints appear in Swagger UI at `/swagger/`.
- Production deployment must complete before client code changes.
- To verify live deployment, check Swagger on production: `https://apiv2.ilovefreegle.org/swagger/`

## V2 Go API Handler Guide

This is the canonical guide for all new Go API handlers. Follow these patterns exactly.

### Handler Structure

Every handler follows this sequence: AUTH → PARSE → DB → PRIVACY → RESPOND.

```go
func GetThing(c *fiber.Ctx) error {
    // 1. AUTH
    myid := user.WhoAmI(c)

    // 2. PARSE parameters
    id, err := strconv.ParseUint(c.Params("id"), 10, 64)
    if err != nil {
        return fiber.NewError(fiber.StatusBadRequest, "Invalid ID")
    }

    // 3. DB fetch (goroutines for 2+ independent queries)
    db := database.DBConn
    var thing ThingType
    db.Raw("SELECT ... FROM thing WHERE id = ?", id).Scan(&thing)

    // 4. PRIVACY filter for non-owners
    if thing.Userid != myid {
        thing.Textbody = er.ReplaceAllString(thing.Textbody, "***@***.com")
    }

    // 5. RESPOND
    return c.JSON(thing)
}
```

### Write Handler Pattern

```go
func CreateThing(c *fiber.Ctx) error {
    myid := user.WhoAmI(c)
    if myid == 0 {
        return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
    }

    type CreateRequest struct {
        Name string `json:"name"`
    }
    var req CreateRequest
    if err := c.BodyParser(&req); err != nil {
        return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
    }

    db := database.DBConn
    result := db.Exec("INSERT INTO thing (...) VALUES (?)", ...)

    return c.JSON(fiber.Map{"ret": 0, "id": result.RowsAffected})
}
```

### Mandatory Patterns

| Pattern | Requirement |
|---------|-------------|
| **Auth** | `myid := user.WhoAmI(c)` at start. Returns 0 if anonymous. |
| **Goroutines** | Use `sync.WaitGroup` for 2+ independent DB queries. |
| **Mutex** | Use `sync.Mutex` when goroutines write to shared slices/maps. |
| **Empty arrays** | Return `make([]T, 0)` not `nil` for empty collections. |
| **Errors** | Use `fiber.NewError(statusCode, message)` consistently. |
| **Privacy** | Check `myid` vs owner before returning emails/phones. Use `utils.Blur()` for locations. |
| **Swagger** | Add `@Summary`, `@Tags`, `@Router` annotations. Run `./generate-swagger.sh`. |
| **Struct tags** | `json:"field"` for API, `json:"-"` for hidden, `gorm:"-"` for computed. |
| **Raw SQL** | Prefer `db.Raw()` for performance. Use GORM only for simple queries. |
| **Route registration** | Register both `/api/` and `/apiv2/` paths in `router/routes.go`. |
| **Rate limiting** | Validate array lengths: `if len(ids) > 20 { return fiber.NewError(400, "Steady on") }` |

### Authentication

```go
// Get current user (0 if anonymous)
myid := user.WhoAmI(c)

// Require login
if myid == 0 {
    return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
}

// Check admin/support role (set by auth middleware)
userRole := c.Locals("userRole")
if userRole != nil && (userRole.(string) == utils.SYSTEMROLE_ADMIN || userRole.(string) == utils.SYSTEMROLE_SUPPORT) {
    // admin logic
}
```

### Parallel DB Fetching

```go
var wg sync.WaitGroup
var mu sync.Mutex
var results []Message

for _, id := range ids {
    wg.Add(1)
    go func(id string) {
        defer wg.Done()
        var msg Message
        db.Raw("SELECT * FROM messages WHERE id = ?", id).First(&msg)

        mu.Lock()
        results = append(results, msg)
        mu.Unlock()
    }(id)
}
wg.Wait()
```

### Privacy Filtering

```go
import "regexp"
import "github.com/freegle/iznik-server-go/utils"

var er = regexp.MustCompile(utils.EMAIL_REGEXP)
var ep = regexp.MustCompile(utils.PHONE_REGEXP)

// Hide contact info from anonymous users
if myid == 0 {
    msg.Textbody = er.ReplaceAllString(msg.Textbody, "***@***.com")
    msg.Textbody = ep.ReplaceAllString(msg.Textbody, "***")
}

// Blur location
msg.Lat, msg.Lng = utils.Blur(msg.Lat, msg.Lng, utils.BLUR_USER)
```

### Go Test Pattern

```go
func TestGetThing(t *testing.T) {
    // ARRANGE
    prefix := uniquePrefix("thing")
    groupID := CreateTestGroup(t, prefix)
    userID := CreateTestUser(t, prefix, "Member")
    CreateTestMembership(t, userID, groupID, "Member")

    // ACT
    req := httptest.NewRequest("GET", "/api/thing/"+strconv.FormatUint(id, 10), nil)
    resp, _ := getApp().Test(req)

    // ASSERT
    assert.Equal(t, fiber.StatusOK, resp.StatusCode)
    body := rsp(resp)
    assert.Equal(t, expectedValue, body["field"])
}

func TestGetThingUnauthorized(t *testing.T) {
    req := httptest.NewRequest("GET", "/api/thing/1", nil)
    resp, _ := getApp().Test(req)
    assert.Equal(t, fiber.StatusUnauthorized, resp.StatusCode)
}
```

### Common Constants (utils/utils.go)

```go
const OFFER = "Offer"
const WANTED = "Wanted"
const CHAT_TYPE_USER2USER = "User2User"
const CHAT_TYPE_USER2MOD = "User2Mod"
const BLUR_USER = 400    // meters
const OPEN_AGE = 90      // days
```

## Go Code Style

- Use goroutines for independent database queries to minimize API latency.
- Use channels or sync.WaitGroup to coordinate parallel operations.
- Follow existing patterns in the codebase (e.g., `message/message.go` for parallel query examples).
- Handle context cancellation properly for long-running operations.
- Always check for database errors before using results.

## Code Style

- Run `eslint --fix` on changed files after modifications.
- Never use `//` comments in SCSS - use `/* */` instead.
- Avoid naked `<a>` tags - use ExternalLink component.
- Avoid curved corners (border-radius).
- House style: put full stops at end of sentences.
- When you create new files, add them to git automatically (unless temporary).
- When removing code, never leave comments about what used to be there.
- When creating temporary scripts, put them in `/tmp` to avoid cluttering git.

## Playwright Tests

- Always use Playwright assertions instead of locator.waitFor() or page.waitForTimeout().
- Never use hardcoded timeouts - use constants from config.js.
- Never fix tests by direct navigation - simulate user behaviour via clicks.
- Never bypass checks with native JavaScript click.
- Use `type()` not `fill()` to simulate user behaviour.

## Flaky Tests

- NEVER accept that a test is flaky or add retries to the test.
- If a test appears flaky, reproduce the failure or add more debug logging.
- The goal is to fix the root cause so tests run reliably every time.

## Parallel Test Isolation

Tests run in parallel (PHPUnit uses 4 workers with separate databases). Write tests that can run simultaneously:

**Do:**
- Use `$this->uniqueEmail('test@test.com')` - adds worker suffix automatically.
- Use `$this->uniqueGroupName('testgroup')` - adds worker suffix automatically.
- Use high-level helpers like `createTestUser()`, `createTestGroup()`, `createTestMessage()`.
- Create all fixture data within the test - don't rely on pre-existing data.
- Clean up custom test data in tearDown() if not covered by standard patterns.

**Don't:**
- Hardcode emails/group names without worker suffix (causes conflicts).
- Assume specific database state between tests.
- Rely on test execution order.
- Access shared resources (Redis, caches) without clearing first.

**PHP Test Pattern:**
```php
public function testSomething() {
    // Good - uses helpers with automatic worker isolation
    list($user, $uid) = $this->createTestUserWithLogin('Test User', 'testpw');
    list($group, $gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
    // ... test logic
}
```

## Bug Fix Workflow

When fixing a bug, follow test-driven development principles:

1. **Reproduce the bug with a test** - Write a test case that demonstrates the bug.
2. **Verify the test fails** - Confirm the test fails as expected before making any fix.
3. **Fix the bug** - Make the minimum change necessary to fix the issue.
4. **Verify the test passes** - Confirm the test now passes with your fix.
5. **Add additional tests** - Add any other relevant test cases that seem sensible.

This approach ensures you are actually fixing the right bug and prevents regressions.

## Feedback Loops for Validation

Validate changes using appropriate tools before considering work complete:

- **Front-end changes**: Use Chrome DevTools MCP to visually review and validate UI changes.
- **Email changes**: Use MailPit to inspect generated emails.
- **API/backend changes**: Ensure test coverage of at least 90% on any module you touch.

## Test Coverage

- Aim for at least 90% code coverage on any module you modify.
- Use coverage reports to identify untested code paths.
- Coverage is an integral part of testing - never skip or make it optional.

## Code Quality

Avoid code duplication and maintain clean code:

- **No copy-paste**: Never duplicate significant blocks of code. Extract shared logic into reusable functions.
- **Refactor over duplicate**: If you find yourself copying code, refactor it into a shared utility instead.
- **Static analysis**: Address linting warnings and static analysis issues before committing.
- **Code smells**: Watch for and fix common issues like:
  - Long methods (>50 lines)
  - Deeply nested conditionals (>3 levels)
  - God objects (classes doing too much)
  - Magic numbers (use named constants)

**Available tools:**
- `jscpd` - Cross-language copy-paste detection.
- `golangci-lint` - Go linting including duplication detection.
- PHPStan - PHP static analysis (available in apiv1 container).
- ESLint - JavaScript/Vue linting (already configured).

## Branch Management

- Plans should be created in FreegleDocker/plans/, never in submodules.
- When switching branches, rebuild Freegle dev containers.
- Never merge the whole app-ci-fd branch into master.

## Docker

- Never use hardcoded IP addresses in docker-compose.yml.
- Changes made directly to containers are lost on restart - make changes locally.

## CircleCI

- After orb changes, publish: `~/.local/bin/circleci orb publish .circleci/orb/freegle-tests.yml freegle/tests@X.X.X`
- Check orb version: `~/.local/bin/circleci orb info freegle/tests`
