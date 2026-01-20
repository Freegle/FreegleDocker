/**
 * Freegle Claude Agent Server
 *
 * HTTP server that bridges ModTools browser with Claude Agent SDK.
 * Uses HTTP polling for compatibility (not WebSocket).
 * The agent has codebase access for system knowledge, but requests
 * fact queries from the browser to get user data (privacy-preserving).
 */

const express = require('express')
const cors = require('cors')
const { v4: uuidv4 } = require('uuid')
const { execSync } = require('child_process')
const fs = require('fs')
const Anthropic = require('@anthropic-ai/sdk')
const { runAgent, warmupSession } = require('./agent')

// Initialize Anthropic client - uses ANTHROPIC_API_KEY env var
let anthropic = null

function getClient() {
  if (!anthropic) {
    anthropic = new Anthropic()
  }
  return anthropic
}

const app = express()
app.use(express.json())
app.use(cors({
  origin: true,
  credentials: true,
  methods: ['GET', 'POST', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Authorization', 'sentry-trace', 'baggage'],
}))

// Track active sessions
const sessions = new Map()

// Session timeout (5 minutes)
const SESSION_TIMEOUT = 5 * 60 * 1000

// Track auth and codebase status
let authStatus = { valid: false, checked: false, message: 'Not checked yet' }
let lastCodeUpdate = null

/**
 * PII detection patterns - used to scan Claude responses for leaks
 * If real PII appears in output, it means our pseudonymization failed
 */
const PII_PATTERNS = {
  // Real email addresses (not our pseudonymized format)
  realEmail: /(?<![a-z0-9_])[\w.-]+@(?!(?:gmail|outlook|yahoo|other)\.com)[a-z0-9.-]+\.[a-z]{2,}/gi,

  // Real IP addresses (not our 10.0.x.x format)
  realIp: /(?<!10\.0\.)\b(?:(?:25[0-5]|2[0-4]\d|1?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|1?\d\d?)\b/g,

  // UK phone numbers
  ukPhone: /\b0\d{2,4}[\s-]?\d{3}[\s-]?\d{3,4}\b/g,

  // UK postcodes (not our ZZ format)
  ukPostcode: /\b(?!ZZ)[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}\b/gi,
}

/**
 * Scan text for potential PII leaks
 * Returns list of detected patterns with samples for user investigation
 * NOTE: The actual matches ARE returned to the user (for transparency)
 * but NOT logged to console (which could be sent to external logging)
 */
function scanForPII(text) {
  const findings = []

  for (const [type, pattern] of Object.entries(PII_PATTERNS)) {
    const matches = text.match(pattern)
    if (matches && matches.length > 0) {
      findings.push({
        type,
        count: matches.length,
        // Include actual matches for user to investigate (up to 5)
        // These are shown to the human user, NOT to Claude
        samples: [...new Set(matches)].slice(0, 5),
      })
    }
  }

  return findings
}

/**
 * Log PII leak alert (without exposing the actual PII)
 */
function logPIIAlert(sessionId, findings) {
  console.error(`[${sessionId}] ⚠️ POTENTIAL PII LEAK DETECTED:`)
  for (const finding of findings) {
    console.error(`  - ${finding.type}: ${finding.count} occurrence(s)`)
  }
  console.error(`  This indicates a bug in pseudonymization. Investigate immediately.`)
}

/**
 * Check if Anthropic API key is configured.
 */
function checkAuth() {
  if (!process.env.ANTHROPIC_API_KEY) {
    authStatus = {
      valid: false,
      checked: true,
      message: 'ANTHROPIC_API_KEY not set. Add it to your .env file.',
    }
    return
  }

  authStatus = {
    valid: true,
    checked: true,
    message: 'Anthropic API key configured.',
  }
}

/**
 * Update codebase from git.
 */
function updateCodebase() {
  const repos = [
    '/app/codebase/iznik-nuxt3',
    '/app/codebase/iznik-server',
    '/app/codebase/iznik-server-go',
  ]

  for (const repo of repos) {
    if (fs.existsSync(repo)) {
      try {
        execSync('git pull --ff-only 2>&1', { cwd: repo, timeout: 30000 })
        console.log(`Updated codebase: ${repo}`)
      } catch (error) {
        console.error(`Failed to update ${repo}:`, error.message)
      }
    }
  }

  lastCodeUpdate = new Date().toISOString()
  console.log(`Codebase update completed at ${lastCodeUpdate}`)
}

// Check auth on startup
checkAuth()
console.log(`Auth status: ${authStatus.message}`)

// Update codebase on startup
updateCodebase()

// Update codebase every 30 minutes
setInterval(updateCodebase, 30 * 60 * 1000)

// Clean up old sessions periodically
setInterval(() => {
  const now = Date.now()
  for (const [sessionId, session] of sessions) {
    if (now - session.lastActivity > SESSION_TIMEOUT) {
      console.log(`Cleaning up expired session: ${sessionId}`)
      session.stopped = true
      sessions.delete(sessionId)
    }
  }
}, 60000)

// Health check endpoint with auth status
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    service: 'freegle-ai-support-helper',
    auth: authStatus,
    lastCodeUpdate,
  })
})

/**
 * Start a new conversation session.
 * POST /api/ask
 * Body: { question: string, conversationHistory?: array }
 * Returns: { sessionId: string }
 */
app.post('/api/ask', async (req, res) => {
  // Re-check auth before processing
  checkAuth()

  if (!authStatus.valid) {
    return res.status(503).json({
      error: 'AUTH_NOT_CONFIGURED',
      message: authStatus.message,
    })
  }

  const { question, conversationHistory } = req.body

  if (!question) {
    return res.status(400).json({ error: 'Question is required' })
  }

  const sessionId = uuidv4()
  console.log(`[${sessionId}] New question: ${question.substring(0, 50)}...`)

  // Create session
  const session = {
    id: sessionId,
    question,
    conversationHistory: conversationHistory || [],
    status: 'processing',
    events: [], // Events to send to client
    pendingQueries: new Map(), // queryId -> { resolve, reject }
    lastActivity: Date.now(),
    stopped: false,
    answer: null,
    suggestedQueries: [],
  }
  sessions.set(sessionId, session)

  // Return session ID immediately
  res.json({ sessionId })

  // Process question asynchronously
  processQuestion(session)
})

/**
 * Poll for events.
 * GET /api/poll/:sessionId
 * Returns: { events: array, status: string }
 */
app.get('/api/poll/:sessionId', (req, res) => {
  const { sessionId } = req.params
  const session = sessions.get(sessionId)

  if (!session) {
    return res.status(404).json({ error: 'Session not found' })
  }

  session.lastActivity = Date.now()

  // Return and clear pending events
  const events = [...session.events]
  session.events = []

  res.json({
    events,
    status: session.status,
    answer: session.answer,
    suggestedQueries: session.suggestedQueries,
  })
})

/**
 * Submit fact query response.
 * POST /api/fact-response/:sessionId
 * Body: { queryId: string, result?: any, error?: string }
 */
app.post('/api/fact-response/:sessionId', (req, res) => {
  const { sessionId } = req.params
  const { queryId, result, error } = req.body
  const session = sessions.get(sessionId)

  if (!session) {
    return res.status(404).json({ error: 'Session not found' })
  }

  session.lastActivity = Date.now()

  const pending = session.pendingQueries.get(queryId)
  if (pending) {
    session.pendingQueries.delete(queryId)
    if (error) {
      pending.reject(new Error(error))
    } else {
      pending.resolve(result)
    }
  }

  res.json({ ok: true })
})

/**
 * Stop processing.
 * POST /api/stop/:sessionId
 */
app.post('/api/stop/:sessionId', (req, res) => {
  const { sessionId } = req.params
  const session = sessions.get(sessionId)

  if (!session) {
    return res.status(404).json({ error: 'Session not found' })
  }

  session.stopped = true
  session.status = 'interrupted'

  // Reject all pending queries
  for (const [, pending] of session.pendingQueries) {
    pending.reject(new Error('Stopped by user'))
  }
  session.pendingQueries.clear()

  session.events.push({ type: 'stopped' })

  res.json({ ok: true })
})

/**
 * Process a question using the Claude Agent.
 */
async function processQuestion(session) {
  try {
    // Add thinking event
    session.events.push({
      type: 'thinking',
      content: 'Starting investigation...',
    })

    // Create a fact query function for this session
    const requestFactQuery = async (query, params) => {
      if (session.stopped) {
        throw new Error('Session stopped')
      }

      return new Promise((resolve, reject) => {
        const queryId = uuidv4()

        // Store the pending query
        session.pendingQueries.set(queryId, { resolve, reject })

        // Send fact query request to browser via events
        session.events.push({
          type: 'fact_query',
          queryId,
          query,
          params,
        })

        // Timeout after 30 seconds
        setTimeout(() => {
          if (session.pendingQueries.has(queryId)) {
            session.pendingQueries.delete(queryId)
            reject(new Error('Fact query timeout'))
          }
        }, 30000)
      })
    }

    // Run the agent with the question and conversation history
    const result = await runAgent(
      session.question,
      requestFactQuery,
      (thinking) => {
        if (!session.stopped) {
          session.events.push({
            type: 'thinking',
            content: thinking,
          })
        }
      },
      session.conversationHistory || []
    )

    if (!session.stopped) {
      session.answer = result.answer
      session.suggestedQueries = result.suggestedQueries || []
      session.status = 'complete'
      session.events.push({
        type: 'answer',
        content: result.answer,
      })
    }
  } catch (error) {
    console.error(`[${session.id}] Error processing question:`, error)

    // Check for auth errors
    if (
      error.message?.includes('authentication') ||
      error.message?.includes('token') ||
      error.message?.includes('401')
    ) {
      session.status = 'error'
      session.events.push({
        type: 'error',
        code: 'AUTH_EXPIRED',
        message: 'Claude authentication has expired',
      })
    } else if (!session.stopped) {
      session.status = 'error'
      session.events.push({
        type: 'error',
        code: 'AGENT_ERROR',
        message: error.message || 'Unknown error',
      })
    }
  }
}

/**
 * Log analysis endpoint using Claude Code CLI with MCP and codebase access.
 * POST /api/log-analysis
 * Body: {
 *   query: string,           // User's question
 *   userId: number,          // User being investigated
 *   claudeSessionId?: string // For conversation continuity (omit for new session)
 * }
 * Returns: { analysis: string, claudeSessionId: string, model: string, escalated: boolean }
 *
 * Uses model escalation: Haiku (cheap) -> Sonnet -> Opus (expensive)
 * Automatically escalates if the cheaper model can't answer adequately.
 */
app.post('/api/log-analysis', async (req, res) => {
  // Re-check auth before processing
  checkAuth()

  if (!authStatus.valid) {
    return res.status(503).json({
      error: 'AUTH_NOT_CONFIGURED',
      message: authStatus.message,
    })
  }

  const { query, userId, claudeSessionId, privacyReviewMode, frontendSessionId, lokiUrl, sqlUrl } = req.body

  if (!query) {
    return res.status(400).json({ error: 'query is required' })
  }

  // Track whether this is a new session for logging
  const isNewSession = !claudeSessionId
  const requestId = uuidv4().substring(0, 8) // Short ID for logging

  console.log(`[${requestId}] Log analysis request:`)
  console.log(`  User ID: ${userId}`)
  console.log(`  New session: ${isNewSession}`)
  console.log(`  Claude session: ${claudeSessionId || '(new)'}`)
  console.log(`  Privacy review mode: ${privacyReviewMode ? 'YES' : 'NO'}`)
  console.log(`  Frontend session: ${frontendSessionId || '(none)'}`)
  console.log(`  Loki URL: ${lokiUrl || '(default)'}`)
  console.log(`  SQL URL: ${sqlUrl || '(default)'}`)
  console.log(`  Query: ${query.substring(0, 100)}...`)

  try {
    // Use ClaudeRunner with model escalation
    const { runQuery } = require('./claude-runner')

    // Pass Claude CLI's session ID directly (null for new session)
    // Claude CLI returns its own session_id which we'll return to the frontend
    const result = await runQuery(query, userId, claudeSessionId || null, {
      requireApproval: !!privacyReviewMode,
      frontendSessionId: frontendSessionId || null,
      lokiUrl: lokiUrl || null,
      sqlUrl: sqlUrl || null,
    })

    // Use Claude CLI's returned session_id for conversation continuity
    const returnedSessionId = result.claudeSessionId

    console.log(`[${requestId}] Analysis complete:`)
    console.log(`  Model: ${result.model}`)
    console.log(`  Escalated: ${result.escalated}`)
    console.log(`  Claude session: ${returnedSessionId || '(not returned)'}`)
    console.log(`  Cost: $${result.costUsd?.toFixed(4) || 'unknown'}`)
    console.log(`  Response: ${result.analysis.length} chars`)

    // SECURITY: Scan output for potential PII leaks
    const piiFindings = scanForPII(result.analysis)
    if (piiFindings.length > 0) {
      logPIIAlert(requestId, piiFindings)
    }

    return res.json({
      analysis: result.analysis,
      // Return Claude CLI's actual session ID for conversation continuity
      claudeSessionId: returnedSessionId,
      isNewSession,
      model: result.model,
      escalated: result.escalated,
      escalationHistory: result.escalationHistory,
      costUsd: result.costUsd,
      usage: result.usage,
      piiScanResult: piiFindings.length > 0 ? {
        warning: 'Potential PII patterns detected in response - please investigate',
        findings: piiFindings,
      } : null,
    })
  } catch (error) {
    console.error(`[${requestId}] Log analysis error:`, error.message)

    // Check for timeout
    if (error.killed || error.signal === 'SIGTERM') {
      return res.status(504).json({
        error: 'TIMEOUT',
        message: 'Analysis took too long. Try a more specific question.',
        claudeSessionId: null,
      })
    }

    // Check for expired/missing session
    const errorOutput = error.stderr || error.message || ''
    if (errorOutput.includes('No conversation found with session ID')) {
      console.log(`[${requestId}] Session expired or not found, prompting new session`)
      return res.status(410).json({
        error: 'SESSION_EXPIRED',
        message: 'Your previous session has expired. Please start a new conversation.',
        claudeSessionId: null,
      })
    }

    return res.status(500).json({
      error: 'ANALYSIS_FAILED',
      message: error.message || 'Failed to analyze',
      claudeSessionId: null,
    })
  }
})

/**
 * Get available models and their costs.
 * GET /api/models
 */
app.get('/api/models', (req, res) => {
  const { getModels } = require('./claude-runner')
  res.json({ models: getModels() })
})

const PORT = process.env.PORT || 3000
app.listen(PORT, async () => {
  console.log(`Freegle Claude Agent server listening on port ${PORT}`)
  console.log(`Health check: http://localhost:${PORT}/health`)
  console.log(`API endpoints:`)
  console.log(`  POST /api/ask - Start new question`)
  console.log(`  GET /api/poll/:sessionId - Poll for events`)
  console.log(`  POST /api/fact-response/:sessionId - Submit fact query response`)
  console.log(`  POST /api/stop/:sessionId - Stop processing`)
  console.log(`  POST /api/log-analysis - Analyze logs for a user (MCP-based)`)

  // Warm up the session by reading codebase context
  if (authStatus.valid) {
    console.log('Starting session warmup...')
    await warmupSession((status) => console.log(status))
  }
})
