/**
 * AI Sanitizer Service - Combined service for AI Support
 *
 * This service combines:
 * - Query Sanitizer: PII detection and tokenization (/scan, /sanitize)
 * - Pseudonymizer: Token storage and Loki queries (/register-mapping, /query)
 * - Database queries: SQL validation and pseudonymization (/api/mcp/db-query)
 *
 * Security model:
 * - This container has access to real PII, Loki, and the database
 * - It is the only container that can see real PII
 * - All responses to clients are pseudonymized
 */

const express = require('express')
const cors = require('cors')
const { v4: uuidv4 } = require('uuid')
const Database = require('better-sqlite3')
const mysql = require('mysql2/promise')
const fs = require('fs')
const path = require('path')

const app = express()
app.use(cors({
  origin: true,
  credentials: true,
  methods: ['GET', 'POST', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Authorization', 'sentry-trace', 'baggage'],
}))
app.use(express.json())

// Configuration from environment
const LOKI_URL = process.env.LOKI_URL || 'http://loki:3100'
const DATA_DIR = process.env.DATA_DIR || '/data'
const AUDIT_LOG_DIR = process.env.AUDIT_LOG_DIR || '/var/log/mcp-audit'

// Database configuration
const DB_CONFIG = {
  host: process.env.DB_HOST || 'freegle-percona',
  port: parseInt(process.env.DB_PORT || '3306', 10),
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || 'iznik',
  database: process.env.DB_NAME || 'iznik',
  waitForConnections: true,
  connectionLimit: 5,
  maxIdle: 2,
  idleTimeout: 60000,
  queueLimit: 0,
  multipleStatements: false,
}

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

// MySQL connection pool (lazy initialized)
let mysqlPool = null

function getMysqlPool() {
  if (!mysqlPool) {
    mysqlPool = mysql.createPool(DB_CONFIG)
  }
  return mysqlPool
}

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

// PII detection patterns
const PII_PATTERNS = {
  email: /[\w.-]+@[\w.-]+\.\w+/gi,
  ip: /\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/g,
  phone: /\b(?:\+44|0)\s*\d{2,4}\s*\d{3,4}\s*\d{3,4}\b/g,
  postcode: /\b[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}\b/gi,
  userId: /\b\d{6,}\b/g,
}

// Email trail detection patterns
const MAIL_HEADER_PATTERNS = [
  /^From:.*@/im,
  /^To:.*@/im,
  /^Cc:.*@/im,
  /^Subject:/im,
  /^Date:.*\d{4}/im,
  /On .* wrote:/i,
  /From: .* <.*@.*>/i,
  /-{3,}.*Original Message/i,
  /Sent from my iPhone/i,
  /Sent from my Android/i,
]

// Patterns to detect already-pseudonymized tokens (to avoid double-tokenization)
const TOKEN_PATTERNS = {
  email: /^user_[a-f0-9]{6}@[\w.]+$/i,
  ip: /^10\.0\.\d{1,3}\.\d{1,3}$/,
  phone: /^07700\d{6}$/,
  postcode: /^ZZ[A-Z0-9]{2}\s*9ZZ$/i,
  userId: /^999\d{7}$/,
  name: /^(User|Person|Member|Freecycler|Helper|Volunteer)_[a-f0-9]{6}$/i,
}

/**
 * Check if a value is already a pseudonymized token.
 * This prevents double-tokenization when the sanitizer re-processes text.
 */
function isAlreadyPseudonymized(value, type = null) {
  if (!value || typeof value !== 'string') return false

  if (type) {
    const pattern = TOKEN_PATTERNS[type.toLowerCase()]
    return pattern ? pattern.test(value) : false
  }

  return Object.values(TOKEN_PATTERNS).some(pattern => pattern.test(value))
}

/**
 * Extract and normalize email domain for pseudonymization.
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
      numericIdCounter++
      token = numericIdCounter.toString()
      break
    case 'EMAIL':
      const emailDomain = extractEmailDomain(value)
      token = `user_${shortId}@${emailDomain}`
      break
    case 'IP':
      token = `10.0.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}`
      break
    case 'PHONE':
      token = `07700${Math.floor(100000 + Math.random() * 900000)}`
      break
    case 'POSTCODE':
      token = `ZZ${shortId.slice(0, 2).toUpperCase()} 9ZZ`
      break
    case 'NAME':
      const names = ['User', 'Person', 'Member', 'Freecycler', 'Helper', 'Volunteer']
      const namePick = names[Math.floor(Math.random() * names.length)]
      token = `${namePick}_${shortId}`
      break
    default:
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
 * Check if text contains email trail markers.
 */
function containsEmailTrail(text) {
  return MAIL_HEADER_PATTERNS.some((pattern) => pattern.test(text))
}

/**
 * Scan text for all types of PII.
 */
function scanForPii(text) {
  const detected = []

  const emails = text.match(PII_PATTERNS.email) || []
  for (const email of emails) {
    detected.push({ type: 'email', value: email, severity: 'high' })
  }

  const ips = text.match(PII_PATTERNS.ip) || []
  for (const ip of ips) {
    detected.push({ type: 'ip', value: ip, severity: 'medium' })
  }

  const phones = text.match(PII_PATTERNS.phone) || []
  for (const phone of phones) {
    detected.push({ type: 'phone', value: phone, severity: 'high' })
  }

  const postcodes = text.match(PII_PATTERNS.postcode) || []
  for (const postcode of postcodes) {
    detected.push({ type: 'postcode', value: postcode, severity: 'medium' })
  }

  return detected
}

/**
 * Extract and pseudonymize PII from query text.
 */
function extractAndPseudonymize(text, knownPii = {}) {
  const mapping = {}
  let result = text

  // Process known PII from user selection first
  if (knownPii.email) {
    const token = getOrCreateToken(knownPii.email, 'EMAIL')
    mapping[token] = knownPii.email
    result = result.replace(new RegExp(escapeRegex(knownPii.email), 'gi'), token)
  }

  if (knownPii.displayname) {
    const token = getOrCreateToken(knownPii.displayname, 'NAME')
    mapping[token] = knownPii.displayname
    result = result.replace(new RegExp(escapeRegex(knownPii.displayname), 'gi'), token)
  }

  if (knownPii.postcode) {
    const token = getOrCreateToken(knownPii.postcode, 'POSTCODE')
    mapping[token] = knownPii.postcode
    result = result.replace(new RegExp(escapeRegex(knownPii.postcode), 'gi'), token)
  }

  if (knownPii.location) {
    const token = getOrCreateToken(knownPii.location, 'LOCATION')
    mapping[token] = knownPii.location
    result = result.replace(new RegExp(escapeRegex(knownPii.location), 'gi'), token)
  }

  if (knownPii.userid) {
    const token = getOrCreateToken(knownPii.userid.toString(), 'USER')
    mapping[token] = knownPii.userid.toString()
    result = result.replace(new RegExp(`\\b${knownPii.userid}\\b`, 'g'), token)
  }

  // Scan for any other emails in the query (skip already-pseudonymized tokens)
  const emails = result.match(PII_PATTERNS.email) || []
  for (const email of emails) {
    if (isAlreadyPseudonymized(email, 'email')) continue
    const token = getOrCreateToken(email, 'EMAIL')
    mapping[token] = email
    result = result.replace(new RegExp(escapeRegex(email), 'gi'), token)
  }

  // Scan for IP addresses (skip already-pseudonymized tokens)
  const ips = result.match(PII_PATTERNS.ip) || []
  for (const ip of ips) {
    if (isAlreadyPseudonymized(ip, 'ip')) continue
    const token = getOrCreateToken(ip, 'IP')
    mapping[token] = ip
    result = result.replace(new RegExp(escapeRegex(ip), 'g'), token)
  }

  // Scan for phone numbers (skip already-pseudonymized tokens)
  const phones = result.match(PII_PATTERNS.phone) || []
  for (const phone of phones) {
    if (isAlreadyPseudonymized(phone, 'phone')) continue
    const token = getOrCreateToken(phone, 'PHONE')
    mapping[token] = phone
    result = result.replace(new RegExp(escapeRegex(phone), 'g'), token)
  }

  // Scan for postcodes (skip already-pseudonymized tokens)
  const postcodes = result.match(PII_PATTERNS.postcode) || []
  for (const postcode of postcodes) {
    if (isAlreadyPseudonymized(postcode, 'postcode')) continue
    const token = getOrCreateToken(postcode, 'POSTCODE')
    mapping[token] = postcode
    result = result.replace(new RegExp(escapeRegex(postcode), 'gi'), token)
  }

  return { pseudonymizedText: result, mapping }
}

/**
 * Pseudonymize text by replacing PII with tokens (for Loki results).
 */
function pseudonymizeText(text) {
  if (!text || typeof text !== 'string') {
    return { text, tokensUsed: [] }
  }

  let result = text
  const tokensUsed = new Set()

  // Replace emails (skip already-pseudonymized tokens)
  const emails = result.match(PII_PATTERNS.email) || []
  for (const email of emails) {
    if (isAlreadyPseudonymized(email, 'email')) continue
    const token = getOrCreateToken(email, 'EMAIL')
    tokensUsed.add(token)
    result = result.replace(new RegExp(escapeRegex(email), 'gi'), token)
  }

  // Replace IPs (skip already-pseudonymized tokens)
  const ips = result.match(PII_PATTERNS.ip) || []
  for (const ip of ips) {
    if (isAlreadyPseudonymized(ip, 'ip')) continue
    const token = getOrCreateToken(ip, 'IP')
    tokensUsed.add(token)
    result = result.replace(new RegExp(escapeRegex(ip), 'g'), token)
  }

  // Replace phone numbers (skip already-pseudonymized tokens)
  const phones = result.match(PII_PATTERNS.phone) || []
  for (const phone of phones) {
    if (isAlreadyPseudonymized(phone, 'phone')) continue
    const token = getOrCreateToken(phone, 'PHONE')
    tokensUsed.add(token)
    result = result.replace(new RegExp(escapeRegex(phone), 'g'), token)
  }

  // Replace postcodes (skip already-pseudonymized tokens)
  const postcodes = result.match(PII_PATTERNS.postcode) || []
  for (const postcode of postcodes) {
    if (isAlreadyPseudonymized(postcode, 'postcode')) continue
    const token = getOrCreateToken(postcode, 'POSTCODE')
    tokensUsed.add(token)
    result = result.replace(new RegExp(escapeRegex(postcode), 'gi'), token)
  }

  // Replace user IDs (6+ digit numbers, skip already-pseudonymized tokens)
  const userIds = result.match(PII_PATTERNS.userId) || []
  for (const userId of userIds) {
    if (userId.length > 13) continue // Skip timestamps
    if (isAlreadyPseudonymized(userId, 'userid')) continue
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
function translateQuery(query) {
  if (!query) return query

  // Find all tokens in the query (various formats)
  const tokenPatterns = [
    /(?:EMAIL|IP|PHONE|POSTCODE|NAME|USER|LOCATION)_[a-f0-9]{6}/gi,
    /user_[a-f0-9]{6}@[\w.-]+/gi,
    /10\.0\.\d{1,3}\.\d{1,3}/g,
    /07700\d{6}/g,
    /ZZ[A-Z]{2} 9ZZ/gi,
    /\b999900\d{4,}\b/g,
  ]

  let result = query
  for (const pattern of tokenPatterns) {
    const tokens = query.match(pattern) || []
    for (const token of tokens) {
      const realValue = getRealValue(token)
      if (realValue) {
        result = result.replace(new RegExp(escapeRegex(token), 'g'), realValue)
      }
    }
  }

  return result
}

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

// =============================================================================
// DATABASE SCHEMA AND VALIDATION (from status-nuxt)
// =============================================================================

const DB_SCHEMA = {
  users: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      firstname: 'SENSITIVE',
      lastname: 'SENSITIVE',
      fullname: 'SENSITIVE',
      systemrole: 'PUBLIC',
      added: 'PUBLIC',
      lastaccess: 'PUBLIC',
      bouncing: 'PUBLIC',
      deleted: 'PUBLIC',
      engagement: 'PUBLIC',
      trustlevel: 'PUBLIC',
      chatmodstatus: 'PUBLIC',
      newsfeedmodstatus: 'PUBLIC',
      lastupdated: 'PUBLIC',
    },
  },
  users_emails: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      email: 'SENSITIVE',
      preferred: 'PUBLIC',
      added: 'PUBLIC',
      validated: 'PUBLIC',
      bounced: 'PUBLIC',
      viewed: 'PUBLIC',
    },
  },
  messages: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      arrival: 'PUBLIC',
      date: 'PUBLIC',
      deleted: 'PUBLIC',
      source: 'PUBLIC',
      fromip: 'SENSITIVE',
      fromcountry: 'PUBLIC',
      fromuser: 'PUBLIC',
      fromname: 'SENSITIVE',
      fromaddr: 'SENSITIVE',
      subject: 'PUBLIC',
      suggestedsubject: 'PUBLIC',
      type: 'PUBLIC',
      lat: 'PUBLIC',
      lng: 'PUBLIC',
      locationid: 'PUBLIC',
      availableinitially: 'PUBLIC',
      availablenow: 'PUBLIC',
      spamtype: 'PUBLIC',
      spamreason: 'PUBLIC',
      heldby: 'PUBLIC',
      editedby: 'PUBLIC',
      editedat: 'PUBLIC',
    },
  },
  messages_groups: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      msgid: 'PUBLIC',
      groupid: 'PUBLIC',
      collection: 'PUBLIC',
      arrival: 'PUBLIC',
      autoreposts: 'PUBLIC',
      lastautopostwarning: 'PUBLIC',
      lastchaseup: 'PUBLIC',
      deleted: 'PUBLIC',
    },
  },
  messages_outcomes: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      msgid: 'PUBLIC',
      outcome: 'PUBLIC',
      timestamp: 'PUBLIC',
      userid: 'PUBLIC',
      happiness: 'PUBLIC',
      comments: 'SENSITIVE',
    },
  },
  chat_rooms: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      chattype: 'PUBLIC',
      user1: 'PUBLIC',
      user2: 'PUBLIC',
      groupid: 'PUBLIC',
      created: 'PUBLIC',
      lastmsg: 'PUBLIC',
      synctofacebook: 'PUBLIC',
    },
  },
  chat_messages: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      chatid: 'PUBLIC',
      userid: 'PUBLIC',
      type: 'PUBLIC',
      reportreason: 'PUBLIC',
      refmsgid: 'PUBLIC',
      refchatid: 'PUBLIC',
      imageid: 'PUBLIC',
      date: 'PUBLIC',
      message: 'SENSITIVE',
      platform: 'PUBLIC',
      seenbyall: 'PUBLIC',
      mailedtoall: 'PUBLIC',
      reviewrequired: 'PUBLIC',
      reviewedby: 'PUBLIC',
      reviewrejected: 'PUBLIC',
      deleted: 'PUBLIC',
    },
  },
  groups: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      nameshort: 'PUBLIC',
      namefull: 'PUBLIC',
      nameabbr: 'PUBLIC',
      type: 'PUBLIC',
      region: 'PUBLIC',
      lat: 'PUBLIC',
      lng: 'PUBLIC',
      membercount: 'PUBLIC',
      modcount: 'PUBLIC',
      tagline: 'PUBLIC',
      description: 'PUBLIC',
      founded: 'PUBLIC',
      publish: 'PUBLIC',
      listable: 'PUBLIC',
      onmap: 'PUBLIC',
      onhere: 'PUBLIC',
      contactmail: 'SENSITIVE',
      external: 'PUBLIC',
      lastmoderated: 'PUBLIC',
      lastmodactive: 'PUBLIC',
    },
  },
  memberships: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      groupid: 'PUBLIC',
      role: 'PUBLIC',
      collection: 'PUBLIC',
      configid: 'PUBLIC',
      added: 'PUBLIC',
      deleted: 'PUBLIC',
      emailfrequency: 'PUBLIC',
      eventsallowed: 'PUBLIC',
      volunteeringallowed: 'PUBLIC',
      ourpostingstatus: 'PUBLIC',
      heldby: 'PUBLIC',
    },
  },
  memberships_history: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      groupid: 'PUBLIC',
      collection: 'PUBLIC',
      added: 'PUBLIC',
    },
  },
  logs: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      timestamp: 'PUBLIC',
      byuser: 'PUBLIC',
      type: 'PUBLIC',
      subtype: 'PUBLIC',
      groupid: 'PUBLIC',
      user: 'PUBLIC',
      msgid: 'PUBLIC',
      configid: 'PUBLIC',
      bulkopid: 'PUBLIC',
      text: 'SENSITIVE',
    },
  },
  users_logins: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      type: 'PUBLIC',
      added: 'PUBLIC',
      lastaccess: 'PUBLIC',
    },
  },
  users_active: {
    allowed: true,
    fields: {
      userid: 'PUBLIC',
      timestamp: 'PUBLIC',
    },
  },
  bounces: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      date: 'PUBLIC',
      to: 'SENSITIVE',
      msg: 'SENSITIVE',
      permanent: 'PUBLIC',
    },
  },
  bounces_emails: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      emailid: 'PUBLIC',
      date: 'PUBLIC',
      reason: 'SENSITIVE',
      permanent: 'PUBLIC',
      reset: 'PUBLIC',
    },
  },
}

function getAllowedTables() {
  return Object.keys(DB_SCHEMA).filter((table) => DB_SCHEMA[table].allowed)
}

function getAllowedColumns(table) {
  const schema = DB_SCHEMA[table.toLowerCase()]
  if (!schema || !schema.allowed) {
    return []
  }
  return Object.keys(schema.fields)
}

function getColumnPrivacy(table, column) {
  const schema = DB_SCHEMA[table.toLowerCase()]
  if (!schema || !schema.allowed) {
    return null
  }
  return schema.fields[column.toLowerCase()] || null
}

function isTableAllowed(table) {
  const schema = DB_SCHEMA[table.toLowerCase()]
  return schema?.allowed ?? false
}

// Dangerous SQL keywords
const DANGEROUS_KEYWORDS = [
  'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE',
  'REPLACE', 'GRANT', 'REVOKE', 'CALL', 'EXEC', 'EXECUTE',
  'INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE', 'LOAD DATA',
]

const MAX_LIMIT = 500

/**
 * Validate a SQL query for safety and compliance with whitelist
 */
function validateSql(sql) {
  const normalizedSql = sql.trim()
  const upperSql = normalizedSql.toUpperCase()

  if (!upperSql.startsWith('SELECT')) {
    return { valid: false, error: 'Only SELECT queries are allowed' }
  }

  for (const keyword of DANGEROUS_KEYWORDS) {
    const regex = new RegExp(`\\b${keyword}\\b`, 'i')
    if (regex.test(normalizedSql)) {
      return { valid: false, error: `Forbidden keyword: ${keyword}` }
    }
  }

  const subqueryCount = (normalizedSql.match(/\bSELECT\b/gi) || []).length
  if (subqueryCount > 1) {
    return { valid: false, error: 'Subqueries are not supported' }
  }

  const { tables, aliasMap } = extractTables(normalizedSql)
  if (tables.length === 0) {
    return { valid: false, error: 'No tables found in query' }
  }

  for (const table of tables) {
    if (!isTableAllowed(table)) {
      const allowedList = getAllowedTables().join(', ')
      return {
        valid: false,
        error: `Table '${table}' is not allowed. Allowed tables: ${allowedList}`,
      }
    }
  }

  const columnsResult = extractColumns(normalizedSql, tables, aliasMap)
  if (!columnsResult.valid) {
    return columnsResult
  }

  for (const col of columnsResult.columns || []) {
    const privacy = getColumnPrivacy(col.table, col.column)
    if (!privacy) {
      const allowedCols = getAllowedColumns(col.table).join(', ')
      return {
        valid: false,
        error: `Column '${col.column}' is not allowed on table '${col.table}'. Allowed columns: ${allowedCols}`,
      }
    }
  }

  const limitMatch = upperSql.match(/LIMIT\s+(\d+)/i)
  let finalSql = normalizedSql
  if (!limitMatch) {
    finalSql = `${normalizedSql} LIMIT ${MAX_LIMIT}`
  } else {
    const requestedLimit = parseInt(limitMatch[1], 10)
    if (requestedLimit > MAX_LIMIT) {
      finalSql = normalizedSql.replace(/LIMIT\s+\d+/i, `LIMIT ${MAX_LIMIT}`)
    }
  }

  return {
    valid: true,
    tables,
    columns: columnsResult.columns,
    normalizedSql: finalSql,
  }
}

function extractTables(sql) {
  const tables = []
  const aliasMap = {} // Maps alias -> real table name

  // Match FROM table [AS] alias pattern
  const fromMatch = sql.match(/\bFROM\s+([a-z_][a-z0-9_]*)(?:\s+(?:AS\s+)?([a-z_][a-z0-9_]*))?/i)
  if (fromMatch) {
    const tableName = fromMatch[1].toLowerCase()
    tables.push(tableName)
    // If there's an alias (e.g., "FROM users u" or "FROM users AS u")
    if (fromMatch[2]) {
      const alias = fromMatch[2].toLowerCase()
      // Make sure it's not a SQL keyword
      if (!['inner', 'left', 'right', 'outer', 'cross', 'join', 'where', 'order', 'group', 'having', 'limit'].includes(alias)) {
        aliasMap[alias] = tableName
      }
    }
  }

  // Match JOIN table [AS] alias pattern
  const joinRegex = /\b(?:INNER\s+|LEFT\s+|RIGHT\s+|OUTER\s+|CROSS\s+)?JOIN\s+([a-z_][a-z0-9_]*)(?:\s+(?:AS\s+)?([a-z_][a-z0-9_]*))?/gi
  let match
  while ((match = joinRegex.exec(sql)) !== null) {
    const tableName = match[1].toLowerCase()
    if (!tables.includes(tableName)) {
      tables.push(tableName)
    }
    // If there's an alias
    if (match[2]) {
      const alias = match[2].toLowerCase()
      if (!['on', 'where', 'order', 'group', 'having', 'limit', 'inner', 'left', 'right', 'outer'].includes(alias)) {
        aliasMap[alias] = tableName
      }
    }
  }

  return { tables, aliasMap }
}

function extractColumns(sql, tables, aliasMap = {}) {
  const columns = []

  const selectMatch = sql.match(/SELECT\s+(.*?)\s+FROM/is)
  if (!selectMatch) {
    return { valid: false, error: 'Could not parse SELECT clause' }
  }

  const selectClause = selectMatch[1].trim()

  if (selectClause === '*') {
    for (const table of tables) {
      const allowedCols = getAllowedColumns(table)
      for (const col of allowedCols) {
        columns.push({ table, column: col })
      }
    }
    return { valid: true, columns }
  }

  if (/^[a-z_][a-z0-9_]*\.\*$/i.test(selectClause)) {
    let table = selectClause.replace('.*', '').toLowerCase()
    // Resolve alias to real table name
    table = aliasMap[table] || table
    if (!tables.includes(table)) {
      return { valid: false, error: `Table '${table}' not in FROM clause` }
    }
    const allowedCols = getAllowedColumns(table)
    for (const col of allowedCols) {
      columns.push({ table, column: col })
    }
    return { valid: true, columns }
  }

  const columnParts = splitColumnList(selectClause)

  for (const part of columnParts) {
    const trimmed = part.trim()

    if (/^(COUNT|SUM|AVG|MIN|MAX|GROUP_CONCAT)\s*\(/i.test(trimmed)) {
      const innerMatch = trimmed.match(/\(([a-z_][a-z0-9_.]*|\*)\)/i)
      if (innerMatch && innerMatch[1] !== '*') {
        const colRef = parseColumnRef(innerMatch[1], tables, aliasMap)
        if (colRef) {
          columns.push(colRef)
        }
      }
      continue
    }

    const colAliasMatch = trimmed.match(/^([a-z_][a-z0-9_.]*)\s+(?:AS\s+)?([a-z_][a-z0-9_]*)$/i)
    if (colAliasMatch) {
      const colRef = parseColumnRef(colAliasMatch[1], tables, aliasMap)
      if (colRef) {
        colRef.alias = colAliasMatch[2]
        columns.push(colRef)
      }
      continue
    }

    const colRef = parseColumnRef(trimmed, tables, aliasMap)
    if (colRef) {
      columns.push(colRef)
    }
  }

  return { valid: true, columns }
}

function splitColumnList(selectClause) {
  const parts = []
  let current = ''
  let depth = 0

  for (const char of selectClause) {
    if (char === '(') {
      depth++
      current += char
    } else if (char === ')') {
      depth--
      current += char
    } else if (char === ',' && depth === 0) {
      parts.push(current)
      current = ''
    } else {
      current += char
    }
  }

  if (current.trim()) {
    parts.push(current)
  }

  return parts
}

function parseColumnRef(ref, tables, aliasMap = {}) {
  const trimmed = ref.trim()

  if (trimmed.includes('.')) {
    const [tableOrAlias, column] = trimmed.split('.')
    // Resolve alias to real table name
    const table = aliasMap[tableOrAlias.toLowerCase()] || tableOrAlias.toLowerCase()
    return { table, column: column.toLowerCase() }
  }

  for (const table of tables) {
    const privacy = getColumnPrivacy(table, trimmed)
    if (privacy) {
      return { table: table.toLowerCase(), column: trimmed.toLowerCase() }
    }
  }

  return null
}

function expandSelectStar(sql, tables) {
  const upperSql = sql.toUpperCase()

  if (!upperSql.includes('SELECT *') && !upperSql.match(/SELECT\s+[a-z_]+\.\*/i)) {
    return sql
  }

  const allColumns = []
  for (const table of tables) {
    const cols = getAllowedColumns(table)
    for (const col of cols) {
      allColumns.push(`${table}.${col}`)
    }
  }

  return sql.replace(/SELECT\s+\*/i, `SELECT ${allColumns.join(', ')}`)
}

/**
 * Detect the type of a value for appropriate tokenization
 */
function detectValueType(value, columnName) {
  const lowerCol = columnName.toLowerCase()
  if (lowerCol.includes('email') || lowerCol === 'fromaddr' || lowerCol === 'contactmail') {
    return 'EMAIL'
  }
  if (lowerCol.includes('name') || lowerCol === 'firstname' || lowerCol === 'lastname' ||
      lowerCol === 'fullname' || lowerCol === 'fromname') {
    return 'NAME'
  }
  if (lowerCol.includes('ip') || lowerCol === 'fromip') {
    return 'IP'
  }

  if (/[\w.-]+@[\w.-]+\.\w+/.test(value)) return 'EMAIL'
  if (/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(value)) return 'IP'
  if (/^(?:\+44|0)\s*\d{2,4}\s*\d{3,4}\s*\d{3,4}$/.test(value)) return 'PHONE'
  if (/^[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}$/i.test(value)) return 'POSTCODE'

  return 'TEXT'
}

/**
 * Pseudonymize database result rows
 */
function pseudonymizeDbRows(rows, columns) {
  return rows.map((row) => {
    const result = {}

    for (const col of columns) {
      const key = col.alias || col.column
      const value = row[key]

      const privacy = getColumnPrivacy(col.table, col.column)
      if (!privacy) continue

      if (privacy === 'PUBLIC') {
        result[key] = value
      } else if (value !== null && value !== undefined && value !== '') {
        const stringValue = String(value)
        const valueType = detectValueType(stringValue, col.column)
        result[key] = getOrCreateToken(stringValue, valueType)
      } else {
        result[key] = value
      }
    }

    return result
  })
}

// =============================================================================
// ENDPOINTS
// =============================================================================

// Health check
app.get('/health', async (req, res) => {
  let dbStatus = 'unknown'
  try {
    const pool = getMysqlPool()
    const connection = await pool.getConnection()
    await connection.ping()
    connection.release()
    dbStatus = 'ok'
  } catch {
    dbStatus = 'error'
  }

  res.json({
    status: 'ok',
    service: 'ai-sanitizer',
    lokiUrl: LOKI_URL,
    dbStatus,
  })
})

/**
 * GET /api/mcp/schema - Returns database schema for LLM discovery
 * This helps the LLM understand what tables/columns are available and how to join them
 */
app.get('/api/mcp/schema', (req, res) => {
  const schema = {
    tables: {},
    joins: [],
    examples: [],
  }

  // Build table schema from DB_SCHEMA
  for (const [tableName, tableConfig] of Object.entries(DB_SCHEMA)) {
    if (!tableConfig.allowed) continue

    const columns = {}
    for (const [colName, privacy] of Object.entries(tableConfig.fields)) {
      columns[colName] = {
        type: privacy === 'SENSITIVE' ? 'sensitive (will be pseudonymized)' : 'public',
      }
    }
    schema.tables[tableName] = { columns }
  }

  // Document important relationships
  schema.joins = [
    {
      description: 'Look up user by email address',
      tables: ['users', 'users_emails'],
      joinCondition: 'users.id = users_emails.userid',
      note: 'Email addresses are stored in users_emails, not users table',
      example: 'SELECT u.id, u.fullname, u.lastaccess FROM users u INNER JOIN users_emails ue ON u.id = ue.userid WHERE ue.email = "user@example.com"',
    },
    {
      description: 'Get user messages/posts',
      tables: ['users', 'messages'],
      joinCondition: 'users.id = messages.fromuser',
      example: 'SELECT m.id, m.subject, m.type, m.arrival FROM messages m WHERE m.fromuser = 12345',
    },
    {
      description: 'Get user group memberships',
      tables: ['users', 'memberships', 'groups'],
      joinCondition: 'users.id = memberships.userid, memberships.groupid = groups.id',
      example: 'SELECT g.nameshort, m.role, m.added FROM memberships m INNER JOIN groups g ON m.groupid = g.id WHERE m.userid = 12345',
    },
    {
      description: 'Get chat room participants',
      tables: ['chat_rooms', 'users'],
      joinCondition: 'chat_rooms.user1 = users.id OR chat_rooms.user2 = users.id',
      example: 'SELECT cr.id, cr.chattype, cr.created FROM chat_rooms cr WHERE cr.user1 = 12345 OR cr.user2 = 12345',
    },
  ]

  // Common query examples
  schema.examples = [
    {
      question: 'When did user with email X last log in?',
      query: 'SELECT u.lastaccess FROM users u INNER JOIN users_emails ue ON u.id = ue.userid WHERE ue.email = "X"',
    },
    {
      question: 'What posts has user ID 12345 made?',
      query: 'SELECT id, subject, type, arrival FROM messages WHERE fromuser = 12345 ORDER BY arrival DESC LIMIT 20',
    },
    {
      question: 'What groups is user ID 12345 a member of?',
      query: 'SELECT g.nameshort, m.role FROM memberships m INNER JOIN groups g ON m.groupid = g.id WHERE m.userid = 12345',
    },
    {
      question: 'Is the user active or bouncing?',
      query: 'SELECT engagement, bouncing, lastaccess FROM users WHERE id = 12345',
    },
  ]

  res.json(schema)
})

// =============================================================================
// QUERY SANITIZER ENDPOINTS (from query-sanitizer)
// =============================================================================

/**
 * POST /sanitize - Sanitize a user query
 */
app.post('/sanitize', async (req, res) => {
  const { query, knownPii, userId } = req.body

  if (!query) {
    return res.status(400).json({ error: 'Query is required' })
  }

  if (containsEmailTrail(query)) {
    return res.status(400).json({
      error: 'EMAIL_TRAIL_DETECTED',
      message: 'Your query appears to contain copy-pasted email content. Please describe the issue in your own words to protect user privacy.',
    })
  }

  const sessionId = `sess_${uuidv4().slice(0, 8)}`
  const detectedPii = scanForPii(query)
  const { pseudonymizedText, mapping } = extractAndPseudonymize(query, knownPii || {})

  // Register session mapping (no need to call external pseudonymizer now)
  const expiresAt = new Date(Date.now() + 60 * 60 * 1000).toISOString()
  for (const [token, realValue] of Object.entries(mapping)) {
    const fieldType = token.includes('@') ? 'EMAIL' :
                      token.startsWith('10.0.') ? 'IP' :
                      token.startsWith('07700') ? 'PHONE' :
                      token.startsWith('ZZ') ? 'POSTCODE' :
                      token.includes('_') ? token.split('_')[0] : 'TEXT'
    stmts.insertToken.run(token, realValue.toLowerCase().trim(), fieldType)
    stmts.insertSession.run(sessionId, token, userId || null, expiresAt)
  }

  console.log(`[${sessionId}] Sanitized query: ${pseudonymizedText.substring(0, 50)}...`)
  console.log(`[${sessionId}] Tokens created: ${Object.keys(mapping).length}`)

  res.json({
    pseudonymizedQuery: pseudonymizedText,
    sessionId,
    localMapping: mapping,
    detectedPii: detectedPii.length > 0 ? detectedPii : null,
  })
})

/**
 * POST /scan - Scan query for PII without sanitizing
 */
app.post('/scan', (req, res) => {
  const { query, knownPii } = req.body

  if (!query) {
    return res.status(400).json({ error: 'Query is required' })
  }

  const detectedPii = scanForPii(query)
  const hasEmailTrail = containsEmailTrail(query)

  if (knownPii) {
    const lowerQuery = query.toLowerCase()

    if (knownPii.email && lowerQuery.includes(knownPii.email.toLowerCase())) {
      detectedPii.unshift({ type: 'email', value: knownPii.email, severity: 'high', source: 'selected_user' })
    }

    if (knownPii.displayname && lowerQuery.includes(knownPii.displayname.toLowerCase())) {
      detectedPii.unshift({ type: 'name', value: knownPii.displayname, severity: 'medium', source: 'selected_user' })
    }
  }

  res.json({
    detectedPii,
    containsEmailTrail: hasEmailTrail,
    piiCount: detectedPii.length,
  })
})

// =============================================================================
// PSEUDONYMIZER ENDPOINTS (from pseudonymizer)
// =============================================================================

/**
 * POST /register-mapping - Register mapping from frontend
 */
app.post('/register-mapping', (req, res) => {
  const { sessionId, mapping, userId } = req.body

  if (!sessionId || !mapping) {
    return res.status(400).json({ error: 'sessionId and mapping required' })
  }

  const expiresAt = new Date(Date.now() + 60 * 60 * 1000).toISOString()

  for (const [token, realValue] of Object.entries(mapping)) {
    const fieldType = token.split('_')[0]
    stmts.insertToken.run(token, realValue.toLowerCase().trim(), fieldType)
    stmts.insertSession.run(sessionId, token, userId || null, expiresAt)
  }

  console.log(`[${sessionId}] Registered ${Object.keys(mapping).length} mappings`)

  res.json({ status: 'ok' })
})

/**
 * POST /query - Query Loki and pseudonymize results (was /api/mcp/query)
 */
app.post('/query', async (req, res) => {
  const { sessionId, query, start = '1h', end, limit = 100, lokiUrl } = req.body

  if (!query) {
    return res.status(400).json({ error: 'query required' })
  }

  const effectiveSessionId = sessionId || `query_${uuidv4().slice(0, 8)}`
  const startTime = Date.now()

  try {
    // Translate pseudonymized query to real values
    const realQuery = translateQuery(query)

    // Build Loki query parameters
    const params = new URLSearchParams({
      query: realQuery,
      limit: limit.toString(),
    })

    // Handle time range
    if (start) {
      if (/^\d+[smhdw]$/.test(start)) {
        const now = Date.now()
        const duration = parseDuration(start)
        params.set('start', ((now - duration) * 1000000).toString())
      } else {
        params.set('start', (new Date(start).getTime() * 1000000).toString())
      }
    }

    if (end) {
      params.set('end', (new Date(end).getTime() * 1000000).toString())
    }

    // Query Loki
    const effectiveLokiUrl = lokiUrl || LOKI_URL
    const lokiResponse = await fetch(`${effectiveLokiUrl}/loki/api/v1/query_range?${params}`)

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

    writeAuditLog({
      sessionId: effectiveSessionId,
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

    console.log(`[${effectiveSessionId}] Query completed in ${durationMs}ms, ${allTokensUsed.size} tokens used`)

    res.json(data)
  } catch (error) {
    console.error(`[${effectiveSessionId}] Query error:`, error.message)

    writeAuditLog({
      sessionId: effectiveSessionId,
      operation: 'loki_query',
      request: { query, start, end, limit },
      error: error.message,
      durationMs: Date.now() - startTime,
    })

    res.status(500).json({ error: error.message })
  }
})

/**
 * GET /mapping/:sessionId - Get reverse mapping for frontend de-tokenization
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

// =============================================================================
// DATABASE QUERY ENDPOINT (from status-nuxt)
// =============================================================================

/**
 * POST /api/mcp/db-query - Execute database query with pseudonymization
 */
app.post('/api/mcp/db-query', async (req, res) => {
  const { query, debug } = req.body

  if (!query) {
    return res.status(400).json({ error: 'query is required' })
  }

  const sessionId = `db_${Date.now()}_${uuidv4().slice(0, 8)}`
  const debugInfo = {
    sessionId,
    originalQuery: query,
    steps: [],
  }

  try {
    // Step 1: Validate the SQL query
    debugInfo.steps.push({ step: 1, action: 'Validating SQL query' })

    const validation = validateSql(query)

    if (!validation.valid) {
      debugInfo.steps.push({ step: 2, action: 'Validation failed', detail: validation.error })
      return res.status(400).json({ error: `Invalid query: ${validation.error}` })
    }

    debugInfo.steps.push({
      step: 2,
      action: 'Validation passed',
      detail: { tables: validation.tables, columnCount: validation.columns?.length || 0 },
    })

    // Step 2: Expand SELECT * if present
    let finalSql = validation.normalizedSql
    if (finalSql.toUpperCase().includes('SELECT *')) {
      finalSql = expandSelectStar(finalSql, validation.tables)
      debugInfo.steps.push({ step: 3, action: 'Expanded SELECT *', detail: { expandedSql: finalSql } })
    }

    // Step 3: Translate tokens to real values in WHERE/JOIN conditions
    // This allows queries with pseudonymized emails to work against the real database
    const translatedSql = translateQuery(finalSql)
    debugInfo.steps.push({
      step: 4,
      action: 'Translated tokens to real values',
      detail: { hadTokens: translatedSql !== finalSql },
    })

    // Step 4: Execute the query with translated values
    debugInfo.steps.push({ step: 5, action: 'Executing query', detail: { sql: translatedSql } })

    const pool = getMysqlPool()
    const connection = await pool.getConnection()

    try {
      await connection.query('SET SESSION TRANSACTION READ ONLY')
      const [rows, fields] = await connection.execute(translatedSql)

      debugInfo.steps.push({
        step: 5,
        action: 'Query executed',
        detail: { rowCount: rows.length },
      })

      // Step 4: Build column metadata from validation results
      const columns = validation.columns || []

      if (columns.length === 0 && rows.length > 0) {
        const firstTable = validation.tables[0]
        for (const key of Object.keys(rows[0])) {
          columns.push({ table: firstTable, column: key })
        }
      }

      // Step 5: Pseudonymize sensitive fields
      debugInfo.steps.push({ step: 6, action: 'Pseudonymizing results' })

      const pseudonymizedRows = pseudonymizeDbRows(rows, columns)

      debugInfo.steps.push({
        step: 7,
        action: 'Pseudonymization complete',
        detail: {
          sensitiveColumns: columns
            .filter((c) => getColumnPrivacy(c.table, c.column) === 'SENSITIVE')
            .map((c) => `${c.table}.${c.column}`),
        },
      })

      // Build response
      const result = {
        status: 'success',
        sessionId,
        query: finalSql,
        resultCount: pseudonymizedRows.length,
        columns: columns.map((c) => c.alias || c.column),
        rows: pseudonymizedRows,
      }

      if (debug) {
        result.debug = {
          ...debugInfo,
          explanation: {
            whatAISees: 'Results with sensitive data replaced (emails, names, IPs become tokens)',
            whatIsProtected: columns
              .filter((c) => getColumnPrivacy(c.table, c.column) === 'SENSITIVE')
              .map((c) => `${c.table}.${c.column}`)
              .join(', '),
          },
        }
      }

      res.json(result)
    } finally {
      connection.release()
    }
  } catch (err) {
    if (err.code === 'ECONNREFUSED') {
      return res.status(503).json({ error: 'Database connection failed - service unavailable' })
    }
    if (err.code === 'ER_PARSE_ERROR') {
      return res.status(400).json({ error: `SQL syntax error: ${err.sqlMessage || err.message}` })
    }
    if (err.sqlMessage) {
      return res.status(400).json({ error: `Database error: ${err.sqlMessage}` })
    }
    res.status(500).json({ error: `Query failed: ${err.message}` })
  }
})

// =============================================================================
// BACKWARDS COMPATIBILITY ALIASES
// =============================================================================

// Alias /api/mcp/query to /query for backwards compatibility with ai-support-helper
app.post('/api/mcp/query', async (req, res) => {
  // Forward to the main /query handler
  req.url = '/query'
  app._router.handle(req, res)
})

// =============================================================================
// START SERVER
// =============================================================================

const PORT = process.env.PORT || 8080
app.listen(PORT, () => {
  console.log(`AI Sanitizer service listening on port ${PORT}`)
  console.log(`Loki URL: ${LOKI_URL}`)
  console.log(`Data directory: ${DATA_DIR}`)
  console.log(`Audit log directory: ${AUDIT_LOG_DIR}`)
  console.log(`Database: ${DB_CONFIG.host}:${DB_CONFIG.port}/${DB_CONFIG.database}`)
})

module.exports = { app }
