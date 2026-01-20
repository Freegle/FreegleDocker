/**
 * MCP Query endpoint - Query Loki via pseudonymizer with optional debug info
 *
 * This endpoint provides transparency into what Claude sees vs what's in the logs.
 *
 * Request body:
 * - query: LogQL query string
 * - start?: Relative time (1h, 24h, 7d) or ISO 8601 timestamp
 * - end?: ISO 8601 timestamp
 * - limit?: Max results (default 100)
 * - debug?: If true, shows pseudonymization details
 */

import { randomUUID } from 'crypto'

interface QueryRequest {
  query: string
  start?: string
  end?: string
  limit?: number
  debug?: boolean
  lokiUrl?: string // Optional custom Loki URL (for SSH tunnel to live servers)
}

export default defineEventHandler(async (event) => {
  const body = await readBody<QueryRequest>(event)

  if (!body.query) {
    throw createError({
      statusCode: 400,
      message: 'query is required',
    })
  }

  const sessionId = `status-${randomUUID().slice(0, 8)}`
  const debugInfo: any = {
    sessionId,
    originalQuery: body.query,
    steps: [],
  }

  try {
    // Step 1: Query the pseudonymizer directly (it handles Loki communication)
    debugInfo.steps.push({
      step: 1,
      action: 'Sending query to pseudonymizer',
      detail: 'Pseudonymizer will translate tokens and query Loki',
    })

    const pseudonymizerUrl = 'http://freegle-mcp-pseudonymizer:8080/query'

    const queryPayload: any = {
      sessionId,
      query: body.query,
      start: body.start || '1h',
      end: body.end,
      limit: body.limit || 100,
    }

    // Pass custom Loki URL if provided (for SSH tunnels to live servers)
    if (body.lokiUrl) {
      queryPayload.lokiUrl = body.lokiUrl
    }

    debugInfo.steps.push({
      step: 2,
      action: 'Query payload',
      detail: queryPayload,
    })

    const response = await fetch(pseudonymizerUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(queryPayload),
      signal: AbortSignal.timeout(30000),
    })

    if (!response.ok) {
      const errorText = await response.text()
      debugInfo.steps.push({
        step: 3,
        action: 'Pseudonymizer error',
        detail: { status: response.status, error: errorText },
      })

      throw createError({
        statusCode: response.status,
        message: `Pseudonymizer error: ${errorText}`,
      })
    }

    const lokiData = await response.json()

    debugInfo.steps.push({
      step: 3,
      action: 'Received pseudonymized results',
      detail: {
        resultCount: lokiData.data?.result?.length || 0,
        status: lokiData.status,
      },
    })

    // NOTE: Token mappings are NOT fetched here - they contain real PII
    // The mapping is only available to the browser via the sanitizer endpoint
    // Debug mode only shows metadata, never actual mappings
    debugInfo.steps.push({
      step: 4,
      action: 'Pseudonymization complete',
      detail: 'Token mappings not exposed (PII protection)',
    })

    // Build response - always include sessionId for mapping lookups
    const result: any = {
      status: lokiData.status,
      data: lokiData.data,
      sessionId, // Always return so user can look up anonymization mapping
    }

    // Debug mode shows the mapping inline (for testing only - Claude should NOT use this)
    if (body.debug) {
      result.debug = {
        ...debugInfo,
        explanation: {
          whatClaudeSees: 'The results above with tokens like EMAIL_a8f3c2, IP_b7d4e1',
          whatIsProtected: 'Real email addresses, IP addresses, phone numbers, postcodes',
          howItWorks: [
            '1. Your query is sent to the pseudonymizer',
            '2. Pseudonymizer queries Loki with the real query',
            '3. Results are scanned for PII (emails, IPs, phones, postcodes)',
            '4. PII is replaced with consistent tokens (same email = same token)',
            '5. Only tokenized results are returned',
          ],
        },
      }
    }

    return result
  }
  catch (err: any) {
    if (err.statusCode) throw err

    throw createError({
      statusCode: 500,
      message: `Query failed: ${err.message}`,
    })
  }
})
