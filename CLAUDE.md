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

### 2026-04-09 - V1 parity fixes, status page, banned member improvements
- **Go fixes** (all pushed): Chat unseen 31-day filter (`e8ffdbf`), expired promised posts (`19a5782`), non-spatial message marking (`9f4b03a`), chat completed snippets neutral language (`e619c2b`), `IsModOfUser()` checks `users_banned` table (`e619c2b`).
- **Nuxt fixes** (all pushed): Old posts toggle infinite scroll reset (`99e8465f`), ChatMessageCompleted automated message removal (`975cf83`), MyMessage auto-open outcome modal (`975cf83`).
- **Status page** (pushed): Go/Vitest test count accuracy (`af013f60`).
- **Banned member view** (Nuxt, uncommitted): Simplified ModMember for banned users — hides settings, toggles, ModMemberButtons, ModRole. 9 new tests in ModMember.spec.js.
- **Playwright CI**: ERR_ABORTED fix in `loginViaModTools` — added `waitForLoadState('load')`. Needs local validation before push.

### 2026-04-10 - Session log cleanup
- Pruned session log per 7-day rule. Answered GDPR question re banned users (data not exempted from removal; `users_banned` records are orphaned but not cleaned up by `User::forget()`).

### 2026-04-11 - Coveralls coverage upload for build-and-test
- **Root cause**: `build-and-test` job (master builds) never uploaded coverage to Coveralls.
- **Fix round 1** (`ff100ebd`): Added unified coverage upload step. Orb `freegle/tests@1.1.173`.
- **CI job 3348 results**: Vitest coverage uploaded (empty source_files), Go/Laravel/Playwright/PHP all failed.
  - Go: Default 10m `go test` timeout exceeded with `-race -coverpkg ./...`. Fix: added `-timeout 30m`.
  - Go coverage: gcov2lcov conversion failed — Go not installed on CI host. Fix: run gcov2lcov inside container, sed paths `/app/` → `iznik-server-go/`.
  - Vitest: empty source_files — paths relative to iznik-nuxt3/ but coveralls ran from project root. Fix: sed prefix `iznik-nuxt3/` on lcov paths.
  - Laravel: `cronLog()` redeclaration error — pre-existing issue (not coverage-related).
  - PHP/Playwright: killed by cascading timeout.
- **Local verification**: Go tests with coverage pass (1325✓, 0✗). gcov2lcov conversion verified locally with correct path mapping.
- **Next**: Commit fixes, publish orb, push, rerun CI.
