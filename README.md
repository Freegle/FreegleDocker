This is a top-level development environment that uses Docker Compose to start up a standalone Freegle system. It uses git submodules to manage the various Freegle repositories.

# Installation

After cloning this repository, initialize the submodules:

```bash
git submodule update --init --recursive
```

This will clone the required Freegle repositories:
- `iznik-server` (PHP API)
- `iznik-server-go` (Go API) 
- `iznik-nuxt3` (User website)
- `iznik-nuxt` (ModTools website)
- `iznik-nuxt3-modtools` (ModTools from nuxt3 repo, modtools branch)

Since these are [git submodules](https://git-scm.com/book/en/v2/Git-Tools-Submodules), you can navigate into each subdirectory and work with them as independent git repositories - checking out different branches, making commits, etc.

## Windows

On Windows, you have two options for running this Docker setup:

### Option 1: WSL2 (Recommended - Much Faster)
1. Install [Docker Desktop for Windows](https://www.docker.com/products/docker-desktop/) with WSL2 backend
2. Install a WSL2 distribution (Ubuntu recommended)
3. Clone this repository to a WSL2 path (e.g., `\\wsl$\Ubuntu\home\edward\FreegleDockerWSL`)
4. Run `docker-compose up -d` from within the WSL2 environment

### Option 2: Docker Desktop (Much Slower)
1. Install [Docker Desktop for Windows](https://www.docker.com/products/docker-desktop/)
2. Clone this repository to any Windows directory
3. Run `docker compose up -d` from the cloned directory

Add these to your hosts file:

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

## Linux

Feel free to write this.

# Running

Start the entire system:

```docker-compose up -d```

# Build Process Monitoring

Monitor the startup progress at [Status Monitor](http://status.localhost:8081) to see when all services are ready.

The system builds in stages:

1. **Infrastructure** (databases, queues, reverse proxy) - ~2-3 minutes
2. **Development Tools** (PhpMyAdmin, MailHog) - ~1 minute  
3. **Freegle Components** (websites, APIs) - ~10-15 minutes

The Freegle and ModTools sites take the longest as they need to:
- Install npm dependencies
- Build Nuxt.js applications  
- Start the web servers

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

**Note:** It's normal for Freegle and ModTools pages to reload a few times on first view - this is expected Nuxt.js development mode behavior. Additionally, this development environment uses HTTP/1.1 which serializes asset loading, making it slower than the live system which uses HTTP/2.  This means the page load can be quite slow until the browser has cached the code.  You can see this via 'Pending' calls in the Network tab.

## Development Tools
* **[PhpMyAdmin](https://phpmyadmin.localhost)** - Database management (Login: `root` / `iznik`)
* **[MailHog](https://mailhog.localhost)** - Email testing interface
* **[TusD](https://tusd.localhost)** - Image upload service
* **[Traefik Dashboard](http://localhost:8080)** - Reverse proxy dashboard

## API Endpoints
* **[API v1](https://apiv1.localhost)** - Legacy PHP API
* **[API v2](https://apiv2.localhost:8192)** - Modern Go API

**Important Notes:**
- HTTP is also available on port 82 for compatibility
- Port 8081 is used for the status monitor (HTTP only)
- Port 8192 is used for API v2

# Test Configuration

The system contains one test group, FreeglePlayground, centered around Edinburgh.  
The only recognised postcode is EH3 6SS.

# Troubleshooting

* Sometimes  
# Limitations

* Email to Mailhog not yet verified.
* This doesn't run the various background jobs, so it won't be sending out emails in the way the live system would.