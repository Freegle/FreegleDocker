This is a Docker Compose file which starts up a standalone Freegle system, typically for development.

# Pre-requisites

You'll need [Docker](https://www.docker.com/get-started/) and a clone of this repo.

 

# Installation & Building

## Quick Start

Start the entire system:

```bash
docker compose up -d
```

## Build Process Monitoring

Monitor the startup progress at [Status Monitor](http://localhost:8081) to see when all services are ready.

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

## Development Mode

For faster development builds, both Freegle and ModTools containers support development mode:

```bash
# Containers will run `npm run dev` instead of full build + start
# This enables hot reload and faster startup times
# Already configured via NUXT_DEV_MODE=true in docker-compose.yml
```

**Development mode benefits:**
- Skip production build step (saves 5-10 minutes)
- Hot reload for code changes
- Faster container startup

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
* **[Freegle](http://freegle.localhost)** - User site (Login: `test@test.com` / `freegle`)
* **[ModTools](http://modtools.localhost)** - Moderator site (Login: `testmod@test.com` / `freegle`)

## Development Tools
* **[PhpMyAdmin](http://phpmyadmin.localhost)** - Database management (Login: `root` / `iznik`)
* **[MailHog](http://mailhog.localhost)** - Email testing interface
* **[TusD](http://tusd.localhost)** - Image upload service
* **[Traefik Dashboard](http://localhost:8080)** - Reverse proxy dashboard

## API Endpoints
* **[API v1](http://apiv1.localhost)** - Legacy PHP API
* **[API v2](http://apiv2.localhost:8192)** - Modern Go API

## Network Configuration

**Note**: On Windows, you may need to add these entries to your hosts file (`C:\Windows\System32\drivers\etc\hosts`):
```
127.0.0.1 freegle.localhost
127.0.0.1 modtools.localhost
127.0.0.1 phpmyadmin.localhost
127.0.0.1 mailhog.localhost
127.0.0.1 tusd.localhost
127.0.0.1 status.localhost
127.0.0.1 apiv1.localhost
127.0.0.1 apiv2.localhost
```

**Important Notes:**
- All services use HTTP for local development  
- Port 80 is used for all web services
- Port 8081 is used for the status monitor
- Port 8192 is used for API v2

# Configuration

The system contains one test group, FreeglePlayground, centered around Edinburgh.  
The only recognised postcode is EH3 6SS.

# Using a real domain

Although this setup is hardcoded to use the hostnames above, you can use it on a real domain with appropriate
nginx configuration.  See `nginx.conf` for the config.

# Troubleshooting

## Common Issues

**Services stuck in "Starting..." state:**
- Check container logs: `docker logs freegle-<service-name>`
- First-time builds can take 15+ minutes for Freegle components
- Restart if needed: `docker restart freegle-<service-name>`

**Status monitor showing offline services:**
- Wait for infrastructure services to become healthy first
- Development tools depend on infrastructure
- Freegle components depend on development tools

**Performance Issues:**
- Monitor CPU usage in the status dashboard
- Increase Docker memory allocation if containers are resource-constrained
- Check disk space for Docker images and containers

**Container Names Reference:**
```
freegle-traefik          # Reverse proxy (HTTP)
freegle-percona          # MySQL database  
freegle-postgres         # PostgreSQL database
freegle-redis            # Cache
freegle-beanstalkd       # Job queue
freegle-spamassassin     # Email filtering
freegle-mailhog          # Email testing
freegle-phpmyadmin       # Database UI
freegle-tusd             # Upload service (also on port 1080)
freegle-apiv1            # PHP API
freegle-apiv2            # Go API
freegle-freegle          # User website
freegle-modtools         # Moderator website
freegle-status           # Status monitor
```

**Direct Port Access:**
- Port 80: HTTP for all web services
- Port 1080: TusD file upload service (direct access)
- Port 8080: Traefik dashboard
- Port 8081: Status monitor
- Port 8192: API v2 service
