/**
 * MCP User Search Endpoint
 *
 * Searches for users by email, name, or ID using the MCP database infrastructure.
 * This avoids CORS issues when searching live data from the browser.
 *
 * Query params:
 * - search: email, name, or user ID to search for
 * - limit: max results (default 10)
 */

import { getQuery as getQueryParams } from 'h3'
import { executeQuery } from '../../utils/database'

export default defineEventHandler(async (event) => {
  const query = getQueryParams(event) || {}
  const search = (query.search as string)?.trim()
  const limit = Math.min(parseInt(query.limit as string) || 10, 50)

  if (!search) {
    throw createError({
      statusCode: 400,
      message: 'Missing search parameter',
    })
  }

  try {
    let sql: string
    let users: any[]

    // Check if search is a numeric ID
    if (/^\d+$/.test(search)) {
      sql = `SELECT u.id, u.fullname AS displayname, ue.email, u.lastaccess
             FROM users u
             LEFT JOIN users_emails ue ON u.id = ue.userid AND ue.preferred = 1
             WHERE u.id = ?
             LIMIT 1`
      const result = await executeQuery(sql, [parseInt(search)])
      users = result.rows as any[]
    } else {
      // Search by email or name (join with users_emails for email search)
      // Note: LIMIT value is sanitized (max 50) and embedded directly as mysql2 has issues with LIMIT params
      sql = `SELECT u.id, u.fullname AS displayname, ue.email, u.lastaccess
             FROM users u
             LEFT JOIN users_emails ue ON u.id = ue.userid AND ue.preferred = 1
             WHERE ue.email LIKE ? OR u.fullname LIKE ?
             ORDER BY u.lastaccess DESC
             LIMIT ${limit}`
      const searchPattern = `%${search}%`
      const result = await executeQuery(sql, [searchPattern, searchPattern])
      users = result.rows as any[]
    }

    console.log(`[MCP User Search] Found ${users.length} users for "${search}"`)

    return {
      ret: 0,
      status: 'Success',
      users,
    }
  } catch (error: any) {
    console.error('[MCP User Search] Error:', error)

    if (error.code === 'ECONNREFUSED') {
      throw createError({
        statusCode: 503,
        message: 'Database connection failed',
      })
    }

    throw createError({
      statusCode: 500,
      message: error.message || 'Search failed',
    })
  }
})
