/**
 * MCP Status endpoint - Check health of MCP system components
 *
 * Two-container architecture:
 * - Query Sanitizer: Creates tokens from user input (before sending to Claude)
 * - Pseudonymizer: Has the key, queries Loki, pseudonymizes results
 */
export default defineEventHandler(async () => {
  const results: Record<string, { status: string, message: string }> = {
    lokiLocal: { status: 'unknown', message: '' },
    lokiTunnel: { status: 'unknown', message: '' },
    querySanitizer: { status: 'unknown', message: '' },
    pseudonymizer: { status: 'unknown', message: '' },
  }

  // Check local Loki container
  try {
    const lokiResponse = await fetch('http://loki:3100/ready', {
      signal: AbortSignal.timeout(3000),
    })
    if (lokiResponse.ok) {
      const text = await lokiResponse.text()
      results.lokiLocal = { status: 'online', message: `Local Loki: ${text}` }
    }
    else {
      results.lokiLocal = { status: 'offline', message: `HTTP ${lokiResponse.status}` }
    }
  }
  catch (err: any) {
    results.lokiLocal = { status: 'offline', message: err.message || 'Connection failed' }
  }

  // Check Loki tunnel (port 3101 on host - for live Loki access)
  try {
    const lokiResponse = await fetch('http://host.docker.internal:3101/ready', {
      signal: AbortSignal.timeout(3000),
    })
    if (lokiResponse.ok) {
      const text = await lokiResponse.text()
      results.lokiTunnel = { status: 'online', message: `Live Loki tunnel: ${text}` }
    }
    else {
      results.lokiTunnel = { status: 'offline', message: `HTTP ${lokiResponse.status}` }
    }
  }
  catch (err: any) {
    results.lokiTunnel = { status: 'offline', message: err.message || 'Not configured' }
  }

  // Check Query Sanitizer container (creates tokens from user input)
  try {
    const sanitizerResponse = await fetch('http://freegle-mcp-sanitizer:8080/health', {
      signal: AbortSignal.timeout(3000),
    })
    if (sanitizerResponse.ok) {
      const data = await sanitizerResponse.json()
      results.querySanitizer = { status: 'online', message: JSON.stringify(data) }
    }
    else {
      results.querySanitizer = { status: 'offline', message: `HTTP ${sanitizerResponse.status}` }
    }
  }
  catch (err: any) {
    results.querySanitizer = { status: 'offline', message: err.message || 'Connection failed' }
  }

  // Check Pseudonymizer container (has key, queries Loki)
  try {
    const pseudoResponse = await fetch('http://freegle-mcp-pseudonymizer:8080/health', {
      signal: AbortSignal.timeout(3000),
    })
    if (pseudoResponse.ok) {
      const data = await pseudoResponse.json()
      results.pseudonymizer = { status: 'online', message: JSON.stringify(data) }
    }
    else {
      results.pseudonymizer = { status: 'offline', message: `HTTP ${pseudoResponse.status}` }
    }
  }
  catch (err: any) {
    results.pseudonymizer = { status: 'offline', message: err.message || 'Connection failed' }
  }

  // Determine overall status - need either local or tunnel Loki, plus both MCP services
  const hasLoki = results.lokiLocal.status === 'online' || results.lokiTunnel.status === 'online'
  const hasMcp = results.querySanitizer.status === 'online' && results.pseudonymizer.status === 'online'
  const lokiSource = results.lokiLocal.status === 'online' ? 'local' : (results.lokiTunnel.status === 'online' ? 'tunnel' : 'none')

  return {
    status: hasLoki && hasMcp ? 'ready' : 'partial',
    lokiSource,
    components: results,
  }
})
