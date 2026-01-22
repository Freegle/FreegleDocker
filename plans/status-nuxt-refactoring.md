# Status Container Nuxt Refactoring Plan

## Current Progress (Updated 2026-01-18)

### ✅ Phase 1: Foundation - COMPLETE
- Nuxt 3 scaffolding with proper project structure
- Bootstrap 5 + bootstrap-vue-next integration
- Type definitions for services, status, and tests
- Service configuration in `server/utils/services.ts`
- Nitro API routes (all endpoints ported from server.js)
- Pinia stores for status and test state management
- All UI components (ServiceCard, ServiceGrid, TestRunner, etc.)
- All 7 tabs implemented: Freegle, ModTools, Backend, Dev Tools, Testing, Infrastructure, Production
- Tab status indicators showing actual service health (colored dots)
- Responsive tab navigation (compact, non-wrapping)
- "Recreate Test Users" button on Freegle page
- Visit buttons include service name (e.g., "Visit Freegle Dev")
- Production tab with LIVE badge and warning message
- API layer following Freegle Nuxt patterns (BaseAPI, StatusAPI, api/index.ts)

### 🔄 Phase 2: Polish & Improvements - IN PROGRESS
- ✅ Visual design matching original status page
- ✅ Tab status dots showing actual service health
- ✅ Responsive layout (tabs don't overflow)
- ⬜ Loading skeletons (not yet implemented)
- ⬜ Toast notifications for actions (using alert() currently)
- ⬜ Dark mode support (not yet implemented)

### ⬜ Phase 3: Production Monitoring - PLANNED
See "Future: Live Site Monitoring" section below.

### ⬜ Phase 4: Migration - PENDING
- Switch new status to port 8081 (replace old container)
- Update documentation
- Remove old status container code

---

## Overview

Refactor the status container from hand-coded JavaScript (3,300 line `server.js` + 29k char `index.html`) to a well-structured Nuxt 3 application with proper component architecture.

## Goals

1. **Clean Architecture**: Well-organized components with consistent look and feel
2. **Feature Parity**: Match all existing functionality before extending
3. **Future-Ready**: Design abstractions for production monitoring (without implementing yet)
4. **Non-Breaking**: Run on separate port (8082) during development
5. **Consistency**: Use Bootstrap + bootstrap-vue-next matching existing Freegle/ModTools sites

## Current Functionality Summary

### API Endpoints (17 total)

| Category | Endpoint | Method | Purpose |
|----------|----------|--------|---------|
| Status | `/api/status` | GET | All services status |
| Status | `/api/status/all` | GET | Detailed status with metadata |
| Status | `/api/cpu` | GET | CPU usage for all containers |
| Status | `/api/logs` | GET | Container logs (query: container) |
| Container | `/api/container/restart` | POST | Restart a container |
| Container | `/api/container/rebuild` | POST | Async rebuild via host-scripts |
| Container | `/api/container/start-live` | POST | Start freegle-dev-live |
| Container | `/api/container/start-modtools-live` | POST | Start modtools-dev-live |
| Tests | `/api/tests/go` | POST | Start Go tests |
| Tests | `/api/tests/go/status` | GET | Go test status |
| Tests | `/api/tests/php` | POST | Start PHP tests |
| Tests | `/api/tests/php/status` | GET | PHP test status |
| Tests | `/api/tests/laravel` | POST | Start Laravel tests |
| Tests | `/api/tests/laravel/status` | GET | Laravel test status |
| Tests | `/api/tests/playwright` | POST | Start Playwright tests |
| Tests | `/api/tests/playwright/status` | GET | Playwright test status |
| Tests | `/api/tests/playwright/report` | GET | HTML report link |
| Utility | `/api/recreate-test-users` | POST | Recreate test accounts |
| Utility | `/api/dev-connect/test` | GET | Dev server connectivity |
| Utility | `/ssl/ca.crt` | GET | CA certificate |

### Monitored Services (27 total)

**Freegle Components (9):**
- freegle-dev-local, freegle-dev-live, freegle-prod-local
- modtools-dev-local, modtools-dev-live, modtools-prod-local
- apiv1, apiv2, batch

**Development Tools (4):**
- phpmyadmin, mailpit, loki, grafana

**Infrastructure (8):**
- traefik, percona, postgres, redis, beanstalkd, spamassassin, tusd, delivery

**MCP Tools (3):**
- mcp-sanitizer, mcp-interface, mcp-pseudonymizer

**Other (3):**
- freegle-host-scripts, freegle-status, playwright

### UI Features

- 7 tabs: Freegle, ModTools, Backend, Dev Tools, Testing, Infrastructure, Production
- Service cards with status indicator, CPU, actions (restart/rebuild/visit)
- Test runners with progress bars, filters, log viewers
- Traffic light overall health indicator
- Countdown to next refresh
- Modal log viewer

## Architecture

### Project Structure

```
status-nuxt/
├── nuxt.config.ts
├── app.vue
├── assets/
│   └── css/
│       ├── bootstrap-custom.scss    # Bootstrap customizations
│       └── status.scss              # Status-specific styles
├── components/
│   ├── layout/
│   │   ├── AppHeader.vue            # Title, traffic light, countdown
│   │   ├── AppTabs.vue              # Tab navigation (BTabs)
│   │   └── AppModal.vue             # Reusable modal wrapper (BModal)
│   ├── service/
│   │   ├── ServiceCard.vue          # Individual service display (BCard)
│   │   ├── ServiceGrid.vue          # Grid of service cards
│   │   ├── ServiceStatus.vue        # Status badge (BBadge)
│   │   ├── ServiceActions.vue       # Action buttons (BButtonGroup)
│   │   └── ServiceCpu.vue           # CPU usage indicator
│   ├── testing/
│   │   ├── TestRunner.vue           # Individual test suite runner
│   │   ├── TestProgress.vue         # Progress bar (BProgress)
│   │   ├── TestLogs.vue             # Expandable log viewer (BCollapse)
│   │   └── TestFilter.vue           # Test filter input (BFormInput)
│   └── common/
│       ├── CountdownTimer.vue       # Next refresh countdown
│       ├── TrafficLight.vue         # Overall health indicator
│       ├── LogViewer.vue            # Generic log display
│       └── CredentialsDisplay.vue   # Login credentials display
├── pages/
│   ├── index.vue                    # Redirect to default tab
│   ├── freegle.vue                  # Freegle services tab
│   ├── modtools.vue                 # ModTools services tab
│   ├── backend.vue                  # APIs, batch, MCP tools
│   ├── devtools.vue                 # PhpMyAdmin, Mailpit, etc.
│   ├── testing.vue                  # All test suites
│   ├── infrastructure.vue           # Database, cache, proxy
│   ├── production.vue               # Production containers
│   └── logs/
│       └── [container].vue          # Dynamic log viewer page
├── composables/
│   ├── useStatus.ts                 # Status polling and state
│   ├── useCpu.ts                    # CPU monitoring
│   ├── useTests.ts                  # Test execution state
│   ├── useDocker.ts                 # Docker availability detection
│   └── useCountdown.ts              # Refresh countdown logic
├── server/
│   ├── api/
│   │   ├── status.get.ts            # Service status endpoint
│   │   ├── cpu.get.ts               # CPU stats endpoint
│   │   ├── logs.get.ts              # Container logs
│   │   ├── container/
│   │   │   ├── restart.post.ts
│   │   │   ├── rebuild.post.ts
│   │   │   ├── start-live.post.ts
│   │   │   └── start-modtools-live.post.ts
│   │   ├── tests/
│   │   │   ├── go.post.ts
│   │   │   ├── go/
│   │   │   │   └── status.get.ts
│   │   │   ├── php.post.ts
│   │   │   ├── php/
│   │   │   │   └── status.get.ts
│   │   │   ├── laravel.post.ts
│   │   │   ├── laravel/
│   │   │   │   └── status.get.ts
│   │   │   ├── playwright.post.ts
│   │   │   └── playwright/
│   │   │       ├── status.get.ts
│   │   │       └── report.get.ts
│   │   └── utility/
│   │       ├── recreate-test-users.post.ts
│   │       └── dev-connect.get.ts
│   ├── plugins/
│   │   └── docker.ts                # Docker client initialization
│   └── utils/
│       ├── docker.ts                # Docker command execution
│       ├── health.ts                # Health check interface
│       ├── services.ts              # Service definitions
│       └── tests/
│           ├── go.ts                # Go test runner
│           ├── php.ts               # PHP test runner
│           ├── laravel.ts           # Laravel test runner
│           └── playwright.ts        # Playwright test runner
├── stores/
│   ├── status.ts                    # Pinia store for status state
│   └── tests.ts                     # Pinia store for test state
├── types/
│   ├── service.ts                   # Service type definitions
│   ├── status.ts                    # Status response types
│   └── test.ts                      # Test state types
├── Dockerfile
├── package.json
└── tsconfig.json
```

### Dependencies

```json
{
  "dependencies": {
    "bootstrap": "^5.3.1",
    "bootstrap-vue-next": "^0.24.x",
    "@pinia/nuxt": "^0.5.x",
    "dockerode": "^4.0.x"
  },
  "devDependencies": {
    "sass": "^1.69.x",
    "@nuxt/devtools": "^1.x"
  }
}
```

### Bootstrap Configuration

Match existing Freegle/ModTools setup:

```scss
/* assets/css/bootstrap-custom.scss */

/* Status-specific colors */
$status-online: #28a745;
$status-offline: #dc3545;
$status-loading: #ffc107;
$status-unknown: #6c757d;

$primary: #338808;
$secondary: #DCDCDC;
$info: #17a2b8;
$enable-rounded: false;

@import "bootstrap/scss/bootstrap";
@import 'bootstrap-vue-next/dist/bootstrap-vue-next.css';

/* Status-specific styles */
.service-card {
  /* ... */
}
```

### Type Definitions

```typescript
/* types/service.ts */

export type ServiceCategory =
  | 'freegle'
  | 'modtools'
  | 'backend'
  | 'devtools'
  | 'infrastructure'
  | 'mcp'

export type ServiceStatus = 'online' | 'offline' | 'loading' | 'unknown'

export type ServiceAction = 'restart' | 'rebuild' | 'visit' | 'logs'

export interface ServiceConfig {
  id: string
  name: string
  category: ServiceCategory
  container?: string              // Docker container name (if applicable)
  url?: string                    // Access URL
  healthCheck: HealthCheckConfig
  actions: ServiceAction[]
  description: string
  credentials?: {
    username: string
    password: string
  }
  production?: boolean            // Is this a production service?
}

export interface HealthCheckConfig {
  type: 'http' | 'tcp' | 'docker' | 'command'
  path?: string                   // For HTTP checks
  port?: number                   // For TCP checks
  command?: string                // For command checks
  timeout?: number                // Milliseconds
}

export interface ServiceState {
  id: string
  status: ServiceStatus
  cpu?: number
  message?: string
  lastChecked: Date
}
```

```typescript
/* types/test.ts */

export type TestType = 'go' | 'php' | 'laravel' | 'playwright'
export type TestStatus = 'idle' | 'running' | 'completed' | 'failed'

export interface TestProgress {
  total: number
  completed: number
  passed: number
  failed: number
  current?: string               // Current test name
}

export interface TestState {
  type: TestType
  status: TestStatus
  message: string
  logs: string
  progress: TestProgress
  startTime?: Date
  endTime?: Date
  filter?: string
}
```

### Health Check Abstraction (Future Production Support)

```typescript
/* server/utils/health.ts */

export interface HealthProvider {
  /** Get status of a single service */
  getServiceStatus(service: ServiceConfig): Promise<ServiceState>

  /** Get CPU stats for all containers */
  getContainerStats(): Promise<Map<string, number>>

  /** Can this provider manage containers (restart/rebuild)? */
  canManageContainers(): boolean

  /** Can this provider execute tests? */
  canExecuteTests(): boolean
}

/**
 * Local Docker provider - full control via Docker socket
 * Used in local development
 */
export class DockerSocketProvider implements HealthProvider {
  // Implementation using dockerode
}

/**
 * HTTP provider - polls remote health endpoints
 * Used for production monitoring (future)
 */
export class HttpHealthProvider implements HealthProvider {
  canManageContainers() { return false }
  canExecuteTests() { return false }
  // Implementation using fetch to poll /health endpoints
}

/**
 * Composed provider - aggregates multiple sources
 * Used for central dashboard monitoring multiple servers (future)
 */
export class ComposedProvider implements HealthProvider {
  constructor(private providers: Map<string, HealthProvider>) {}
  // Delegates to appropriate provider per service
}
```

### Service Definitions

```typescript
/* server/utils/services.ts */

export const services: ServiceConfig[] = [
  // Freegle
  {
    id: 'freegle-dev-local',
    name: 'Freegle Dev (Local)',
    category: 'freegle',
    container: 'freegle-dev-local',
    url: 'http://freegle-dev-local.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'rebuild', 'visit', 'logs'],
    description: 'Development mode with hot reload, local APIs'
  },
  {
    id: 'freegle-dev-live',
    name: 'Freegle Dev (Live)',
    category: 'freegle',
    container: 'freegle-dev-live',
    url: 'http://freegle-dev-live.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Development mode with PRODUCTION APIs',
    production: true
  },
  // ... more services

  // Infrastructure
  {
    id: 'percona',
    name: 'Percona MySQL',
    category: 'infrastructure',
    container: 'freegle-percona',
    healthCheck: { type: 'command', command: 'mysqladmin ping -h localhost' },
    actions: ['restart', 'logs'],
    description: 'MySQL database',
    credentials: { username: 'root', password: 'iznik' }
  },
  // ... more services
]
```

## Component Design

### ServiceCard.vue

```vue
<template>
  <BCard class="service-card h-100">
    <template #header>
      <div class="d-flex justify-content-between align-items-center">
        <span class="fw-bold">{{ service.name }}</span>
        <ServiceCpu v-if="state.cpu !== undefined" :cpu="state.cpu" />
      </div>
    </template>

    <BCardText class="text-muted small">
      {{ service.description }}
    </BCardText>

    <div v-if="service.url" class="mb-2">
      <a :href="service.url" target="_blank">{{ service.url }}</a>
    </div>

    <CredentialsDisplay
      v-if="service.credentials"
      :credentials="service.credentials"
    />

    <template #footer>
      <div class="d-flex justify-content-between align-items-center">
        <ServiceStatus :status="state.status" :message="state.message" />
        <ServiceActions
          :service="service"
          :can-manage="canManage"
          @restart="$emit('restart', service.id)"
          @rebuild="$emit('rebuild', service.id)"
        />
      </div>
    </template>
  </BCard>
</template>
```

### TestRunner.vue

```vue
<template>
  <BCard class="test-runner">
    <template #header>
      <div class="d-flex justify-content-between align-items-center">
        <span class="fw-bold">{{ title }}</span>
        <BBadge :variant="statusVariant">{{ state.status }}</BBadge>
      </div>
    </template>

    <TestFilter
      v-model="filter"
      :disabled="state.status === 'running'"
      :placeholder="filterPlaceholder"
    />

    <TestProgress
      v-if="state.status === 'running'"
      :progress="state.progress"
    />

    <div class="mt-3">
      <BButton
        :variant="state.status === 'running' ? 'danger' : 'primary'"
        :disabled="!canExecute"
        @click="handleRun"
      >
        {{ state.status === 'running' ? 'Running...' : 'Run Tests' }}
      </BButton>

      <BButton
        v-if="hasReport"
        variant="outline-secondary"
        class="ms-2"
        @click="openReport"
      >
        View Report
      </BButton>
    </div>

    <TestLogs
      v-if="state.logs"
      :logs="state.logs"
      class="mt-3"
    />
  </BCard>
</template>
```

## Docker Configuration

### Dockerfile

```dockerfile
FROM node:20-slim

WORKDIR /app

# Install dependencies for dockerode
RUN apt-get update && apt-get install -y \
    docker.io \
    && rm -rf /var/lib/apt/lists/*

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build

EXPOSE 3000

CMD ["node", ".output/server/index.mjs"]
```

### docker-compose.yml Addition

```yaml
  status-nuxt:
    build:
      context: ./status-nuxt
      dockerfile: Dockerfile
    container_name: freegle-status-nuxt
    restart: unless-stopped
    networks:
      - default
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - ./rebuild-requests:/rebuild-requests
      - .:/project:ro
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.status-nuxt.rule=Host(`status-nuxt.localhost`)"
      - "traefik.http.services.status-nuxt.loadbalancer.server.port=3000"
    environment:
      - DOCKER_HOST=unix:///var/run/docker.sock
```

## Implementation Phases

### Phase 1: Foundation (Feature Parity)

**Tasks:**
1. Create `status-nuxt/` directory with Nuxt 3 scaffolding
2. Configure Bootstrap + bootstrap-vue-next
3. Implement type definitions
4. Create service configuration
5. Implement Nitro API routes (port from server.js)
6. Create Pinia stores
7. Build all components
8. Create all pages with tabs
9. Add to docker-compose.yml on port 8082
10. Test all functionality matches existing status container

**Estimated subtasks:** ~25-30

### Phase 2: Polish & Improvements

**Tasks:**
1. Add loading skeletons
2. Improve error handling and display
3. Add toast notifications for actions
4. Optimize polling (WebSocket consideration)
5. Add dark mode support
6. Improve mobile responsiveness

### Phase 3: Production Preparation (Future)

**Tasks:**
1. Implement HttpHealthProvider
2. Add configuration for remote health endpoints
3. Design health endpoint specification for services
4. Create lightweight health agent (if needed)
5. Security review for production deployment

### Phase 4: Migration

**Tasks:**
1. Switch new status to port 8081
2. Update documentation
3. Remove old status container
4. Deploy to production

---

## Future: Live Site Monitoring

### Vision

Extend the status page to monitor the **production Freegle and ModTools sites** (ilovefreegle.org, modtools.org) in addition to the local Docker development environment. This creates a single dashboard for both development and production health.

### Architecture

The existing `HealthProvider` interface (see "Health Check Abstraction" section above) is designed to support this:

```
┌─────────────────────────────────────────────────────────────┐
│                    Status Dashboard                          │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐   │
│  │ Local Docker │  │ Production   │  │ Production       │   │
│  │ Services     │  │ Freegle      │  │ ModTools         │   │
│  └──────┬───────┘  └──────┬───────┘  └────────┬─────────┘   │
│         │                 │                    │             │
│         ▼                 ▼                    ▼             │
│  DockerSocket       HttpHealth           HttpHealth          │
│  Provider           Provider             Provider            │
│  (full control)     (read-only)          (read-only)         │
└─────────────────────────────────────────────────────────────┘
```

### Implementation Plan

1. **HttpHealthProvider**: Polls remote `/api/health` or similar endpoints
   - Read-only (no container management)
   - Handles network errors gracefully
   - Configurable timeout and retry

2. **Health Endpoints on Production**:
   - Add `/api/health` endpoint to iznik-server returning service status
   - Include database connectivity, cache status, queue health
   - Return response times and basic metrics

3. **Configuration**:
   ```typescript
   // Example: services.ts addition for production
   {
     id: 'freegle-prod',
     name: 'Freegle Production',
     category: 'production',
     url: 'https://www.ilovefreegle.org/',
     healthCheck: {
       type: 'http',
       path: '/api/health',
       timeout: 10000
     },
     actions: ['visit'],  // No restart/rebuild for production
     description: 'Live user site',
     production: true
   }
   ```

4. **UI Considerations**:
   - Clear visual separation between local and production
   - Warning banners for production services
   - No destructive actions (restart/rebuild) available for production
   - Real-time latency display

### Security Considerations

- Health endpoints should not expose sensitive information
- Consider authentication for detailed health data
- Rate limiting on health endpoints
- No write operations from status dashboard to production

### Metrics to Display

| Metric | Source | Display |
|--------|--------|---------|
| Site availability | HTTP check | Green/Red indicator |
| Response time | HTTP timing | Latency in ms |
| API health | `/api/health` | Component status |
| Database | Health endpoint | Connected/Disconnected |
| Queue depth | Health endpoint | Backlog count |
| Error rate | Optional: Sentry | Errors/hour |

### Not In Scope (Initially)

- Log viewing for production (security concern)
- Container management for production (dangerous)
- Test execution against production (separate concern)
- Alerting/notifications (use existing tools)

---

## Testing Strategy

- Manual testing against existing status container (feature comparison)
- Visual testing with Chrome DevTools MCP
- Component tests with Vitest (optional)
- E2E tests with Playwright (if warranted)

## Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| Feature regression | Side-by-side comparison before switching ports |
| Bootstrap conflicts | Isolated SCSS, no global style leaks |
| Docker socket issues | Reuse proven patterns from existing server.js |
| Test execution complexity | Port existing code carefully, test each suite |

## Success Criteria

1. All 17 API endpoints working identically
2. All 27 services monitored correctly
3. All 4 test suites executable from UI
4. Visual parity with existing status page (improved styling acceptable)
5. No regressions in container management
6. Clean component architecture (no 3,300 line files)

## Open Questions

1. ~~Should we use WebSockets for real-time status updates instead of polling?~~ **Decision: Stick with polling for now.** Polling is simpler and the 30-second refresh is adequate for status monitoring.
2. ~~Do we want to add any new features during refactoring (e.g., service grouping, search)?~~ **Decision: Minimal new features.** Added Production tab with LIVE badge, but keeping scope tight for Phase 1.
3. Should the Sentry integration move to this new status app? **Still open** - the current Sentry integration uses Claude CLI which works in the old status container.
4. **NEW**: Should we add real-time latency monitoring for production sites?
5. **NEW**: What authentication/authorization should the production health endpoints use?

## References

- Existing status container: `status/server.js`, `status/index.html`
- Bootstrap configuration: `iznik-nuxt3/assets/css/bootstrap-custom.scss`
- Freegle Nuxt patterns: `iznik-nuxt3/` (composables, stores, components)
