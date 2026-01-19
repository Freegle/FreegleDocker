/**
 * Log Field Schema - Whitelist for Loki log fields
 *
 * Similar to db-schema.ts, this defines which log fields are:
 * - PUBLIC: Passed through unchanged
 * - SENSITIVE: Always pseudonymized
 *
 * Fields not in the schema are treated as UNKNOWN and pseudonymized by default.
 */

export type LogFieldPrivacy = 'PUBLIC' | 'SENSITIVE'

export interface LogSourceSchema {
  description: string
  fields: Record<string, LogFieldPrivacy>
}

/**
 * Log field schema by source/container
 *
 * To add a new field:
 * 1. Check if the field contains PII (names, emails, IPs, user content)
 * 2. If yes: mark as SENSITIVE
 * 3. If no (system data, counts, timestamps): mark as PUBLIC
 * 4. When in doubt, mark as SENSITIVE
 */
export const LOG_SCHEMA: Record<string, LogSourceSchema> = {
  // PHP API (apiv1) logs
  api: {
    description: 'PHP API request logs',
    fields: {
      // Timestamps and request metadata
      timestamp: 'PUBLIC',
      level: 'PUBLIC',
      method: 'PUBLIC',
      path: 'PUBLIC',
      status: 'PUBLIC',
      duration_ms: 'PUBLIC',
      response_size: 'PUBLIC',

      // Identifiers (numeric IDs only - no PII)
      userid: 'PUBLIC',
      groupid: 'PUBLIC',
      msgid: 'PUBLIC',

      // Network info - SENSITIVE
      ip: 'SENSITIVE',
      forwarded_for: 'SENSITIVE',

      // User agent can contain device info but no direct PII
      user_agent: 'PUBLIC',

      // Error info
      error_code: 'PUBLIC',
      error_type: 'PUBLIC',
      error_message: 'SENSITIVE', // May contain user data in error context
    },
  },

  // Go API (apiv2) logs
  apiv2: {
    description: 'Go API request logs',
    fields: {
      timestamp: 'PUBLIC',
      level: 'PUBLIC',
      msg: 'PUBLIC', // System messages only
      caller: 'PUBLIC',
      latency: 'PUBLIC',
      status: 'PUBLIC',
      method: 'PUBLIC',
      path: 'PUBLIC',

      // Identifiers
      userid: 'PUBLIC',
      groupid: 'PUBLIC',
      msgid: 'PUBLIC',
      chatid: 'PUBLIC',

      // Network - SENSITIVE
      ip: 'SENSITIVE',
      clientIP: 'SENSITIVE',

      // Errors
      error: 'SENSITIVE', // May contain context with user data
      stack: 'SENSITIVE',
    },
  },

  // Batch job logs
  batch: {
    description: 'Laravel batch job logs',
    fields: {
      timestamp: 'PUBLIC',
      level: 'PUBLIC',
      job: 'PUBLIC',
      queue: 'PUBLIC',
      status: 'PUBLIC',
      duration_ms: 'PUBLIC',
      memory_mb: 'PUBLIC',
      processed: 'PUBLIC',
      failed: 'PUBLIC',

      // Identifiers
      userid: 'PUBLIC',
      groupid: 'PUBLIC',
      msgid: 'PUBLIC',

      // Emails handled by batch
      email_type: 'PUBLIC',
      recipient_count: 'PUBLIC',

      // Errors
      error_type: 'PUBLIC',
      error_message: 'SENSITIVE',
    },
  },

  // Client-side logs (from browser)
  client: {
    description: 'Client-side JavaScript logs',
    fields: {
      timestamp: 'PUBLIC',
      level: 'PUBLIC',
      component: 'PUBLIC',
      action: 'PUBLIC',
      page: 'PUBLIC',

      // User context - only IDs
      userid: 'PUBLIC',
      groupid: 'PUBLIC',

      // Error info
      error_type: 'PUBLIC',
      error_name: 'PUBLIC',
      error_message: 'SENSITIVE', // May contain user input

      // URLs may contain PII in query params
      url: 'SENSITIVE',
      referrer: 'SENSITIVE',

      // User agent
      browser: 'PUBLIC',
      os: 'PUBLIC',

      // User-provided content
      search_query: 'SENSITIVE',
      input_value: 'SENSITIVE',
    },
  },

  // Email logs
  email: {
    description: 'Email sending/delivery logs',
    fields: {
      timestamp: 'PUBLIC',
      level: 'PUBLIC',
      type: 'PUBLIC', // Email type (digest, notification, etc.)
      status: 'PUBLIC', // sent, failed, bounced

      // Identifiers
      userid: 'PUBLIC',
      groupid: 'PUBLIC',
      msgid: 'PUBLIC',

      // Email addresses - SENSITIVE
      to: 'SENSITIVE',
      from: 'SENSITIVE',
      reply_to: 'SENSITIVE',

      // Subject may contain post titles (public) but mark sensitive for safety
      subject: 'SENSITIVE',

      // Delivery info
      smtp_code: 'PUBLIC',
      bounce_type: 'PUBLIC',
      bounce_reason: 'SENSITIVE', // May contain email address
    },
  },

  // Database logs (slow queries, errors)
  database: {
    description: 'Database query logs',
    fields: {
      timestamp: 'PUBLIC',
      level: 'PUBLIC',
      duration_ms: 'PUBLIC',
      rows_affected: 'PUBLIC',
      query_type: 'PUBLIC', // SELECT, INSERT, etc.

      // Full SQL may contain PII
      query: 'SENSITIVE',
      table: 'PUBLIC',
    },
  },

  // System/infrastructure logs
  system: {
    description: 'System-level logs',
    fields: {
      timestamp: 'PUBLIC',
      level: 'PUBLIC',
      service: 'PUBLIC',
      event: 'PUBLIC',
      message: 'PUBLIC', // System messages only
      hostname: 'PUBLIC',
      pid: 'PUBLIC',
      cpu_percent: 'PUBLIC',
      memory_mb: 'PUBLIC',
      disk_percent: 'PUBLIC',
    },
  },
}

/**
 * Get field privacy level for a log source and field
 * Returns null if the field is not in the schema (unknown)
 */
export function getLogFieldPrivacy(
  source: string,
  field: string
): LogFieldPrivacy | null {
  const schema = LOG_SCHEMA[source.toLowerCase()]
  if (!schema) return null
  return schema.fields[field.toLowerCase()] || null
}

/**
 * Check if a log source is known
 */
export function isKnownLogSource(source: string): boolean {
  return source.toLowerCase() in LOG_SCHEMA
}

/**
 * Get all known log sources
 */
export function getKnownLogSources(): string[] {
  return Object.keys(LOG_SCHEMA)
}

/**
 * Get all known fields for a log source
 */
export function getKnownFieldsForSource(source: string): string[] {
  const schema = LOG_SCHEMA[source.toLowerCase()]
  return schema ? Object.keys(schema.fields) : []
}
