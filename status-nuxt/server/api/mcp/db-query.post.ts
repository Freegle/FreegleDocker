/**
 * MCP Database Query Endpoint
 *
 * Executes read-only SQL queries against the Freegle database with:
 * - SQL validation (SELECT only, whitelisted tables/columns)
 * - Automatic pseudonymization of sensitive fields
 * - Result limiting for safety
 *
 * Request body:
 * - query: SQL query string
 * - debug?: If true, shows pseudonymization details
 */

import { v4 as uuidv4 } from 'uuid'
import { validateSql, expandSelectStar } from '../../utils/sql-validator'
import { executeQuery } from '../../utils/database'
import {
  pseudonymizeRows,
  getTokenMapping,
  clearTokenMapping,
  generateSessionId,
} from '../../utils/db-pseudonymizer'
import { getColumnPrivacy } from '../../utils/db-schema'
import {
  registerDbQuery,
  getQuery,
  setQueryResults,
  approveQuery as approveQueryFn,
} from '../../utils/mcp-approval'

interface QueryRequest {
  query: string
  debug?: boolean
  requireApproval?: boolean // If true, query goes through approval flow
}

export default defineEventHandler(async (event) => {
  const body = await readBody<QueryRequest>(event)

  if (!body.query) {
    throw createError({
      statusCode: 400,
      message: 'query is required',
    })
  }

  const sessionId = generateSessionId()
  const debugInfo: any = {
    sessionId,
    originalQuery: body.query,
    steps: [],
  }

  // Clear any previous token mappings for this session
  clearTokenMapping()

  try {
    // Step 1: Validate the SQL query
    debugInfo.steps.push({
      step: 1,
      action: 'Validating SQL query',
    })

    const validation = validateSql(body.query)

    if (!validation.valid) {
      debugInfo.steps.push({
        step: 2,
        action: 'Validation failed',
        detail: validation.error,
      })

      throw createError({
        statusCode: 400,
        message: `Invalid query: ${validation.error}`,
      })
    }

    debugInfo.steps.push({
      step: 2,
      action: 'Validation passed',
      detail: {
        tables: validation.tables,
        columnCount: validation.columns?.length || 0,
      },
    })

    // Step 2a: If approval required, register and wait for approval
    let approvalId: string | undefined
    if (body.requireApproval) {
      approvalId = registerDbQuery(
        body.query,
        500, // max rows
        validation.tables || [],
        (validation.columns || []).map((c) => `${c.table}.${c.column}`)
      )

      debugInfo.steps.push({
        step: 3,
        action: 'Registered for approval',
        detail: { approvalId },
      })

      // Poll for approval (timeout after 2 minutes)
      const startTime = Date.now()
      const timeout = 120000

      while (Date.now() - startTime < timeout) {
        const pendingQuery = getQuery(approvalId)
        if (!pendingQuery) {
          throw createError({
            statusCode: 500,
            message: 'Approval state lost',
          })
        }

        if (pendingQuery.status === 'rejected') {
          throw createError({
            statusCode: 403,
            message: 'Query rejected by user',
          })
        }

        if (pendingQuery.status === 'approved_query') {
          debugInfo.steps.push({
            step: 4,
            action: 'Query approved',
          })
          break
        }

        // Wait 500ms before polling again
        await new Promise((resolve) => setTimeout(resolve, 500))
      }

      // Check if we timed out
      const finalQuery = getQuery(approvalId)
      if (finalQuery?.status !== 'approved_query') {
        throw createError({
          statusCode: 408,
          message: 'Approval timeout - query not approved within 2 minutes',
        })
      }
    }

    // Step 3: Expand SELECT * if present
    let finalSql = validation.normalizedSql!
    if (finalSql.toUpperCase().includes('SELECT *')) {
      finalSql = expandSelectStar(finalSql, validation.tables!)
      debugInfo.steps.push({
        step: 3,
        action: 'Expanded SELECT *',
        detail: { expandedSql: finalSql },
      })
    }

    // Step 3: Execute the query
    debugInfo.steps.push({
      step: 4,
      action: 'Executing query',
      detail: { sql: finalSql },
    })

    const startTime = Date.now()
    const { rows, fields } = await executeQuery(finalSql)
    const executionTime = Date.now() - startTime

    debugInfo.steps.push({
      step: 5,
      action: 'Query executed',
      detail: {
        rowCount: rows.length,
        executionTimeMs: executionTime,
      },
    })

    // Step 4: Build column metadata from validation results
    const columns = validation.columns || []

    // If we don't have column metadata (shouldn't happen), infer from first row
    if (columns.length === 0 && rows.length > 0) {
      const firstTable = validation.tables![0]
      for (const key of Object.keys(rows[0] as Record<string, unknown>)) {
        columns.push({ table: firstTable, column: key })
      }
    }

    // Step 5: Pseudonymize sensitive fields
    debugInfo.steps.push({
      step: 6,
      action: 'Pseudonymizing results',
    })

    const pseudonymizedRows = pseudonymizeRows(
      rows as Record<string, unknown>[],
      columns
    )

    // Get the token mapping for debug mode
    const tokenMapping = getTokenMapping()

    debugInfo.steps.push({
      step: 7,
      action: 'Pseudonymization complete',
      detail: {
        tokenCount: Object.keys(tokenMapping).length,
        sensitiveColumns: columns
          .filter((c) => getColumnPrivacy(c.table, c.column) === 'SENSITIVE')
          .map((c) => `${c.table}.${c.column}`),
      },
    })

    // Step 6: If approval required, wait for results approval
    if (body.requireApproval && approvalId) {
      // Store results for approval
      setQueryResults(approvalId, {
        rows: pseudonymizedRows,
        columns: columns.map((c) => c.alias || c.column),
        rowCount: pseudonymizedRows.length,
        tokenCount: Object.keys(tokenMapping).length,
      })

      debugInfo.steps.push({
        step: 8,
        action: 'Results registered for approval',
      })

      // Poll for results approval
      const startTime = Date.now()
      const timeout = 120000

      while (Date.now() - startTime < timeout) {
        const pendingQuery = getQuery(approvalId)
        if (!pendingQuery) {
          throw createError({
            statusCode: 500,
            message: 'Approval state lost',
          })
        }

        if (pendingQuery.status === 'rejected') {
          throw createError({
            statusCode: 403,
            message: 'Results rejected by user',
          })
        }

        if (pendingQuery.status === 'approved_results') {
          debugInfo.steps.push({
            step: 9,
            action: 'Results approved',
          })
          break
        }

        // Wait 500ms before polling again
        await new Promise((resolve) => setTimeout(resolve, 500))
      }

      // Check if we timed out
      const finalQuery = getQuery(approvalId)
      if (finalQuery?.status !== 'approved_results') {
        throw createError({
          statusCode: 408,
          message: 'Approval timeout - results not approved within 2 minutes',
        })
      }
    }

    // Build response
    const result: any = {
      status: 'success',
      sessionId,
      query: finalSql,
      resultCount: pseudonymizedRows.length,
      columns: columns.map((c) => c.alias || c.column),
      rows: pseudonymizedRows,
      note:
        Object.keys(tokenMapping).length > 0
          ? 'Some values pseudonymized for privacy'
          : undefined,
    }

    // Debug mode shows metadata only - NEVER expose token mappings (PII leak risk)
    if (body.debug) {
      result.debug = {
        ...debugInfo,
        // NOTE: tokenMappings intentionally NOT included - contains real PII
        // The mapping is only available to the browser via the sanitizer
        tokenCount: Object.keys(tokenMapping).length,
        explanation: {
          whatAISees:
            'Results with sensitive data replaced (emails, names, IPs become tokens)',
          whatIsProtected: columns
            .filter((c) => getColumnPrivacy(c.table, c.column) === 'SENSITIVE')
            .map((c) => `${c.table}.${c.column}`)
            .join(', '),
          howItWorks: [
            '1. Query is validated (SELECT only, whitelisted tables/columns)',
            '2. Query is executed in read-only mode',
            '3. Results are scanned for sensitive columns',
            '4. Sensitive values are replaced with consistent tokens',
            '5. Same value always maps to same token within session',
          ],
        },
      }
    }

    return result
  } catch (err: any) {
    // If already a Nuxt error, re-throw
    if (err.statusCode) throw err

    // Database errors
    if (err.code === 'ECONNREFUSED') {
      throw createError({
        statusCode: 503,
        message: 'Database connection failed - service unavailable',
      })
    }

    // SQL syntax errors
    if (err.code === 'ER_PARSE_ERROR') {
      throw createError({
        statusCode: 400,
        message: `SQL syntax error: ${err.sqlMessage || err.message}`,
      })
    }

    // Other database errors
    if (err.sqlMessage) {
      throw createError({
        statusCode: 400,
        message: `Database error: ${err.sqlMessage}`,
      })
    }

    throw createError({
      statusCode: 500,
      message: `Query failed: ${err.message}`,
    })
  }
})
