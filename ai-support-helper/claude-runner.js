/**
 * Claude Query Runner with Model Escalation
 *
 * Implements model escalation: Haiku -> Sonnet -> Opus based on response quality.
 * Always starts with the cheapest model (Haiku) and escalates if needed.
 *
 * Architecture:
 * - Invokes Claude CLI for each query (using async exec)
 * - Detects when model "gives up" and escalates to more capable model
 * - Tracks costs per model for reporting
 * - Non-blocking execution keeps health checks working during long Claude queries
 *
 * Future optimization: Use spawn() with persistent Claude process to eliminate
 * 3-6s startup time per query. This would require stdin/stdout communication
 * with the Claude CLI in continuous mode.
 */

const { exec } = require('child_process')
const { promisify } = require('util')
const execAsync = promisify(exec)
const fs = require('fs')
const path = require('path')

// Model escalation ladder (cheapest to most capable)
const MODELS = [
  { id: 'haiku', name: 'claude-3-5-haiku-20241022', inputCost: 0.80, outputCost: 4.00 },
  { id: 'sonnet', name: 'claude-sonnet-4-20250514', inputCost: 3.00, outputCost: 15.00 },
  { id: 'opus', name: 'claude-opus-4-20250514', inputCost: 15.00, outputCost: 75.00 },
]

// Phrases that indicate model needs escalation (can't answer adequately)
const ESCALATION_TRIGGERS = [
  "I don't have enough information",
  "I'm not able to determine",
  "I cannot access",
  "I'm unable to",
  "beyond my capabilities",
  "would need more context",
  "I don't have access to",
  "insufficient data",
  "cannot be determined from",
  "I apologize, but I cannot",
  "this requires more",
  "I would need to",
]

// Phrases that indicate a confident, complete answer (no escalation needed)
// These should appear at the START of a sentence to avoid false matches
const COMPLETION_INDICATORS = [
  "The user",
  "This user",
  "Based on the",
  "I found",
  "Looking at",
  "The logs show",
  "The database shows",
  "According to",
]

// MCP config paths
const MCP_CONFIG_DEFAULT = '/app/mcp-config.json'
const MCP_CONFIG_APPROVAL = '/app/mcp-config-approval.json'
const MCP_CONFIG_TEMP_DIR = '/tmp'

/**
 * Generate a dynamic MCP config file with environment variables injected.
 * This is necessary because the MCP server env is defined in the config file,
 * not inherited from the Claude CLI's environment.
 *
 * @param {string} baseConfigPath - Path to the base MCP config file
 * @param {object} options - Options to inject into MCP server env
 * @param {string} options.frontendSessionId - Frontend session ID for query filtering
 * @param {string} options.lokiUrl - Custom Loki URL (for SSH tunnel to live servers)
 * @param {string} options.sqlUrl - Custom SQL URL (for SSH tunnel to live servers)
 */
function generateMcpConfig(baseConfigPath, options = {}) {
  const { frontendSessionId, lokiUrl, sqlUrl } = options

  // If no customization needed, use the base config as-is
  if (!frontendSessionId && !lokiUrl && !sqlUrl) {
    return baseConfigPath
  }

  try {
    const baseConfig = JSON.parse(fs.readFileSync(baseConfigPath, 'utf8'))

    // Inject environment variables into each MCP server's env
    for (const serverName of Object.keys(baseConfig.mcpServers || {})) {
      const server = baseConfig.mcpServers[serverName]
      server.env = server.env || {}

      // Inject frontend session ID if provided
      if (frontendSessionId) {
        server.env.FRONTEND_SESSION_ID = frontendSessionId
      }

      // Inject custom Loki URL if provided (for log queries)
      if (lokiUrl) {
        server.env.LOKI_URL = lokiUrl
      }

      // Inject custom SQL URL if provided (for database queries)
      if (sqlUrl) {
        server.env.SQL_URL = sqlUrl
      }
    }

    // Write to a temp file with unique name
    const configId = frontendSessionId || Date.now().toString()
    const tempPath = path.join(MCP_CONFIG_TEMP_DIR, `mcp-config-${configId}.json`)
    fs.writeFileSync(tempPath, JSON.stringify(baseConfig, null, 2))

    console.log(`[ClaudeRunner] Generated dynamic MCP config: ${tempPath}`)
    if (lokiUrl) console.log(`[ClaudeRunner]   Custom Loki URL: ${lokiUrl}`)
    if (sqlUrl) console.log(`[ClaudeRunner]   Custom SQL URL: ${sqlUrl}`)
    return tempPath
  } catch (error) {
    console.error(`[ClaudeRunner] Failed to generate dynamic MCP config: ${error.message}`)
    // Fall back to base config
    return baseConfigPath
  }
}

/**
 * Clean up temporary MCP config file.
 */
function cleanupMcpConfig(configPath) {
  if (configPath && configPath.startsWith(MCP_CONFIG_TEMP_DIR)) {
    try {
      fs.unlinkSync(configPath)
    } catch {
      // Ignore cleanup errors
    }
  }
}

/**
 * Check if response indicates escalation is needed.
 */
function needsEscalation(response) {
  const lowerResponse = response.toLowerCase()

  // Check for escalation triggers
  for (const trigger of ESCALATION_TRIGGERS) {
    if (lowerResponse.includes(trigger.toLowerCase())) {
      return true
    }
  }

  // Short responses with no concrete findings often need escalation
  if (response.length < 100 && !COMPLETION_INDICATORS.some(ind => response.includes(ind))) {
    return true
  }

  return false
}

/**
 * Execute query with a specific model using Claude CLI.
 * Now async to avoid blocking the event loop (allows health checks to work during execution).
 *
 * @param {number} modelIndex - Index into MODELS array
 * @param {string} prompt - The user's question
 * @param {string|null} claudeSessionId - Claude CLI's session ID for conversation continuity
 * @param {string} mcpConfigPath - Path to MCP config file
 * @param {object} mcpOptions - Options to inject into MCP config
 * @param {string|null} mcpOptions.frontendSessionId - Frontend session ID for MCP query filtering
 * @param {string|null} mcpOptions.lokiUrl - Custom Loki URL for log queries
 * @param {string|null} mcpOptions.sqlUrl - Custom SQL URL for database queries
 * @returns {Promise<{analysis: string, costUsd: number|null, usage: object|null, claudeSessionId: string|null}>}
 */
async function executeWithModel(modelIndex, prompt, claudeSessionId, mcpConfigPath, mcpOptions = {}) {
  const model = MODELS[modelIndex]
  const baseConfigPath = mcpConfigPath || MCP_CONFIG_DEFAULT

  // Generate dynamic MCP config with environment variables injected
  // This is necessary because MCP server env comes from the config file, not the CLI's env
  const configPath = generateMcpConfig(baseConfigPath, mcpOptions)

  try {
    const claudeArgs = [
      '--mcp-config', configPath,
      '--dangerously-skip-permissions',
      '--output-format', 'json',
      '--model', model.id,
    ]

    // Use Claude CLI's actual session ID for conversation continuity
    // This is the session_id returned in Claude CLI's JSON output, NOT our generated UUIDs
    if (claudeSessionId) {
      claudeArgs.push('--resume', claudeSessionId)
    }

    claudeArgs.push('--print')

    // Escape the prompt for shell
    const escapedPrompt = prompt.replace(/'/g, "'\\''")
    // Timeout increased to 300s (5 min) to allow for Privacy Review approval flow
    // which requires multiple human approvals for DB queries and log queries
    // Use script -qc to allocate a PTY - Claude CLI requires a terminal to output in --print mode
    const claudeCommand = `cd /app/codebase && timeout 300 claude ${claudeArgs.join(' ')} '${escapedPrompt}'`
    const command = `script -qc "${claudeCommand.replace(/"/g, '\\"')}" /dev/null`

    console.log(`[ClaudeRunner] Executing with ${model.id}...`)
    if (mcpOptions.frontendSessionId) {
      console.log(`[ClaudeRunner]   Frontend session: ${mcpOptions.frontendSessionId}`)
    }
    const startTime = Date.now()

    // Use async exec to avoid blocking event loop (keeps health checks working)
    // Need to pass environment and HOME directory for Claude CLI
    const { stdout, stderr } = await execAsync(command, {
      timeout: 310000, // 310s to allow for 300s CLI timeout plus buffer
      maxBuffer: 10 * 1024 * 1024,
      env: { ...process.env, HOME: '/home/claude' },
      cwd: '/app/codebase',
    })

    // Log any stderr output for debugging
    if (stderr) {
      console.log(`[ClaudeRunner] stderr: ${stderr.substring(0, 500)}`)
    }

    // Strip ANSI escape sequences added by the script -qc PTY wrapper
    // These sequences break JSON parsing: [?1004l, [?2004l, [?25h, etc.
    let cleanOutput = stdout
      .replace(/\x1b\[[0-9;]*[a-zA-Z]/g, '') // Standard ANSI sequences
      .replace(/\x1b\[\?[0-9;]*[a-zA-Z]/g, '') // Private mode sequences like [?1004l
      .replace(/[\x00-\x08\x0b\x0c\x0e-\x1f]/g, '') // Control chars except \t \n \r
      .trim()

    // Extract just the JSON object - find first { and last }
    // This handles any trailing junk from PTY wrapper (e.g., "[<u[?1004l...")
    const firstBrace = cleanOutput.indexOf('{')
    const lastBrace = cleanOutput.lastIndexOf('}')
    if (firstBrace !== -1 && lastBrace > firstBrace) {
      cleanOutput = cleanOutput.substring(firstBrace, lastBrace + 1)
    }

    const elapsed = Date.now() - startTime
    console.log(`[ClaudeRunner] ${model.id} responded in ${elapsed}ms`)
    console.log(`[ClaudeRunner] Output length: ${cleanOutput.length} chars`)

    // Parse JSON output
    let analysis = cleanOutput
    let costUsd = null
    let usage = null
    let returnedSessionId = null

    try {
      const jsonResult = JSON.parse(cleanOutput)
      analysis = jsonResult.result || cleanOutput
      costUsd = jsonResult.total_cost_usd || null
      // Capture Claude CLI's session_id for conversation continuity
      // This is the key to maintaining real conversation context across queries
      returnedSessionId = jsonResult.session_id || null
      usage = {
        inputTokens: jsonResult.usage?.input_tokens,
        outputTokens: jsonResult.usage?.output_tokens,
        cacheReadTokens: jsonResult.usage?.cache_read_input_tokens,
        cacheCreationTokens: jsonResult.usage?.cache_creation_input_tokens,
      }
      if (returnedSessionId) {
        console.log(`[ClaudeRunner] Claude session ID: ${returnedSessionId}`)
      }
    } catch {
      // Non-JSON output, use as text
    }

    return { analysis, costUsd, usage, claudeSessionId: returnedSessionId }
  } finally {
    // Clean up temporary MCP config file
    cleanupMcpConfig(configPath)
  }
}

/**
 * Run a query with automatic model escalation.
 * IMPORTANT: Always starts with Haiku (cheapest) for each query.
 * Does NOT persist model choice across queries - each query starts fresh.
 *
 * @param {string} query - The user's question
 * @param {number} userId - User being investigated (0 for general queries) - DEPRECATED
 * @param {string} claudeSessionId - Claude CLI's session ID for conversation continuity (null for new session)
 * @param {object} options - Additional options
 * @param {boolean} options.requireApproval - If true, MCP queries require human approval
 * @param {string} options.frontendSessionId - Frontend session ID for MCP query filtering
 * @param {string} options.lokiUrl - Custom Loki URL for log queries (for SSH tunnels to live servers)
 * @param {string} options.sqlUrl - Custom SQL URL for database queries (for SSH tunnels to live servers)
 * @returns {Promise<{analysis: string, model: string, escalated: boolean, costUsd: number, usage: object, claudeSessionId: string|null}>}
 */
async function runQuery(query, userId = 0, claudeSessionId = null, options = {}) {
  const { requireApproval = false, frontendSessionId = null, lokiUrl = null, sqlUrl = null } = options
  const mcpConfigPath = requireApproval ? MCP_CONFIG_APPROVAL : MCP_CONFIG_DEFAULT

  // Build MCP options object for dynamic config generation
  const mcpOptions = { frontendSessionId, lokiUrl, sqlUrl }

  // PRIVACY FIX: Do NOT add user context here - it contains real PII!
  // The frontend now includes user context in the query BEFORE sanitization,
  // so the query already contains "Investigating Freegle user {pseudonymized_id}..."
  // Adding user context here with the real userId would leak PII to Claude.
  //
  // The userId parameter is now deprecated and should not be used.
  // It's kept for backwards compatibility but ignored.
  if (userId) {
    console.warn('[ClaudeRunner] WARNING: userId parameter is deprecated and ignored. User context should be in the query text (pseudonymized).')
  }

  const fullPrompt = query

  // ALWAYS start with Haiku (cheapest) - reset for each query
  // Model escalation only happens within a single query, not across queries
  let modelIndex = 0
  let lastResponse = null
  let lastClaudeSessionId = null
  let totalCost = 0
  let totalUsage = { inputTokens: 0, outputTokens: 0 }
  let escalationHistory = []

  while (modelIndex < MODELS.length) {
    const model = MODELS[modelIndex]
    console.log(`[ClaudeRunner] Trying ${model.id} for query: ${query.substring(0, 50)}...`)

    try {
      // Pass Claude's session ID for conversation continuity
      const result = await executeWithModel(modelIndex, fullPrompt, claudeSessionId, mcpConfigPath, mcpOptions)

      // Track costs
      if (result.costUsd) {
        totalCost += result.costUsd
      }
      if (result.usage) {
        totalUsage.inputTokens += result.usage.inputTokens || 0
        totalUsage.outputTokens += result.usage.outputTokens || 0
      }

      lastResponse = result.analysis
      // Capture Claude's session ID from the response
      lastClaudeSessionId = result.claudeSessionId || claudeSessionId

      // Check if we need to escalate
      if (modelIndex < MODELS.length - 1 && needsEscalation(lastResponse)) {
        console.log(`[ClaudeRunner] ${model.id} response needs escalation`)
        escalationHistory.push({
          model: model.id,
          response: lastResponse.substring(0, 200),
          reason: 'Insufficient answer detected',
        })
        modelIndex++
        // Note: When escalating, we continue the same Claude session
        claudeSessionId = lastClaudeSessionId
        continue
      }

      // Good response - return it with Claude's session ID
      return {
        analysis: lastResponse,
        model: model.id,
        escalated: escalationHistory.length > 0,
        escalationHistory: escalationHistory.length > 0 ? escalationHistory : undefined,
        costUsd: totalCost,
        usage: totalUsage,
        claudeSessionId: lastClaudeSessionId,
      }

    } catch (error) {
      console.error(`[ClaudeRunner] ${model.id} error:`, error.message)

      // On error, try escalating to next model
      if (modelIndex < MODELS.length - 1) {
        escalationHistory.push({
          model: model.id,
          reason: `Error: ${error.message}`,
        })
        modelIndex++
        continue
      }

      throw error
    }
  }

  // Used all models, return last response
  return {
    analysis: lastResponse || 'Unable to analyze the query.',
    model: MODELS[MODELS.length - 1].id,
    escalated: true,
    escalationHistory,
    costUsd: totalCost,
    usage: totalUsage,
    claudeSessionId: lastClaudeSessionId,
  }
}

/**
 * Get model information.
 */
function getModels() {
  return MODELS.map(m => ({
    id: m.id,
    name: m.name,
    inputCostPerMTok: m.inputCost,
    outputCostPerMTok: m.outputCost,
  }))
}

module.exports = { runQuery, getModels, MODELS, ESCALATION_TRIGGERS }
