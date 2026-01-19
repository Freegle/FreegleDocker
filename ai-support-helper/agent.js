/**
 * Claude Agent for Freegle support queries.
 *
 * Two-tier approach:
 * 1. First use fact_query tools to get live data from the browser
 * 2. Fall back to codebase search if fact queries can't answer the question
 */

const Anthropic = require('@anthropic-ai/sdk')
const fs = require('fs')
const path = require('path')

// Initialize Anthropic client - uses ANTHROPIC_API_KEY env var
let anthropic = null

function getClient() {
  if (!anthropic) {
    anthropic = new Anthropic()
  }
  return anthropic
}

// Available fact query types (executed by browser with user's auth)
const FACT_QUERY_TYPES = {
  // User lookup
  find_user_by_email: { params: ['email'], returns: '{id, role, found}' },
  find_user_by_name: { params: ['name'], returns: '[{id, displayname, role}]' },
  get_user_profile: { params: ['userid'], returns: '{id, role, added, lastaccess, engagement, trustlevel, displayname}' },
  get_user_role: { params: ['userid'], returns: 'string' },

  // User activity
  get_last_login: { params: ['userid'], returns: 'ISO date or "never"' },
  get_last_activity: { params: ['userid'], returns: 'ISO date or "never"' },
  has_recent_activity: { params: ['userid', 'timerange'], returns: 'boolean' },
  count_user_posts: { params: ['userid', 'timerange'], returns: 'number' },
  get_user_messages: { params: ['userid', 'timerange'], returns: '[{id, type, subject, arrival, hasoutcome}]' },
  get_user_post_replies: { params: ['userid', 'timerange'], returns: 'number' },

  // User memberships
  get_user_groups: { params: ['userid'], returns: '[{groupid, groupname, role, joined}]' },
  get_user_teams: { params: ['userid'], returns: '[teamname]' },
  check_team_membership: { params: ['userid', 'teamname'], returns: 'boolean' },

  // Team/Admin queries
  list_teams: { params: [], returns: '[teamname]' },
  list_team_members: { params: ['teamname'], returns: '[{id, displayname, role}]' },

  // Group queries
  search_groups: { params: ['search'], returns: '[{id, name, region, membercount}]' },
  get_group_info: { params: ['groupid'], returns: '{id, name, region, membercount, founded, modcount}' },
  get_group_stats: { params: ['groupid', 'timerange'], returns: '{posts, taken}' },
  get_group_mods: { params: ['groupid'], returns: '[{id, displayname, role}]' },

  // Message/Post queries
  get_message_info: { params: ['messageid'], returns: '{id, type, subject, status, groupname, posted, outcome}' },
  get_message_history: { params: ['messageid'], returns: '[{action, timestamp, byuser}]' },
  search_messages: { params: ['search', 'groupid'], returns: '[{id, type, subject, status}]' },
  count_group_messages: { params: ['groupid', 'timerange'], returns: '{offers, wanteds, taken}' },

  // Error/Log queries
  count_errors: { params: ['userid', 'timerange'], returns: 'number' },
  has_errors: { params: ['userid', 'timerange'], returns: 'boolean' },
  get_error_summary: { params: ['userid', 'timerange'], returns: '[{statusCode, count}]' },
  get_error_types: { params: ['userid', 'timerange'], returns: '[{endpoint, method, count}]' },
  get_recent_errors: { params: ['userid', 'limit'], returns: '[{timestamp, statusCode, endpoint, method}]' },

  // System log queries
  count_api_calls: { params: ['userid', 'timerange'], returns: 'number' },
  count_logins: { params: ['userid', 'timerange'], returns: 'number' },
  get_user_actions: { params: ['userid', 'timerange'], returns: '[{type, subtype, timestamp, groupid}]' },
  get_login_history: { params: ['userid', 'limit'], returns: '[{timestamp, success, ip_hash}]' },

  // Moderation queries
  get_user_spam_score: { params: ['userid'], returns: '{score, reason_summary}' },
  is_user_banned: { params: ['userid'], returns: 'boolean' },
  get_user_warnings: { params: ['userid'], returns: '[{date, type, groupid}]' },
  get_pending_messages: { params: ['groupid'], returns: '[{id, subject, type, pending_since}]' },
}

// Codebase paths for search
const CODEBASE_PATHS = [
  '/app/codebase/iznik-nuxt3',
  '/app/codebase/iznik-server',
  '/app/codebase/iznik-server-go',
]

/**
 * Search codebase for a pattern (local execution).
 */
function searchCodebase(pattern, fileGlob = null) {
  const { execSync } = require('child_process')
  const results = []

  // Default extensions - must use multiple --include flags (brace expansion doesn't work in quotes)
  const defaultExtensions = ['js', 'vue', 'php', 'go', 'ts', 'json']
  let includeFlags
  if (fileGlob && fileGlob !== '*.{js,vue,php,go,ts}') {
    includeFlags = `--include="${fileGlob}"`
  } else {
    includeFlags = defaultExtensions.map(ext => `--include="*.${ext}"`).join(' ')
  }

  for (const basePath of CODEBASE_PATHS) {
    if (!fs.existsSync(basePath)) continue

    try {
      // Use grep to search
      const cmd = `grep -r -n -I ${includeFlags} "${pattern}" "${basePath}" 2>/dev/null | head -50`
      const output = execSync(cmd, { encoding: 'utf-8', timeout: 10000 })
      if (output.trim()) {
        results.push(...output.trim().split('\n').map(line => {
          const match = line.match(/^([^:]+):(\d+):(.*)$/)
          if (match) {
            return {
              file: match[1].replace('/app/codebase/', ''),
              line: parseInt(match[2]),
              content: match[3].substring(0, 200)
            }
          }
          return { raw: line.substring(0, 200) }
        }))
      }
    } catch (e) {
      // grep returns non-zero if no matches
    }
  }

  return results.slice(0, 20) // Limit results
}

/**
 * Read a file from codebase (local execution).
 */
function readCodebaseFile(filePath, startLine = 1, numLines = 50) {
  // Security: only allow reading from codebase paths
  const fullPath = filePath.startsWith('/app/codebase/')
    ? filePath
    : path.join('/app/codebase', filePath)

  const isAllowed = CODEBASE_PATHS.some(base => fullPath.startsWith(base))
  if (!isAllowed) {
    return { error: 'Access denied - can only read codebase files' }
  }

  if (!fs.existsSync(fullPath)) {
    return { error: 'File not found' }
  }

  try {
    const content = fs.readFileSync(fullPath, 'utf-8')
    const lines = content.split('\n')
    const start = Math.max(0, startLine - 1)
    const end = Math.min(lines.length, start + numLines)

    return {
      file: filePath,
      startLine: start + 1,
      endLine: end,
      totalLines: lines.length,
      content: lines.slice(start, end).map((line, i) => `${start + i + 1}: ${line}`).join('\n')
    }
  } catch (e) {
    return { error: e.message }
  }
}

/**
 * Build concise query list for tool description (token-optimized).
 * Groups queries by category to reduce repetition.
 */
function buildQueryList() {
  // Group queries by prefix for concise listing
  const categories = {
    user: ['find_user_by_email', 'find_user_by_name', 'get_user_profile', 'get_user_role', 'get_last_login', 'get_last_activity', 'has_recent_activity', 'count_user_posts', 'get_user_messages', 'get_user_post_replies', 'get_user_groups', 'get_user_teams', 'check_team_membership', 'get_user_spam_score', 'is_user_banned', 'get_user_warnings', 'get_user_actions', 'get_login_history'],
    team: ['list_teams', 'list_team_members'],
    group: ['search_groups', 'get_group_info', 'get_group_stats', 'get_group_mods', 'get_pending_messages'],
    message: ['get_message_info', 'get_message_history', 'search_messages', 'count_group_messages'],
    error: ['count_errors', 'has_errors', 'get_error_summary', 'get_error_types', 'get_recent_errors', 'count_api_calls', 'count_logins'],
  }

  // Build compact list: "user: find_user_by_email(email), get_user_profile(userid), ..."
  return Object.entries(categories)
    .map(([cat, queries]) => `${cat}: ${queries.map(q => {
      const def = FACT_QUERY_TYPES[q]
      return def.params.length ? `${q}(${def.params.join(',')})` : q
    }).join(', ')}`)
    .join('; ')
}

/**
 * Build the tool definitions for Claude API.
 */
function buildTools() {
  const queryList = buildQueryList()

  return [
    // Primary tool: fact queries (executed by browser)
    {
      name: 'fact_query',
      description: `Query Freegle data. Queries: ${queryList}`,
      input_schema: {
        type: 'object',
        properties: {
          query: {
            type: 'string',
            description: 'Query type',
            enum: Object.keys(FACT_QUERY_TYPES),
          },
          params: {
            type: 'object',
            description: 'Query params',
          },
        },
        required: ['query', 'params'],
      },
    },
    // Secondary tool: suggest new queries
    {
      name: 'suggest_fact_query',
      description: 'Suggest new query if needed',
      input_schema: {
        type: 'object',
        properties: {
          name: { type: 'string' },
          description: { type: 'string' },
          params: { type: 'array', items: { type: 'string' } },
          returns: { type: 'string' },
          rationale: { type: 'string' },
        },
        required: ['name', 'description', 'params', 'returns', 'rationale'],
      },
    },
    // Fallback: search codebase
    {
      name: 'search_codebase',
      description: 'Search code (for implementation questions only)',
      input_schema: {
        type: 'object',
        properties: {
          pattern: { type: 'string' },
          file_glob: { type: 'string' },
        },
        required: ['pattern'],
      },
    },
    // Fallback: read file
    {
      name: 'read_codebase_file',
      description: 'Read code file',
      input_schema: {
        type: 'object',
        properties: {
          file_path: { type: 'string' },
          start_line: { type: 'integer' },
          num_lines: { type: 'integer' },
        },
        required: ['file_path'],
      },
    },
  ]
}

/**
 * Build the system prompt (token-optimized).
 */
function buildSystemPrompt() {
  return `Freegle support assistant. Help moderators investigate issues.

TOOLS: Use fact_query for user/group/team/message data. Use search_codebase only for implementation questions.

RULES:
- Use IDs when available (more reliable than names)
- Be concise, use markdown
- Don't ask for clarification - investigate`
}

/**
 * No-op warmup - kept for API compatibility.
 */
async function warmupSession(onStatus) {
  onStatus('Ready')
}

/**
 * Run a support query using Claude API with tools.
 * @param {string} question - The user's question
 * @param {function} requestFactQuery - Function to execute fact queries via browser
 * @param {function} onThinking - Callback for status updates
 * @param {array} conversationHistory - Previous messages in format [{role, content}]
 */
async function runAgent(question, requestFactQuery, onThinking, conversationHistory = []) {
  const suggestedQueries = []

  // Build messages array with history + new question
  const messages = [
    ...conversationHistory,
    { role: 'user', content: question }
  ]

  console.log('[Agent] Conversation history length:', conversationHistory.length)

  onThinking('Investigating...')
  console.log('[Agent] Starting query:', question.substring(0, 50))

  const client = getClient()
  const tools = buildTools()
  const systemPrompt = buildSystemPrompt()

  // Agentic loop - keep calling Claude until it gives a final answer
  let iterations = 0
  const maxIterations = 50

  while (iterations < maxIterations) {
    iterations++
    console.log(`[Agent] Iteration ${iterations}`)

    try {
      const response = await client.messages.create({
        model: 'claude-sonnet-4-20250514',
        max_tokens: 4096,
        system: systemPrompt,
        tools,
        messages,
      })

      console.log('[Agent] Response stop_reason:', response.stop_reason)

      // Check if Claude wants to use tools
      if (response.stop_reason === 'tool_use') {
        // Process tool calls
        const toolResults = []

        for (const block of response.content) {
          if (block.type === 'tool_use') {
            const toolName = block.name
            const toolInput = block.input

            console.log(`[Agent] Tool call: ${toolName}`, JSON.stringify(toolInput).substring(0, 100))
            onThinking(`→ ${toolName}(${JSON.stringify(toolInput).substring(0, 50)}...)`)

            let result

            if (toolName === 'fact_query') {
              // Execute via browser
              try {
                result = await requestFactQuery(toolInput.query, toolInput.params || {})
                console.log('[Agent] Fact query result:', JSON.stringify(result).substring(0, 100))
              } catch (e) {
                result = { error: e.message }
              }
            } else if (toolName === 'suggest_fact_query') {
              suggestedQueries.push(toolInput)
              result = { status: 'recorded', message: 'Suggestion noted' }
            } else if (toolName === 'search_codebase') {
              result = searchCodebase(toolInput.pattern, toolInput.file_glob || '*.{js,vue,php,go,ts}')
            } else if (toolName === 'read_codebase_file') {
              result = readCodebaseFile(toolInput.file_path, toolInput.start_line || 1, toolInput.num_lines || 50)
            } else {
              result = { error: 'Unknown tool' }
            }

            toolResults.push({
              type: 'tool_result',
              tool_use_id: block.id,
              content: JSON.stringify(result),
            })
          }
        }

        // Add assistant message and tool results to conversation
        messages.push({ role: 'assistant', content: response.content })
        messages.push({ role: 'user', content: toolResults })

      } else {
        // Claude is done - extract the final text answer
        let answer = ''
        for (const block of response.content) {
          if (block.type === 'text') {
            answer += block.text
          }
        }

        console.log('[Agent] Final answer length:', answer.length)
        return { answer, suggestedQueries }
      }

    } catch (error) {
      console.error('[Agent] Error:', error)
      throw error
    }
  }

  return { answer: 'This question required too many lookups to complete. Please try asking a more specific question, or break it into smaller parts.', suggestedQueries }
}

module.exports = { runAgent, warmupSession, FACT_QUERY_TYPES }
