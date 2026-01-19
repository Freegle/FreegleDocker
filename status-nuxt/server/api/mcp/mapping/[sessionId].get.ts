/**
 * MCP Mapping endpoint - Get anonymization mapping for a session
 *
 * This endpoint allows users to see what was anonymized in their queries.
 * Claude does NOT have access to this - it's for user transparency only.
 *
 * Returns the mapping of tokens to original values:
 * - EMAIL_abc123 → john@example.com
 * - IP_xyz789 → 192.168.1.1
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
    // Query the pseudonymizer for the mapping
    const mappingResponse = await fetch(
      `http://freegle-mcp-pseudonymizer:8080/mapping/${sessionId}`,
      { signal: AbortSignal.timeout(5000) }
    )

    if (!mappingResponse.ok) {
      if (mappingResponse.status === 404) {
        throw createError({
          statusCode: 404,
          message: 'Session not found or expired',
        })
      }

      const errorText = await mappingResponse.text()
      throw createError({
        statusCode: mappingResponse.status,
        message: `Failed to get mapping: ${errorText}`,
      })
    }

    const mappingData = await mappingResponse.json()

    // Format the mapping for display
    const mapping = mappingData.mapping || {}
    const tokenCount = Object.keys(mapping).length

    // Categorize tokens by type
    const categories: Record<string, Record<string, string>> = {
      emails: {},
      ips: {},
      phones: {},
      postcodes: {},
      other: {},
    }

    for (const [token, original] of Object.entries(mapping)) {
      const value = original as string
      if (token.startsWith('EMAIL_')) {
        categories.emails[token] = value
      } else if (token.startsWith('IP_')) {
        categories.ips[token] = value
      } else if (token.startsWith('PHONE_')) {
        categories.phones[token] = value
      } else if (token.startsWith('POSTCODE_')) {
        categories.postcodes[token] = value
      } else {
        categories.other[token] = value
      }
    }

    return {
      sessionId,
      tokenCount,
      categories,
      rawMapping: mapping,
      explanation: {
        purpose: 'This shows what personal data was anonymized before being sent to Claude',
        privacy: 'Claude only sees the tokens (e.g., EMAIL_abc123), not the real values',
        gdpr: 'This pseudonymization protects user privacy under GDPR guidelines',
      },
    }
  } catch (err: any) {
    if (err.statusCode) throw err

    throw createError({
      statusCode: 500,
      message: `Failed to retrieve mapping: ${err.message}`,
    })
  }
})
