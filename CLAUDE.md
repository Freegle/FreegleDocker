**See also: [codingstandards.md](codingstandards.md)** for coding rules. **Use the `ralph` skill** for any non-trivial development task. For automated execution: `./ralph.sh -t "task description"`

## Critical Rules

- **NEVER merge PRs.** Only humans merge PRs. Stop at "PR is ready for merge".
- **NEVER skip or make coverage optional in tests.** Fix the root cause if coverage upload fails.
- **NEVER dismiss test failures as "pre-existing" or "unrelated".** Investigate and fix all failures.
- **NEVER push unless explicitly told to** by the user.
- **MANDATORY: After every `git push` to master that triggers CI, cancel the auto-triggered pipeline and rerun with SSH enabled.** See `.circleci/README.md` "SSH Debugging" section.

## Container Quick Reference

- **Ports**: Live in `docker-compose.ports.yml`, included via `COMPOSE_FILE` in `.env`. Never hardcode ports.
- **Container names**: Prefixed by `COMPOSE_PROJECT_NAME` (default: `freegle`). E.g. `freegle-apiv1`, `freegle-traefik`.
- **Dev containers**: File sync via `freegle-host-scripts` — no rebuild needed for code changes.
- **HMR caveat**: If changes don't appear after sync, restart container: `docker restart <container>`.
- **Production containers**: Require full rebuild (`docker-compose build <name> && docker-compose up -d <name>`).
- **Go API (apiv2)**: Requires rebuild after code changes.
- **Status container**: Restart after code changes (`docker restart status`).
- **Compose check**: Stop all containers, prune, rebuild, restart, monitor via status container.
- **Profiles**: Set `COMPOSE_PROFILES` in `.env`. Local dev: `frontend,database,backend,dev,monitoring`. See `docker-compose.yml` for profile definitions.
- **Networking**: No hardcoded IPs. Traefik handles `.localhost` routing via network aliases. Playwright uses Docker default network.
- **Playwright tests**: Run against **production container**. If debugging failures, check for container reload triggers — add to pre-optimization in `nuxt.config.js`.
- Container changes are lost on restart — always make changes locally too.

## Multi-Instance / Worktree Isolation

Multiple Docker Compose environments can run in parallel using git worktrees. Only one worktree has exposed ports at a time (the "active" one). Use `./freegle` CLI:

```bash
./freegle worktree create feature-x    # Create isolated worktree
./freegle activate feature-x           # Swap ports to feature-x
./freegle status                       # See which is active
./freegle worktree remove feature-x    # Cleanup
```

**Architecture**: Ports live in `docker-compose.ports.yml` (separate from `docker-compose.yml`). The `COMPOSE_FILE` env var controls inclusion. Secondary worktrees set `COMPOSE_FILE=docker-compose.yml` (no ports) and get a unique `COMPOSE_PROJECT_NAME` for container/volume isolation.

**Single-checkout users**: No changes needed. Default `.env` includes the ports file.

## Yesterday

Uses `docker-compose.override.yesterday.yml` (copy to `docker-compose.override.yml`). Set `COMPOSE_FILE=docker-compose.yml:docker-compose.ports.yml:docker-compose.override.yesterday.yml` in `.env`. Only dev containers run (faster startup). Uses `deploy.replicas: 0` to disable services. Don't break local dev or CircleCI when making yesterday changes.

## Database Schema

- **Laravel migrations** in `iznik-batch/database/migrations/` are the single source of truth.
- `schema.sql` is retired (historical reference only).
- Stored functions managed by migration `2026_02_20_000002_create_stored_functions.php`.
- Test databases: `scripts/setup-test-database.sh` runs `php artisan migrate`, clones schema to test DBs.

## CircleCI

- Submodule webhooks: `trigger-parent-ci.yml` workflow + `FREEGLE_DOCKER_TOKEN` secret (PAT scoped to **Freegle org**, not personal). See `.circleci/README.md` for full docs.
- Publish orb after changes: `source .env && ~/.local/bin/circleci orb publish .circleci/orb/freegle-tests.yml freegle/tests@1.x.x`
- Check version: `~/.local/bin/circleci orb info freegle/tests`
- **Docker build caching**: Controlled by `ENABLE_DOCKER_CACHE` env var in CircleCI. Bump version suffixes in orb YAML to invalidate cache. Set to `false` for immediate rollback.
- **Auto-merge**: When all tests pass on master, auto-merges to production branch in iznik-nuxt3.

## Batch Production Container

`batch-prod` runs Laravel scheduled jobs against production DB. Secrets in `.env.background` (see `.env.background.example`). Profile: `backend`.

## Loki

Logs on `localhost:3100`. Use `-G` with `--data-urlencode` for queries. Timestamps are nanoseconds. Label values must be quoted. See `iznik-server-go/systemlogs/systemlogs.go` for Go API wrapper.

## Sentry

Status container has Sentry integration. Set `SENTRY_AUTH_TOKEN` in `.env`. See `SENTRY-INTEGRATION.md`.

## Miscellaneous

- When making app changes, update `README-APP.md`.
- Never merge the whole `app-ci-fd` branch into master.
- Plans go in `FreegleDocker/plans/`, never in submodules.
- When switching branches, rebuild dev containers.
- When making test changes, don't forget to update the orb.
- **Browser Testing**: See `BROWSER-TESTING.md`.

## Session Log

**Auto-prune rule**: Keep only entries from the last 7 days.

**Active plan**: `plans/active/v1-to-v2-api-migration.md` - READ THIS ON EVERY RESUME/COMPACTION.

### 2026-04-09 - Chat unseen V1 parity, expiry/spatial fixes, status page, Playwright ERR_ABORTED
- **Chat unseen ACTIVELIM** (Go): Added 31-day date filter to unseen count queries in `chatroom.go`, matching V1's `countAllUnseenForUser()`. Stale old chats (2020-2022) no longer inflate the unread badge. Test: `TestUnseenCountExcludesOldMessages`. Committed `e8ffdbf`, pushed.
- **Expired promised posts** (Go): Removed `m.Promised` from skip condition in `applyExpiry()` — promised messages now expire like all others (matches removed client-side `hasExpired()`). Test: `TestExpiredPromisedMessageExcludedFromActive`. Committed `19a5782`, pushed.
- **Non-spatial messages** (Go): `markExpiredMessages()` now marks non-spatial, non-pending/rejected messages as `hasoutcome=true` — consistent between active=true and active=false. Test: `TestNonSpatialMessageMarkedOldInInactiveQuery`. Committed `9f4b03a`, pushed.
- **Old posts toggle** (Nuxt): Fixed infinite scroll not resetting on toggle. Added `infiniteKey` ref with `:key` on InfiniteLoading to force remount. Tests added for emit, toggle, and remount. Committed `99e8465f`, pushed.
- **Status page fixes** (status-nuxt): Go test total now counts `=== RUN` lines; Vitest runs `vitest list` upfront for accurate total (10MB maxBuffer for ~12K tests); summary parser reordered; TestRunner auto-collapses logs on completion; hook allows `docker cp`/`docker exec`. Committed `af013f60`, pushed.
- **All 1256 Go tests pass**, all 10972 Vitest tests pass.
- **CI pipelines 2930-2932**: All fail on Playwright only — different modtools tests each time (support, member-logs, chat-reply, member-review) with `ERR_ABORTED` / "Navigation interrupted by another navigation to /?noguard=true". Root cause: `loginViaModTools` sidebar check completes but redirect chain not fully settled; subsequent `page.goto()` gets interrupted.
- **Fix in progress**: Added `waitForLoadState('load')` after sidebar check in `loginViaModTools` (`tests/e2e/utils/user.js`). Running ALL Playwright tests locally before pushing.
- **Next**: Wait for full Playwright run to complete locally. Fix any additional failures. Push only after all pass.
