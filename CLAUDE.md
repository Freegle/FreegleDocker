**See also: [codingstandards.md](codingstandards.md)** for coding rules. **Use the `ralph` skill** for any non-trivial development task. For automated execution: `./ralph.sh -t "task description"`

## Critical Rules

- **NEVER merge PRs.** Only humans merge PRs. Stop at "PR is ready for merge".
- **NEVER skip or make coverage optional in tests.** Fix the root cause if coverage upload fails.
- **NEVER dismiss test failures as "pre-existing" or "unrelated".** Investigate and fix all failures.
- **NEVER push unless explicitly told to** by the user.
- **MANDATORY: After every `git push` to master that triggers CI, cancel the auto-triggered pipeline and rerun with SSH enabled.** See `.circleci/README.md` "SSH Debugging" section.

## Container Quick Reference

- **Ports**: Configured via `PORT_*` variables in `.env`. Never assume defaults.
- **Dev containers**: File sync via `freegle-host-scripts` — no rebuild needed for code changes.
- **HMR caveat**: If changes don't appear after sync, restart container: `docker restart <container>`.
- **Production containers**: Require full rebuild (`docker-compose build <name> && docker-compose up -d <name>`).
- **Go API (apiv2)**: Requires rebuild after code changes.
- **Status container**: Restart after code changes (`docker restart status`).
- **Compose check**: Stop all containers, prune, rebuild, restart, monitor via status container.
- **Profiles**: Set `COMPOSE_PROFILES` in `.env`. Local dev: `frontend,database,backend,dev,monitoring`. See `docker-compose.yml` for profile definitions.
- **Networking**: No hardcoded IPs. Traefik handles `.localhost` routing. Playwright uses host network mode.
- **Playwright tests**: Run against **production container**. If debugging failures, check for container reload triggers — add to pre-optimization in `nuxt.config.js`.
- Container changes are lost on restart — always make changes locally too.

## Yesterday

Uses `docker-compose.override.yesterday.yml` (copy to `docker-compose.override.yml`). Only dev containers run (faster startup). Uses `deploy.replicas: 0` to disable services. Don't break local dev or CircleCI when making yesterday changes.

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

### 2026-02-26 - Group store separation + Go graceful degradation for ModTools
- **Status**: Go 3dd8332 pushed (test fixes for graceful degradation). CI pipeline pending.
- **Completed**:
  - **Group store separation**: Added `summaryList` state to `stores/group.js`, updated 9 callers. Committed as c36e751a on feature/v2-unified-migration.
  - **Go graceful degradation**: Fixed 4 endpoints to return empty results instead of 400/403 for non-moderator users. Fixed 3 test assertions to expect 200.
  - **Browser verified**: All 18 ModTools dev-live pages load without errors.
  - Test user (44656449) now a moderator (user made this change).
- **Next**: Rebuild apiv2-live, browser-test ModTools pages with moderator access.

### 2026-02-25 - V2 Session Slimdown + CI fixes + Donation thank-you
- Session slimdown complete (Go ddaa699, nuxt3 f79b3dd9). Master CI GREEN.
- Donation thank-you fix for unlinked donors (code complete, not yet committed).

### 2026-02-24 - V1→V2 migration: CI GREEN, cleanup done
- **Status**: CI GREEN. Job 2302 SUCCESS. Auto-merged to production.
- **Key state**: Go ret removal was REVERTED on master. Tasks 18+19 must deploy atomically with nuxt3 `feature/v2-unified-migration`.

### 2026-02-22 - Performance + V1 elimination
- PR #186 (bootstrap-lean-imports): Production build verified, manualChunks removed.
- PR #187 (V1→V2 migration): All V1 API methods removed from iznik-nuxt3. CI GREEN. Ready for human merge.

### 2026-02-20 - Mobile app adversarial reviews + branding
- **Active plan**: `plans/active/freegle-mobile-app.md`
- Two adversarial review rounds completed with fixes (29 issues total). Branding refined (logo placement, custom Give button). APK builds clean.
- **Outstanding**: Auth persistence (DataStore), CameraX photo capture, Give flow API, map/list toggle.
