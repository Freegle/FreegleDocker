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
