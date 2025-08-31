This is a top-level Docker Compose environment for a [Freegle](https://www.ilovefreegle.org) development system.  You should be able to start up a local development environment and make changes to each of the client/server components.

<details>
<summary>📦 Installation</summary>

## Installation

This top-level repository has a number of [git submodules](https://git-scm.com/book/en/v2/Git-Tools-Submodules) (see `.gitmodules` in project root).

To clone this repository and all submodules:

`git clone --recurse-submodules https://github.com/freegle/FreegleDocker`

If you cloned without the `--recurse-submodules` flag, you can initialize them with:

`git submodule update --init --recursive`

**Make sure you do this from a WSL page (e.g. /home/user/whatever) not from a Windows path (e.g. /mnt/c/whatever).**  
(Not 100% sure this is necessary, but it is what is tested)

This will clone the required Freegle repositories:
- `iznik-nuxt3` (User website aka FD - runs as both dev and prod containers)
- `iznik-nuxt3-modtools` (Moderator website aka ModTools)
- `iznik-server` (legacy PHP API)
- `iznik-server-go` (modern Go API)

Since these are git submodules, you can navigate into each subdirectory and work with them as independent git repositories - checking out different branches, making commits, etc.

## Configuration

The system can be customized through environment variables in a `.env` file. Copy `.env.example` to `.env` and modify as needed. The basic system will work without any configuration, but some features require API keys.

**Branch Selection (Optional):**
- `IZNIK_SERVER_BRANCH` - Branch for the PHP API server (default: master)
- `IZNIK_SERVER_GO_BRANCH` - Branch for the Go API server (default: master)  
- `IZNIK_NUXT3_BRANCH` - Branch for the user website (default: master)
- `IZNIK_NUXT3_MODTOOLS_BRANCH` - Branch for ModTools (default: master)

**External Service API Keys (Optional but Recommended):**
These keys enable full functionality and are used for both application features and PHPUnit testing:

- `GOOGLE_CLIENT_ID` - Google OAuth client ID for user authentication
- `GOOGLE_CLIENT_SECRET` - Google OAuth client secret for user authentication
- `GOOGLE_PUSH_KEY` - Google API key for push notifications
- `GOOGLE_VISION_KEY` - Google Vision API key for image analysis
- `GOOGLE_PERSPECTIVE_KEY` - Google Perspective API key for content moderation
- `GOOGLE_GEMINI_API_KEY` - Google Gemini API key for AI services
- `GOOGLE_PROJECT` - Google Cloud project ID
- `GOOGLE_APP_NAME` - Application name for Google services (usually "Freegle")
- `MAPBOX_KEY` - Mapbox API key for map tiles and routing
- `MAXMIND_ACCOUNT` - MaxMind account ID for GeoIP services
- `MAXMIND_KEY` - MaxMind license key for GeoIP services

**Example `.env` file:**
```bash
# Branch configuration (optional)
IZNIK_SERVER_BRANCH=better-phpunit
IZNIK_SERVER_GO_BRANCH=master
IZNIK_NUXT3_BRANCH=master
IZNIK_NUXT3_MODTOOLS_BRANCH=master

# API Keys (optional but recommended)
GOOGLE_CLIENT_ID=your_google_client_id_here.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your_google_client_secret_here
GOOGLE_PUSH_KEY=your_google_push_key_here
GOOGLE_VISION_KEY=your_google_vision_api_key_here
GOOGLE_PERSPECTIVE_KEY=your_google_perspective_api_key_here
GOOGLE_GEMINI_API_KEY=your_google_gemini_api_key_here
GOOGLE_PROJECT=your_google_project_id_here
GOOGLE_APP_NAME=Freegle
MAPBOX_KEY=your_mapbox_api_key_here
MAXMIND_ACCOUNT=your_maxmind_account_here
MAXMIND_KEY=your_maxmind_key_here
```

**After Configuration Changes:**
If you modify branch settings or API keys, rebuild the affected containers:

```bash
# Rebuild specific container
docker-compose build --no-cache apiv1

# Or rebuild all containers
docker-compose build --no-cache
```

## Windows

Add these to your hosts file first:

```
127.0.0.1 freegle.localhost
127.0.0.1 freegle-dev.localhost
127.0.0.1 freegle-prod.localhost
127.0.0.1 modtools.localhost
127.0.0.1 phpmyadmin.localhost
127.0.0.1 mailhog.localhost
127.0.0.1 tusd.localhost
127.0.0.1 status.localhost
127.0.0.1 apiv1.localhost
127.0.0.1 apiv2.localhost
127.0.0.1 delivery.localhost
```

On Windows, using Docker Desktop works but is unusably slow.  So we won't document that.  Instead we use WSL2, with some jiggery-pokery to get round issues with file syncing and WSL2.

Here are instructions on the assumption that you have a JetBrains IDE (e.g. PhpStorm):
1. Install a WSL2 distribution (Ubuntu recommended). If you already have a WSL installation, you may benefit from installing a dedicated freegle one `wsl --install --name freegle`
2. Clone this repository from JetBrains **and give it a WSL2 path** (e.g., `\\wsl$\Ubuntu\home\edward\FreegleDockerWSL`).
3. [Install docker](https://docs.docker.com/engine/install/ubuntu/#install-using-the-repository)
4. Use `wsl` to open a WSL2 terminal in the repository directory.
5. Start the docker service: `sudo service docker start`
6. Move on to the Running section.

### Troubleshooting

If the localhost domains above don't work, check that Windows hasn't blocked access: `curl -I freegle.localhost` should give a 200 response.

If this is the case, you can open a proxy port: `sudo netsh interface portproxy add v4tov4 listenport=80 listenaddress=0.0.0.0 connectport=80 connectaddress=<wsl IP address>` (see [SO post](https://stackoverflow.com/questions/70566305/unable-to-connect-to-local-server-on-wsl2-from-windows-host) for more info)

## Linux

Feel free to write this.

</details>

<details>
<summary>🚀 Running</summary>

# Running

On Windows:
* Run `docker-compose up -d` from within the WSL2 environment to start the system.
* Run `./file-sync.sh` from within WSL2.  This monitors file changes (e.g. from your Windows IDE) and syncs them to the Docker containers.

`file-sync.sh` only monitors changes while it's running.  So if you do bulk changes (e.g. switching branches) while this isn't running, you may need to docker stop/prune/start to make sure they're picked up by the container.

# Monitoring

Monitor the startup progress at [Status Monitor](http://status.localhost:8081) to see when all services are ready.

The system builds in stages:

1. **Infrastructure** (databases, queues, reverse proxy) - ~2-3 minutes
2. **Development Tools** (PhpMyAdmin, MailHog) - ~1 minute
3. **Freegle Components** (websites, APIs) - ~10-15 minutes

**Container Status Indicators:**
- 🟢 **Running** - Service is ready
- 🟡 **Starting...** - Service is building/starting up
- 🔴 **Offline** - Service has failed

## Rebuild from Scratch

If you need to wipe everything and rebuild:

```bash
docker compose down
docker system prune -a  # Warning: removes all unused Docker data
docker compose up -d
```

## Individual Container Management

All containers use consistent `freegle-*` naming:

```bash
# View logs
docker logs freegle-freegle-dev    # Development Freegle site
docker logs freegle-freegle-prod   # Production Freegle site
docker logs freegle-modtools       # ModTools site
docker logs freegle-apiv1          # PHP API
docker logs freegle-apiv2          # Go API
docker logs freegle-status         # Status monitor
docker logs freegle-delivery       # Image delivery service
docker logs freegle-playwright     # Test runner

# Execute commands in containers  
docker exec -it freegle-freegle-dev bash
docker exec -it freegle-percona mysql -u root -piznik

# Restart specific services
docker restart freegle-modtools
docker restart freegle-status
```

## Running Playwright Tests Manually

To run Playwright tests manually within the playwright container:

```bash
# Enter the playwright container
docker exec -it freegle-playwright bash

# Run all tests
npm run test

# Run specific test file
npx playwright test tests/e2e/filename.spec.js

# Run tests with UI (requires X11 forwarding in WSL)
npm run test:ui

# Run tests in headed mode (requires X11 forwarding in WSL)
npm run test:headed

# View test report after running tests
npm run test:show-report
```

**Accessing Test Reports:**
After running `npm run test:show-report` inside the container, the Playwright HTML report will be accessible at:
- **[Test Report](http://localhost:9324)** - Playwright HTML test report

The report server will display:
```
Serving HTML report at http://localhost:9323. Press Ctrl+C to quit.
```

Note: The report runs on port 9323 inside the container but is mapped to port 9324 on the host system.

# Using the System

Once all services show as **Running** in the status monitor, you can access:

## Status & Monitoring
* **[Status Monitor](http://localhost:8081)** - Real-time service health with CPU monitoring, visit buttons, and container management
  - **Restart Button** - Available for all containers to quickly restart services
  - **Rebuild Button** - Available for containers with build context (freegle, modtools, apiv1, apiv2, status) to rebuild and restart
  - **Playwright Test Runner** - Run end-to-end tests with real-time progress tracking and HTML reports
  
  > ⚠️ **Development Tool Notice**: The status monitor and test runner functionality was created by [Claude Code](https://claude.ai/code) and is intended for development use only. It is not production-quality code and should not be used in production environments.

## Main Applications
* **[Freegle Dev](https://freegle-dev.localhost)** - User site development version (Login: `test@test.com` / `freegle`)
* **[Freegle Prod](https://freegle-prod.localhost)** - User site production build (Login: `test@test.com` / `freegle`) 
* **[ModTools](https://modtools.localhost)** - Moderator site (Login: `testmod@test.com` / `freegle`)

**Note:** It's normal for Freegle Dev and ModTools pages to reload a few times on first view - 
this is expected Nuxt.js development mode behavior. The Freegle Prod container runs a production 
build for testing production-like behavior. Also, `nuxt dev` uses HTTP/1.1 which 
serializes asset loading, making it slower than the live system which uses HTTP/2.  
This means the page load can be quite slow until the browser has cached the code.  
You can see this via 'Pending' calls in the Network tab.

## Development Tools
* **[PhpMyAdmin](https://phpmyadmin.localhost)** - Database management (Login: `root` / `iznik`)
* **[MailHog](https://mailhog.localhost)** - Email testing interface
* **[TusD](https://tusd.localhost)** - Image upload service
* **[Image Delivery](https://delivery.localhost)** - Image processing service (weserv/images)
* **[Traefik Dashboard](http://localhost:8080)** - Reverse proxy dashboard

## API Endpoints
* **[API v1](https://apiv1.localhost)** - Legacy PHP API
* **[API v2](https://apiv2.localhost:8192)** - Modern Go API

</details>

<details>
<summary>🔄 Continuous Integration</summary>

# CircleCI Integration

This repository includes CircleCI configuration that automatically monitors submodules and runs integration tests when changes are detected.

## Automated Submodule Testing

The system automatically:
- **Monitors submodules** every 6 hours for updates
- **Updates submodules** to latest commits on their default branches
- **Runs full integration tests** using the complete Docker Compose stack
- **Commits successful updates** back to the repository
- **Responds to webhooks** from submodule repositories for immediate testing

## Workflows

### Scheduled Check: `scheduled-submodule-check`
- **Schedule**: Every 6 hours (`0 */6 * * *`)
- **Branch**: Only runs on `master`
- **Process**:
  1. Updates all submodules to latest commits
  2. Starts complete Docker Compose environment (if changes detected)
  3. Waits for all services to be ready
  4. Runs Playwright end-to-end tests via status container
  5. Collects test artifacts and logs
  6. Commits updates if tests pass

### Webhook Trigger: `webhook-triggered`
- **Purpose**: Immediate testing when submodule repositories push changes
- **Trigger**: API calls from submodule repository webhooks
- **Behavior**: Forces testing regardless of detected changes

### Manual/Push: `build-and-test`
- **Trigger**: Push to `master` branch or manual pipeline trigger
- **Purpose**: On-demand testing and validation

## Webhook Integration

The following submodules are pre-configured with GitHub Actions workflows that automatically trigger CircleCI builds in this repository when changes are pushed:

- **iznik-nuxt3** - User website repository
- **iznik-nuxt3-modtools** - ModTools repository  
- **iznik-server** - Legacy PHP API repository
- **iznik-server-go** - Modern Go API repository

Each submodule contains `.github/workflows/trigger-parent-ci.yml` that triggers the FreegleDocker CircleCI pipeline on push to master/main branches.

### Setup Required

To activate webhook integration, add a `CIRCLECI_TOKEN` secret to each submodule repository:

1. **Get CircleCI API Token** from CircleCI → Personal API Tokens
2. **Add secret** to each submodule: Settings → Secrets and Variables → Actions
3. **Secret name**: `CIRCLECI_TOKEN`
4. **Secret value**: Your CircleCI API token

Once configured, any push to master/main in the submodules will automatically trigger integration testing in this repository.

## Manual Testing

Trigger tests manually via CircleCI dashboard or API:

```bash
# Via CircleCI API
curl -X POST \
  -H "Circle-Token: YOUR_CIRCLECI_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"branch": "master"}' \
  https://circleci.com/api/v2/project/github/Freegle/FreegleDocker/pipeline
```

## Monitoring

- **Build Artifacts**: Docker logs, test reports, and debugging info automatically collected
- **Timeout Protection**: Builds timeout after appropriate intervals to prevent resource waste
- **Resource Cleanup**: Docker resources are always cleaned up after completion
- **Smart Testing**: Only runs tests when submodule changes are detected

For detailed setup instructions, see [`.circleci/README.md`](.circleci/README.md).

</details>

<details>
<summary>🧪 Test Configuration</summary>

# Test Configuration

The system contains one test group, FreeglePlayground, centered around Edinburgh.  
The only recognised postcode is EH3 6SS.

</details>

<details>
<summary>⚠️ Limitations</summary>

# Limitations

* Email to Mailhog not yet verified.
* We're sharing the live tiles server - we've not added this to the Docker Compose setup yet.
* This doesn't run the various background jobs, so it won't be sending out emails in the way the live system would.
* **Test Coverage Reports:** Code coverage reporting is disabled in the Docker environment to prevent test hangs. Coverage reports are only generated in CI/CircleCI environments.
* **Playwright Coverage:** Playwright code coverage collection is disabled in the local Docker environment to prevent performance issues and test instability. Coverage is only collected during CI builds.

**Container Development Notes:**
- **Freegle Dev**: Runs `nuxt dev` with hot module reloading for rapid development
- **Freegle Prod**: Runs production build to test optimized behavior without HMR
- **ModTools**: Runs `nuxt dev` with hot module reloading for rapid development  
- **Go API (apiv2)**: No hot module reloading - use **Rebuild Button** in [Status Monitor](http://localhost:8081) for quick rebuilds
- **Playwright**: Dedicated container for running end-to-end tests with network access to all services

</details>
