/**
 * MCP Session endpoint - Get full session details including query and results
 *
 * This endpoint allows users to see everything Claude received from a log query:
 * - The original query that was made
 * - The pseudonymized results that Claude saw
 * - The anonymization mapping
 *
 * This provides full transparency into what data Claude accessed.
 */

export default defineEventHandler(async (event) => {
  const sessionId = getRouterParam(event, 'sessionId')

  if (!sessionId) {
    throw createError({
      statusCode: 400,
      message: 'sessionId is required',
    })
  }

  try {
    // Query the pseudonymizer for full session details
    const sessionResponse = await fetch(
      `http://freegle-mcp-pseudonymizer:8080/session/${sessionId}`,
      { signal: AbortSignal.timeout(5000) }
    )

    if (!sessionResponse.ok) {
      if (sessionResponse.status === 404) {
        throw createError({
          statusCode: 404,
          message: 'Session not found or expired',
        })
      }

      const errorText = await sessionResponse.text()
      throw createError({
        statusCode: sessionResponse.status,
        message: `Failed to get session: ${errorText}`,
      })
    }

    const sessionData = await sessionResponse.json()

    // Format the response for display
    return {
      sessionId,
      query: sessionData.query || null,
      timestamp: sessionData.timestamp || null,
      resultCount: sessionData.results?.length || 0,
      pseudonymizedResults: sessionData.results || [],
      mapping: sessionData.mapping || {},
      explanation: {
        query: 'The LogQL query that was executed',
        pseudonymizedResults: 'What Claude saw (with PII replaced by tokens)',
        mapping: 'The token-to-real-value mapping (Claude never sees this)',
      },
    }
  } catch (err: any) {
    if (err.statusCode) throw err

    throw createError({
      statusCode: 500,
      message: `Failed to retrieve session: ${err.message}`,
    })
  }
})
