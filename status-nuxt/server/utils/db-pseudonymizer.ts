/**
 * Database Result Pseudonymizer
 *
 * Applies pseudonymization to database query results based on field privacy levels.
 * Uses type-aware tokenization to maintain data validity (emails look like emails, etc.)
 */

import { v4 as uuidv4 } from 'uuid'
import { getColumnPrivacy, type FieldPrivacy } from './db-schema'

// In-memory token mapping (same value = same token within session)
const tokenMap = new Map<string, string>()
const reverseMap = new Map<string, string>() // For de-pseudonymization

// Common email domains to preserve
const COMMON_EMAIL_DOMAINS = [
  'gmail.com',
  'googlemail.com',
  'outlook.com',
  'hotmail.com',
  'live.com',
  'msn.com',
  'yahoo.com',
  'yahoo.co.uk',
  'icloud.com',
  'me.com',
  'mac.com',
  'aol.com',
  'protonmail.com',
  'proton.me',
  'btinternet.com',
  'btopenworld.com',
  'sky.com',
  'virginmedia.com',
  'talktalk.net',
  'ntlworld.com',
]

// Counter for numeric IDs
let numericIdCounter = 9999000000

/**
 * Get the domain from an email, normalized
 */
function extractEmailDomain(email: string): string {
  const domain = email.split('@')[1]?.toLowerCase()
  if (!domain) return 'other.com'
  if (COMMON_EMAIL_DOMAINS.includes(domain)) {
    return domain
  }
  return 'other.com'
}

/**
 * Detect the type of a value for appropriate tokenization
 */
function detectValueType(
  value: string,
  columnName: string
): 'EMAIL' | 'IP' | 'PHONE' | 'POSTCODE' | 'NAME' | 'TEXT' {
  // Check by column name first
  const lowerCol = columnName.toLowerCase()
  if (
    lowerCol.includes('email') ||
    lowerCol === 'fromaddr' ||
    lowerCol === 'contactmail'
  ) {
    return 'EMAIL'
  }
  if (
    lowerCol.includes('name') ||
    lowerCol === 'firstname' ||
    lowerCol === 'lastname' ||
    lowerCol === 'fullname' ||
    lowerCol === 'fromname'
  ) {
    return 'NAME'
  }
  if (lowerCol.includes('ip') || lowerCol === 'fromip') {
    return 'IP'
  }

  // Check by value pattern
  if (/[\w.-]+@[\w.-]+\.\w+/.test(value)) {
    return 'EMAIL'
  }
  if (/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(value)) {
    return 'IP'
  }
  if (/^(?:\+44|0)\s*\d{2,4}\s*\d{3,4}\s*\d{3,4}$/.test(value)) {
    return 'PHONE'
  }
  if (/^[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}$/i.test(value)) {
    return 'POSTCODE'
  }

  return 'TEXT'
}

/**
 * Get or create a pseudonymized token for a value
 */
function getOrCreateToken(value: string, valueType: string): string {
  const normalizedValue = value.toLowerCase().trim()

  // Check if we already have a token for this value
  const existing = tokenMap.get(normalizedValue)
  if (existing) {
    return existing
  }

  // Create new token that maintains the same type as original
  let token: string
  const shortId = uuidv4().slice(0, 6)

  switch (valueType) {
    case 'EMAIL': {
      const emailDomain = extractEmailDomain(value)
      token = `user_${shortId}@${emailDomain}`
      break
    }
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
      // Generate a realistic-looking name
      const names = [
        'User',
        'Person',
        'Member',
        'Freecycler',
        'Helper',
        'Volunteer',
      ]
      const namePick = names[Math.floor(Math.random() * names.length)]
      token = `${namePick}_${shortId}`
      break
    default:
      token = `TEXT_${shortId}`
  }

  tokenMap.set(normalizedValue, token)
  reverseMap.set(token.toLowerCase(), value)

  return token
}

/**
 * Pseudonymize a single value based on column privacy
 */
export function pseudonymizeValue(
  value: unknown,
  table: string,
  column: string
): unknown {
  const privacy = getColumnPrivacy(table, column)

  // If column is not in whitelist, return null
  if (!privacy) {
    return null
  }

  // PUBLIC values pass through unchanged
  if (privacy === 'PUBLIC') {
    return value
  }

  // SENSITIVE values get pseudonymized
  if (value === null || value === undefined) {
    return value
  }

  const stringValue = String(value)
  if (stringValue === '') {
    return value
  }

  const valueType = detectValueType(stringValue, column)
  return getOrCreateToken(stringValue, valueType)
}

/**
 * Pseudonymize an entire row of data
 */
export function pseudonymizeRow(
  row: Record<string, unknown>,
  table: string
): Record<string, unknown> {
  const result: Record<string, unknown> = {}

  for (const [column, value] of Object.entries(row)) {
    const pseudonymized = pseudonymizeValue(value, table, column)
    if (pseudonymized !== null) {
      result[column] = pseudonymized
    }
  }

  return result
}

/**
 * Pseudonymize rows with table context from column metadata
 */
export function pseudonymizeRows(
  rows: Record<string, unknown>[],
  columns: Array<{ table: string; column: string; alias?: string }>
): Record<string, unknown>[] {
  return rows.map((row) => {
    const result: Record<string, unknown> = {}

    for (const col of columns) {
      const key = col.alias || col.column
      const value = row[key]

      const privacy = getColumnPrivacy(col.table, col.column)
      if (!privacy) {
        continue // Skip blocked columns
      }

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

/**
 * Get the current token mapping for this session
 */
export function getTokenMapping(): Record<string, string> {
  const mapping: Record<string, string> = {}
  for (const [original, token] of tokenMap.entries()) {
    mapping[token] = original
  }
  return mapping
}

/**
 * Clear token mapping (call between sessions)
 */
export function clearTokenMapping(): void {
  tokenMap.clear()
  reverseMap.clear()
}

/**
 * Generate a session ID
 */
export function generateSessionId(): string {
  return `db_${Date.now()}_${uuidv4().slice(0, 8)}`
}
