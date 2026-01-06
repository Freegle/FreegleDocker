# Freegle Coding Standards

These rules apply to all development work on the Freegle codebase.

## Testing Requirements

- **NEVER skip or make coverage optional in tests.**
- Run local tests BEFORE committing anything.
- All four test suites must pass: Go, PHPUnit, Laravel, Playwright.
- ALWAYS run tests via the Status Container - never run tests manually.
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

## Container Architecture

- Dev containers (freegle-dev-local, modtools-dev-local) pick up changes via file sync automatically.
- Production containers require full rebuild: `docker-compose build <container> && docker-compose up -d <container>`
- Go API (apiv2) requires rebuild after code changes.
- After status container changes, restart it: `docker restart status`

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

- Never use `expect().toBeVisible()` - use `locator.waitFor({ state: 'visible' })`.
- Never use hardcoded timeouts - use constants from config.js.
- Never fix tests by direct navigation - simulate user behaviour via clicks.
- Never bypass checks with native JavaScript click.
- Use `type()` not `fill()` to simulate user behaviour.

## Flaky Tests

- NEVER accept that a test is flaky or add retries to the test.
- If a test appears flaky, reproduce the failure or add more debug logging.
- The goal is to fix the root cause so tests run reliably every time.

## Feedback Loops for Validation

Validate changes using appropriate tools before considering work complete:

- **Front-end changes**: Use Chrome DevTools MCP to visually review and validate UI changes.
- **Email changes**: Use MailPit to inspect generated emails.
- **API/backend changes**: Ensure test coverage of at least 90% on any module you touch.

## Test Coverage

- Aim for at least 90% code coverage on any module you modify.
- Use coverage reports to identify untested code paths.
- Coverage is an integral part of testing - never skip or make it optional.

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
