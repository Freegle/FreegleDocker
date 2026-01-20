#!/usr/bin/env node
/**
 * MCP Server for Freegle Log Queries
 *
 * This is a stdio-based MCP server that the AI can use to query logs.
 * It proxies requests to the status container's MCP endpoint.
 *
 * The server:
 * - Receives queries via MCP protocol
 * - Optionally requires human approval before executing (REQUIRE_APPROVAL=true)
 * - Calls the pseudonymizer (which anonymizes PII)
 * - Returns pseudonymized results + sessionId
 * - Does NOT return the mapping (AI must not see it)
 * - Includes retry logic for reliability
 */

const readline = require('readline')

// Status container endpoints
const STATUS_URL = process.env.STATUS_URL || 'http://freegle-status:8081'
const MCP_QUERY_URL = process.env.MCP_QUERY_URL || `${STATUS_URL}/api/mcp/query`
const MCP_REQUEST_URL = `${STATUS_URL}/api/mcp/request`
const MCP_POLL_URL = `${STATUS_URL}/api/mcp/poll`

// Whether to require human approval for queries (default: true for safety)
const REQUIRE_APPROVAL = process.env.REQUIRE_APPROVAL !== 'false'

// Frontend session ID for filtering queries (passed from ai-support-helper)
const FRONTEND_SESSION_ID = process.env.FRONTEND_SESSION_ID || null

// Custom Loki URL for SSH tunnels to live servers (optional)
const LOKI_URL = process.env.LOKI_URL || null

/**
 * Normalize time range - accepts any valid duration format
 */
function normalizeTimeRange(timeRange) {
  if (!timeRange) return '1h'
  const normalized = timeRange.toLowerCase().trim()
  // Accept any valid duration format (e.g., 1h, 24h, 7d, 30d)
  if (/^\d+[smhdw]$/.test(normalized)) {
    return normalized
  }
  return '1h' // Default
}

/**
 * Execute query with retry logic (direct execution, no approval)
 */
async function executeQueryDirect(queryPayload, maxRetries = 2) {
  let lastError = null

  for (let attempt = 0; attempt <= maxRetries; attempt++) {
    try {
      const controller = new AbortController()
      const timeoutId = setTimeout(() => controller.abort(), 120000) // 2 minute timeout

      const response = await fetch(MCP_QUERY_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(queryPayload),
        signal: controller.signal,
      })

      clearTimeout(timeoutId)

      if (!response.ok) {
        const errorText = await response.text()
        throw new Error(`Query failed (${response.status}): ${errorText}`)
      }

      return await response.json()
    } catch (err) {
      lastError = err
      if (attempt < maxRetries) {
        // Wait before retry (exponential backoff)
        await new Promise(resolve => setTimeout(resolve, 1000 * (attempt + 1)))
        process.stderr.write(`Retry ${attempt + 1}/${maxRetries} after error: ${err.message}\n`)
      }
    }
  }

  throw lastError
}

/**
 * Execute query with human approval flow
 */
async function executeQueryWithApproval(query, timeRange, limit) {
  // Step 1: Register query for approval
  process.stderr.write(`Registering query for approval: ${query}\n`)
  if (FRONTEND_SESSION_ID) {
    process.stderr.write(`  Frontend session: ${FRONTEND_SESSION_ID}\n`)
  }
  if (LOKI_URL) {
    process.stderr.write(`  Custom Loki URL: ${LOKI_URL}\n`)
  }

  const requestPayload = {
    query,
    timeRange,
    limit,
    frontendSessionId: FRONTEND_SESSION_ID, // For filtering in frontend
  }
  if (LOKI_URL) {
    requestPayload.lokiUrl = LOKI_URL
  }

  const registerResponse = await fetch(MCP_REQUEST_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(requestPayload),
  })

  if (!registerResponse.ok) {
    throw new Error(`Failed to register query: ${await registerResponse.text()}`)
  }

  const { id: queryId } = await registerResponse.json()
  process.stderr.write(`Query registered with ID: ${queryId}, waiting for approval...\n`)

  // Step 2: Poll for approval (timeout after 5 minutes)
  const pollTimeout = 5 * 60 * 1000
  const pollInterval = 2000 // 2 seconds
  const startTime = Date.now()

  while (Date.now() - startTime < pollTimeout) {
    const pollResponse = await fetch(`${MCP_POLL_URL}/${queryId}`)

    if (!pollResponse.ok) {
      if (pollResponse.status === 404) {
        throw new Error('Query expired or not found')
      }
      throw new Error(`Poll failed: ${await pollResponse.text()}`)
    }

    const pollResult = await pollResponse.json()

    if (pollResult.status === 'approved_results') {
      process.stderr.write(`Query and results approved, returning results\n`)
      return pollResult.results
    }

    if (pollResult.status === 'rejected') {
      throw new Error(pollResult.error || 'Query rejected by user')
    }

    // Still pending, wait and poll again
    await new Promise(resolve => setTimeout(resolve, pollInterval))
  }

  throw new Error('Query approval timed out (5 minutes)')
}

// MCP protocol handling
const rl = readline.createInterface({
  input: process.stdin,
  output: process.stdout,
  terminal: false,
})

function sendResponse(id, result) {
  const response = {
    jsonrpc: '2.0',
    id,
    result,
  }
  console.log(JSON.stringify(response))
}

function sendError(id, code, message) {
  const response = {
    jsonrpc: '2.0',
    id,
    error: { code, message },
  }
  console.log(JSON.stringify(response))
}

// Handle MCP requests
rl.on('line', async (line) => {
  try {
    const request = JSON.parse(line)
    const { id, method, params } = request

    if (method === 'initialize') {
      sendResponse(id, {
        protocolVersion: '2024-11-05',
        capabilities: {
          tools: {},
        },
        serverInfo: {
          name: 'freegle-log-query',
          version: '1.0.0',
        },
      })
    } else if (method === 'tools/list') {
      sendResponse(id, {
        tools: [
          {
            name: 'query_logs',
            description: 'Query Freegle system logs. Logs are pseudonymized - emails and IPs are replaced with tokens for GDPR compliance. Use LogQL syntax.',
            inputSchema: {
              type: 'object',
              properties: {
                query: {
                  type: 'string',
                  description: 'LogQL query (e.g., {job="freegle"} |= "error")',
                },
                time_range: {
                  type: 'string',
                  description: 'Time range (e.g., "1h", "24h", "7d")',
                  default: '1h',
                },
                limit: {
                  type: 'integer',
                  description: 'Max results to return',
                  default: 100,
                },
              },
              required: ['query'],
            },
          },
        ],
      })
    } else if (method === 'tools/call') {
      const { name, arguments: args } = params

      if (name === 'query_logs') {
        try {
          const timeRange = normalizeTimeRange(args.time_range)
          const limit = args.limit || 100

          process.stderr.write(`Executing query: ${args.query} (range: ${timeRange}, limit: ${limit}, approval: ${REQUIRE_APPROVAL})\n`)
          if (LOKI_URL) {
            process.stderr.write(`Using custom Loki URL: ${LOKI_URL}\n`)
          }

          // Build query payload with optional custom Loki URL
          const queryPayload = {
            query: args.query,
            start: timeRange,
            limit,
            debug: false, // IMPORTANT: Never include mapping for AI
          }
          if (LOKI_URL) {
            queryPayload.lokiUrl = LOKI_URL
          }

          let data
          if (REQUIRE_APPROVAL) {
            // Use human approval flow
            data = await executeQueryWithApproval(args.query, timeRange, limit)
          } else {
            // Direct execution (no approval)
            data = await executeQueryDirect(queryPayload)
          }

          // Format results for AI - include sessionId but NOT the mapping
          const resultCount = data.data?.result?.reduce((sum, s) => sum + (s.values?.length || 0), 0) || 0

          const resultText = JSON.stringify({
            status: data.status,
            sessionId: data.sessionId, // User can look up mapping with this
            timeRange,
            resultCount,
            streamCount: data.data?.result?.length || 0,
            results: data.data?.result || [],
          }, null, 2)

          process.stderr.write(`Query returned ${resultCount} results\n`)

          sendResponse(id, {
            content: [{ type: 'text', text: resultText }],
          })
        } catch (err) {
          process.stderr.write(`Query failed: ${err.message}\n`)
          sendResponse(id, {
            content: [{ type: 'text', text: `Query error: ${err.message}. Try a more specific query or shorter time range.` }],
            isError: true,
          })
        }
      } else {
        sendError(id, -32601, `Unknown tool: ${name}`)
      }
    } else if (method === 'notifications/initialized') {
      // No response needed for notifications
    } else {
      sendError(id, -32601, `Method not found: ${method}`)
    }
  } catch (err) {
    console.error('MCP server error:', err)
  }
})

// Log startup to stderr (not stdout which is for MCP protocol)
process.stderr.write(`Freegle Log Query MCP Server started (approval required: ${REQUIRE_APPROVAL})\n`)
