**See also: [codingstandards.md](codingstandards.md)** for core coding rules that apply to all development.

**Use the `ralph` skill** for any non-trivial development task. For automated/unattended execution: `./ralph.sh -t "task description"`

- **NEVER skip or make coverage optional in tests.** Coverage is an integral part of testing and must always be collected and uploaded. If coverage upload fails, fix the root cause - never bypass it.
- **NEVER dismiss test failures as "pre-existing flaky tests" or "unrelated to my changes".** If a test fails during your work, you must investigate and fix it. Period. It does not matter whether you think the failure is related to your changes or not. The tests must pass. While it's possible some tests have underlying reliability issues, if they passed before and now fail, the change since the last successful build is usually the cause. Compare with the last successful build, find what changed, and fix it. Even if the root cause turns out to be a pre-existing issue, it still needs to be fixed - don't use "flaky" as an excuse to avoid investigation.
- Always restart the status monitor after making changes to its code.
- Remember that the process for checking whether this compose project is working should involve stopping all containers, doing a prune, rebulding and restarting, and monitoring progress using the status container.
- You don't need to rebuild the Freegle Dev or ModTools Dev containers to pick up code fixes - the `freegle-host-scripts` container automatically syncs file changes to dev containers.
- The Freegle Production and ModTools Production containers require a full rebuild to pick up code changes since they run production builds.
- **File Sync**: The `freegle-host-scripts` container runs `file-sync.sh` which uses inotifywait to monitor file changes in iznik-nuxt3, iznik-nuxt3-modtools, iznik-server, iznik-server-go, and iznik-batch directories. Changes are automatically synced to dev containers via `docker cp`. Check logs with `docker logs freegle-host-scripts --tail 20`.
- **HMR Caveat**: While file sync works reliably, Nuxt's HMR may not always detect `docker cp` file changes. If changes don't appear after sync, restart the container: `docker restart modtools-dev-live`.
- The API v2 (Go) container requires a full rebuild to pick up code changes: `docker-compose build apiv2 && docker-compose up -d apiv2`
- After making changes to the status code, remember to restart the container
- When running in a docker compose environment and making changes, be careful to copy them to the container.

## Yesterday Environment Configuration

The Yesterday server (yesterday.ilovefreegle.org) runs with specific configuration:

### Active Containers
- **Only dev containers run** - Production containers (freegle-prod-local, modtools-prod-local) are disabled in docker-compose.override.yml
- Dev containers exposed on external ports: 3002 (freegle-dev-local), 3003 (modtools-dev-local)
- A template override file is provided: `docker-compose.override.yesterday.yml` - copy to `docker-compose.override.yml` on yesterday
- **Why dev containers?** They start up much faster (seconds vs 10+ minutes for production builds)
  - Dev mode uses `npm run dev` which starts immediately
  - Production mode requires full `npm run build` which is very slow
  - For Yesterday's use case (testing, data recovery), dev containers are sufficient and much more practical

### Database Configuration
- Database runs **without** innodb_force_recovery mode
- Config file: ./conf/percona-my.cnf contains InnoDB settings from backup (persists across reboots)
- SQL_MODE is set without ONLY_FULL_GROUP_BY to allow flexible GROUP BY queries
- If database has corruption issues, temporarily add `innodb_force_recovery=1` to the config
- Note: force_recovery mode prevents all database modifications (INSERT/UPDATE/DELETE)

### Port Mappings (Yesterday)
- 3002: Freegle Dev (externally accessible)
- 3003: ModTools Dev (externally accessible)
- 3012: Freegle Prod (if enabled, not accessible externally)
- 3013: ModTools Prod (if enabled, not accessible externally)
- 8095: Image Delivery (externally accessible - weserv/images for resizing/converting)
- 8181: API v1 (not accessible externally via firewall)
- 8193: API v2 (not accessible externally via firewall)

## Container Architecture

### Freegle Development vs Production
- **freegle-dev-local** (`freegle-dev-local.localhost`): Development mode with local test APIs, fast startup, hot reloading
- **freegle-dev-live** (`freegle-dev-live.localhost`, port 3004): Development mode with PRODUCTION APIs - use with caution
- **freegle-prod-local** (`freegle-prod-local.localhost`): Production build with local test APIs, slower startup
- Both dev containers use the same codebase but different Dockerfiles and environment configurations
- Production container uses `Dockerfile.prod` with hardcoded production build process

### ModTools Development vs Production
- **modtools-dev-local** (`modtools-dev-local.localhost`): Development mode with local test APIs, fast startup, hot reloading
- **modtools-prod-local** (`modtools-prod-local.localhost`): Production build with local test APIs, slower startup
- Both containers use the same codebase but different Dockerfiles and environment configurations
- Development container uses `modtools/Dockerfile` and production container uses `Dockerfile.prod`
- Production container requires a full rebuild to pick up code changes since it runs a production build

## Networking Configuration

### No Hardcoded IP Addresses
- **Never use hardcoded IP addresses** in docker-compose.yml - Docker assigns IPs dynamically
- All services use `networks: - default` without specific IP addresses
- Services communicate using container names and aliases through Docker's internal DNS
- **No hosts file entries needed**: Traefik handles routing for `.localhost` domains automatically

### Image Delivery Service Configuration
The delivery container uses weserv/images. For local development:
- **Custom nginx config**: `delivery-nginx.conf` overrides the default config to allow Docker network access
- **Environment variables**: `USER_SITE` and `IMAGE_BASE_URL` use hostnames for browser accessibility
- **Routing through Traefik**: All services route through the reverse proxy using `host-gateway`

### Playwright Testing Container
The Playwright container is configured with special networking to behave exactly like a browser:
- **Host network mode**: `network_mode: "host"` allows access to localhost services
- **No extra_hosts needed**: Direct access to production and development sites
- **Volume mounts**: Test files are mounted for automatic sync without container rebuilds
- **Base URL**: Uses `http://freegle-prod.localhost` to test against production build
- **Testing Target**: **IMPORTANT** - Tests run against the **production container** to ensure testing matches production behavior
- **Container Lifecycle**: Container is restarted for each test run to ensure clean state, but report server persists using `nohup`

Test URLs work properly:
- `http://freegle-dev-local.localhost/` - Development Freegle site (fast, hot-reload, local APIs)
- `http://freegle-dev-live.localhost/` - Development Freegle site with PRODUCTION APIs (use with caution)
- `http://freegle-prod-local.localhost/` - Production Freegle build (optimized, tested by Playwright)
- `http://apiv2.localhost:8192/` - API v2 access  
- `http://delivery.localhost/?url=http://freegle-prod.localhost/icon.png&w=116&output=png` - Image delivery
- Never add specific IP addresses in as extra_hosts in docker-compose config. That will not work when a rebuild happens.
- Remember that if you make changes directly to a container, they will be lost on restart. Any container changes must also be made locally.
- If debugging Playwright test failures, check the Freegle container for logs triggering a reload. Those will break tests. Add anything shown to the pre-optimization in nuxt.config.js and rebuild the container to pick it up.

## CircleCI Submodule Integration

This repository uses CircleCI to automatically test submodule changes. Each submodule is configured with a GitHub Actions workflow that triggers the parent repository's CircleCI pipeline.

### Current Submodule Configuration

The following submodules have `.github/workflows/trigger-parent-ci.yml` configured:
- `iznik-nuxt3`
- `iznik-nuxt3-modtools` 
- `iznik-server`
- `iznik-server-go`

### How Submodule Updates Work

When code is pushed to a submodule's master branch:

1. **GitHub Actions workflow** (`trigger-parent-ci.yml`) runs in the submodule
2. The workflow clones FreegleDocker and updates the submodule reference
3. The updated reference is pushed back to FreegleDocker master
4. This push triggers CircleCI to run the full test suite

### GitHub Token Configuration

The submodule workflows use a **fine-grained Personal Access Token (PAT)** from the FreegleGeeks service account.

**Important**: The PAT must be scoped to the **Freegle organization**, not a personal account.

To create/update the PAT:
1. Log in as FreegleGeeks
2. Settings → Developer settings → Fine-grained personal access tokens
3. **Resource owner**: Select **Freegle** (the organization) - NOT FreegleGeeks
4. **Repository access**: Select "Only select repositories" → choose `FreegleDocker`
5. **Permissions**: Contents (Read and write), Metadata (Read-only)

The PAT is stored as `FREEGLE_DOCKER_TOKEN` secret in each submodule repo.

**Troubleshooting**: "Permission denied to FreegleGeeks" means the PAT is scoped to the user account instead of the Freegle organization.

### Adding New Submodules

When adding new submodules to this repository, follow these steps:

1. **Add the submodule** to the repository using `git submodule add`

2. **Create webhook workflow** in the new submodule repository:
   ```bash
   mkdir -p NEW_SUBMODULE/.github/workflows
   ```

3. **Copy the trigger workflow** from an existing submodule:
   ```bash
   cp iznik-nuxt3/.github/workflows/trigger-parent-ci.yml NEW_SUBMODULE/.github/workflows/
   ```

4. **Add FREEGLE_DOCKER_TOKEN secret** to the new submodule repository:
   - Go to Settings → Secrets and Variables → Actions
   - Add repository secret named `FREEGLE_DOCKER_TOKEN`
   - Use the fine-grained PAT from FreegleGeeks (scoped to Freegle org)

5. **Update documentation** in:
   - Main `README.md` (add to webhook integration list)
   - `.circleci/README.md` (add to configured submodules list)
   - This `CLAUDE.md` file (add to current configuration list)

6. **Test the integration** by making a test commit to the new submodule and verifying it triggers the FreegleDocker CircleCI pipeline.

### Publishing the CircleCI Orb

**IMPORTANT**: After making changes to `.circleci/orb/freegle-tests.yml`, you must publish the orb to CircleCI for the changes to take effect:

```bash
# Load the CircleCI token from .env
source .env

# Configure the CLI (one-time setup)
~/.local/bin/circleci setup --token "$CIRCLECI_TOKEN" --host https://circleci.com --no-prompt

# Validate the orb YAML
~/.local/bin/circleci orb validate .circleci/orb/freegle-tests.yml

# Publish a new version (increment the patch version)
~/.local/bin/circleci orb publish .circleci/orb/freegle-tests.yml freegle/tests@1.x.x
```

Check the current version with: `~/.local/bin/circleci orb info freegle/tests`

## Docker Build Caching

Production containers use BuildKit cache-from and CircleCI save_cache for faster builds.

### Feature Flag

Caching is controlled by the `ENABLE_DOCKER_CACHE` environment variable in CircleCI:

- **Enable**: Set `ENABLE_DOCKER_CACHE=true` in CircleCI project settings → Environment Variables
- **Disable**: Set `ENABLE_DOCKER_CACHE=false` (rollback to old docker-compose build)
- **Default**: If not set, caching defaults to `false` (safe fallback)

### How It Works

**Two-layer caching strategy**:

1. **CircleCI save_cache** (Nuxt build artifacts):
   - Caches `.output/` directory (production build output)
   - Skips `npm run build` if code unchanged
   - ~10-16 minute savings on cache hit

2. **BuildKit cache-from** (Docker layers):
   - Caches npm dependencies and build layers in GHCR
   - Reuses unchanged layers (npm ci, Nuxt build)
   - ~3-5 minute additional savings

### Cache Versions

Current cache versions (bump to invalidate):

- **BuildKit cache**: `buildcache-v1`
- **Nuxt artifacts**: `nuxt-output-v2`

### Cache Strategy

- **Master branch**: Pulls cache, builds, pushes updated cache
- **Feature branches**: Pulls cache from master, builds, does NOT push (avoids conflicts)

### How to Invalidate Cache

When cache appears stale or corrupted:

1. Edit `.circleci/orb/freegle-tests.yml`
2. Change version suffixes:
   - `buildcache-v1` → `buildcache-v2` (find/replace all instances)
   - `nuxt-v1` → `nuxt-v2` (find/replace all instances)
3. Publish updated orb: `~/.local/bin/circleci orb publish .circleci/orb/freegle-tests.yml freegle/tests@1.x.x`
4. Push changes to trigger rebuild

### Build Performance

**Expected times**:

| Scenario | Without Cache | With Cache | Savings |
|----------|---------------|------------|---------|
| No code changes | 51 min | ~25 min | 26 min (51%) |
| Code changes only | 51 min | ~35 min | 16 min (31%) |
| Dependency changes | 51 min | ~37 min | 14 min (27%) |
| **Average (75% cache hit)** | **51 min** | **~28 min** | **23 min (45%)** |

### Rollback Instructions

**Immediate rollback** (no code changes needed):

1. Go to CircleCI project settings → Environment Variables
2. Set `ENABLE_DOCKER_CACHE=false`
3. Retrigger failed build
4. **Result**: Falls back to old docker-compose build (no caching)

**Full rollback** (if Dockerfiles cause issues):

```bash
cd /tmp/FreegleDocker
git checkout HEAD~1 -- iznik-nuxt3/Dockerfile.prod
git checkout HEAD~1 -- iznik-nuxt3-modtools/modtools/Dockerfile.prod
git commit -m "Rollback: Restore old Dockerfiles"
git push
```

### Cache Locations

**CircleCI save_cache**:
- Freegle: `~/nuxt-cache/freegle/` (`.output/`, `.nuxt/`)
- ModTools: `~/nuxt-cache/modtools/` (`.output/`, `.nuxt/`)

**GHCR BuildKit cache**:
- Freegle: `ghcr.io/freegle/freegle-prod:buildcache-v1`
- ModTools: `ghcr.io/freegle/modtools-prod:buildcache-v1`

### Monitoring Cache Performance

Check CircleCI logs for cache status:

```
✅ Found cached Freegle build (245M)
✅ Pulled Freegle cache in 45s (size: 3.2GB)
✅ Freegle build completed in 120s
📊 Total build time: 180s
```

**Warning signs** (indicates cache not working):

- ⚠️ No cached build found (expected on first run)
- ⚠️ Pull time >120s (slow network, consider disabling)
- ⚠️ Build time not improving (cache invalidated or not hitting)

## Testing Consolidation

All testing is now consolidated in the FreegleDocker CircleCI pipeline for consistency and efficiency:

### Test Suites
- **Go API Tests**: Test the fast v2 API (iznik-server-go)
- **PHPUnit Tests**: Test v1 API and background functions (iznik-server)
- **Playwright E2E Tests**: End-to-end browser testing against production build (iznik-nuxt3)

### Test Execution
- Tests run via the status page API endpoints for consistency
- All tests must pass for auto-merge to production
- Tests can be triggered manually via the status page at `http://status.localhost`
- CircleCI tests are disabled in individual submodule repositories to avoid duplication

### Auto-merge Logic
When all tests pass successfully in CircleCI, the system automatically:
1. Merges master to production branch in iznik-nuxt3
2. Triggers production deployment
3. Only proceeds if all three test suites pass (Go, PHPUnit, Playwright)
4. Auto-merge only happens on master branch builds
5. The merge commit message is "Auto-merge master to production after successful tests"

### Test Commands
- **Go Tests**: `curl -X POST http://localhost:8081/api/tests/go`
- **PHPUnit Tests**: `curl -X POST http://localhost:8081/api/tests/php`
- **Playwright Tests**: `curl -X POST http://localhost:8081/api/tests/playwright`

- When making app changes, remember to update README-APP.md.
- Remember that when working on the yesterday system you need to make sure you don't break local dev and CircleCI. We have a docker override file to help with this.
- Never merge the whole of the app-ci-fd branch into master.

## Sentry Auto-Fix Integration

The status container includes automated Sentry error analysis and fixing:

- **Uses Task Agents via Claude CLI** - deep analysis with Explore agent for finding code
- **Manual trigger only** by default (click button on status page)
- **SQLite tracking** at `/project/sentry-issues.db` prevents reprocessing (gitignored)
- **Automatic retries** up to 2 times on timeout
- **Skips local tests** - full suite runs on CircleCI after PR creation
- **Creates PRs** automatically with test cases and fixes

### Configuration

Set `SENTRY_AUTH_TOKEN` in `.env` to enable (see `SENTRY-INTEGRATION.md` for full setup).

### Important Notes

- Database at `/project/sentry-issues.db` tracks processed issues (persists across container restarts)
- Status page has "Analyze Sentry Issues Now" button for manual triggering
- API endpoints: `/api/sentry/status`, `/api/sentry/poll`, `/api/sentry/clear`
- Integration invokes `claude` CLI with `--dangerously-skip-permissions` for automation
- Each Sentry issue analysis uses your Claude Code quota (no additional costs)
- When making changes to the tests, don't forget to update the orb.
- We should always create plans/ md files in FreegleDocker, never in submodules.
- When we switch branches, we usually need to rebuild the Freegle dev containers, so do that automatically.
- **Browser Testing**: See `BROWSER-TESTING.md` for Chrome DevTools MCP usage, login flow, debugging computed styles, and injecting CSS fixes.