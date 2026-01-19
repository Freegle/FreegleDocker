/**
 * Database connection utility for MCP queries
 *
 * Connects to the Freegle MySQL database for read-only queries
 */

import mysql from 'mysql2/promise'

// Database configuration from environment or defaults
const DB_CONFIG = {
  host: process.env.DB_HOST || 'freegle-percona',
  port: parseInt(process.env.DB_PORT || '3306', 10),
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || 'iznik',
  database: process.env.DB_NAME || 'iznik',
  // Connection pool settings
  waitForConnections: true,
  connectionLimit: 5,
  maxIdle: 2,
  idleTimeout: 60000,
  queueLimit: 0,
  // Disable multi-statement queries for security
  multipleStatements: false,
}

// Connection pool (lazy initialized)
let pool: mysql.Pool | null = null

/**
 * Get database connection pool (lazy initialization)
 */
export function getPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool(DB_CONFIG)
  }
  return pool
}

/**
 * Execute a read-only query with automatic connection management
 */
export async function executeQuery<T = any>(
  sql: string,
  params?: any[]
): Promise<{ rows: T[]; fields: mysql.FieldPacket[] }> {
  const connection = await getPool().getConnection()

  try {
    // Set session to read-only for extra security
    await connection.query('SET SESSION TRANSACTION READ ONLY')

    // Execute the query
    const [rows, fields] = await connection.execute(sql, params || [])

    return {
      rows: rows as T[],
      fields,
    }
  } finally {
    // Always release the connection back to the pool
    connection.release()
  }
}

/**
 * Test database connection
 */
export async function testConnection(): Promise<boolean> {
  try {
    const connection = await getPool().getConnection()
    await connection.ping()
    connection.release()
    return true
  } catch {
    return false
  }
}

/**
 * Close the connection pool (for graceful shutdown)
 */
export async function closePool(): Promise<void> {
  if (pool) {
    await pool.end()
    pool = null
  }
}
