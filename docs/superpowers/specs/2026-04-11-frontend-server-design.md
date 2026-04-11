# Frontend Server Design

Retire the existing bare-metal image/tile/geocode servers and replace them with a new Katapult server running FreegleDocker's `frontend` profile.

## Background

The current production setup for latency-sensitive non-API services is spread across **two servers** running a mix of bare-metal processes and standalone Docker containers:

**bulk3** (this machine — tiles, geocoding, batch work):
- **Tile server** (`overv/openstreetmap-tile-server` Docker container, named `confident_curran`) — renderd + Apache + mod_tile, ~50K requests/day, fronted by nginx (`tiles.ilovefreegle.org`)
- **Photon geocoder** (bare metal Java) — `photon-0.5.0.jar` on port 2322, fronted by nginx (`geocode.ilovefreegle.org`), ~3GB RAM, ~11K real requests/day (was 1.9M/day before rate limiting — 99% was a single scraper bot)
- **ORS** (Docker container) — unused, zero traffic, 4GB RAM allocated, to be dropped
- Also runs batch containers (batch-prod, loki, mjml, redis, postfix, spamassassin, rspamd)

**app1-internal** (delivery, uploads, API):
- **TuSD** (bare metal) — resumable image uploads on port 8080 (no nginx proxy, applb routes directly), writes to NFS share (`/images/`, 768GB used / 5TB, mounted from `nfs2.nlc.storage.katapult.io`)
- **Delivery nginx** — caches transformed images at `/wsrv_cache` (41GB), proxies cache misses to **wsrv.nl** (external free service), which fetches originals back from tusd over the public internet. 92% cache hit rate, ~100K requests/day
- **images.ilovefreegle.org** — legacy PHP image serving (rewrites `/img_*.jpg` etc. to `api/image.php` via PHP-FPM on port 9000). Some delivery requests still reference these URLs
- No Docker installed — everything is bare metal (nginx, tusd, PHP-FPM, MySQL)
- Disk: 158GB total, 110GB used (74%), delivery cache is largest consumer

Problems:
- Dependency on wsrv.nl (external free service with quotas)
- Mix of bare metal and containerised services across two servers
- Tiles/geocoding co-located with batch work on bulk3 (wrong server tier)
- Geocoder was unprotected against abuse (rate limiting now added but needs to be in the new config)
- No Docker on app1-internal — migration will containerise delivery+tusd for the first time
- Servers need replacing with a clean install

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
  ├── geocode.ilovefreegle.org ──▶ [rate limit: 10r/s per IP, burst 20]
  │     ──▶ photon :2322
  │
  └── images.ilovefreegle.org ──▶ (legacy, see notes)
```

- **applb** handles TLS termination and forwards HTTP to the frontend server
- **frontend-nginx** routes by hostname, with disk caching for delivery and tiles
- **weserv** fetches originals from tusd over the internal Docker network (no external round-trip, eliminates wsrv.nl dependency)
- **tusd** reads/writes to NFS mount
- **geocode** rate-limited to prevent bot abuse (single IP was responsible for 99% of traffic pre-limiting)

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

`frontend-nginx.conf` with server blocks and cache zones:

```
proxy_cache_path /var/cache/nginx/delivery levels=1:2 keys_zone=delivery:100m max_size=40g inactive=30d use_temp_path=off;
proxy_cache_path /var/cache/nginx/tiles keys_zone=tiles:10m max_size=10g inactive=30d;
limit_req_zone $binary_remote_addr zone=geocode_limit:10m rate=10r/s;
```

Separate caches because delivery (large variable-size images, 40GB) and tiles (predictable 256x256 PNGs) have different eviction characteristics.

**Delivery cache key**: The live config on app1-internal uses nginx's **default cache key** (`$scheme$proxy_host$request_uri`) — no explicit `proxy_cache_key` is set. The live config also uses `levels=1:2`, `keys_zone` size of `100m`, and `use_temp_path=off`. The `frontend-nginx.conf` must replicate these settings exactly for the migrated 41GB cache to be recognised.

**Delivery URL patterns**: The live delivery logs show two distinct URL patterns that weserv must handle:
- Old format: `/?url=https://uploads.ilovefreegle.org:8080/{hash}/&w=200&h=200` (includes tusd port 8080)
- New format: `/?filename={hash}&we&w=768&h=768&output=webp&fit=cover&url=https://uploads.ilovefreegle.org/{hash}` (no port, with webp)

The weserv container's `url=` parameter points at `uploads.ilovefreegle.org` — internally, this must resolve to the tusd container, not go out to the public internet. Options: Docker network alias, or nginx rewrite in the delivery server block.

**Delivery redirect handling**: The live config includes `proxy_intercept_errors on` with a `@handle_redirect` location to follow 301/302/307 responses from wsrv.nl. The weserv Docker container may not need this (direct internal access), but it should be tested.

**Geocode rate limiting**: Required. The geocoder was getting 1.9M requests/day from a single scraper bot (IP `185.53.57.149`). Rate limit config: `limit_req zone=geocode_limit burst=20 nodelay` with `limit_req_status 429`.

**Tile server CORS**: The live config on bulk3 includes `Access-Control-Allow-Origin *` and other CORS headers. These must be replicated in the tiles server block.

## Data and Volumes

### Migrated from two source servers

**From bulk3** (tiles, geocoding):

| Volume | Contents | Size | Source |
|--------|----------|------|--------|
| `osm-data` | PostGIS tile database | 56GB | Docker volume `osm-data` (container `confident_curran`) |
| `osm-tiles` | Rendered tile cache | 43GB | Docker volume `osm-tiles` (container `confident_curran`) |
| `photon-data` | Geocoding index | 6.3GB | `/var/www/photon/photon_data/` (bare metal) |

**From app1-internal** (delivery, uploads):

| Volume | Contents | Size | Source |
|--------|----------|------|--------|
| `delivery-cache` | nginx image transform cache | 41GB | `/wsrv_cache` |

Total transfer: ~146GB from two servers. All data migrated so nothing starts cold.

### NFS mount

TuSD storage via bind mount:
```yaml
volumes:
  - /mnt/nfs/images:/srv/tusd-data
```

The NFS share is on Katapult's network (`nfs2.nlc.storage.katapult.io:/katapult/fsv_5ivInYUXp22oVueE`), currently mounted at `/images` on app1-internal. NFS v3, TCP, 1MB read/write size. Same provider as the new server — no special connectivity requirements.

**TuSD upload directory**: On app1-internal, tusd runs with `-upload-dir=images` from `/var/www/tusd/`, so images land in `/var/www/tusd/images/`. This is a local directory, **not** the NFS mount at `/images`. The relationship between these two paths needs clarifying during migration — likely the NFS mount is the canonical store and the local dir is a small working area (only 84KB).

### Cache characteristics

| Cache | Max size | Retention | Hit rate | Requests/day |
|-------|----------|-----------|----------|--------------|
| Delivery | 40GB | 30d inactive | 92% (41K HIT / 3.4K MISS per half-day) | ~100K |
| Tiles | configurable | configurable | not cached by nginx currently | ~50K |
| Geocode | n/a (proxy_cache on bulk3) | 10d | high (Photon responses cached by nginx) | ~11K (real) |

Delivery cache warms quickly due to `messages_spatial` concentrating requests on active posts, but pre-warming from the copied cache eliminates the transition period entirely.

## Migration Plan

### Phase 1: Provision and transfer
- Provision new server on Katapult
- Mount NFS share at `/mnt/nfs/images` (same Katapult NFS: `nfs2.nlc.storage.katapult.io`)
- Clone FreegleDocker repo, configure `.env` with `COMPOSE_PROFILES=frontend`
- Transfer data from **bulk3**: tile Docker volumes (~99GB), photon data (6.3GB)
- Transfer data from **app1-internal**: delivery cache (~41GB)

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

## Live Server Audit (2026-04-11)

Findings from inspecting bulk3 and app1-internal:

### Resolved from deferred items
- **Delivery cache key**: Uses nginx default (`$scheme$proxy_host$request_uri`), no explicit `proxy_cache_key`. Config uses `levels=1:2`, `keys_zone=wsrv_cache:100m`, `use_temp_path=off`. Replicate exactly.
- **Tile traffic**: ~50K requests/day. Currently no nginx caching layer on bulk3 — nginx just proxies to Apache/renderd on port 8080. Adding a cache layer in frontend-nginx is a new optimisation.
- **Geocode traffic**: ~11K real requests/day after filtering out bot. Rate limiting is essential — added to bulk3 nginx as interim fix.

### images.ilovefreegle.org (legacy)
Some delivery requests still reference `images.ilovefreegle.org` URLs (e.g. `url=https://images.ilovefreegle.org/img_44020105.jpg`). This is served by PHP-FPM on app1-internal with rewrite rules mapping `/img_{id}.jpg` to `api/image.php?id={id}`. This legacy path needs to either:
1. Continue running on app1-internal (it depends on PHP + database), or
2. Be redirected at the applb/DNS level

It cannot move to the frontend server (no PHP/database). This should be documented as an explicit exclusion.

### uploads.ilovefreegle.org port 8080
TuSD currently listens on port 8080 with no nginx proxy — applb routes directly. Old delivery URLs include `:8080` in the `url=` parameter. After migration, tusd is behind frontend-nginx on port 80. Either:
1. frontend-nginx also listens on 8080 for backwards compatibility, or
2. Old cached URLs with `:8080` are accepted (they'll age out of the delivery cache over 30 days)

### app1-internal post-migration
After the frontend server takes over delivery and uploads, app1-internal retains:
- API servers (apiv1, apiv2)
- Database (MySQL)
- images.ilovefreegle.org (legacy PHP)
- PHP-FPM

The delivery nginx config and `/wsrv_cache` can be removed from app1-internal after cutover, freeing ~41GB (bringing disk usage from 74% to ~48%).

## Deferred

- Photon update strategy (manual updates for now, can enable auto-update later)
- Determine whether ORS should be reprovisioned on the new server or permanently dropped
- Clarify tusd upload-dir vs NFS mount relationship on app1-internal before migration
