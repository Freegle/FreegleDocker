- Always restart the status monitor after making changes to its code.
- Remember that the process for checking whether this compose project is working should involve stopping all containers, doing a prune, rebulding and restarting, and monitoring progress using the status container.
- You don't need to rebuild the Freegle Dev or ModTools containers to pick up code fixes - they run nuxt dev which will do that.
- The Freegle Production container requires a full rebuild to pick up code changes since it runs a production build.
- The API v2 (Go) container requires a full rebuild to pick up code changes: `docker-compose build apiv2 && docker-compose up -d apiv2`
- After making changes to the status code, remember to restart the container
- When running in a docker compose environment and making changes, be careful to copy them to the container.

## Container Architecture

### Freegle Development vs Production
- **freegle-dev** (`freegle-dev.localhost`): Development mode with `npm run dev`, fast startup, hot reloading
- **freegle-prod** (`freegle-prod.localhost`): Production mode with `npm run build`, full optimization, slower startup
- Both containers use the same codebase but different Dockerfiles and environment configurations
- Production container uses `Dockerfile.prod` with hardcoded production build process

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
- `http://freegle-dev.localhost/` - Development Freegle site (fast, hot-reload)
- `http://freegle-prod.localhost/` - Production Freegle site (optimized, tested by Playwright)
- `http://apiv2.localhost:8192/` - API v2 access  
- `http://delivery.localhost/?url=http://freegle-prod.localhost/icon.png&w=116&output=png` - Image delivery
- When you create new files, add them to git automatically unless they are temporary for testing.
- Never add specific IP addresses in as extra_hosts in docker-compose config.  That will not work when a rebuild happens.
- Remember if that if you make changes directly to a container, they will be lost on restarte..  Any container changes miust aalso be make locally.
- Don't use simple delays in Playwright tests - these are prone to failure on slow runs.  It's ok to have a wait as a fallback.
- If debugging Playwright test failures, check the Freegle container for logs triggering a reload.  Those will break tests.  Add anything shown to the pre-optimization in nuxt.config.js and rebuild the container to pick it up.
- Never fix Playwright tests by direct navigation - we need to simular user behaviour via clicks.  Similarly never bypass Playwright's checks by doing native Javascript click.
- In Playwright tests, always use type() not fill() where possible to simulate user behaviour.
Fix Withdraw issue.

## CircleCI Submodule Integration

This repository uses CircleCI to automatically test submodule changes. Each submodule is configured with a GitHub Actions workflow that triggers the parent repository's CircleCI pipeline.

### Current Submodule Configuration

The following submodules have `.github/workflows/trigger-parent-ci.yml` configured:
- `iznik-nuxt3`
- `iznik-nuxt3-modtools` 
- `iznik-server`
- `iznik-server-go`

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

4. **Add CIRCLECI_TOKEN secret** to the new submodule repository:
   - Go to Settings → Secrets and Variables → Actions
   - Add repository secret named `CIRCLECI_TOKEN`
   - Use the CircleCI API token value

5. **Update documentation** in:
   - Main `README.md` (add to webhook integration list)
   - `.circleci/README.md` (add to configured submodules list)
   - This `CLAUDE.md` file (add to current configuration list)

6. **Test the integration** by making a test commit to the new submodule and verifying it triggers the FreegleDocker CircleCI pipeline.

The webhook workflow automatically triggers CircleCI builds when changes are pushed to master/main branches, enabling continuous integration testing of the complete Docker stack.

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

- When removing code, never leave comments about what used to be there, or "this is now" stuff - the code should reflect the current behaviour.
- When making changes to iznik-nuxt3, don't make the corresponding changes to the modtools branch which contains the same code.  That will get handled later via merge.
- When creating temporary scripts to run, put them in /tmp.  That way they won't clutter up git.