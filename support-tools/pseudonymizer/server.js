/**
 * Pseudonymizer Service (Container 3)
 *
 * Core service for privacy-preserving log analysis:
 * - Receives mappings from Query Sanitizer (Container 0)
 * - Translates pseudonymized queries to real values for Loki
 * - Pseudonymizes Loki results before returning to MCP Interface
 * - Maintains audit log of all queries
 *
 * Security: This container has access to the key (mapping) and Loki.
 * It is the only container that can see real PII.
 */

const express = require('express')
const { v4: uuidv4 } = require('uuid')
const Database = require('better-sqlite3')
const fs = require('fs')
const path = require('path')

const app = express()
app.use(express.json())

const LOKI_URL = process.env.LOKI_URL || 'http://loki:3100'
const DATA_DIR = process.env.DATA_DIR || '/data'
const AUDIT_LOG_DIR = process.env.AUDIT_LOG_DIR || '/var/log/mcp-audit'

// Ensure directories exist
fs.mkdirSync(DATA_DIR, { recursive: true })
fs.mkdirSync(AUDIT_LOG_DIR, { recursive: true })

// SQLite database for persistent token mappings
const db = new Database(path.join(DATA_DIR, 'mappings.db'))

// Initialize database schema
db.exec(`
  CREATE TABLE IF NOT EXISTS token_mappings (
    token TEXT PRIMARY KEY,
    real_value TEXT NOT NULL,
    field_type TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  );

  CREATE INDEX IF NOT EXISTS idx_real_value ON token_mappings(real_value);

  CREATE TABLE IF NOT EXISTS session_mappings (
    session_id TEXT NOT NULL,
    token TEXT NOT NULL,
    user_id INTEGER,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    expires_at TEXT,
    PRIMARY KEY (session_id, token)
  );

  CREATE INDEX IF NOT EXISTS idx_session_expires ON session_mappings(expires_at);
`)

// Prepared statements for performance
const stmts = {
  getToken: db.prepare('SELECT token FROM token_mappings WHERE real_value = ? COLLATE NOCASE'),
  insertToken: db.prepare('INSERT OR IGNORE INTO token_mappings (token, real_value, field_type) VALUES (?, ?, ?)'),
  getRealValue: db.prepare('SELECT real_value FROM token_mappings WHERE token = ?'),
  insertSession: db.prepare('INSERT OR REPLACE INTO session_mappings (session_id, token, user_id, expires_at) VALUES (?, ?, ?, ?)'),
  getSessionTokens: db.prepare('SELECT token FROM session_mappings WHERE session_id = ?'),
  cleanExpiredSessions: db.prepare("DELETE FROM session_mappings WHERE expires_at < datetime('now')"),
}

// Clean expired sessions periodically
setInterval(() => {
  stmts.cleanExpiredSessions.run()
}, 60000)

// Counter for generating unique numeric IDs
let numericIdCounter = 9999000000

// Common email domains to preserve
const COMMON_EMAIL_DOMAINS = [
  'gmail.com', 'googlemail.com',
  'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
  'yahoo.com', 'yahoo.co.uk',
  'icloud.com', 'me.com', 'mac.com',
  'aol.com',
  'protonmail.com', 'proton.me',
  'btinternet.com', 'btopenworld.com',
  'sky.com',
  'virginmedia.com',
  'talktalk.net',
  'ntlworld.com',
]

/**
 * Extract and normalize email domain for pseudonymization.
 * Common providers keep their domain, others become 'other.com'
 */
function extractEmailDomain(email) {
  const domain = email.split('@')[1]?.toLowerCase()
  if (!domain) return 'other.com'

  if (COMMON_EMAIL_DOMAINS.includes(domain)) {
    return domain
  }
  return 'other.com'
}

/**
 * Get or create a persistent token for a value.
 * Same value always maps to same token for cross-session correlation.
 * Tokens maintain the same type as the original value.
 */
function getOrCreateToken(value, fieldType) {
  const normalizedValue = value.toLowerCase().trim()

  // Check if token already exists
  const existing = stmts.getToken.get(normalizedValue)
  if (existing) {
    return existing.token
  }

  // Create new token that maintains the same type as original
  let token
  const shortId = uuidv4().slice(0, 6)

  switch (fieldType) {
    case 'USER':
      // Keep user IDs numeric
      numericIdCounter++
      token = numericIdCounter.toString()
      break
    case 'EMAIL':
      // Make it look like an email, preserving the domain type
      const emailDomain = extractEmailDomain(value)
      token = `user_${shortId}@${emailDomain}`
      break
    case 'IP':
      // Make it look like an internal IP
      token = `10.0.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}`
      break
    case 'PHONE':
      // Make it look like a phone number
      token = `07700${Math.floor(100000 + Math.random() * 900000)}`
      break
    case 'POSTCODE':
      // Keep postcode format
      token = `ZZ${shortId.slice(0, 2).toUpperCase()} 9ZZ`
      break
    default:
      // Default format with prefix
      token = `${fieldType}_${shortId}`
  }

  stmts.insertToken.run(token, normalizedValue, fieldType)
  return token
}

/**
 * Get real value for a token.
 */
function getRealValue(token) {
  const row = stmts.getRealValue.get(token)
  return row ? row.real_value : null
}

/**
 * Escape special regex characters.
 */
function escapeRegex(string) {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * PII detection patterns.
 */
const PII_PATTERNS = {
  // Email addresses
  email: /[\w.-]+@[\w.-]+\.\w+/gi,

  // IP addresses (v4)
  ip: /\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/g,

  // UK phone numbers
  phone: /\b(?:\+44|0)\s*\d{2,4}\s*\d{3,4}\s*\d{3,4}\b/g,

  // UK postcodes
  postcode: /\b[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}\b/gi,

  // User IDs (6+ digit numbers)
  userId: /\b\d{6,}\b/g,
}

/**
 * Pseudonymize text by replacing PII with tokens.
 */
function pseudonymizeText(text) {
  if (!text || typeof text !== 'string') {
    return text
  }

  let result = text
  const tokensUsed = new Set()

  // Replace emails
  const emails = result.match(PII_PATTERNS.email) || []
  for (const email of emails) {
    const token = getOrCreateToken(email, 'EMAIL')
    tokensUsed.add(token)
    result = result.replace(new RegExp(escapeRegex(email), 'gi'), token)
  }

  // Replace IPs
  const ips = result.match(PII_PATTERNS.ip) || []
  for (const ip of ips) {
    const token = getOrCreateToken(ip, 'IP')
    tokensUsed.add(token)
    result = result.replace(new RegExp(escapeRegex(ip), 'g'), token)
  }

  // Replace phone numbers
  const phones = result.match(PII_PATTERNS.phone) || []
  for (const phone of phones) {
    const token = getOrCreateToken(phone, 'PHONE')
    tokensUsed.add(token)
    result = result.replace(new RegExp(escapeRegex(phone), 'g'), token)
  }

  // Replace postcodes
  const postcodes = result.match(PII_PATTERNS.postcode) || []
  for (const postcode of postcodes) {
    const token = getOrCreateToken(postcode, 'POSTCODE')
    tokensUsed.add(token)
    result = result.replace(new RegExp(escapeRegex(postcode), 'gi'), token)
  }

  // Replace user IDs (6+ digit numbers) - do this AFTER other patterns
  // to avoid replacing parts of emails, IPs, etc.
  const userIds = result.match(PII_PATTERNS.userId) || []
  for (const userId of userIds) {
    // Skip if it looks like part of an IP (already replaced) or timestamp
    if (userId.length > 13) continue // Skip timestamps (13+ digits)
    const token = getOrCreateToken(userId, 'USER')
    tokensUsed.add(token)
    result = result.replace(new RegExp(`\\b${escapeRegex(userId)}\\b`, 'g'), token)
  }

  // Round coordinates to ~1km precision for privacy
  result = result.replace(/"lat":\s*([\d.-]+)/g, (match, lat) => {
    return `"lat": ${parseFloat(lat).toFixed(2)}`
  })
  result = result.replace(/"lng":\s*([\d.-]+)/g, (match, lng) => {
    return `"lng": ${parseFloat(lng).toFixed(2)}`
  })

  return { text: result, tokensUsed: [...tokensUsed] }
}

/**
 * Translate a pseudonymized query to real values for Loki.
 */
function translateQuery(query, sessionId) {
  if (!query) return query

  // Find all tokens in the query
  const tokenPattern = /(?:EMAIL|IP|PHONE|POSTCODE|NAME|USER)_[a-f0-9]{8}/g
  const tokens = query.match(tokenPattern) || []

  let result = query
  for (const token of tokens) {
    const realValue = getRealValue(token)
    if (realValue) {
      result = result.replace(new RegExp(token, 'g'), realValue)
    }
  }

  return result
}

/**
 * Write audit log entry.
 */
function writeAuditLog(entry) {
  const dateStr = new Date().toISOString().slice(0, 10)
  const logFile = path.join(AUDIT_LOG_DIR, `${dateStr}.jsonl`)

  const logEntry = {
    timestamp: new Date().toISOString(),
    ...entry,
  }

  fs.appendFileSync(logFile, JSON.stringify(logEntry) + '\n')
}

// Health check
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    service: 'pseudonymizer',
    lokiUrl: LOKI_URL,
  })
})

/**
 * Register mapping from Query Sanitizer (Container 0).
 * POST /register-mapping
 * Body: { sessionId, mapping, userId }
 */
app.post('/register-mapping', (req, res) => {
  const { sessionId, mapping, userId } = req.body

  if (!sessionId || !mapping) {
    return res.status(400).json({ error: 'sessionId and mapping required' })
  }

  // Session expires in 1 hour
  const expiresAt = new Date(Date.now() + 60 * 60 * 1000).toISOString()

  // Register each token in the mapping
  for (const [token, realValue] of Object.entries(mapping)) {
    // Ensure the token exists in persistent storage
    const fieldType = token.split('_')[0]
    stmts.insertToken.run(token, realValue.toLowerCase().trim(), fieldType)

    // Register session mapping
    stmts.insertSession.run(sessionId, token, userId || null, expiresAt)
  }

  console.log(`[${sessionId}] Registered ${Object.keys(mapping).length} mappings`)

  res.json({ status: 'ok' })
})

/**
 * Query Loki and pseudonymize results.
 * POST /query
 * Body: { sessionId, query, start, end, limit }
 */
app.post('/query', async (req, res) => {
  const { sessionId, query, start = '1h', end, limit = 100 } = req.body

  if (!sessionId || !query) {
    return res.status(400).json({ error: 'sessionId and query required' })
  }

  const startTime = Date.now()

  try {
    // Translate pseudonymized query to real values
    const realQuery = translateQuery(query, sessionId)

    // Build Loki query parameters
    const params = new URLSearchParams({
      query: realQuery,
      limit: limit.toString(),
    })

    // Handle time range
    if (start) {
      // Relative time like "1h" or ISO timestamp
      if (/^\d+[smhdw]$/.test(start)) {
        const now = Date.now()
        const duration = parseDuration(start)
        params.set('start', ((now - duration) * 1000000).toString()) // nanoseconds
      } else {
        params.set('start', (new Date(start).getTime() * 1000000).toString())
      }
    }

    if (end) {
      params.set('end', (new Date(end).getTime() * 1000000).toString())
    }

    // Query Loki
    const lokiResponse = await fetch(`${LOKI_URL}/loki/api/v1/query_range?${params}`)

    if (!lokiResponse.ok) {
      throw new Error(`Loki error: ${lokiResponse.status} ${await lokiResponse.text()}`)
    }

    const data = await lokiResponse.json()
    const allTokensUsed = new Set()

    // Pseudonymize results
    if (data.data?.result) {
      for (const stream of data.data.result) {
        if (stream.values) {
          stream.values = stream.values.map(([ts, line]) => {
            const { text, tokensUsed } = pseudonymizeText(line)
            tokensUsed.forEach((t) => allTokensUsed.add(t))
            return [ts, text]
          })
        }
      }
    }

    const durationMs = Date.now() - startTime

    // Audit log
    writeAuditLog({
      sessionId,
      operation: 'loki_query',
      request: { query, start, end, limit },
      lokiQuery: {
        query: realQuery,
        resultCount: data.data?.result?.length || 0,
      },
      response: {
        pseudonymizedEntries: data.data?.result?.reduce((sum, s) => sum + (s.values?.length || 0), 0) || 0,
        tokensUsed: [...allTokensUsed],
      },
      durationMs,
    })

    console.log(`[${sessionId}] Query completed in ${durationMs}ms, ${allTokensUsed.size} tokens used`)

    res.json(data)
  } catch (error) {
    console.error(`[${sessionId}] Query error:`, error.message)

    writeAuditLog({
      sessionId,
      operation: 'loki_query',
      request: { query, start, end, limit },
      error: error.message,
      durationMs: Date.now() - startTime,
    })

    res.status(500).json({ error: error.message })
  }
})

/**
 * Get the reverse mapping for frontend de-tokenization.
 * GET /mapping/:sessionId
 */
app.get('/mapping/:sessionId', (req, res) => {
  const { sessionId } = req.params

  const tokens = stmts.getSessionTokens.all(sessionId)
  const mapping = {}

  for (const { token } of tokens) {
    const realValue = getRealValue(token)
    if (realValue) {
      mapping[token] = realValue
    }
  }

  res.json({ mapping })
})

/**
 * Parse duration string to milliseconds.
 */
function parseDuration(str) {
  const match = str.match(/^(\d+)([smhdw])$/)
  if (!match) return 0

  const value = parseInt(match[1], 10)
  const unit = match[2]

  const multipliers = {
    s: 1000,
    m: 60 * 1000,
    h: 60 * 60 * 1000,
    d: 24 * 60 * 60 * 1000,
    w: 7 * 24 * 60 * 60 * 1000,
  }

  return value * (multipliers[unit] || 0)
}

const PORT = process.env.PORT || 8080
app.listen(PORT, () => {
  console.log(`Pseudonymizer service listening on port ${PORT}`)
  console.log(`Loki URL: ${LOKI_URL}`)
  console.log(`Data directory: ${DATA_DIR}`)
  console.log(`Audit log directory: ${AUDIT_LOG_DIR}`)
})

module.exports = { app, getOrCreateToken, getRealValue, pseudonymizeText, translateQuery }
