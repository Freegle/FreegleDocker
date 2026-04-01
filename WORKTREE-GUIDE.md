# Running Parallel Instances with Git Worktrees

This guide explains how to run multiple isolated FreegleDocker instances on the same machine using git worktrees. Each instance has its own containers, database, ports, and volumes — completely independent.

## Prerequisites

- A working default instance (the main FreegleDocker checkout)
- `COMPOSE_PROJECT_NAME=freegle` in your `.env` (this is the default)

## Quick Start

### 1. Create a worktree

```bash
cd /home/edward/FreegleDockerWSL
git worktree add ../FreegleDockerWSL-feature feature/my-branch
cd ../FreegleDockerWSL-feature
git submodule update --init --recursive
```

### 2. Configure the instance

Copy your `.env` and change the project name and all ports:

```bash
cp ../FreegleDockerWSL/.env .env
```

Edit `.env` — change these values:

```env
COMPOSE_PROJECT_NAME=freegle2

# Every port must be different from the default instance.
PORT_TRAEFIK_HTTP=9080
PORT_TRAEFIK_API=9192
PORT_TRAEFIK_DASHBOARD=9880
PORT_STATUS=9081
PORT_APIV1_SSH=2023
PORT_APIV1_HTTP=9083
PORT_APIV1_HTTP2=9181
PORT_APIV2=9193
PORT_APIV2_LIVE=9194
PORT_FREEGLE_DEV_LOCAL=4002
PORT_FREEGLE_DEV_LIVE=4005
PORT_FREEGLE_PROD_LOCAL=4012
PORT_MODTOOLS_DEV_LOCAL=4003
PORT_MODTOOLS_DEV_LIVE=4006
PORT_MODTOOLS_PROD_LOCAL=4013
PORT_MAILPIT_WEB=9025
PORT_MAILPIT_SMTP=2025
PORT_SPAMASSASSIN=9783
PORT_RSPAMD=12335
PORT_TUSD=2080
PORT_AI_SUPPORT=9883
PORT_LOKI=4100
PORT_PHPMYADMIN=9086
PORT_POSTFIX=9025
PORT_MCP_SANITIZER=9084

# Loki volume must be unique per instance
LOKI_VOLUME=freegle2-loki-data
```

### 3. Start the instance

```bash
docker-compose up -d
```

This creates containers named `freegle2-*` with their own database volume, network, and ports.

### 4. Verify

- Status page: `http://localhost:9081`
- The status page shows the project name and branch in the header

## How Isolation Works

| Resource | Default Instance | Worktree Instance |
|----------|-----------------|-------------------|
| Container names | `freegle-traefik` | `freegle2-traefik` |
| Database volume | `freegle_freegle_db` | `freegle2_freegle_db` |
| Loki volume | `loki-data` | `freegle2-loki-data` |
| HTTP port | 80 | 9080 |
| Status page | 8081 | 9081 |
| API v2 | 8193 | 9193 |
| Mailpit | 8025 | 9025 |

### What's automatically isolated

- **Container names**: All use `${COMPOSE_PROJECT_NAME}-` prefix
- **Database**: Docker Compose auto-prefixes named volumes with the project name
- **Docker network**: Each project gets its own `freegle2_default` network
- **Traefik routing**: Each traefik instance only manages containers from its own project (via `com.docker.compose.project` label constraint)

### What needs manual configuration

- **Ports**: You must set every `PORT_*` variable to avoid clashes
- **Loki volume**: Set `LOKI_VOLUME` to a unique name (the default `loki-data` is shared)

## Stopping and Cleaning Up

```bash
# Stop the worktree instance
cd /home/edward/FreegleDockerWSL-feature
docker-compose down

# Remove volumes too (deletes the database)
docker-compose down -v

# Remove the worktree
cd /home/edward/FreegleDockerWSL
git worktree remove ../FreegleDockerWSL-feature
```

Note: `git worktree remove` may fail if the worktree contains submodules. In that case, manually delete the directory and run `git worktree prune`.

## Running Tests

Each instance has its own status container with test runners. Tests run against that instance's own containers and database:

- **Go tests**: `curl -X POST http://localhost:9081/api/tests/go`
- **Laravel tests**: `curl -X POST http://localhost:9081/api/tests/laravel`
- **Vitest**: `curl -X POST http://localhost:9081/api/tests/vitest`
- **Playwright**: `curl -X POST http://localhost:9081/api/tests/playwright`

Replace `9081` with the worktree's `PORT_STATUS` value.

## Troubleshooting

### Port conflict on startup
Check which ports are already in use:
```bash
docker ps --format '{{.Ports}}' | grep -oP '0\.0\.0\.0:\d+' | sort | uniq -d
```

### CRLF line endings in worktree (WSL)
If Docker builds fail with "not found" errors on shell scripts, fix line endings:
```bash
cd worktree/iznik-server-go
git config core.autocrlf input
git rm --cached -r . && git reset --hard HEAD
```

### Container name clash
If you see "container name already in use", check that all `container_name` entries in `docker-compose.yml` use the `${COMPOSE_PROJECT_NAME:-freegle}-` prefix. The modtools containers were fixed in the port isolation PR.

### Loki crash loop
If loki keeps restarting with "segments are not sequential", clear its WAL:
```bash
docker stop freegle2-loki
docker run --rm -v freegle2-loki-data:/loki alpine sh -c 'rm -rf /loki/wal/*'
docker start freegle2-loki
```
