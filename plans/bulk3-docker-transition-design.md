# Bulk3 Docker Compose Transition Design

This document reviews the modular docker-compose design with specific reference to deploying batch processing on bulk3 (this live server), addressing email routing, log collection, and container management.

---

## Server Specifications (bulk3.ilovefreegle.org)

Captured: 2026-01-16

### Hardware

| Resource | Specification |
|----------|---------------|
| **CPU** | AMD EPYC 9354 32-Core @ 2 vCPUs |
| **RAM** | 5.7 GB total, ~4.1 GB available |
| **Swap** | 10 GB (1.3 GB used) |
| **Disk** | 197 GB total, 93 GB free (51% used) |
| **Network** | Dual-homed: public (ens10) + private 10.220.0.0/22 (ens11) |

### Software

| Component | Version |
|-----------|---------|
| **OS** | Ubuntu 22.04.5 LTS (Jammy) |
| **Docker** | 29.1.4 |
| **PHP** | 8.1.34 (cli), 8.5 also available |
| **Kernel** | 5.15.0-161-generic |

### Current Load

- **Uptime**: 72 days
- **Load average**: ~2-3 (on 2 vCPUs = moderately loaded)
- **Batch processes**: ~12 PHP processes running concurrently
- **Docker**: Idle (no containers running, 1 GB images cached)

### Capacity Considerations

- **CPU-bound**: With only 2 vCPUs and load ~2-3, the server is near CPU capacity during peak batch processing
- **Memory-comfortable**: 4 GB available is adequate for containerized batch workloads
- **Disk-adequate**: 93 GB free is sufficient, but monitor Docker image/volume growth
- **Not Kubernetes-ready**: 2 vCPUs and 6 GB RAM is below minimum for a single-node K8s cluster (recommend 4+ vCPUs, 8+ GB RAM)

### Kubernetes Assessment

**Not recommended for bulk3** due to:
1. Insufficient resources for K8s control plane overhead
2. Single-server deployment (K8s benefits come from multi-node orchestration)
3. Docker Compose is simpler and sufficient for single-server batch workloads
4. No HA requirement - batch can tolerate brief outages

**When to consider Kubernetes**:
- Multiple batch servers needing coordination
- Auto-scaling based on queue depth
- Multi-region deployment
- If bulk3 is upgraded to 8+ vCPUs, 16+ GB RAM

---

## Current System State (bulk3)

### What's Running Natively

| Component | Location | Status |
|-----------|----------|--------|
| **iznik-batch** | /var/www/iznik-batch | Running via cron + daemon |
| **Laravel scheduler** | cron: `php artisan schedule:run` | Every minute |
| **Mail spool daemon** | nohup process | Running continuously |
| **PHP spool scripts** | /var/www/iznik/scripts/cron | Multiple spool_* processes |
| **Alloy** | systemd service | Running, but failing to connect to Loki |
| **Postfix** | System service | Relaying to bulk2-internal:25 |
| **Docker** | Installed | No containers running |

### External Dependencies

| Service | Host | Port | Notes |
|---------|------|------|-------|
| **MySQL cluster** | db1/db2/db3-internal | 3306/3307 | Via 10.220.0.0/22 network |
| **Loki** | docker-internal (10.220.0.103) | 3100 | Working (remote server) |
| **Email smarthost** | bulk2-internal | 25 | Postfix relays here |

### Network Configuration

- **Public IP**: 185.53.57.149 (ens10)
- **Private IP**: 10.220.0.90 (ens11) - bulk3-internal
- **Docker bridge**: 172.17.0.0/16 (docker0) - currently down
- **Internal network**: 10.220.0.0/22 - can reach all *-internal hosts

### Current Issues

1. **No log rotation**: /var/log/freegle/ growing unbounded (email.log: 63MB)
2. ~~**Hostname mismatch**: Alloy config said "live1.ilovefreegle.org"~~ - **FIXED**: Now "bulk3.ilovefreegle.org"

---

## Design Review: Modular Docker Compose

### Profile Strategy Assessment

The design document proposes these profiles for bulk3:

```
Production (bulk3) - batch profile
├── batch container
├── redis (for worker pools)
├── mjml (for email templates)
└── (optional) spamassassin/rspamd for outbound checks
```

**Assessment**: This is correct. Bulk3 should run ONLY batch-related containers, not the full stack.

### What's Missing from the Design

1. **Network configuration for internal hosts**: Docker containers need access to 10.220.0.0/22 network
2. **Alloy integration**: How do containerized logs reach remote Loki?
3. **Log rotation strategy**: Managing container and volume log growth
4. **Postfix/email relay**: Containers need SMTP access to smarthost

---

## Detailed Design: Email Routing

### Current Flow
```
Laravel/PHP → localhost:25 (Postfix) → bulk2-internal:25 → Internet
```

### Proposed Docker Flow

**Option A: Use Host Network** (Simplest)
```yaml
batch:
  network_mode: "host"
  environment:
    - MAIL_HOST=127.0.0.1
    - MAIL_PORT=25
```
- Pros: Works immediately, uses existing Postfix
- Cons: No network isolation, port conflicts possible

**Option B: Direct to Smarthost** (Recommended)
```yaml
batch:
  networks:
    - batch-internal
  extra_hosts:
    - "bulk2-internal:10.220.0.217"
  environment:
    - MAIL_HOST=bulk2-internal
    - MAIL_PORT=25
```
- Pros: Clean isolation, no Postfix dependency
- Cons: Need to ensure Docker network can route to 10.220.0.0/22

**Option C: Add Postfix Container**
```yaml
postfix:
  image: boky/postfix
  environment:
    - RELAYHOST=bulk2-internal:25
```
- Pros: Full control, can add DKIM signing in container
- Cons: Additional complexity, another container to manage

### Recommendation: Option B

Docker's default bridge can route to the internal network because:
- The host has direct access to 10.220.0.0/22 via ens11
- Docker uses NAT, so outbound traffic will route correctly

Test with:
```bash
docker run --rm alpine ping -c1 10.220.0.217
```

---

## Detailed Design: Log Collection

### Challenge

Alloy currently runs as a systemd service and reads:
1. `/var/log/freegle/*.log` - JSON logs from PHP
2. `/var/www/iznik-batch/storage/logs/*.log` - Laravel logs

When iznik-batch runs in Docker, its logs will be:
1. Inside the container (lost on rebuild)
2. In a Docker volume (accessible but not at the same path)
3. Written to stdout (captured by Docker daemon)

### Option A: Mount Host Paths (Recommended)

```yaml
batch:
  volumes:
    # Mount host log directory into container
    - /var/log/freegle:/var/log/freegle
    - /var/www/iznik-batch-docker/storage/logs:/app/storage/logs
```

Keep Alloy as systemd service, update config:
```hcl
local.file_match "laravel_logs" {
  path_targets = [{
    __path__ = "/var/www/iznik-batch-docker/storage/logs/*.log",
  }]
}
```

- Pros: Alloy unchanged, host logrotate works, simple
- Cons: Tight coupling between container and host paths

### Option B: Alloy Sidecar Container

```yaml
alloy:
  image: grafana/alloy:latest
  volumes:
    - batch-logs:/logs:ro
    - ./alloy-config.alloy:/etc/alloy/config.alloy:ro
  environment:
    - LOKI_URL=http://docker-internal:3100
```

- Pros: Self-contained, no host dependencies
- Cons: Still need to route to remote Loki, more containers

### Option C: Docker Log Driver

Configure Docker to send logs directly to Loki:
```json
// /etc/docker/daemon.json
{
  "log-driver": "loki",
  "log-opts": {
    "loki-url": "http://10.220.0.103:3100/loki/api/v1/push",
    "loki-external-labels": "hostname=bulk3"
  }
}
```

- Pros: Zero in-container configuration
- Cons: Global setting, affects ALL containers, limited label extraction

### Recommendation: Option A + Fix Remote Loki

1. Keep Alloy as systemd service
2. Mount log volumes to host paths
3. Fix Loki connectivity (separate issue - it's refusing connections)
4. Add proper logrotate config

---

## Detailed Design: Log Rotation & Container Growth

### Problem Areas

1. **Application logs in volumes**: Grow indefinitely without rotation
2. **Docker container logs** (stdout/stderr): Grow indefinitely by default
3. **Spool directories**: Old sent emails accumulate
4. **Docker images/volumes**: Prune needed periodically

### Solution 1: Docker Daemon Log Rotation

Create `/etc/docker/daemon.json`:
```json
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "50m",
    "max-file": "5"
  }
}
```

Requires Docker daemon restart (service docker restart).

### Solution 2: Host Logrotate for Mounted Volumes

Create `/etc/logrotate.d/freegle-docker`:
```
/var/log/freegle/*.log
/var/www/iznik-batch-docker/storage/logs/*.log {
    daily
    rotate 7
    missingok
    notifempty
    compress
    delaycompress
    create 0644 www-data www-data
    copytruncate
}
```

Note: `copytruncate` is essential when the writing process can't be signaled to reopen files.

### Solution 3: Laravel Daily Log Channel

Configure Laravel to rotate its own logs:
```env
LOG_CHANNEL=daily
LOG_DAILY_DAYS=14
```

This creates `laravel-2026-01-16.log` files and auto-cleans old ones.

### Solution 4: Spool Cleanup

The batch container should have a scheduled job to clean old spool files:
```php
// Already exists: storage/spool/mail/sent/ cleaned after 7 days
$schedule->command('mail:spool:cleanup --days=7')->daily();
```

### Solution 5: Docker System Prune

Add to crontab:
```bash
# Weekly Docker cleanup (removes unused images, volumes, networks)
0 3 * * 0 docker system prune -f --volumes >> /var/log/docker-prune.log 2>&1
```

**Warning**: `--volumes` removes ALL unused volumes. Only use if all important data is in named volumes or bind mounts.

---

## Detailed Design: Database Access

### Current iznik-batch .env
```env
DB_HOST=127.0.0.1
DB_PORT=3306
```

This uses a local ProxySQL or direct connection. In Docker:

### Option A: Use Host Network (matches email option)
```yaml
batch:
  network_mode: "host"
```
Same pros/cons as for email.

### Option B: Direct to DB Cluster
```yaml
batch:
  extra_hosts:
    - "db1-internal:10.220.0.22"
    - "db2-internal:10.220.0.150"
    - "db3-internal:10.220.0.47"
  environment:
    - DB_HOST=db2-internal  # Or use comma-separated for failover
```

### Recommendation: Option B with extra_hosts

Match the existing iznik.conf pattern:
```env
DB_HOST=db2-internal
# Or for read replicas: db2-internal,db1-internal,db3-internal
```

---

## Transition Plan

### Phase 1: Preparation (No Risk)

1. **Create Docker daemon.json** with log rotation
2. **Create logrotate config** for /var/log/freegle/
3. ~~**Fix Alloy config** - update hostname from "live1" to "bulk3"~~ - **DONE**
4. **Test network routing**: `docker run --rm alpine ping db2-internal`

### Phase 2: Parallel Testing (Low Risk)

1. **Create dedicated directory**: `/var/www/iznik-batch-docker/`
2. **Create .env.docker** with:
   - Email types DISABLED (empty FREEGLE_MAIL_ENABLED_TYPES)
   - Database pointing to read replica
   - Log paths to host-mounted volumes
3. **Start containers** with `--profile batch`
4. **Verify**:
   - Containers can reach database
   - Logs appear in mounted volumes
   - Alloy picks up logs (if Loki working)
   - No actual emails sent

### Phase 3: Controlled Switchover (Medium Risk)

1. **Stop native iznik-batch** processes:
   ```bash
   # Comment out Laravel scheduler cron
   # Kill mail spool daemon
   pkill -f "artisan mail:spool:process"
   ```
2. **Enable email sending** in Docker .env
3. **Start Docker scheduler and mail-spooler**
4. **Monitor**:
   - Email queue processing
   - Error rates in logs
   - Loki metrics (if working)

### Phase 4: Cleanup (Post-Verification)

1. **Remove native cron entries** for iznik-batch
2. **Archive native installation** (don't delete yet)
3. **Document new operational procedures**

---

## Open Questions

### 1. Where should Loki run?

**Resolved**: Loki runs on docker-internal (10.220.0.103) and is working. This is a centralized logging server that receives logs from multiple Freegle servers via Alloy.

### 2. Should Alloy run in Docker or as systemd?

- **Systemd** (current): Works, simple, but config is host-specific
- **Docker**: More portable, but needs volume mounts for log access

Recommendation: Keep as systemd for now. It's working (minus the Loki connection) and simpler.

### 3. What about Redis persistence?

The docker-compose.batch.yml uses a `redis-data` volume. For batch processing semaphores, this is LOW priority - if Redis restarts, workers just re-acquire permits.

### 4. Network mode: bridge vs host?

| Aspect | Bridge (default) | Host |
|--------|-----------------|------|
| Isolation | Good | None |
| Port conflicts | None | Possible |
| Internal network access | Needs extra_hosts | Automatic |
| Complexity | Medium | Low |

Recommendation: Use bridge with extra_hosts for clarity and isolation.

---

## Updated docker-compose.batch.yml

Based on this analysis, the compose file needs these additions:

```yaml
services:
  batch:
    extra_hosts:
      # Database cluster
      - "db1-internal:10.220.0.22"
      - "db2-internal:10.220.0.150"
      - "db3-internal:10.220.0.47"
      # Email smarthost
      - "bulk2-internal:10.220.0.217"
      # Loki (if remote)
      - "docker-internal:10.220.0.103"
    volumes:
      # Mount logs to host for Alloy + logrotate
      - /var/log/freegle:/var/log/freegle
      - /var/www/iznik-batch-docker/storage/logs:/app/storage/logs
    environment:
      # Database
      - DB_HOST=db2-internal
      - DB_PORT=3306
      # Email via smarthost
      - MAIL_HOST=bulk2-internal
      - MAIL_PORT=25
      - MAIL_ENCRYPTION=null
      # Logging
      - LOG_CHANNEL=daily
      - LOKI_JSON_PATH=/var/log/freegle
```

---

## Production Docker Operations

### Security Updates & Package Upgrades

Docker containers are immutable - you can't `apt upgrade` inside a running container. Updates require rebuilding images.

#### Strategy 1: Base Image Updates (Recommended)

1. **Pin major versions, float patches** in Dockerfiles:
   ```dockerfile
   FROM php:8.3-fpm  # Gets 8.3.x security patches on rebuild
   FROM node:20-alpine  # Gets 20.x.y patches on rebuild
   ```

2. **Scheduled rebuilds** - Weekly cron to rebuild and redeploy:
   ```bash
   # /etc/cron.d/docker-rebuild
   0 4 * * 0 root cd /var/www/FreegleDocker && docker-compose --profile batch build --pull --no-cache && docker-compose --profile batch up -d
   ```
   The `--pull` fetches latest base images, `--no-cache` ensures fresh package installs.

3. **Monitor for CVEs** - Use tools like:
   - `docker scout` (Docker Desktop/Hub)
   - `trivy image freegle-batch:latest`
   - Dependabot for Dockerfile base image updates

#### Strategy 2: Watchtower (Automated Updates)

For images pulled from registries (not locally built):
```yaml
watchtower:
  image: containrrr/watchtower
  volumes:
    - /var/run/docker.sock:/var/run/docker.sock
  environment:
    - WATCHTOWER_CLEANUP=true
    - WATCHTOWER_SCHEDULE=0 0 4 * * 0  # Weekly at 4am Sunday
```

Not suitable for locally-built images like batch.

#### Strategy 3: CI/CD Triggered Rebuilds

When submodule updates trigger FreegleDocker CI:
1. CI builds fresh images with latest packages
2. Push to GHCR (GitHub Container Registry)
3. Production pulls and deploys

This is the current model for front-facing servers.

### Node.js Version Upgrades

For iznik-batch (Laravel), Node.js isn't critical. For Nuxt containers:

1. **Update Dockerfile** base image version
2. **Test in CI** - the existing pipeline catches incompatibilities
3. **Deploy** via the normal submodule update flow

### PHP Version Upgrades

1. Update `FROM php:X.Y-fpm` in Dockerfile
2. Run tests (PHPUnit catches compatibility issues)
3. Deploy

### Handling Container Drift

Over time, containers may diverge from their images:
- Temp files accumulate in tmpfs
- Process state builds up

**Solution**: Periodic restart policy
```yaml
batch:
  restart: unless-stopped
  # Add to scheduler:
  # 0 3 * * * docker-compose --profile batch restart
```

Or use Docker's built-in healthcheck + restart:
```yaml
healthcheck:
  test: ["CMD", "php", "artisan", "health:check"]
  interval: 1m
  timeout: 10s
  retries: 3
  start_period: 30s
```

### Image/Volume Bloat Prevention

```bash
# Weekly cleanup cron
0 3 * * 0 docker system prune -f >> /var/log/docker-prune.log 2>&1

# Monthly aggressive cleanup (removes unused volumes too)
0 4 1 * * docker system prune -af --volumes >> /var/log/docker-prune.log 2>&1
```

**Warning**: The aggressive cleanup removes ALL unused volumes. Ensure critical data is either:
- In named volumes referenced by running containers
- In bind mounts to host paths

### Rollback Strategy

Keep previous images available:
```bash
# Before deploying new version
docker tag freegle-batch:latest freegle-batch:previous

# To rollback
docker-compose --profile batch down
docker tag freegle-batch:previous freegle-batch:latest
docker-compose --profile batch up -d
```

Or use explicit version tags in CI builds.

---

## Summary

The modular docker-compose design is sound, but deploying to bulk3 requires:

1. **Network routing** via extra_hosts for internal services
2. **Log management** through host-mounted volumes + logrotate
3. **Docker daemon config** for container log rotation
4. **Loki investigation** - current remote endpoint failing
5. **Phased transition** with parallel running for safety

The key insight is that bulk3's Docker environment should be minimal (batch only) and integrate with existing host services (Alloy, Postfix) rather than trying to containerize everything.
