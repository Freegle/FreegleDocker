/**
 * SQL Validator for MCP Database Queries
 *
 * Validates that SQL queries are:
 * 1. Read-only (SELECT only)
 * 2. Using whitelisted tables
 * 3. Using whitelisted columns
 * 4. Limited in result count
 */

import {
  getAllowedTables,
  getAllowedColumns,
  isTableAllowed,
  getColumnPrivacy,
} from './db-schema'

export interface ValidationResult {
  valid: boolean
  error?: string
  tables?: string[]
  columns?: Array<{ table: string; column: string; alias?: string }>
  normalizedSql?: string
}

// Dangerous SQL keywords that indicate non-SELECT operations
const DANGEROUS_KEYWORDS = [
  'INSERT',
  'UPDATE',
  'DELETE',
  'DROP',
  'CREATE',
  'ALTER',
  'TRUNCATE',
  'REPLACE',
  'GRANT',
  'REVOKE',
  'CALL',
  'EXEC',
  'EXECUTE',
  'INTO OUTFILE',
  'INTO DUMPFILE',
  'LOAD_FILE',
  'LOAD DATA',
]

// Maximum rows to return
const MAX_LIMIT = 500

/**
 * Validate a SQL query for safety and compliance with whitelist
 */
export function validateSql(sql: string): ValidationResult {
  const normalizedSql = sql.trim()
  const upperSql = normalizedSql.toUpperCase()

  // Check it starts with SELECT
  if (!upperSql.startsWith('SELECT')) {
    return { valid: false, error: 'Only SELECT queries are allowed' }
  }

  // Check for dangerous keywords
  for (const keyword of DANGEROUS_KEYWORDS) {
    // Use word boundary check to avoid false positives
    const regex = new RegExp(`\\b${keyword}\\b`, 'i')
    if (regex.test(normalizedSql)) {
      return { valid: false, error: `Forbidden keyword: ${keyword}` }
    }
  }

  // Check for subqueries (not supported for simplicity)
  const subqueryCount = (normalizedSql.match(/\bSELECT\b/gi) || []).length
  if (subqueryCount > 1) {
    return { valid: false, error: 'Subqueries are not supported' }
  }

  // Extract tables from the query
  const tables = extractTables(normalizedSql)
  if (tables.length === 0) {
    return { valid: false, error: 'No tables found in query' }
  }

  // Check all tables are allowed
  for (const table of tables) {
    if (!isTableAllowed(table)) {
      const allowedList = getAllowedTables().join(', ')
      return {
        valid: false,
        error: `Table '${table}' is not allowed. Allowed tables: ${allowedList}`,
      }
    }
  }

  // Extract columns from SELECT clause
  const columnsResult = extractColumns(normalizedSql, tables)
  if (!columnsResult.valid) {
    return columnsResult
  }

  // Check all columns are allowed
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

  // Ensure LIMIT is present and reasonable
  const limitMatch = upperSql.match(/LIMIT\s+(\d+)/i)
  let finalSql = normalizedSql
  if (!limitMatch) {
    // Add LIMIT if not present
    finalSql = `${normalizedSql} LIMIT ${MAX_LIMIT}`
  } else {
    const requestedLimit = parseInt(limitMatch[1], 10)
    if (requestedLimit > MAX_LIMIT) {
      // Replace with max limit
      finalSql = normalizedSql.replace(
        /LIMIT\s+\d+/i,
        `LIMIT ${MAX_LIMIT}`
      )
    }
  }

  return {
    valid: true,
    tables,
    columns: columnsResult.columns,
    normalizedSql: finalSql,
  }
}

/**
 * Extract table names from a SQL query
 */
function extractTables(sql: string): string[] {
  const tables: string[] = []

  // Match FROM clause (main table)
  const fromMatch = sql.match(/\bFROM\s+([a-z_][a-z0-9_]*)/i)
  if (fromMatch) {
    tables.push(fromMatch[1].toLowerCase())
  }

  // Match JOIN clauses
  const joinRegex = /\bJOIN\s+([a-z_][a-z0-9_]*)/gi
  let match
  while ((match = joinRegex.exec(sql)) !== null) {
    const tableName = match[1].toLowerCase()
    if (!tables.includes(tableName)) {
      tables.push(tableName)
    }
  }

  return tables
}

/**
 * Extract column references from SELECT clause
 */
function extractColumns(
  sql: string,
  tables: string[]
): ValidationResult {
  const columns: Array<{ table: string; column: string; alias?: string }> = []

  // Extract SELECT clause (everything between SELECT and FROM)
  const selectMatch = sql.match(/SELECT\s+(.*?)\s+FROM/is)
  if (!selectMatch) {
    return { valid: false, error: 'Could not parse SELECT clause' }
  }

  const selectClause = selectMatch[1].trim()

  // Handle SELECT *
  if (selectClause === '*') {
    // Expand to all allowed columns for all tables
    for (const table of tables) {
      const allowedCols = getAllowedColumns(table)
      for (const col of allowedCols) {
        columns.push({ table, column: col })
      }
    }
    return { valid: true, columns }
  }

  // Handle SELECT table.*
  if (/^[a-z_][a-z0-9_]*\.\*$/i.test(selectClause)) {
    const table = selectClause.replace('.*', '').toLowerCase()
    if (!tables.includes(table)) {
      return { valid: false, error: `Table '${table}' not in FROM clause` }
    }
    const allowedCols = getAllowedColumns(table)
    for (const col of allowedCols) {
      columns.push({ table, column: col })
    }
    return { valid: true, columns }
  }

  // Parse individual columns
  // Split by comma, but be careful of functions with commas
  const columnParts = splitColumnList(selectClause)

  for (const part of columnParts) {
    const trimmed = part.trim()

    // Handle aggregate functions
    if (/^(COUNT|SUM|AVG|MIN|MAX|GROUP_CONCAT)\s*\(/i.test(trimmed)) {
      // Allow aggregate functions - extract inner column if present
      const innerMatch = trimmed.match(/\(([a-z_][a-z0-9_.]*|\*)\)/i)
      if (innerMatch && innerMatch[1] !== '*') {
        const colRef = parseColumnRef(innerMatch[1], tables)
        if (colRef) {
          columns.push(colRef)
        }
      }
      continue
    }

    // Handle aliased columns (col AS alias or col alias)
    const aliasMatch = trimmed.match(
      /^([a-z_][a-z0-9_.]*)\s+(?:AS\s+)?([a-z_][a-z0-9_]*)$/i
    )
    if (aliasMatch) {
      const colRef = parseColumnRef(aliasMatch[1], tables)
      if (colRef) {
        colRef.alias = aliasMatch[2]
        columns.push(colRef)
      }
      continue
    }

    // Handle simple column reference
    const colRef = parseColumnRef(trimmed, tables)
    if (colRef) {
      columns.push(colRef)
    }
  }

  return { valid: true, columns }
}

/**
 * Split a SELECT column list by commas, handling nested parentheses
 */
function splitColumnList(selectClause: string): string[] {
  const parts: string[] = []
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

/**
 * Parse a column reference (may be table.column or just column)
 */
function parseColumnRef(
  ref: string,
  tables: string[]
): { table: string; column: string } | null {
  const trimmed = ref.trim()

  // Handle table.column
  if (trimmed.includes('.')) {
    const [table, column] = trimmed.split('.')
    return {
      table: table.toLowerCase(),
      column: column.toLowerCase(),
    }
  }

  // Handle just column - try to find which table it belongs to
  // Default to first table if ambiguous
  for (const table of tables) {
    const privacy = getColumnPrivacy(table, trimmed)
    if (privacy) {
      return {
        table: table.toLowerCase(),
        column: trimmed.toLowerCase(),
      }
    }
  }

  // Column not found in any table
  return null
}

/**
 * Expand SELECT * to explicit columns
 */
export function expandSelectStar(sql: string, tables: string[]): string {
  const upperSql = sql.toUpperCase()

  // Check for SELECT *
  if (!upperSql.includes('SELECT *') && !upperSql.match(/SELECT\s+[a-z_]+\.\*/i)) {
    return sql
  }

  // Get all allowed columns for all tables
  const allColumns: string[] = []
  for (const table of tables) {
    const cols = getAllowedColumns(table)
    for (const col of cols) {
      allColumns.push(`${table}.${col}`)
    }
  }

  // Replace SELECT * with explicit columns
  return sql.replace(
    /SELECT\s+\*/i,
    `SELECT ${allColumns.join(', ')}`
  )
}
