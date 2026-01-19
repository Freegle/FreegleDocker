/**
 * Poll query status (used by MCP server to wait for approval)
 *
 * GET /api/mcp/poll/:id
 * Returns: { status, results? }
 */

import { getQuery, cleanupQuery } from '../../../utils/mcp-approval'

export default defineEventHandler(async (event) => {
  const id = getRouterParam(event, 'id')

  if (!id) {
    throw createError({
      statusCode: 400,
      message: 'Query ID is required',
    })
  }

  const query = getQuery(id)
  if (!query) {
    throw createError({
      statusCode: 404,
      message: 'Query not found or expired',
    })
  }

  // If approved, return results and clean up
  if (query.status === 'approved_results') {
    const results = query.results
    cleanupQuery(id)
    return {
      status: 'approved_results',
      results,
    }
  }

  // If rejected, return error and clean up
  if (query.status === 'rejected') {
    const error = query.error || 'Query rejected by user'
    cleanupQuery(id)
    return {
      status: 'rejected',
      error,
    }
  }

  // Still pending
  return {
    status: query.status,
    message: query.status === 'pending_query'
      ? 'Waiting for query approval'
      : 'Waiting for results approval',
  }
})
