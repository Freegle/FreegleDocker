/**
 * Approve a pending query or results
 *
 * POST /api/mcp/approve/:id
 * Body: { stage: 'query' | 'results' }
 * Returns: { status, results? }
 */

import {
  getQuery,
  approveQuery,
  setQueryResults,
  setQueryError,
  approveResults,
} from '../../../utils/mcp-approval'

interface ApproveBody {
  stage: 'query' | 'results'
}

export default defineEventHandler(async (event) => {
  const id = getRouterParam(event, 'id')
  const body = await readBody<ApproveBody>(event)

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

  if (body.stage === 'query') {
    // Approve the query for execution
    if (query.status !== 'pending_query') {
      throw createError({
        statusCode: 400,
        message: `Cannot approve query in status: ${query.status}`,
      })
    }

    approveQuery(id)

    // Execute the query
    try {
      const pseudonymizerUrl = 'http://freegle-mcp-pseudonymizer:8080/query'

      const response = await fetch(pseudonymizerUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          sessionId: `approval-${id}`,
          query: query.query,
          start: query.timeRange,
          limit: query.limit,
        }),
        signal: AbortSignal.timeout(60000),
      })

      if (!response.ok) {
        const errorText = await response.text()
        setQueryError(id, `Query failed: ${errorText}`)
        throw createError({
          statusCode: response.status,
          message: `Query execution failed: ${errorText}`,
        })
      }

      const results = await response.json()
      setQueryResults(id, results)

      return {
        status: 'pending_results',
        message: 'Query executed, results pending approval',
        resultCount: results.data?.result?.reduce(
          (sum: number, s: any) => sum + (s.values?.length || 0),
          0
        ) || 0,
      }
    } catch (err: any) {
      if (err.statusCode) throw err
      setQueryError(id, err.message)
      throw createError({
        statusCode: 500,
        message: `Query execution failed: ${err.message}`,
      })
    }
  } else if (body.stage === 'results') {
    // Approve the results to be returned to AI
    if (query.status !== 'pending_results') {
      throw createError({
        statusCode: 400,
        message: `Cannot approve results in status: ${query.status}`,
      })
    }

    approveResults(id)

    return {
      status: 'approved_results',
      message: 'Results approved for AI',
    }
  } else {
    throw createError({
      statusCode: 400,
      message: 'stage must be "query" or "results"',
    })
  }
})
