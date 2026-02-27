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

### 2026-02-27 - Unified MT chat listing + V1-vs-V2 comparative review fixes
- **Unified chat handler**: Extended `ListForUser`/`listChats()` with `chattypes` param to handle User2Mod, User2User, Mod2Mod dynamically. Deleted slow `doListChatRoomsMT` (was hanging on production). Added `ListForUserMT` wrapper, Mod2Mod UNION branch, search branches for all types. 8 new tests. Pushed to Go master (2054d51), CI pending.
- **V1-vs-V2 review**: CI GREEN. Job #2395 SUCCESS. Auto-merged to production.
- **Completed**:
  - **30 findings from V1-vs-V2 review** addressed systematically (19 files, +484/-206 lines).
  - Security: session forget (spammer/mod/partner checks), user delete (mod protection), signup re-login.
  - Auth package: extracted shared auth functions (VerifyPassword, CreateSessionAndJWT) to break circular deps.
  - Spammers: partner key auth, export endpoint for Trash Nothing.
  - Group polygon: cga/dpa as separate fields. CE/Vol: canmodify field. Notifications: lastaccess + response format.
  - Removed dead Facebook micro-volunteering code path.
  - Fixed 2 pre-existing test failures: TestJobs (missing canonical_title column + NULL scan), TestMicroVolunteeringResponseFacebook (dead code removed).
  - Fixed TestLocation_NonExistentID CI panic: skip ClosestGroups for non-existent locations, add timeout+nil guard.
  - V1/PHP references removed from all modified comments.
- **Key decisions**: F13 myrole/mysettings intentionally NOT in group endpoint (comes from session). F8/15/19 bare V2 responses are correct.
- **CI note**: nuxt3 submodule on master still uses V1 PHP for chat/rooms (V2 migration on feature/v2-unified-migration branch). Personal CircleCI PAT from `~/.circleci/cli.yml` needed for cancel/rerun (org PAT is read-only).

### 2026-02-26 - Browser testing V2 branch (ModTools + Freegle)
- **ModTools browser testing** (26 pages): 24 OK, 1 expected 403 (giftaid), 1 expected redirect (discourse).
- **Freegle browser testing** (13 pages): 12 OK, 1 ERROR (/chitchat - profile.path undefined).
- **Previous session work**: Group store separation (c36e751a), Go graceful degradation (3dd8332).

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
