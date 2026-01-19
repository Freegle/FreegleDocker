/**
 * Unknown Fields Tracker
 *
 * Tracks log fields that are encountered but not in the schema.
 * These are flagged for admin review and classification.
 *
 * Storage: In-memory with periodic save to JSON file
 * (Could be upgraded to SQLite if needed)
 */

import { readFileSync, writeFileSync, existsSync } from 'fs'

interface UnknownField {
  source: string
  field: string
  firstSeen: string
  lastSeen: string
  sampleValues: string[] // Pseudonymized examples
  count: number
  classified?: 'PUBLIC' | 'SENSITIVE' // Once admin classifies it
  classifiedAt?: string
  classifiedBy?: string
}

// In-memory store
const unknownFields = new Map<string, UnknownField>()

// File path for persistence
const DATA_FILE = '/tmp/unknown-log-fields.json'

// Maximum sample values to keep per field
const MAX_SAMPLES = 5

/**
 * Load unknown fields from file on startup
 */
function loadFromFile(): void {
  try {
    if (existsSync(DATA_FILE)) {
      const data = JSON.parse(readFileSync(DATA_FILE, 'utf8'))
      for (const field of data.fields || []) {
        const key = `${field.source}:${field.field}`
        unknownFields.set(key, field)
      }
      console.log(`[UnknownFields] Loaded ${unknownFields.size} unknown fields from file`)
    }
  } catch (err) {
    console.error('[UnknownFields] Failed to load from file:', err)
  }
}

/**
 * Save unknown fields to file
 */
function saveToFile(): void {
  try {
    const data = {
      lastUpdated: new Date().toISOString(),
      fields: Array.from(unknownFields.values()),
    }
    writeFileSync(DATA_FILE, JSON.stringify(data, null, 2))
  } catch (err) {
    console.error('[UnknownFields] Failed to save to file:', err)
  }
}

// Load on module init
loadFromFile()

// Save periodically (every 5 minutes)
setInterval(saveToFile, 5 * 60 * 1000)

/**
 * Record an unknown field encounter
 * @param source - Log source (api, apiv2, client, etc.)
 * @param field - Field name
 * @param sampleValue - Pseudonymized sample value (NOT the real value!)
 */
export function recordUnknownField(
  source: string,
  field: string,
  sampleValue?: string
): void {
  const key = `${source}:${field}`
  const now = new Date().toISOString()

  const existing = unknownFields.get(key)
  if (existing) {
    // Update existing
    existing.lastSeen = now
    existing.count++

    // Add sample if unique and under limit
    if (
      sampleValue &&
      existing.sampleValues.length < MAX_SAMPLES &&
      !existing.sampleValues.includes(sampleValue)
    ) {
      existing.sampleValues.push(sampleValue)
    }
  } else {
    // New unknown field
    unknownFields.set(key, {
      source,
      field,
      firstSeen: now,
      lastSeen: now,
      sampleValues: sampleValue ? [sampleValue] : [],
      count: 1,
    })

    // Log warning for new unknown field
    console.warn(
      `[UnknownFields] New unknown field: ${source}.${field} - will be pseudonymized by default`
    )
  }
}

/**
 * Get all unknown fields, optionally filtered by source
 */
export function getUnknownFields(source?: string): UnknownField[] {
  const fields = Array.from(unknownFields.values())

  if (source) {
    return fields.filter((f) => f.source === source)
  }

  return fields.sort((a, b) => {
    // Sort by: unclassified first, then by count descending
    if (a.classified && !b.classified) return 1
    if (!a.classified && b.classified) return -1
    return b.count - a.count
  })
}

/**
 * Get summary of unknown fields by source
 */
export function getUnknownFieldsSummary(): Record<
  string,
  { total: number; unclassified: number }
> {
  const summary: Record<string, { total: number; unclassified: number }> = {}

  for (const field of unknownFields.values()) {
    if (!summary[field.source]) {
      summary[field.source] = { total: 0, unclassified: 0 }
    }
    summary[field.source].total++
    if (!field.classified) {
      summary[field.source].unclassified++
    }
  }

  return summary
}

/**
 * Classify an unknown field (admin action)
 */
export function classifyField(
  source: string,
  field: string,
  classification: 'PUBLIC' | 'SENSITIVE',
  classifiedBy?: string
): boolean {
  const key = `${source}:${field}`
  const existing = unknownFields.get(key)

  if (!existing) {
    return false
  }

  existing.classified = classification
  existing.classifiedAt = new Date().toISOString()
  existing.classifiedBy = classifiedBy

  // Save immediately after classification
  saveToFile()

  console.log(
    `[UnknownFields] Field ${source}.${field} classified as ${classification} by ${classifiedBy || 'unknown'}`
  )

  return true
}

/**
 * Generate TypeScript code to add classified fields to the schema
 * This can be copied and pasted into log-schema.ts
 */
export function generateSchemaCode(): string {
  const classified = Array.from(unknownFields.values()).filter(
    (f) => f.classified
  )

  if (classified.length === 0) {
    return '// No classified fields to add'
  }

  // Group by source
  const bySource: Record<string, UnknownField[]> = {}
  for (const field of classified) {
    if (!bySource[field.source]) {
      bySource[field.source] = []
    }
    bySource[field.source].push(field)
  }

  let code = '// Add these fields to LOG_SCHEMA in log-schema.ts:\n\n'

  for (const [source, fields] of Object.entries(bySource)) {
    code += `// Source: ${source}\n`
    code += `// Add to LOG_SCHEMA['${source}'].fields:\n`

    for (const field of fields) {
      const comment =
        field.classified === 'SENSITIVE'
          ? '// Contains PII - always pseudonymize'
          : '// Safe - no PII'
      code += `      ${field.field}: '${field.classified}', ${comment}\n`
    }

    code += '\n'
  }

  code += `// After adding, remove these fields from the unknown tracker:\n`
  code += `// DELETE FROM unknown_fields WHERE source IN (${Object.keys(bySource)
    .map((s) => `'${s}'`)
    .join(', ')})\n`

  return code
}

/**
 * Clear classified fields from the tracker (after they've been added to schema)
 */
export function clearClassifiedFields(): number {
  let count = 0

  for (const [key, field] of unknownFields.entries()) {
    if (field.classified) {
      unknownFields.delete(key)
      count++
    }
  }

  if (count > 0) {
    saveToFile()
  }

  return count
}
