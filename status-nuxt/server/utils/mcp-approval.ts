/**
 * MCP Query Approval Management
 *
 * Manages the human-in-the-loop approval flow for MCP log queries:
 * 1. MCP server registers a query for approval
 * 2. Frontend shows the query to the user
 * 3. User approves/rejects
 * 4. If approved, query executes and results are shown for approval
 * 5. If results approved, they're returned to Claude
 */

import { randomUUID } from 'crypto'

export interface PendingQuery {
  id: string
  type: 'log' | 'db'
  query: string
  timeRange?: string
  limit: number
  status: 'pending_query' | 'approved_query' | 'pending_results' | 'approved_results' | 'rejected'
  createdAt: number
  results?: any
  error?: string
  // DB-specific fields
  tables?: string[]
  columns?: string[]
}

// In-memory storage for pending queries (cleared on restart)
const pendingQueries = new Map<string, PendingQuery>()

// Cleanup old queries every minute
setInterval(() => {
  const now = Date.now()
  const maxAge = 5 * 60 * 1000 // 5 minutes
  for (const [id, query] of pendingQueries) {
    if (now - query.createdAt > maxAge) {
      pendingQueries.delete(id)
    }
  }
}, 60000)

/**
 * Register a new log query for approval
 */
export function registerQuery(query: string, timeRange: string, limit: number): string {
  const id = randomUUID().slice(0, 8)
  pendingQueries.set(id, {
    id,
    type: 'log',
    query,
    timeRange,
    limit,
    status: 'pending_query',
    createdAt: Date.now(),
  })
  return id
}

/**
 * Register a new database query for approval
 */
export function registerDbQuery(
  query: string,
  limit: number,
  tables: string[],
  columns: string[]
): string {
  const id = randomUUID().slice(0, 8)
  pendingQueries.set(id, {
    id,
    type: 'db',
    query,
    limit,
    tables,
    columns,
    status: 'pending_query',
    createdAt: Date.now(),
  })
  return id
}

/**
 * Get all pending queries
 */
export function getPendingQueries(): PendingQuery[] {
  return Array.from(pendingQueries.values())
    .filter(q => q.status === 'pending_query' || q.status === 'pending_results')
    .sort((a, b) => b.createdAt - a.createdAt)
}

/**
 * Get a specific query by ID
 */
export function getQuery(id: string): PendingQuery | undefined {
  return pendingQueries.get(id)
}

/**
 * Approve a query for execution
 */
export function approveQuery(id: string): boolean {
  const query = pendingQueries.get(id)
  if (query && query.status === 'pending_query') {
    query.status = 'approved_query'
    return true
  }
  return false
}

/**
 * Set results and mark as pending results approval
 */
export function setQueryResults(id: string, results: any): boolean {
  const query = pendingQueries.get(id)
  if (query && query.status === 'approved_query') {
    query.results = results
    query.status = 'pending_results'
    return true
  }
  return false
}

/**
 * Set error for a query
 */
export function setQueryError(id: string, error: string): boolean {
  const query = pendingQueries.get(id)
  if (query) {
    query.error = error
    query.status = 'rejected'
    return true
  }
  return false
}

/**
 * Approve results to be returned to Claude
 */
export function approveResults(id: string): boolean {
  const query = pendingQueries.get(id)
  if (query && query.status === 'pending_results') {
    query.status = 'approved_results'
    return true
  }
  return false
}

/**
 * Reject a query or results
 */
export function rejectQuery(id: string): boolean {
  const query = pendingQueries.get(id)
  if (query) {
    query.status = 'rejected'
    return true
  }
  return false
}

/**
 * Poll for query status (used by MCP server)
 */
export function pollQueryStatus(id: string): PendingQuery | undefined {
  return pendingQueries.get(id)
}

/**
 * Clean up a completed query
 */
export function cleanupQuery(id: string): void {
  pendingQueries.delete(id)
}
