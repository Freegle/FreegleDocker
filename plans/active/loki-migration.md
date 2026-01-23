# Loki Migration: Standalone Container → Docker Compose

**Status**: In Progress
**Branch**: `feature/worker-pools-v2`
**PR**: #18

## Overview

Migrate Loki from a standalone Docker container to the FreegleDocker docker-compose environment. This is phase 1 of a larger migration that will later include iznik-batch and incoming mail.

## Current State

### Standalone Container (This Server)
- **Image**: `grafana/loki:3.0.0`
- **Data**: `/opt/loki/data` (26GB)
- **Config**: `/opt/loki/config/loki-config.yaml`
- **Restart Policy**: `--restart unless-stopped` (will restart on reboot!)
- **Network**: Default bridge (isolated from docker-compose network)

### Docker Compose (Current)
- **Image**: `grafana/loki:2.9.0` (outdated)
- **Data**: Named volume `loki-data`
- **Config**: `./conf/loki-config.yaml`
- **Restart**: `"no"`

## Design Decision: Bind Mount vs Named Volume

### Option A: Named Volume (Recommended)
Use Docker named volume everywhere, migrate existing data once.

**Pros**:
- Consistent across all environments (local dev, CI, production)
- Docker handles permissions automatically
- No path dependencies
- Works out-of-box for new developers

**Cons**:
- One-time migration of 26GB required
- Data in Docker's internal storage (harder to inspect)
- Lost if Docker storage reset

**Migration**:
```bash
docker volume create loki-data
docker run --rm -v loki-data:/loki -v /opt/loki/data:/source alpine sh -c "cp -a /source/. /loki/"
```

### Option B: Bind Mount with Override
Use named volume as default, override on this server to use existing bind mount.

**Pros**:
- No data migration needed
- Easy backup with standard tools
- Data survives Docker reinstall

**Cons**:
- Inconsistent between environments
- Need override file on this server
- Must create directory on new systems

**Override** (`docker-compose.override.yml` on this server):
```yaml
services:
  loki:
    volumes:
      - /opt/loki/data:/loki
      - ./conf/loki-config.yaml:/etc/loki/local-config.yaml:ro
```

## Changes Required

### 1. docker-compose.yml

```yaml
loki:
  container_name: freegle-loki
  image: grafana/loki:3.0.0  # Upgrade from 2.9.0
  networks:
    - default
  ports:
    - "3100:3100"
  volumes:
    - loki-data:/loki
    - ./conf/loki-config.yaml:/etc/loki/local-config.yaml:ro
  command: -config.file=/etc/loki/local-config.yaml
  restart: unless-stopped  # Changed from "no" for production reliability
  healthcheck:
    test: ["CMD-SHELL", "wget --no-verbose --tries=1 --spider http://localhost:3100/ready || exit 1"]
    interval: 10s
    timeout: 5s
    retries: 5
    start_period: 30s
  labels:
    - "traefik.enable=true"
    - "traefik.http.routers.loki.rule=Host(`loki.localhost`)"
    - "traefik.http.routers.loki.entrypoints=web"
    - "traefik.http.services.loki.loadbalancer.server.port=3100"
```

### 2. Remove Grafana Service
Grafana is not needed - remove from docker-compose.yml and the `grafana-data` volume.

### 3. Config File Sync
The repo's `./conf/loki-config.yaml` is already more comprehensive than the live server's config (has retention policies). Keep using the repo version.

## Migration Steps (This Server)

### Pre-Migration

1. **Save standalone container config for backout**:
   ```bash
   mkdir -p /opt/loki/backup
   docker inspect loki > /opt/loki/backup/container-config-$(date +%Y%m%d).json
   cp /opt/loki/config/loki-config.yaml /opt/loki/backup/loki-config-$(date +%Y%m%d).yaml
   ```

2. **Verify current logs are flowing** (baseline):
   ```bash
   curl -G -s "http://localhost:3100/loki/api/v1/query" \
     --data-urlencode 'query={app="freegle"}' \
     --data-urlencode 'limit=1' | jq '.data.result | length'
   ```

### Migration (Option A - Named Volume)

3. **Create named volume and copy data**:
   ```bash
   docker volume create loki-data
   docker run --rm \
     -v loki-data:/loki \
     -v /opt/loki/data:/source:ro \
     alpine sh -c "cp -a /source/. /loki/"
   ```

4. **Stop and remove standalone container** (prevents restart on reboot):
   ```bash
   docker stop loki && docker rm loki
   ```

5. **Pull latest branch and start via compose**:
   ```bash
   cd /var/www/FreegleDocker
   git pull
   docker compose up -d loki
   ```

### Migration (Option B - Bind Mount Override)

3. **Create override file**:
   ```bash
   cat >> docker-compose.override.yml << 'EOF'
   services:
     loki:
       volumes:
         - /opt/loki/data:/loki
         - ./conf/loki-config.yaml:/etc/loki/local-config.yaml:ro
   EOF
   ```

4. **Stop and remove standalone container**:
   ```bash
   docker stop loki && docker rm loki
   ```

5. **Start via compose**:
   ```bash
   docker compose up -d loki
   ```

### Post-Migration Verification

6. **Check container is healthy**:
   ```bash
   docker ps --filter name=freegle-loki
   docker logs freegle-loki --tail 20
   ```

7. **Verify historical data accessible**:
   ```bash
   curl -s "http://localhost:3100/loki/api/v1/label/source/values" | jq .
   ```

8. **Verify new logs flowing** (wait 5 minutes, then):
   ```bash
   curl -G -s "http://localhost:3100/loki/api/v1/query_range" \
     --data-urlencode 'query={app="freegle"}' \
     --data-urlencode "start=$(date -d '5 minutes ago' +%s)000000000" \
     --data-urlencode "end=$(date +%s)000000000" | jq '.data.result | length'
   ```

## Backout Plan

If issues arise, restore the standalone container:

```bash
# Stop compose container
docker compose stop loki

# Restore standalone container
docker run -d \
  --name loki \
  --restart unless-stopped \
  -p 3100:3100 \
  -v /opt/loki/config/loki-config.yaml:/etc/loki/local-config.yaml:ro \
  -v /opt/loki/data:/loki \
  grafana/loki:3.0.0 \
  -config.file=/etc/loki/local-config.yaml

# Verify
curl http://localhost:3100/ready
```

## Files Made Redundant

After successful migration, these can be archived/removed:
- `/opt/loki/config/loki-config.yaml` - using `./conf/loki-config.yaml` instead
- `plans/reference/loki-live-setup.md` - standalone setup instructions

## Future Phases

- **Phase 2**: Migrate iznik-batch (currently on separate server)
- **Phase 3**: Migrate incoming mail (not yet designed)

## Testing

### Local Dev
Loki will start automatically with `docker compose up`. Verify with:
```bash
curl http://loki.localhost/ready
```

### CircleCI
Loki is needed for tests that verify logging. The service starts automatically.
