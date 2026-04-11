# Frontend Server Design

Retire the existing bare-metal image/tile/geocode server and replace it with a new Katapult server running FreegleDocker's `frontend` profile.

## Background

The current production setup for latency-sensitive non-API services is spread across a single server running a mix of bare-metal processes and standalone Docker containers:

- **TuSD** (bare metal) — resumable image uploads, writes to NFS share (`/images/`, 768GB used / 5TB)
- **Delivery nginx** — caches transformed images, proxies cache misses to **wsrv.nl** (external free service), which fetches originals back from tusd over the public internet
- **Tile server** (`overv/openstreetmap-tile-server` Docker container) — renderd + Apache + mod_tile, 55K requests/day
- **Photon geocoder** (bare metal Java) — `photon-0.5.0.jar` on port 2322, fronted by nginx, ~3GB RAM, 13K requests/day
- **ORS** (Docker container) — unused, no traffic, to be dropped

Problems:
- Dependency on wsrv.nl (external free service with quotas)
- Mix of bare metal and containerised services
- Co-located with batch work (wrong server tier)
- Server needs replacing with a clean install

## Architecture

### Service Tier Model

| Server | Profile | Services |
|--------|---------|----------|
| **Batch (bulk3)** | `batch` | batch, mjml, redis, spamassassin, rspamd |
| **Frontend (new)** | `frontend` | frontend-nginx, delivery, tusd, photon, tile-server |
| **API servers** | *(bare metal, no Docker)* | apiv1, apiv2, co-located with database |

### Profile Strategy

```
Profile       Local Dev   CircleCI   Yesterday   Batch (bulk3)   Frontend (new)
────────────────────────────────────────────────────────────────────────────────
(default)     ✓           ✓          ✓           ✓               -
frontend      -           -          -           -               ✓
batch         ✓           ✓          -           ✓               -
ci            -           ✓          -           -               -
dev           ✓           -          ✓           -               -
infra-db      ✓           ✓          ✓           -               -
```

The frontend server runs **only** the `frontend` profile — no default services (no redis, beanstalkd, etc.). It is a pure proxy/cache/serve tier with no application logic.

## Request Flow

```
applb (TLS termination, 185.199.221.13)
  │
  ▼ HTTP
frontend-nginx :80
  │
  ├── delivery.ilovefreegle.org ──▶ [40GB disk cache, 30d]
  │     cache miss ──▶ delivery (weserv) :80 ──▶ tusd :8080 ──▶ /images/ (NFS)
  │
  ├── uploads.ilovefreegle.org ──▶ tusd :8080 ──▶ /images/ (NFS)
  │
  ├── tiles.ilovefreegle.org ──▶ [tile cache, configurable size/TTL]
  │     cache miss ──▶ tile-server :80
  │
  └── geocode.ilovefreegle.org ──▶ photon :2322
```

- **applb** handles TLS termination and forwards HTTP to the frontend server
- **frontend-nginx** routes by hostname, with disk caching for delivery and tiles
- **weserv** fetches originals from tusd over the internal Docker network (no external round-trip, eliminates wsrv.nl dependency)
- **tusd** reads/writes to NFS mount

## Services

### Docker Compose Profile: `frontend`

| Service | Image | Role |
|---------|-------|------|
| `frontend-nginx` | `nginx:alpine` | Reverse proxy + disk caching |
| `delivery` | `ghcr.io/weserv/images:5.x` | Image transforms (libvips) |
| `tusd` | `tusproject/tusd:latest` | Resumable image uploads |
| `photon` | `rtuszik/photon-docker` | Geocoding API |
| `tile-server` | `overv/openstreetmap-tile-server` | Raster map tiles |

The existing `delivery` and `tusd` services get the `frontend` profile added alongside their current default profile, so they run in both local dev and production frontend.

### New Service Definitions

```yaml
frontend-nginx:
  image: nginx:alpine
  profiles: [frontend]
  ports:
    - "80:80"
  volumes:
    - ./frontend-nginx.conf:/etc/nginx/nginx.conf:ro
    - delivery-cache:/var/cache/nginx/delivery
    - tile-cache:/var/cache/nginx/tiles
  depends_on:
    - delivery
    - tusd
    - photon
    - tile-server

photon:
  image: rtuszik/photon-docker:latest
  profiles: [frontend]
  volumes:
    - photon-data:/photon/data
  environment:
    - UPDATE_STRATEGY=DISABLED

tile-server:
  image: overv/openstreetmap-tile-server
  profiles: [frontend]
  volumes:
    - osm-data:/data/database/
    - osm-tiles:/data/tiles/
  shm_size: 192m
```

### nginx Configuration

`frontend-nginx.conf` with four server blocks and two separate cache zones:

```
proxy_cache_path /var/cache/nginx/delivery keys_zone=delivery:10m max_size=40g inactive=30d;
proxy_cache_path /var/cache/nginx/tiles keys_zone=tiles:10m max_size=10g inactive=30d;
```

Separate caches because delivery (large variable-size images, 40GB) and tiles (predictable 256x256 PNGs) have different eviction characteristics.

**Important**: The delivery cache key configuration must match the existing live server's nginx config exactly, so that the migrated cache files are recognised. This requires reviewing the live nginx configs before writing the final `frontend-nginx.conf`.

## Data and Volumes

### Migrated from old server

| Volume | Contents | Size | Source |
|--------|----------|------|--------|
| `osm-data` | PostGIS tile database | 56GB | Docker volume export |
| `osm-tiles` | Rendered tile cache | 43GB | Docker volume export |
| `photon-data` | Geocoding index | 6.3GB | `/var/www/photon/photon_data/` |
| `delivery-cache` | nginx image transform cache | 40GB | `/wsrv_cache` |

Total transfer: ~145GB. All data migrated so nothing starts cold.

### NFS mount

TuSD storage via bind mount:
```yaml
volumes:
  - /mnt/nfs/images:/srv/tusd-data
```

The NFS share is on Katapult's network, same provider as the new server. No special connectivity requirements.

### Cache characteristics

| Cache | Max size | Retention | Hit rate | Requests/day |
|-------|----------|-----------|----------|--------------|
| Delivery | 40GB | 30d inactive | ~92% | ~1M |
| Tiles | configurable | configurable | TBD | ~55K |

Delivery cache warms quickly due to `messages_spatial` concentrating requests on active posts, but pre-warming from the copied cache eliminates the transition period entirely.

## Migration Plan

### Phase 1: Provision and transfer
- Provision new server on Katapult
- Mount NFS share at `/mnt/nfs/images`
- Clone FreegleDocker repo, configure `.env` with `COMPOSE_PROFILES=frontend`
- Transfer data from old server (~145GB total)

### Phase 2: Validate
- `docker compose --profile frontend up -d`
- Test each service directly (bypass applb) using Host headers
- Verify delivery serves cached images without hitting weserv
- Verify tusd serves existing images from NFS
- Verify tiles render from copied cache/DB
- Verify Photon responds to geocode queries

### Phase 3: Cutover
- Update applb backend targets for all four domains to point at new server
- Monitor for errors
- Keep old server running for rollback (a few days)
- Once confident, decommission old server and drop ORS

Cutover is instant from the user's perspective — applb config change, no DNS propagation.

## What Changes in Local Dev

Nothing. Local dev continues to use traefik for routing to delivery/tusd (default profile). The `frontend-nginx`, `photon`, and `tile-server` services only start with `--profile frontend`. Tiles and geocoding continue to point at the live servers via env vars (`OSM_TILE`, `GEOCODE`).

## Deferred

- Review live nginx configs for cache key compatibility (separate session on the live server)
- Tile cache sizing and TTL (determine after reviewing live traffic patterns)
- Photon update strategy (manual updates for now, can enable auto-update later)
