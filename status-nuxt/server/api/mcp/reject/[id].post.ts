/**
 * Reject a pending query or results
 *
 * POST /api/mcp/reject/:id
 * Returns: { status }
 */

import { getQuery, rejectQuery } from '../../../utils/mcp-approval'

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
      message: 'Query not found',
    })
  }

  rejectQuery(id)

  return {
    status: 'rejected',
    message: 'Query rejected',
  }
})
