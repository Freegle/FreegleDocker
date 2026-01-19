/**
 * Get pending MCP queries awaiting human approval
 *
 * GET /api/mcp/pending
 * Returns: { queries: PendingQuery[] }
 */

import { getPendingQueries } from '../../utils/mcp-approval'

export default defineEventHandler(async () => {
  const queries = getPendingQueries()
  return { queries }
})
