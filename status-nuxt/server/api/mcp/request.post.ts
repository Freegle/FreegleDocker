/**
 * Register a query for human approval
 *
 * POST /api/mcp/request
 * Body: { query, timeRange, limit }
 * Returns: { id, status }
 */

import { registerQuery } from '../../utils/mcp-approval'

interface RequestBody {
  query: string
  timeRange: string
  limit: number
}

export default defineEventHandler(async (event) => {
  const body = await readBody<RequestBody>(event)

  if (!body.query) {
    throw createError({
      statusCode: 400,
      message: 'query is required',
    })
  }

  const id = registerQuery(
    body.query,
    body.timeRange || '1h',
    body.limit || 100
  )

  return {
    id,
    status: 'pending_query',
    message: 'Query registered for approval',
  }
})
