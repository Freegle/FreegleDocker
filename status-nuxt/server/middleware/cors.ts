/**
 * CORS middleware for API routes
 * Allows cross-origin requests from localhost domains
 */

export default defineEventHandler((event) => {
  const origin = getHeader(event, 'origin') || ''

  // Allow requests from localhost domains
  if (origin.includes('.localhost') || origin.includes('localhost')) {
    setResponseHeaders(event, {
      'Access-Control-Allow-Origin': origin,
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, sentry-trace, baggage',
      'Access-Control-Allow-Credentials': 'true',
    })
  }

  // Handle preflight requests
  if (event.method === 'OPTIONS') {
    event.node.res.statusCode = 204
    event.node.res.end()
    return
  }
})
