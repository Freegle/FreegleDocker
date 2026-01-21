#!/usr/bin/env node
/**
 * MCP Server for Freegle Database Queries
 *
 * This is a stdio-based MCP server that the AI can use to query the database.
 * It proxies requests to the status container's MCP database endpoint.
 *
 * The server:
 * - Receives SQL queries via MCP protocol
 * - Validates queries are read-only with allowed tables/columns
 * - Returns pseudonymized results
 * - Does NOT return the token mapping (AI must not see real values)
 */

const readline = require('readline')

// AI Sanitizer endpoint for database queries (combined service)
const AI_SANITIZER_URL = process.env.AI_SANITIZER_URL || 'http://freegle-ai-sanitizer:8080'
const DB_QUERY_URL = `${AI_SANITIZER_URL}/api/mcp/db-query`

// Whether to require human approval for queries (default: true for safety)
const REQUIRE_APPROVAL = process.env.REQUIRE_APPROVAL !== 'false'

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

/**
 * Execute a database query with retry logic
 */
async function executeQuery(sql, maxRetries = 2) {
  let lastError = null

  for (let attempt = 0; attempt <= maxRetries; attempt++) {
    try {
      const controller = new AbortController()
      // 3 minute timeout to allow for human approval (query + results, 2min each max)
      const timeoutId = setTimeout(() => controller.abort(), 180000)

      const response = await fetch(DB_QUERY_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          query: sql,
          debug: false, // IMPORTANT: Never include mapping for AI
          requireApproval: REQUIRE_APPROVAL, // Use env var setting
        }),
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
        await new Promise(resolve => setTimeout(resolve, 1000 * (attempt + 1)))
        process.stderr.write(`Retry ${attempt + 1}/${maxRetries} after error: ${err.message}\n`)
      }
    }
  }

  throw lastError
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
          name: 'freegle-db-query',
          version: '1.0.0',
        },
      })
    } else if (method === 'tools/list') {
      sendResponse(id, {
        tools: [
          {
            name: 'describe_schema',
            description: 'Get database schema including available tables, columns, and JOIN examples. CALL THIS FIRST before writing any queries to understand the data model.',
            inputSchema: {
              type: 'object',
              properties: {},
              required: [],
            },
          },
          {
            name: 'query_database',
            description: `Query Freegle database. Returns pseudonymized data - names, emails, IPs are replaced with tokens for GDPR compliance.

IMPORTANT: Email addresses are stored in users_emails table, NOT the users table!
To look up a user by email, you MUST JOIN: users u INNER JOIN users_emails ue ON u.id = ue.userid WHERE ue.email = ?

Common queries:

LOOK UP USER BY EMAIL:
- SELECT u.id, u.fullname, u.lastaccess FROM users u INNER JOIN users_emails ue ON u.id = ue.userid WHERE ue.email = "user@example.com"

USERS:
- SELECT id, fullname, lastaccess, engagement FROM users WHERE id = ?

MESSAGES (Posts):
- SELECT id, subject, type, arrival FROM messages WHERE fromuser = ?

GROUPS:
- SELECT id, nameshort, membercount FROM groups

MEMBERSHIPS:
- SELECT g.nameshort, m.role FROM memberships m INNER JOIN groups g ON m.groupid = g.id WHERE m.userid = ?

Notes:
- Only SELECT queries allowed
- Maximum 500 results per query
- Sensitive data (names, emails) automatically pseudonymized`,
            inputSchema: {
              type: 'object',
              properties: {
                query: {
                  type: 'string',
                  description: 'SQL SELECT query',
                },
              },
              required: ['query'],
            },
          },
        ],
      })
    } else if (method === 'tools/call') {
      const { name, arguments: args } = params

      if (name === 'describe_schema') {
        try {
          process.stderr.write('Fetching database schema\n')

          const response = await fetch(`${AI_SANITIZER_URL}/api/mcp/schema`)
          if (!response.ok) {
            throw new Error(`Schema fetch failed: ${response.status}`)
          }
          const schema = await response.json()

          const resultText = JSON.stringify(schema, null, 2)
          process.stderr.write(`Schema returned with ${Object.keys(schema.tables || {}).length} tables\n`)

          sendResponse(id, {
            content: [{ type: 'text', text: resultText }],
          })
        } catch (err) {
          process.stderr.write(`Schema fetch failed: ${err.message}\n`)
          sendResponse(id, {
            content: [{ type: 'text', text: `Schema error: ${err.message}` }],
            isError: true,
          })
        }
      } else if (name === 'query_database') {
        try {
          process.stderr.write(`Executing database query: ${args.query}\n`)

          const data = await executeQuery(args.query)

          // Format results for AI - include sessionId but NOT the mapping
          const resultText = JSON.stringify({
            status: data.status,
            sessionId: data.sessionId,
            resultCount: data.resultCount,
            columns: data.columns,
            rows: data.rows,
            note: data.note,
          }, null, 2)

          process.stderr.write(`Query returned ${data.resultCount} results\n`)

          sendResponse(id, {
            content: [{ type: 'text', text: resultText }],
          })
        } catch (err) {
          process.stderr.write(`Query failed: ${err.message}\n`)
          sendResponse(id, {
            content: [{ type: 'text', text: `Query error: ${err.message}. Check your SQL syntax and ensure you're using allowed tables and columns.` }],
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
process.stderr.write(`Freegle Database Query MCP Server started (approval required: ${REQUIRE_APPROVAL})\n`)
