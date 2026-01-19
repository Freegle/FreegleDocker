/**
 * Get unknown log fields for admin review
 *
 * GET /api/mcp/unknown-fields
 * Query params:
 *   - source?: Filter by log source
 *   - format?: 'json' (default) or 'code' (generates TypeScript)
 */

import {
  getUnknownFields,
  getUnknownFieldsSummary,
  generateSchemaCode,
} from '../../utils/unknown-fields'

export default defineEventHandler(async (event) => {
  const query = getQuery(event)
  const source = query.source as string | undefined
  const format = query.format as string | undefined

  if (format === 'code') {
    // Return TypeScript code snippet
    const code = generateSchemaCode()
    setResponseHeader(event, 'Content-Type', 'text/plain')
    return code
  }

  // Return JSON
  const fields = getUnknownFields(source)
  const summary = getUnknownFieldsSummary()

  return {
    summary,
    fields,
    totalUnclassified: fields.filter((f) => !f.classified).length,
    totalClassified: fields.filter((f) => f.classified).length,
    generatedAt: new Date().toISOString(),
  }
})
