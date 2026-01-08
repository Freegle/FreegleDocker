/**
 * Query Sanitizer Service (Container 0)
 *
 * Frontend-facing service that:
 * - Receives user queries with known PII (from user selection step)
 * - Extracts any additional PII from the query text
 * - Creates consistent tokens for all PII
 * - Sends mapping directly to Pseudonymizer (Container 3)
 * - Returns pseudonymized query + mapping to frontend for de-tokenization
 *
 * Security: This container sees the original query but does NOT have
 * access to Loki or the ability to translate tokens back to values.
 */

const express = require('express')
const { v4: uuidv4 } = require('uuid')

const app = express()
app.use(express.json())

const PSEUDONYMIZER_URL = process.env.PSEUDONYMIZER_URL || 'http://pseudonymizer:8080'

// In-memory token cache (for consistent tokens within process lifetime)
// Real persistence is in Container 3's SQLite database
const tokenCache = new Map()

/**
 * Generate or retrieve a consistent token for a value.
 */
function getOrCreateToken(value, fieldType) {
  const key = `${fieldType}:${value.toLowerCase().trim()}`

  if (tokenCache.has(key)) {
    return tokenCache.get(key)
  }

  const token = `${fieldType}_${uuidv4().slice(0, 8)}`
  tokenCache.set(key, token)

  return token
}

/**
 * Escape special regex characters.
 */
function escapeRegex(string) {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * PII detection patterns for query scanning.
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

  // User IDs (6+ digit numbers, optionally prefixed)
  userId: /\b(?:user\s*(?:id)?[:#]?\s*)?(\d{6,})\b/gi,
}

/**
 * Email trail detection patterns.
 * If detected, we should reject the query to prevent accidental PII exposure.
 */
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

  // Email addresses
  const emails = text.match(PII_PATTERNS.email) || []
  for (const email of emails) {
    detected.push({ type: 'email', value: email, severity: 'high' })
  }

  // IP addresses
  const ips = text.match(PII_PATTERNS.ip) || []
  for (const ip of ips) {
    detected.push({ type: 'ip', value: ip, severity: 'medium' })
  }

  // Phone numbers
  const phones = text.match(PII_PATTERNS.phone) || []
  for (const phone of phones) {
    detected.push({ type: 'phone', value: phone, severity: 'high' })
  }

  // Postcodes
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
  const mapping = {} // token -> realValue (for frontend de-tokenization)
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

  // Scan for any other emails in the query
  const emails = result.match(PII_PATTERNS.email) || []
  for (const email of emails) {
    const token = getOrCreateToken(email, 'EMAIL')
    mapping[token] = email
    result = result.replace(new RegExp(escapeRegex(email), 'gi'), token)
  }

  // Scan for IP addresses
  const ips = result.match(PII_PATTERNS.ip) || []
  for (const ip of ips) {
    const token = getOrCreateToken(ip, 'IP')
    mapping[token] = ip
    result = result.replace(new RegExp(escapeRegex(ip), 'g'), token)
  }

  // Scan for phone numbers
  const phones = result.match(PII_PATTERNS.phone) || []
  for (const phone of phones) {
    const token = getOrCreateToken(phone, 'PHONE')
    mapping[token] = phone
    result = result.replace(new RegExp(escapeRegex(phone), 'g'), token)
  }

  // Scan for postcodes (that weren't already processed as known PII)
  const postcodes = result.match(PII_PATTERNS.postcode) || []
  for (const postcode of postcodes) {
    const token = getOrCreateToken(postcode, 'POSTCODE')
    mapping[token] = postcode
    result = result.replace(new RegExp(escapeRegex(postcode), 'gi'), token)
  }

  return { pseudonymizedText: result, mapping }
}

// Health check
app.get('/health', (req, res) => {
  res.json({
    status: 'ok',
    service: 'query-sanitizer',
    pseudonymizerUrl: PSEUDONYMIZER_URL,
  })
})

/**
 * Sanitize a user query.
 * POST /sanitize
 * Body: { query, knownPii, userId }
 * Returns: { pseudonymizedQuery, sessionId, localMapping, detectedPii }
 */
app.post('/sanitize', async (req, res) => {
  const { query, knownPii, userId } = req.body

  if (!query) {
    return res.status(400).json({ error: 'Query is required' })
  }

  // Check for email trail content
  if (containsEmailTrail(query)) {
    return res.status(400).json({
      error: 'EMAIL_TRAIL_DETECTED',
      message: 'Your query appears to contain copy-pasted email content. Please describe the issue in your own words to protect user privacy.',
    })
  }

  const sessionId = `sess_${uuidv4().slice(0, 8)}`

  // Scan for PII before pseudonymization (for UI warning)
  const detectedPii = scanForPii(query)

  // Extract and pseudonymize
  const { pseudonymizedText, mapping } = extractAndPseudonymize(query, knownPii || {})

  // Send mapping to Pseudonymizer (Container 3) for query translation
  try {
    const response = await fetch(`${PSEUDONYMIZER_URL}/register-mapping`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        sessionId,
        mapping,
        userId,
      }),
    })

    if (!response.ok) {
      throw new Error(`Pseudonymizer error: ${response.status}`)
    }
  } catch (error) {
    console.error('Failed to register mapping with pseudonymizer:', error.message)
    return res.status(503).json({
      error: 'PSEUDONYMIZER_UNAVAILABLE',
      message: 'Unable to connect to pseudonymizer service',
    })
  }

  console.log(`[${sessionId}] Sanitized query: ${pseudonymizedText.substring(0, 50)}...`)
  console.log(`[${sessionId}] Tokens created: ${Object.keys(mapping).length}`)

  res.json({
    pseudonymizedQuery: pseudonymizedText,
    sessionId,
    localMapping: mapping, // For frontend de-tokenization
    detectedPii: detectedPii.length > 0 ? detectedPii : null,
  })
})

/**
 * Scan query for PII without sanitizing (for preview/warning).
 * POST /scan
 * Body: { query, knownPii }
 * Returns: { detectedPii, containsEmailTrail }
 */
app.post('/scan', (req, res) => {
  const { query, knownPii } = req.body

  if (!query) {
    return res.status(400).json({ error: 'Query is required' })
  }

  const detectedPii = scanForPii(query)
  const hasEmailTrail = containsEmailTrail(query)

  // Add known PII to detected list if present in query
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

const PORT = process.env.PORT || 8080
app.listen(PORT, () => {
  console.log(`Query Sanitizer service listening on port ${PORT}`)
  console.log(`Pseudonymizer URL: ${PSEUDONYMIZER_URL}`)
})

module.exports = { app, extractAndPseudonymize, scanForPii, containsEmailTrail }
