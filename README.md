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
- `iznik-nuxt3` (User website aka FD)
- `iznik-nuxt3-modtools` (Moderate website aka ModTools, which uses the nuxt3 repo modtools branch)
- `iznik-server` (legacy PHP API)
- `iznik-server-go` (more modern Go API)

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
docker logs freegle-freegle
docker logs freegle-modtools

# Execute commands in containers  
docker exec -it freegle-freegle bash
docker exec -it freegle-percona mysql -u root -piznik

# Restart specific services
docker restart freegle-modtools
```

# Using the System

Once all services show as **Running** in the status monitor, you can access:

## Status & Monitoring
* **[Status Monitor](http://localhost:8081)** - Real-time service health with CPU monitoring and visit buttons

## Main Applications
* **[Freegle](https://freegle.localhost)** - User site (Login: `test@test.com` / `freegle`)
* **[ModTools](https://modtools.localhost)** - Moderator site (Login: `testmod@test.com` / `freegle`)

**Note:** It's normal for Freegle and ModTools pages to reload a few times on first view - 
this is expected Nuxt.js development mode behavior. Also, `nuxt dev` uses HTTP/1.1 which 
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
<summary>🧪 Test Configuration</summary>

# Test Configuration

The system contains one test group, FreeglePlayground, centered around Edinburgh.  
The only recognised postcode is EH3 6SS.

</details>

<details>
<summary>⚠️ Limitations</summary>

# Limitations

* Email to Mailhog not yet verified.
* Image upload not tested yet.
* We're sharing the live tiles server - we've not added this to the Docker Compose setup yet.
* The Go API doesn't have HMR or equivalent, so you'll need to rebuild the container to pick up code changes.
* This doesn't run the various background jobs, so it won't be sending out emails in the way the live system would.

</details>
