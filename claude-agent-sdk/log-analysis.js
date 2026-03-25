/**
 * Log Analysis module using Claude Agent SDK.
 *
 * Uses the Agent SDK's built-in session management and automatic compaction
 * to handle multi-turn conversations without token overflow.
 * Custom MCP tools query Loki via the pseudonymizer pipeline.
 */

const { query, tool, createSdkMcpServer } = require('@anthropic-ai/claude-agent-sdk')
const { z } = require('zod')

// MCP Interface URL (Container 2) - internal Docker network
const MCP_INTERFACE_URL = process.env.MCP_INTERFACE_URL || 'http://freegle-mcp-interface:8080'

// Go API URL for database queries (via apiv2-live which connects to production DB)
const API_URL = process.env.API_URL || 'http://freegle-apiv2-live:8192'

// Track sanitizer session IDs per agent session (for token translation)
const sessionSanitizerMap = new Map()

/**
 * Create the Loki query tool.
 * The sanitizerSessionId is passed via closure so the pseudonymizer
 * can translate tokens (USER_xxx) to real values in queries.
 */
function createLokiTool(getSanitizerSessionId) {
  return tool(
    'loki_query',
    'Query application logs from Loki using LogQL. Returns pseudonymized log entries. ' +
      'Use this to investigate API errors, user activity, slow requests, etc. ' +
      'USER_xxx and EMAIL_xxx tokens in your query are automatically translated to real values.',
    {
      query: z
        .string()
        .describe(
          'LogQL query. Examples: {app="freegle"} |= "error", ' +
            '{app="freegle", source="api"} | json | user_id = "USER_abc12345", ' +
            '{app="freegle"} | json | status_code >= 400'
        ),
      start: z
        .string()
        .optional()
        .describe('Start time - relative (1h, 24h, 7d) or ISO 8601. Default: 24h'),
      limit: z
        .number()
        .optional()
        .describe('Maximum results. Default: 20, max: 50'),
    },
    async (args) => {
      try {
        const sessionId = getSanitizerSessionId() || 'anonymous'
        const response = await fetch(`${MCP_INTERFACE_URL}/tools/loki_query`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            sessionId,
            query: args.query,
            start: args.start || '24h',
            limit: Math.min(args.limit || 20, 50),
          }),
        })

        if (!response.ok) {
          return {
            content: [
              {
                type: 'text',
                text: `Loki query failed with status ${response.status}`,
              },
            ],
            isError: true,
          }
        }

        const lokiData = await response.json()

        // Extract log entries (not the full Loki stats envelope)
        const entries = []
        for (const stream of lokiData.data?.result || []) {
          for (const [ts, line] of stream.values || []) {
            entries.push(line)
          }
        }

        const summary = {
          entryCount: entries.length,
          entries: entries.slice(0, 20),
          labels: lokiData.data?.result?.[0]?.stream || {},
        }

        return {
          content: [{ type: 'text', text: JSON.stringify(summary) }],
        }
      } catch (error) {
        return {
          content: [
            {
              type: 'text',
              text: `MCP pipeline error: ${error.message}`,
            },
          ],
          isError: true,
        }
      }
    },
    { annotations: { readOnlyHint: true } }
  )
}

/**
 * Build the system prompt with optional user context.
 */
function buildSystemPrompt(userQuery) {
  const userTokens = userQuery.match(/USER_[a-f0-9]{8}/g) || []
  let userContext = ''
  if (userTokens.length > 0) {
    userContext =
      `\n\n## Current Investigation\n` +
      `The moderator has selected a user: **${userTokens[0]}**. ` +
      `When they say "they", "this user", "them", etc., they mean ${userTokens[0]}. ` +
      `To find this user's activity, use: {app="freegle", source="api"} |= "${userTokens[0]}" ` +
      `(the system will translate the token to the real user ID automatically). ` +
      `Do NOT ask the moderator to identify the user — they already have.\n`
  }

  return (
    'You are a Freegle support assistant with access to application logs via Loki. ' +
    'You help moderators investigate user issues by querying real log data.\n\n' +
    '## What Loki contains\n' +
    '- **API request/response logs** — every HTTP request to the Freegle API with endpoint, method, status, duration\n' +
    '- **Response body snippets** — truncated to 32 chars per field (user profiles, group data, session data)\n' +
    '- **Client browser events** — page views, session starts, navigation\n' +
    '- **NOT message content** — Loki does not contain posted messages, offers, or wanteds. Those are in the database.\n' +
    '- **NOT posting history** — to find what someone posted, look for POST/PUT requests to /api/message endpoints\n\n' +
    '## How to use loki_query\n' +
    '- Logs are JSON with fields: endpoint, user_id, ip, duration_ms, status_code, trace_id, response_body, request_body\n' +
    '- Label filters: {app="freegle", source="api"} for API logs, {app="freegle", source="api_headers"} for headers\n' +
    '- Also available: {source="client"} for browser events, {source="logs_table"} for moderation actions\n' +
    '- Line filter: |= "text" for substring match\n' +
    '- JSON pipeline: | json | field = "value" for structured field queries\n' +
    '- User data in results is pseudonymized (USER_xxx, EMAIL_xxx, NAME_xxx, IP_xxx)\n' +
    '- To find a specific user, use their USER_xxx token — the system translates it automatically\n' +
    '- Keep queries focused — request 20 results max per query, refine if needed\n' +
    userContext +
    '\n## Response style\n' +
    '- Be concise and use markdown\n' +
    '- Summarize findings clearly\n' +
    '- If you find errors, explain what they mean\n' +
    '- Suggest possible causes and next steps\n' +
    '- Use pseudonymized tokens (USER_xxx, EMAIL_xxx) when referring to people'
  )
}

/**
 * Run a log analysis query using the Claude Agent SDK.
 *
 * @param {string} userQuery - The pseudonymized query from the frontend
 * @param {string|null} sanitizerSessionId - Session ID for token translation
 * @param {string|null} agentSessionId - Session ID for conversation continuity
 * @param {function|null} onProgress - Optional callback for progress updates: (type, message) => void
 * @returns {Promise<{analysis: string, costUsd: number, usage: object, claudeSessionId: string, isNewSession: boolean}>}
 */
async function runLogAnalysis(
  userQuery,
  sanitizerSessionId,
  agentSessionId,
  onProgress
) {
  const progress = onProgress || (() => {})
  const isNewSession = !agentSessionId

  // Store sanitizer session for the loki tool closure
  const effectiveSessionId = agentSessionId || `agent_${Date.now()}`
  if (sanitizerSessionId) {
    sessionSanitizerMap.set(effectiveSessionId, sanitizerSessionId)
  }

  // Create MCP server with Loki tool
  const lokiTool = createLokiTool(
    () => sessionSanitizerMap.get(effectiveSessionId) || sanitizerSessionId
  )

  const mcpServer = createSdkMcpServer({
    name: 'freegle_logs',
    version: '1.0.0',
    tools: [lokiTool],
  })

  const systemPrompt = buildSystemPrompt(userQuery)

  let analysis = ''
  let costUsd = 0
  let usage = {}
  let resultSessionId = effectiveSessionId

  const queryOptions = {
    model: 'claude-sonnet-4-20250514',
    systemPrompt,
    mcpServers: { freegle_logs: mcpServer },
    allowedTools: ['mcp__freegle_logs__loki_query'],
    permissionMode: 'bypassPermissions',
    maxTurns: 15,
  }

  // Resume existing session or start new
  if (agentSessionId) {
    queryOptions.resume = agentSessionId
  }

  progress('status', 'Starting analysis...')

  for await (const message of query({
    prompt: userQuery,
    options: queryOptions,
  })) {
    if (message.type === 'assistant') {
      // Report tool calls as progress
      for (const block of message.message?.content || []) {
        if (block.type === 'tool_use') {
          const toolName = block.name.replace('mcp__freegle_logs__', '')
          const input = block.input || {}
          const desc = toolName === 'loki_query'
            ? `Querying logs: ${(input.query || '').substring(0, 80)}`
            : `${toolName}: ${JSON.stringify(input).substring(0, 80)}`
          progress('tool', desc)
          console.log(
            `[LogAnalysis] Tool: ${block.name}`,
            JSON.stringify(block.input).substring(0, 120)
          )
        }
        if (block.type === 'text' && block.text) {
          // Partial thinking/text from Claude
          progress('thinking', block.text.substring(0, 100))
        }
      }
    }

    if (message.type === 'result') {
      if (message.subtype === 'success') {
        analysis = message.result || ''
        costUsd = message.total_cost_usd || 0
        usage = {
          inputTokens: message.usage?.input_tokens || 0,
          outputTokens: message.usage?.output_tokens || 0,
          cacheCreation: message.usage?.cache_creation_input_tokens || 0,
          cacheRead: message.usage?.cache_read_input_tokens || 0,
          durationMs: message.duration_ms || 0,
        }
        resultSessionId = message.sessionId || effectiveSessionId
      } else {
        // Error result
        const errors = message.errors?.map((e) => e.message).join('; ') || 'Unknown error'
        analysis = `Log analysis error: ${errors}`
        console.error(`[LogAnalysis] Error:`, errors)
      }
    }
  }

  console.log(
    `[LogAnalysis] Done. Cost: $${costUsd.toFixed(4)}, tokens: ${usage.inputTokens}+${usage.outputTokens}`
  )

  return {
    analysis,
    costUsd,
    usage,
    claudeSessionId: resultSessionId,
    isNewSession,
  }
}

module.exports = { runLogAnalysis }
