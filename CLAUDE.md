**See also: [codingstandards.md](codingstandards.md)** for core coding rules that apply to all development.

**Use the `ralph` skill** for any non-trivial development task. For automated/unattended execution: `./ralph.sh -t "task description"`

- **NEVER merge PRs.** Only humans merge PRs. Claude may create PRs, push to branches, and report when CI passes, but NEVER run `gh pr merge` or any equivalent command. Always stop at "PR is ready for merge" and let the user decide.
- **NEVER skip or make coverage optional in tests.** Coverage is an integral part of testing and must always be collected and uploaded. If coverage upload fails, fix the root cause - never bypass it.
- **NEVER dismiss test failures as "pre-existing flaky tests" or "unrelated to my changes".** If a test fails during your work, you must investigate and fix it. Period. It does not matter whether you think the failure is related to your changes or not. The tests must pass. While it's possible some tests have underlying reliability issues, if they passed before and now fail, the change since the last successful build is usually the cause. Compare with the last successful build, find what changed, and fix it. Even if the root cause turns out to be a pre-existing issue, it still needs to be fixed - don't use "flaky" as an excuse to avoid investigation.
- Always restart the status monitor after making changes to its code.
- Remember that the process for checking whether this compose project is working should involve stopping all containers, doing a prune, rebulding and restarting, and monitoring progress using the status container.
- You don't need to rebuild the Freegle Dev or ModTools Dev containers to pick up code fixes - the `freegle-host-scripts` container automatically syncs file changes to dev containers.
- The Freegle Production and ModTools Production containers require a full rebuild to pick up code changes since they run production builds.
- **File Sync**: The `freegle-host-scripts` container runs `file-sync.sh` which uses inotifywait to monitor file changes in iznik-nuxt3, iznik-server, iznik-server-go, and iznik-batch directories. Changes are automatically synced to dev containers via `docker cp`. Check logs with `docker logs freegle-host-scripts --tail 20`.
- **HMR Caveat**: While file sync works reliably, Nuxt's HMR may not always detect `docker cp` file changes. If changes don't appear after sync, restart the container: `docker restart modtools-dev-live`.
- The API v2 (Go) container requires a full rebuild to pick up code changes: `docker-compose build apiv2 && docker-compose up -d apiv2`
- After making changes to the status code, remember to restart the container
- When running in a docker compose environment and making changes, be careful to copy them to the container.
- When making app changes, remember to update README-APP.md.
- Never merge the whole of the app-ci-fd branch into master.
- When making changes to the tests, don't forget to update the orb.
- We should always create plans/ md files in FreegleDocker, never in submodules.
- When we switch branches, we usually need to rebuild the Freegle dev containers, so do that automatically.
- **Browser Testing**: See `BROWSER-TESTING.md` for Chrome DevTools MCP usage, login flow, debugging computed styles, and injecting CSS fixes.
- **Yesterday**: Use the `yesterday-config` skill when working on the yesterday server. Don't break local dev and CircleCI - we have a docker override file to help with this.
- **Sentry**: Use the `sentry-integration` skill when configuring or triggering Sentry error analysis.

## Docker Compose Profiles

Every service in docker-compose.yml has at least one profile. `COMPOSE_PROFILES` must be set in `.env` or nothing starts.

| Profile | Purpose | Key Services |
|---------|---------|-------------|
| `frontend` | Web-facing APIs | apiv1, apiv2, delivery, tusd, redis, beanstalkd |
| `backend` | Background processing | loki, mjml, redis, rspamd, spamassassin, ai-support-helper |
| `production` | Production batch jobs | batch-prod (requires .env.background) |
| `mail` | Incoming mail | postfix (requires MX records pointing to host) |
| `database` | Local databases | percona (MySQL), postgres (PostGIS) |
| `dev` | Development/testing tools | Traefik, status, dev containers, mailpit, phpmyadmin, batch, playwright, MCP tools |
| `monitoring` | Log shipping | alloy |
| `build` | Base image build only | base |
| `dev-live` | Dev with production APIs | freegle-dev-live, modtools-dev-live |
| `prod-live` | API v2 with production DB | apiv2-live |
| `backup` | Loki backup | loki-backup |

| Scenario | COMPOSE_PROFILES |
|----------|-----------------|
| **Local dev** | `frontend,database,backend,dev,monitoring` |
| **Live backend** | `backend,production,mail` |
| **Live frontend** | `frontend` |
| **Yesterday** | `frontend,database,backend,dev,monitoring` (+ override file) |
| **CircleCI** | `frontend,database,backend,dev,monitoring` |

Cross-profile dependencies use `required: false` so they're ignored when the dependency's profile is inactive. The yesterday override uses `deploy.replicas: 0` (not profile overrides) to disable services.

## Container Architecture

- **freegle-dev-local** (`freegle-dev-local.localhost`): Dev mode, local APIs, hot reloading
- **freegle-dev-live** (`freegle-dev-live.localhost`, port 3004): Dev mode, PRODUCTION APIs - use with caution
- **freegle-prod-local** (`freegle-prod-local.localhost`): Production build, local APIs, slower startup
- **modtools-dev-local** / **modtools-prod-local**: Same pattern as Freegle containers
- Production containers use `Dockerfile.prod` and require full rebuild for code changes
- **batch-prod**: Laravel scheduled jobs against production DB. Needs `.env.background` (see `.env.background.example`). Uses `profiles: [backend]`.

## Database Schema Management

- **Laravel migrations** in `iznik-batch/database/migrations/` are the single source of truth
- **schema.sql is retired** - kept for historical reference only
- **Stored functions** managed by migration `2026_02_20_000002_create_stored_functions.php`
- **To add a table**: Create a Laravel migration. Picked up automatically in CI and local dev.
- **Test databases**: Created by `scripts/setup-test-database.sh` (runs migrations, clones schema via mysqldump)

## Networking

- **Never use hardcoded IP addresses** in docker-compose.yml - Docker assigns IPs dynamically
- Services communicate via container names/aliases through Docker's internal DNS
- Traefik handles `.localhost` domain routing automatically
- Never add specific IP addresses as extra_hosts - won't survive rebuilds
- Container changes are lost on restart - always make changes locally too

Test URLs: `http://freegle-dev-local.localhost/`, `http://freegle-prod-local.localhost/`, `http://apiv2.localhost:8192/`

- **Loki logs**: Use the `loki-querying` skill when investigating errors or querying system logs.
- **Playwright**: Tests run against production container. Host network mode. Container restarted per test run. If debugging failures, check for reload-triggering logs.

## CircleCI

- Use the `circleci-submodules` skill for submodule integration, GitHub tokens, adding submodules, and orb publishing.
- Use the `docker-build-caching` skill for cache configuration, invalidation, performance, and rollback.
- **MANDATORY: After every `git push` to master that triggers CI, immediately cancel the auto-triggered pipeline and rerun it with SSH enabled.** Use the `circleci-ssh-rerun` skill for the cancel/rerun workflow.
- See [.circleci/README.md](.circleci/README.md) for full CircleCI documentation.

## Testing

All testing consolidated in the FreegleDocker CircleCI pipeline:
- **Go Tests**: `curl -X POST http://localhost:8081/api/tests/go`
- **PHPUnit Tests**: `curl -X POST http://localhost:8081/api/tests/php`
- **Playwright Tests**: `curl -X POST http://localhost:8081/api/tests/playwright`

All must pass for auto-merge to production (merges master to production in iznik-nuxt3).

## Session Log

**Auto-prune rule**: Keep only entries from the last 7 days. Delete older entries when adding new ones.

**Active plan**: `plans/active/v1-to-v2-api-migration.md` - READ THIS ON EVERY RESUME/COMPACTION.

### 2026-02-22 - Phase 2 Go changes + client V1→V2 switches
- **Status**: Tasks 6-8 ✅ complete. Go changes pushed to master, client changes pushed to feature/v2-unified-migration.
- **Completed**:
  - Go: Isochrone auto-create in ListIsochrones (matches PHP behavior)
  - Go: Session work counts (8 parallel goroutine queries), discourse stats (external API), configid in memberships
  - Go: Stripe endpoints (CreateIntent + CreateSubscription with stripe-go/v82)
  - Client: Fixed V2 session response unwrapping bug (me/groups/work/discourse now properly extracted)
  - Client: Switched stripe calls to V2, removed isochrone V1 fallback, cleaned up donations store
  - Client: Both ModTools and non-ModTools now use V2 first; V1 only for permissions (ModTools) and fallback
- **Remaining V1 calls**: SessionAPI.fetch (permissions + fallback), ImageAPI.postForm (Go needs multipart), MessageAPI illustration fallback
- **Key Decisions**: Kept ModTools V1 background call for permissions only (Go doesn't return permissions yet). Fixed auth store V2 response unwrapping bug that was masked by V1 background sync.

### 2026-02-22 17:51 - Comprehensive V1→V2 migration complete
- **Status**: PR #187 updated with comprehensive migration. 35 files changed, ~170 methods switched. Pushed to `feature/v2-unified-migration`.
- **Completed**: Migrated all switchable V1 API calls to V2 across 34 API classes. Verified Go handler response formats. Added response wrappers for UserAPI.fetchMT and MessageAPI.fetchMT where Go returns flat objects. Reverted MembershipsAPI.fetch/fetchMembers and SessionAPI.fetch/lostPassword/unsubscribe to V1 (incompatible response formats or callback side effects).
- **Next**: Monitor CI on PR #187. Ready for manual testing on dev containers.
- **Key Decisions**: Kept ~20 V1 methods with documented reasons (no Go endpoint, response format mismatch, callback side effects). Path changes for MessageAPI (fetchMT→/message/:id, markSeen→/messages/markseen, del→/message/:id) and ChatAPI.fetchMessagesMT (/chat/:id/message).

### 2026-02-22 16:22 - All iznik-nuxt3 v2 PRs passing CI
- **Status**: ALL 7 open iznik-nuxt3 v2 PRs passing CI. All ready for merge.
  - PASSED: PR #174 (tryst-bandit), #176 (remaining-switches), #180 (dashboard-fix), #181 (comment-get), #182 (noticeboard-get), #183 (ce-vol-fetchmt), #184 (story-fetchmt)
  - MERGED (earlier): PR #179, #185
- **PR #176 fix**: Reverted all premature v2 write switches to v1. Root cause was Go API returning HTTP 401 for unauthenticated writes (vs PHP returning HTTP 200 with {ret: 1}). Playwright test fixture catches 401 as console error. PR now only contains BaseAPI.js v2 write method definitions + formatting fixes.
- **Key learning**: Write API switches require Phase 2/3 (email queue integration) before switching. GET switches are safe.

### 2026-02-22 - Implement v2 API stubs, push to master
- **Completed**: 5 v2 API stubs implemented, 4 tests added, CLAUDE.md extracted to skills.

### 2026-02-21 - PR #36 code review fixes - CI GREEN
- **Status**: CI PASSED. PR #36 ready for merge.
- **Completed**: PR #36 (`fix/v2-code-review-fixes`): security checks, auth package, parallel cluster writes. Fixed 11 test failures across 6 CI iterations.

### 2026-02-19 - Go PR review, CI fixes, V2 batch consolidation
- **Completed**: Reviewed 23 Go PRs, fixed PR #11/#25, unified test branch, schema.sql removal, merged 23 FreegleDocker V2 PRs, published orb v1.1.161.
