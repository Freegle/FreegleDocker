# Modular Docker Compose Design

```
SERVICES BY ENVIRONMENT
=======================

LOCAL DEVELOPMENT                    CIRCLECI
--profile dev --profile batch        --profile ci

+---------------------------+        +---------------------------+
| DEV TOOLS                 |        | TESTING                   |
| phpmyadmin   host-scripts |        | apiv1-phpunit  playwright |
| ai-support-helper         |        +---------------------------+
+---------------------------+        | APPLICATIONS              |
| BATCH PROCESSING          |        | freegle-prod   apiv1      |
| batch                     |        | modtools-prod  apiv2      |
+---------------------------+        | status                    |
| APPLICATIONS              |        +---------------------------+
| freegle-dev    apiv1      |        | INFRASTRUCTURE            |
| modtools-dev   apiv2      |        | traefik      mailpit      |
| freegle-prod   status     |        | redis        beanstalkd   |
| modtools-prod             |        | percona      postgres     |
+---------------------------+        | spamassassin rspamd       |
| INFRASTRUCTURE            |        | loki         mjml         |
| traefik      mailpit      |        +---------------------------+
| redis        beanstalkd   |
| percona      postgres     |
| spamassassin rspamd       |
| loki         mjml         |
+---------------------------+


LIVE API SERVERS                     LIVE BACKGROUND SERVER
(external Nginx, external DB)        --profile batch (external DB)

+---------------------------+        +---------------------------+
| APPLICATIONS              |        | BATCH PROCESSING          |
| apiv1        apiv2        |        | batch                     |
| status                    |        +---------------------------+
+---------------------------+        | INFRASTRUCTURE            |
| INFRASTRUCTURE            |        | redis        mjml         |
| redis        beanstalkd   |        | spamassassin rspamd       |
| loki                      |        +---------------------------+
| delivery     tusd         |
+---------------------------+        Notes:
                                     - Uses external MySQL
Notes:                               - Sends email via smarthost
- External Nginx (no traefik)        - MJML for email templates
- External MySQL database            - Redis for worker pool queues
- No mailpit (real SMTP)             - Spam checking (inbound+outbound)
- No dev containers
- No spam/mjml (background only)

IMPORTANT: Freegle and ModTools frontends are deployed via NETLIFY,
not Docker containers. The freegle-prod and modtools-prod containers
are for local development and CI testing ONLY.


PROFILE QUICK REFERENCE
=======================
Profile     Services Added
-------     --------------
(default)   redis, beanstalkd, percona, postgres, spamassassin, rspamd,
            loki, mjml, delivery, tusd, apiv1, apiv2, freegle-prod,
            modtools-prod, status

dev         traefik, mailpit, freegle-dev, modtools-dev, phpmyadmin,
            ai-support-helper, host-scripts

ci          traefik, mailpit, apiv1-phpunit, playwright

dev-live    freegle-dev-live, modtools-dev-live (PRODUCTION APIs!)

batch       batch

mcp         mcp-query-sanitizer, mcp-interface, mcp-pseudonymizer
```

This document outlines the design for restructuring docker-compose.yml into modular files with proper profile and volume management.

## Service Classification

### Complete Service Inventory

| Service | Tier | Web Interface? | Profile | Status Category |
|---------|------|----------------|---------|-----------------|
| **Online (User-Facing)** |
| freegle-prod-local | Online | Yes | (default) | freegle |
| modtools-prod-local | Online | Yes | (default) | freegle |
| apiv1 | Online | Yes (API) | (default) | backend |
| apiv2 | Online | Yes (API) | (default) | backend |
| delivery | Online | Yes (images) | (default) | infra |
| status | Online | Yes | (default) | infra |
| tusd | Online | Yes (uploads) | (default) | infra |
| reverse-proxy (traefik) | Online | Yes (dashboard) | (default) | infra |
| **Background (No Web UI)** |
| batch | Background | No | batch | backend |
| mjml | Background | No | (default) | infra |
| loki | Background | No (push-only) | (default) | infra |
| **Infrastructure** |
| percona | Infra | No | infra-db | infra |
| postgres | Infra | No | infra-db | infra |
| redis | Infra | No | (default) | infra |
| beanstalkd | Infra | No | (default) | infra |
| mailpit | Infra | Yes | (default) | infra |
| spamassassin-app | Infra | No | (default) | infra |
| rspamd | Infra | Yes (web UI) | (default) | infra |
| **Development Only** |
| freegle-dev-local | Dev | Yes | dev | dev |
| freegle-dev-live | Dev | Yes | dev-live | dev |
| modtools-dev-local | Dev | Yes | dev | dev |
| modtools-dev-live | Dev | Yes | dev-live | dev |
| phpmyadmin | Dev | Yes | dev | dev |
| ai-support-helper | Dev | Yes | dev | dev |
| host-scripts | Dev | No | dev | - |
| **Testing** |
| apiv1-phpunit | Test | No | ci | backend |
| playwright | Test | No | ci | - |
| **MCP Tools** |
| mcp-query-sanitizer | Tool | Yes | mcp | infra |
| mcp-interface | Tool | No | mcp | - |
| mcp-pseudonymizer | Tool | No | mcp | - |

**Notes:**
- Grafana REMOVED - MCP tools query Loki directly
- Loki is default (not optional) - needed by CI tests
- Batch shares Redis with other containers
- Traefik not used in production (external Nginx)
- Monitoring (Loki) included automatically

## Profile Strategy

```
Profile       Local Dev   CircleCI   Yesterday   Production (bulk3)   Production (front)
──────────────────────────────────────────────────────────────────────────────────────────
(default)     ✓           ✓          ✓           ✓                    ✓
ci            -           ✓          -           -                    -
dev           ✓           -          ✓           -                    -
dev-live      Manual      -          -           -                    -
infra-db      ✓           ✓          ✓           -                    -
batch         ✓           ✓          -           ✓                    -
mcp           ✓           -          -           -                    -
```

### Environment Descriptions

| Environment | Purpose | Override File | Key Differences |
|-------------|---------|---------------|-----------------|
| **Local Dev** | Developer workstations | (none) | All profiles available, full hot-reload |
| **CircleCI** | Automated testing | (none) | ci + infra-db profiles, prod containers |
| **Yesterday** | Backup/restore testing | docker-compose.override.yesterday.yml | Dev containers only, external image delivery |
| **Production (bulk3)** | Batch processing | docker-compose.batch.yml | batch profile only, external MySQL |
| **Production (API)** | API servers | TBD | apiv1, apiv2, delivery, tusd - external Nginx |
| **Netlify** | Freegle/ModTools frontends | N/A | Static deployment - NOT Docker |

### Profile Assignments

```yaml
# Default (no profile) - Always runs everywhere
- reverse-proxy      # Routing (but may be Nginx in prod)
- redis              # Caching (or external in some deployments)
- beanstalkd         # Job queue

# Profile: ci - CircleCI testing
- apiv1-phpunit      # Isolated test container
- playwright         # E2E tests

# Profile: dev - Local development only
- freegle-dev-local
- modtools-dev-local
- phpmyadmin
- host-scripts       # File sync for dev
- ai-support-helper

# Profile: dev-live - Manual start only (connects to PRODUCTION APIs!)
- freegle-dev-live
- modtools-dev-live

# Profile: infra-db - Local databases (external in production)
- percona
- postgres

# Profile: monitoring - Can deploy separately
- loki
- grafana            # TODO: Consider removing - MCP tools query Loki directly

# Profile: batch - Background processing (deploy to bulk3)
- batch
- mjml

# Profile: mcp - Privacy-preserving log tools
- mcp-query-sanitizer
- mcp-interface
- mcp-pseudonymizer
```

## Volume Strategy

### Critical Data (external: true in production)

These volumes contain data that **must survive** `docker system prune`:

```yaml
volumes:
  freegle_db:
    # Production: external: true (managed outside Docker)
    # Dev/CI: managed by Docker Compose

  mail_spool:
    # Contains unsent emails - CRITICAL
    # In batch deployment, this is the spool directory
```

### Rebuildable Data (driver: local)

These can be recreated from source:

```yaml
volumes:
  geoip_data:        # Re-downloaded on container start
  rspamd_data:       # Rebuilt from rules
  loki-data:         # Logs can be re-ingested (Alloy pushes)
  grafana-data:      # Dashboards provisioned from files
```

### Volume Documentation

Each volume should be tagged with:
```yaml
volumes:
  example_volume:
    labels:
      freegle.backup: "critical"     # or "important" or "rebuildable"
      freegle.description: "User database - do not delete"
```

## File Structure

```
docker-compose.yml              # Main orchestrator (include directives only)
compose/
├── infra-core.yml             # redis, beanstalkd, mailpit, spamassassin, rspamd
├── infra-db.yml               # percona, postgres (profile: infra-db)
├── infra-network.yml          # traefik, delivery, tusd
├── backend-api.yml            # apiv1, apiv2, apiv1-phpunit
├── apps-online.yml            # freegle-prod, modtools-prod, status
├── apps-dev.yml               # freegle-dev-*, modtools-dev-* (profile: dev)
├── batch.yml                  # batch, mjml (profile: batch)
├── monitoring.yml             # loki, grafana (profile: monitoring)
├── mcp.yml                    # MCP tools (profile: mcp)
└── tools.yml                  # phpmyadmin, host-scripts, ai-support-helper
```

## Deployment Scenarios

### Local Development
```bash
docker-compose --profile dev --profile infra-db --profile monitoring up -d
```

### CircleCI Testing
```bash
docker-compose --profile ci --profile infra-db up -d
# Builds: apiv1 apiv1-phpunit apiv2 freegle-prod-local modtools-prod-local playwright status batch
```

### Production: bulk3 (Batch Processing)
```bash
# Uses compose/batch.yml directly or:
docker-compose --profile batch up -d
# External: percona (connects to existing MySQL)
# Containers: batch, mjml, redis
```

### Production: API Servers
```bash
docker-compose up -d
# External: percona, redis
# Containers: apiv1, apiv2, delivery, tusd, status
```

**Note**: Freegle and ModTools frontends are deployed via **Netlify** (JAMstack architecture).
The freegle-prod and modtools-prod Docker containers are for local dev/CI testing only.

## Migration Phases

### Phase 1: bulk3 Deployment (Current - PR #18)
- Deploy `iznik-batch` + MJML + Redis to bulk3
- Use existing `docker-compose.batch.yml`
- Smart host email relay

### Phase 2: Monitoring Consolidation
- Move Loki to bulk3 (receives logs via Alloy push - no latency impact)
- Consider removing Grafana (MCP tools provide log access)

### Phase 3: Evaluate Other Services
Current services on existing infrastructure:
- OpenRouteService (routing API) - user-facing, keep separate
- MediaWiki + MySQL - user-facing, keep separate
- Tile server - user-facing, keep separate
- Loki - background, candidate for bulk3

### Phase 4: Front-Facing Separation
- Clear separation of online services from batch
- Dedicated servers for user-facing latency-sensitive services

## Override File Compatibility

The modular structure must work with existing override files:
- `docker-compose.override.yml` (gitignored, local customizations)
- `docker-compose.override.yesterday.yml` (template for yesterday server)
- `yesterday/docker-compose.override.yml` (yesterday-specific)

Override files can reference any service defined in any included file.

## CircleCI Integration

Update `.circleci/orb/freegle-tests.yml` to use profiles:

```yaml
# Build only CI-required containers
docker-compose --profile ci --profile infra-db build \
  apiv1 apiv1-phpunit apiv2 \
  freegle-prod-local modtools-prod-local \
  playwright status batch

# Start services
docker-compose --profile ci --profile infra-db up -d
```

## Questions to Resolve

1. **Grafana**: Remove entirely since MCP tools query Loki directly?
2. **Redis location**: Should batch have its own Redis or share with main infrastructure?
3. **Traefik in production**: Use Docker Traefik or external Nginx?
4. **Profile defaults**: Should `--profile batch` include monitoring automatically?
