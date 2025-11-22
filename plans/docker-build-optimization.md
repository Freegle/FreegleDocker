# Docker Build Optimization Plan

## Current State

### Built Containers (our code)
| Container | Base Image | Build Time Bottlenecks |
|-----------|------------|------------------------|
| status | node:18-slim | Docker CLI install, npm install |
| apiv1 | ubuntu:22.04 | Large apt-get install, composer, git clone |
| apiv2 | golang:1.23 | go mod download, swagger generation |
| freegle-dev | node:22-slim | npm ci, playwright browsers |
| freegle-prod | node:22-slim | npm ci, playwright browsers, npm build |
| modtools-dev | node:22-slim | npm ci |
| modtools-prod | node:22-slim | npm ci, npm build |
| playwright | playwright base | npm install |
| yesterday/api | node:22-alpine | gcloud SDK, docker CLI, npm install |
| yesterday/2fa-gateway | node:22-alpine | npm install (very simple) |

### Pulled Containers (external)
- traefik:latest
- percona:8.0.43-34
- postgis/postgis:13-3.1
- phpmyadmin
- mailhog/mailhog
- schickling/beanstalkd
- tiredofit/spamassassin
- redis:6.2-alpine
- ghcr.io/weserv/images:5.x
- tusproject/tusd:latest

---

## Optimization Strategies

### 1. Shared Base Images (High Impact)

**Problem**: Multiple containers install the same dependencies separately.

**Solution**: Create shared base images pushed to a registry (Docker Hub or GitHub Container Registry).

#### Universal "Fat" Base

A single base image containing Node, Go, PHP, and all dependencies. Size is ~3-4GB but cached.

```dockerfile
# freegle-base (publish to ghcr.io/freegle/base:latest)
FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive \
    TZ='UTC'

# Copy retry script
COPY retry.sh /usr/local/bin/retry
RUN chmod +x /usr/local/bin/retry

# ============ COMMON TOOLS ============
RUN apt-get update && apt-get install -y \
    ca-certificates curl gnupg wget git vim \
    build-essential python3 \
    zip unzip jq netcat telnet \
    iputils-ping net-tools dnsutils \
    && rm -rf /var/lib/apt/lists/*

# ============ NODE.JS 22 ============
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && npm config set fetch-retries 5 \
    && npm config set fetch-retry-mintimeout 20000 \
    && npm config set fetch-retry-maxtimeout 120000

# ============ GO 1.23 ============
RUN curl -fsSL https://go.dev/dl/go1.23.0.linux-amd64.tar.gz | tar -C /usr/local -xzf -
ENV PATH="/usr/local/go/bin:${PATH}" \
    GOPATH="/go" \
    GOPROXY="https://proxy.golang.org,direct"

# ============ PHP 8.1 + WEB SERVER ============
# nginx: serves PHP API via php-fpm
# postfix: mail relay for sending emails (configured to use mailhog)
RUN apt-get update && apt-get install -y \
    php8.1-fpm php8.1-cli php8.1-mysql php8.1-pgsql php8.1-redis \
    php8.1-curl php8.1-zip php8.1-gd php8.1-mbstring php8.1-xml \
    php8.1-intl php8.1-xdebug php-mailparse \
    nginx postfix cron rsyslog \
    default-mysql-client postgresql-client \
    tesseract-ocr geoip-bin geoipupdate \
    && rm -rf /var/lib/apt/lists/*

# ============ PLAYWRIGHT DEPS ============
RUN apt-get update && apt-get install -y \
    xvfb dbus libglib2.0-0 libnss3 libnspr4 libatk1.0-0 \
    libatk-bridge2.0-0 libcups2 libxkbcommon0 libatspi2.0-0 \
    libxcomposite1 libxdamage1 libgbm1 libpango-1.0-0 \
    libcairo2 libasound2 \
    && mkdir -p /var/run/dbus \
    && rm -rf /var/lib/apt/lists/*

# ============ DOCKER CLI ============
RUN install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg \
    && chmod a+r /etc/apt/keyrings/docker.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu jammy stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && apt-get update && apt-get install -y docker-ce-cli docker-compose-plugin \
    && rm -rf /var/lib/apt/lists/*

# ============ GOOGLE CLOUD SDK ============
RUN curl -O https://dl.google.com/dl/cloudsdk/channels/rapid/downloads/google-cloud-cli-linux-x86_64.tar.gz \
    && tar -xf google-cloud-cli-linux-x86_64.tar.gz \
    && mv google-cloud-sdk /opt/ \
    && /opt/google-cloud-sdk/install.sh --quiet --usage-reporting=false --path-update=false \
    && rm google-cloud-cli-linux-x86_64.tar.gz
ENV PATH="/opt/google-cloud-sdk/bin:${PATH}"

# ============ CLAUDE CODE CLI ============
RUN npm install -g @anthropic-ai/claude-code

# ============ PLAYWRIGHT BROWSERS ============
RUN npx playwright install chromium && npx playwright install-deps

# ============ GO SWAGGER (for apiv2) ============
RUN go install github.com/go-swagger/go-swagger/cmd/swagger@v0.31.0
```

**Benefits**:
- ALL containers use the same cached base layer
- Single apt-get install cached (~5-10 min saved per container)
- Unused packages have negligible runtime cost (disk only)
- Dramatically faster rebuilds for ALL containers
- Simpler maintenance - one base to update

**Containers that would use this**: ALL built containers (status, apiv1, apiv2, freegle-dev, freegle-prod, modtools-dev, modtools-prod, playwright)

**Simplified child Dockerfiles** (with base handling all system deps):

```dockerfile
# apiv2/Dockerfile - Go API
FROM ghcr.io/freegle/base:latest
WORKDIR /app
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN ./generate-swagger.sh
CMD ["go", "run", "main.go"]
```

```dockerfile
# freegle-prod/Dockerfile - Nuxt production
FROM ghcr.io/freegle/base:latest
WORKDIR /app
COPY package*.json ./
RUN npm ci --legacy-peer-deps
COPY . .
CMD ["sh", "-c", "npm run build && npm run start"]
```

```dockerfile
# status/Dockerfile - Status monitor
FROM ghcr.io/freegle/base:latest
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY . .
CMD ["npm", "start"]
```

```dockerfile
# yesterday/api/Dockerfile - Yesterday API
FROM ghcr.io/freegle/base:latest
WORKDIR /app
COPY package*.json ./
RUN npm install --production
COPY server.js ./
CMD ["node", "server.js"]
```

**What moved to base** (no longer needed in child Dockerfiles):
- retry.sh script
- apt-get installs (all system packages)
- npm config for retries
- Playwright browser installation
- go-swagger installation
- Docker CLI
- gcloud SDK

### 2. Layer Ordering Optimization (Medium Impact)

**Current Problem**: Many Dockerfiles copy all source code before npm install, breaking cache.

**Best Practice** (already partially implemented):
```dockerfile
# 1. Copy only package files first
COPY package*.json ./

# 2. Install dependencies (cached unless package.json changes)
RUN npm ci

# 3. Copy source code last
COPY . .
```

**Files to audit**:
- [x] iznik-nuxt3/Dockerfile.prod - Good
- [ ] iznik-server/Dockerfile - Clones from git, harder to optimize
- [x] iznik-server-go/Dockerfile - Good (go.mod/go.sum first)
- [x] status/Dockerfile - Good

### 3. BuildKit Cache Mounts (Medium Impact)

**Still valuable with fat base**: The base image caches apt-get installs, but BuildKit cache mounts help with package managers that run in child Dockerfiles:

```dockerfile
# syntax=docker/dockerfile:1.4
FROM ghcr.io/freegle/base:latest

# npm cache persists even when package.json changes
RUN --mount=type=cache,target=/root/.npm \
    npm ci --legacy-peer-deps

# Go module cache persists even when go.mod changes
RUN --mount=type=cache,target=/go/pkg/mod \
    go mod download
```

**Benefits with fat base**:
- Base image = apt-get cached (biggest win)
- Cache mounts = npm/go downloads cached (incremental win)
- Combined = near-instant rebuilds when only source code changes

**Requirements**:
- Enable BuildKit: `DOCKER_BUILDKIT=1`
- Docker Compose already uses BuildKit by default

### 4. Multi-Stage Builds (Medium Impact)

For production containers, use multi-stage builds to reduce final image size:

```dockerfile
# Build stage
FROM freegle-node-base:22 AS builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# Production stage
FROM node:22-slim
WORKDIR /app
COPY --from=builder /app/.output ./.output
COPY --from=builder /app/node_modules ./node_modules
CMD ["node", ".output/server/index.mjs"]
```

**Applicable to**: freegle-prod, modtools-prod

### 5. CircleCI Caching Strategies (High Impact for CI)

#### Docker Layer Caching (DLC)
DLC **is available on the free plan** (including open source):
- Costs 200 credits per job run (~$0.12)
- Open source gets 400,000 credits/month - plenty for this
- Cached layers expire after 3 days without use
- 15 GiB storage limit per organization
- **Cache is shared across ALL jobs in the organization** - so FreegleDocker builds AND submodule PR builds share the same cache

Source: [CircleCI DLC Documentation](https://circleci.com/docs/guides/optimize/docker-layer-caching/)

```yaml
jobs:
  build:
    steps:
      - setup_remote_docker:
          docker_layer_caching: true  # Enable DLC - costs 200 credits/job
```

#### Push/Pull Base Images (Recommended with Fat Base)

Even better than DLC alone - pull the pre-built fat base image:

```yaml
jobs:
  build:
    steps:
      - run:
          name: Pull cached base images
          command: |
            docker pull ghcr.io/freegle/node-base:22 || true
            docker pull ghcr.io/freegle/apiv1-base:latest || true
      - run:
          name: Build with cache-from
          command: |
            docker build --cache-from ghcr.io/freegle/node-base:22 ...
```

### 6. apiv1 (PHP) Specific Optimizations

The apiv1 container now builds from the iznik-server submodule, so we can use COPY:

**Current** (inefficient):
```dockerfile
RUN git clone https://github.com/Freegle/iznik-server.git iznik
```

**Improved** (use COPY from submodule context):
```dockerfile
# Build context is ./iznik-server
COPY . /var/www/iznik
```

This eliminates the git clone during build and allows better layer caching - source code changes don't invalidate the apt-get/composer layers.

---

## Implementation Priority

### Phase 1: Quick Wins (1-2 hours)
1. Audit and fix layer ordering in all Dockerfiles
2. Enable BuildKit cache mounts in Dockerfiles

### Phase 2: Universal Base Image (4-8 hours)
1. Create `Dockerfile.base` in FreegleDocker repo
2. Set up GitHub Container Registry (ghcr.io) - free for public repos
3. Create GitHub Action to build/push base image weekly or on change
4. Test base image locally: `docker build -f Dockerfile.base -t freegle-base .`
5. Update ONE container (e.g., status) as proof of concept
6. Measure build time before/after
7. Roll out to remaining containers (apiv1, apiv2, freegle-*, modtools-*, playwright)

### Phase 3: CI Optimization (2-4 hours)
1. Enable Docker Layer Caching in CircleCI (available on free plan, 200 credits/job)
2. Update CircleCI to pull `ghcr.io/freegle/base:latest` before build
3. Add `--cache-from ghcr.io/freegle/base:latest` to build commands

### Phase 4: Advanced (Optional)
1. Multi-stage builds for production containers (smaller final images)
2. Investigate GitHub Actions cache for docker layers
3. Consider ARM64 variant if needed for M1/M2 Macs

---

## Estimated Time Savings

| Change | Current Time | After | Savings |
|--------|--------------|-------|---------|
| Shared base image | ~3-5 min/container | ~30s | 80% |
| BuildKit cache mounts | ~2 min npm | ~30s | 75% |
| Avoid full prune | Full rebuild | Cached | 90% |
| Multi-stage (prod) | N/A | Smaller images | Size only |

**Total potential savings**: 60-80% reduction in build time for cached builds.

---

## Risks and Considerations

1. **Base image maintenance**: Need to rebuild base when dependencies change
2. **Registry costs**: GitHub Container Registry is free for public repos
3. **Cache invalidation**: Some changes will still require full rebuilds
4. **Complexity**: More moving parts to manage

---

## Base Image Maintenance

### Updating the Base Image

The base image is automatically rebuilt monthly. Manual rebuild when:
- Urgent security updates are needed
- Node/Go/PHP versions need upgrading
- New system dependencies are required

### GitHub Action for Automatic Builds

Create `.github/workflows/build-base-image.yml`:

```yaml
name: Build Base Image

on:
  schedule:
    - cron: '0 0 1 * *'  # Monthly on 1st at midnight
  push:
    paths:
      - 'Dockerfile.base'
      - '.github/workflows/build-base-image.yml'
  workflow_dispatch:  # Manual trigger

jobs:
  build:
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write
    steps:
      - uses: actions/checkout@v4

      - name: Log in to GitHub Container Registry
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Build and push
        uses: docker/build-push-action@v5
        with:
          context: .
          file: Dockerfile.base
          push: true
          tags: ghcr.io/freegle/base:latest
```

### Manual Rebuild

```bash
# Build locally
docker build -f Dockerfile.base -t ghcr.io/freegle/base:latest .

# Test it works
docker run --rm ghcr.io/freegle/base:latest node --version
docker run --rm ghcr.io/freegle/base:latest go version
docker run --rm ghcr.io/freegle/base:latest php --version

# Push to registry
docker push ghcr.io/freegle/base:latest
```

### Always Use Latest

All child Dockerfiles should use `:latest` to get security updates automatically:

```dockerfile
FROM ghcr.io/freegle/base:latest
```

The monthly automated rebuild ensures the base stays current with security patches.

### Yesterday System

The yesterday/ containers use the fat base (gcloud SDK, Docker CLI already included):

```dockerfile
# yesterday/api or yesterday/2fa-gateway
FROM ghcr.io/freegle/base:latest
WORKDIR /app
COPY package*.json ./
RUN npm install --production
COPY server.js ./
CMD ["node", "server.js"]
```

---

## Documentation Updates Required

When implementing this plan, update the following:

### FreegleDocker/CLAUDE.md
- Add section about the fat base image (`ghcr.io/freegle/base:latest`)
- Document what's included in the base (Node, Go, PHP, tools)
- Explain that child Dockerfiles should be minimal
- Reference this plan for build optimization details

### FreegleDocker/README.md
- Add section on Docker build architecture
- Mention the shared base image approach
- Link to ghcr.io/freegle/base

### Submodule CLAUDE.md files
Update build instructions in:
- `iznik-server/CLAUDE.md` - Remove references to apt-get installs in Dockerfile
- `iznik-server-go/CLAUDE.md` - Note that go-swagger is in base image
- `iznik-nuxt3/CLAUDE.md` - Note that Playwright browsers are in base image

### .circleci/README.md
- Document DLC usage and credit costs
- Explain base image pull strategy

### New file: Dockerfile.base
- Add comments explaining each section
- Document version numbers for Node/Go/PHP

---

## Next Steps

1. [ ] Create Dockerfile.base in FreegleDocker repo
2. [ ] Set up ghcr.io authentication
3. [ ] Create GitHub Action for base image builds
4. [ ] Build and push initial base image
5. [ ] Update one container (status) as proof of concept
6. [ ] Measure build time before/after
7. [ ] Roll out to remaining containers
8. [ ] Update CircleCI config to enable DLC and pull base image
9. [ ] Update documentation (CLAUDE.md, README.md, submodule docs)
