import type { ServiceConfig } from '~/types/service'

/**
 * Service definitions for all monitored services.
 * These match the existing status container's service list.
 */
export const services: ServiceConfig[] = [
  // ============================================
  // Freegle Components
  // ============================================
  {
    id: 'freegle-dev-local',
    name: 'Freegle Dev (Local)',
    category: 'freegle',
    container: 'freegle-dev-local',
    url: 'http://freegle-dev-local.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'rebuild', 'visit', 'logs'],
    description: 'Development mode with hot reload, local APIs',
  },
  {
    id: 'freegle-dev-live',
    name: 'Freegle Dev (Live)',
    category: 'production',
    container: 'freegle-dev-live',
    url: 'http://freegle-dev-live.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Development mode with PRODUCTION APIs - use with caution',
    production: true,
  },
  {
    id: 'freegle-prod-local',
    name: 'Freegle Prod (Local)',
    category: 'freegle',
    container: 'freegle-prod-local',
    url: 'http://freegle-prod-local.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'rebuild', 'visit', 'logs'],
    description: 'Production build with local APIs',
  },

  // ============================================
  // ModTools Components
  // ============================================
  {
    id: 'modtools-dev-local',
    name: 'ModTools Dev (Local)',
    category: 'modtools',
    container: 'modtools-dev-local',
    url: 'http://modtools-dev-local.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'rebuild', 'visit', 'logs'],
    description: 'Development mode with hot reload, local APIs',
  },
  {
    id: 'modtools-dev-live',
    name: 'ModTools Dev (Live)',
    category: 'production',
    container: 'modtools-dev-live',
    url: 'http://modtools-dev-live.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Development mode with PRODUCTION APIs - use with caution',
    production: true,
  },
  {
    id: 'modtools-prod-local',
    name: 'ModTools Prod (Local)',
    category: 'modtools',
    container: 'modtools-prod-local',
    url: 'http://modtools-prod-local.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'rebuild', 'visit', 'logs'],
    description: 'Production build with local APIs',
  },

  // ============================================
  // Backend Services
  // ============================================
  {
    id: 'apiv1',
    name: 'API v1 (PHP)',
    category: 'backend',
    container: 'freegle-apiv1',
    url: 'http://apiv1.localhost/api/config',
    healthCheck: { type: 'http', path: '/api/config', timeout: 5000 },
    actions: ['restart', 'logs'],
    description: 'PHP API (iznik-server)',
  },
  {
    id: 'apiv2',
    name: 'API v2 (Go)',
    category: 'backend',
    container: 'freegle-apiv2',
    url: 'http://apiv2.localhost:8192/api/online',
    healthCheck: { type: 'http', path: '/api/online', timeout: 3000 },
    actions: ['restart', 'rebuild', 'logs'],
    description: 'Go API (iznik-server-go)',
  },
  {
    id: 'batch',
    name: 'Batch Processor',
    category: 'backend',
    container: 'freegle-batch',
    healthCheck: { type: 'docker', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Laravel batch jobs (iznik-batch)',
  },

  // ============================================
  // MCP Tools (Privacy-preserving log analysis)
  // Consolidated 2-container design
  // ============================================
  {
    id: 'mcp-sanitizer',
    name: 'MCP Sanitizer',
    category: 'mcp',
    container: 'freegle-mcp-sanitizer',
    healthCheck: { type: 'http', path: '/health', timeout: 3000 },
    actions: ['restart', 'rebuild', 'logs'],
    description: 'Sanitization, pseudonymization, token storage, Loki queries',
  },
  {
    id: 'ai-support-helper',
    name: 'AI Support Helper',
    category: 'mcp',
    container: 'freegle-ai-support-helper',
    healthCheck: { type: 'http', path: '/health', timeout: 3000 },
    actions: ['restart', 'rebuild', 'logs'],
    description: 'Claude-powered AI analysis for support staff',
  },

  // ============================================
  // Development Tools
  // ============================================
  {
    id: 'phpmyadmin',
    name: 'phpMyAdmin',
    category: 'devtools',
    container: 'freegle-phpmyadmin',
    url: 'http://phpmyadmin.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Database management interface',
    credentials: { username: 'root', password: 'iznik' },
  },
  {
    id: 'mailpit',
    name: 'Mailpit',
    category: 'devtools',
    container: 'freegle-mailpit',
    url: 'http://mailpit.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Email testing interface',
  },
  {
    id: 'loki',
    name: 'Loki',
    category: 'devtools',
    container: 'freegle-loki',
    healthCheck: { type: 'http', path: '/ready', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Log aggregation service',
  },
  {
    id: 'alloy',
    name: 'Alloy',
    category: 'devtools',
    container: 'freegle-alloy',
    healthCheck: { type: 'docker', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Log shipper (ships API logs to Loki)',
  },
  {
    id: 'grafana',
    name: 'Grafana',
    category: 'devtools',
    container: 'freegle-grafana',
    url: 'http://grafana.localhost/',
    healthCheck: { type: 'http', path: '/api/health', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Metrics and log visualization',
    credentials: { username: 'admin', password: 'admin' },
  },
  {
    id: 'playwright',
    name: 'Playwright',
    category: 'devtools',
    container: 'freegle-playwright',
    healthCheck: { type: 'docker', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'E2E test runner container',
  },

  // ============================================
  // Infrastructure
  // ============================================
  {
    id: 'percona',
    name: 'Percona MySQL',
    category: 'infrastructure',
    container: 'freegle-percona',
    healthCheck: { type: 'command', command: 'mysqladmin ping -h localhost', timeout: 5000 },
    actions: ['restart', 'logs'],
    description: 'MySQL database server',
    credentials: { username: 'root', password: 'iznik' },
  },
  {
    id: 'postgres',
    name: 'PostgreSQL',
    category: 'infrastructure',
    container: 'freegle-postgres',
    healthCheck: { type: 'command', command: 'pg_isready', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'PostgreSQL database (for Loki)',
  },
  {
    id: 'redis',
    name: 'Redis',
    category: 'infrastructure',
    container: 'freegle-redis',
    healthCheck: { type: 'command', command: 'redis-cli ping', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Cache and session storage',
  },
  {
    id: 'beanstalkd',
    name: 'Beanstalkd',
    category: 'infrastructure',
    container: 'freegle-beanstalkd',
    healthCheck: { type: 'tcp', port: 11300, timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Job queue server',
  },
  {
    id: 'spamassassin',
    name: 'SpamAssassin',
    category: 'infrastructure',
    container: 'freegle-spamassassin',
    healthCheck: { type: 'tcp', port: 783, timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Email spam filtering',
  },
  {
    id: 'traefik',
    name: 'Traefik',
    category: 'infrastructure',
    container: 'freegle-traefik',
    url: 'http://traefik.localhost/dashboard/',
    healthCheck: { type: 'http', path: '/dashboard/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Reverse proxy and load balancer',
  },
  {
    id: 'tusd',
    name: 'tusd',
    category: 'infrastructure',
    container: 'freegle-tusd',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Resumable file upload server',
  },
  {
    id: 'delivery',
    name: 'Image Delivery',
    category: 'infrastructure',
    container: 'freegle-delivery',
    url: 'http://delivery.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'Image transformation service (weserv)',
  },
  {
    id: 'loki',
    name: 'Loki',
    category: 'infrastructure',
    container: 'freegle-loki',
    url: 'http://loki.localhost/ready',
    healthCheck: { type: 'http', path: '/ready', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Log aggregation service',
  },
  {
    id: 'mjml',
    name: 'MJML Server',
    category: 'infrastructure',
    container: 'freegle-mjml',
    healthCheck: { type: 'http', path: '/health', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'Email template compilation server',
  },
  {
    id: 'loki-backup',
    name: 'Loki Backup',
    category: 'infrastructure',
    container: 'freegle-loki-backup',
    healthCheck: { type: 'docker', timeout: 3000 },
    actions: ['logs'],
    description: 'On-demand backup: docker compose --profile backup run --rm loki-backup',
    profile: 'backup',
  },

  // ============================================
  // Support Services
  // ============================================
  {
    id: 'host-scripts',
    name: 'Host Scripts',
    category: 'backend',
    container: 'freegle-host-scripts',
    healthCheck: { type: 'docker', timeout: 3000 },
    actions: ['restart', 'logs'],
    description: 'File sync and rebuild handler',
  },
  {
    id: 'status',
    name: 'Status (Current)',
    category: 'devtools',
    container: 'freegle-status',
    url: 'http://status.localhost/',
    healthCheck: { type: 'http', path: '/', timeout: 3000 },
    actions: ['restart', 'visit', 'logs'],
    description: 'This status page (being replaced)',
  },
]

/**
 * Get services by category
 */
export function getServicesByCategory(category: string): ServiceConfig[] {
  return services.filter(s => s.category === category)
}

/**
 * Get a service by ID
 */
export function getServiceById(id: string): ServiceConfig | undefined {
  return services.find(s => s.id === id)
}

/**
 * Get services that have a specific action
 */
export function getServicesWithAction(action: string): ServiceConfig[] {
  return services.filter(s => s.actions.includes(action as any))
}
