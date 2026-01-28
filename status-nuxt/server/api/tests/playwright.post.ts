import { spawn, execSync } from 'child_process'
import { getTestState, setTestState, appendTestLogs, isTestRunning } from '../../utils/testState'

export default defineEventHandler(async (event) => {
  const query = getQuery(event)
  const body = await readBody(event).catch(() => ({}))

  // Get test file and name from query params or body
  const testFile = (body?.testFile || query.testSpec) as string | null
  const testName = (body?.testName || body?.filter || query.testName || query.filter) as string | null

  let logMessage = 'Received request to run Playwright tests'
  if (testFile) logMessage += ` for file: ${testFile}`
  if (testName) logMessage += ` with grep: "${testName}"`
  if (!testFile && !testName) logMessage += ' (all tests)'
  console.log(logMessage)

  // Check if already running
  if (isTestRunning('playwright')) {
    throw createError({
      statusCode: 409,
      message: 'Playwright tests are already running'
    })
  }

  // Initialize test status
  let statusMessage = 'Initializing test environment...'
  if (testFile && testName) {
    statusMessage = `Running test "${testName}" in ${testFile}`
  } else if (testFile) {
    statusMessage = `Running specific test file: ${testFile}`
  } else if (testName) {
    statusMessage = `Running tests matching: "${testName}"`
  }

  setTestState('playwright', {
    status: 'running',
    message: statusMessage,
    logs: '',
    progress: { completed: 0, total: 0, passed: 0, failed: 0, current: '' },
    startTime: Date.now(),
    endTime: null,
  })

  // Run tests asynchronously
  runPlaywrightTests(testFile, testName).catch((error) => {
    console.error('Test execution error:', error)
    setTestState('playwright', {
      status: 'failed',
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
  })

  return { status: 'started', message: 'Playwright tests started successfully' }
})

async function runPlaywrightTests(testFile: string | null, testName: string | null) {
  try {
    // Check freegle-prod-local is running
    const freegleProdCheck = execSync(
      'docker ps --filter "name=freegle-prod-local" --format "{{.Status}}"',
      { encoding: 'utf8', timeout: 5000 }
    ).trim()

    if (!freegleProdCheck.includes('Up')) {
      throw new Error('Freegle Production container is not running')
    }

    appendTestLogs('playwright', 'Freegle Production container is running\n')

    // Restart Playwright container
    setTestState('playwright', { message: 'Restarting Playwright container...' })
    appendTestLogs('playwright', 'Restarting Playwright container...\n')

    try {
      execSync('docker restart freegle-playwright', {
        encoding: 'utf8',
        timeout: 30000,
      })
      appendTestLogs('playwright', 'Playwright container restarted\n')
    } catch (restartError: any) {
      appendTestLogs('playwright', `Warning: Failed to restart container: ${restartError.message}\n`)
    }

    // Wait a bit for container to be ready
    await new Promise(resolve => setTimeout(resolve, 3000))

    // Build test args for both --list and actual run
    let testArgs = ''
    if (testFile) testArgs += ` ${testFile}`
    if (testName) testArgs += ` --grep "${testName}"`

    // Get accurate test count using --list before running
    setTestState('playwright', { message: 'Counting tests...' })
    try {
      const listOutput = execSync(
        `docker exec freegle-playwright sh -c "cd /app && export NODE_PATH=/usr/lib/node_modules && npx playwright test --list${testArgs}"`,
        { encoding: 'utf8', timeout: 60000 }
      )
      // Count lines that match test entries (lines with [chromium] marker)
      const testLines = listOutput.split('\n').filter(line => line.includes('[chromium]'))
      if (testLines.length > 0) {
        const state = getTestState('playwright')
        state.progress.total = testLines.length
        setTestState('playwright', state)
        appendTestLogs('playwright', `Test count from --list: ${testLines.length}\n`)
        console.log(`Playwright --list found ${testLines.length} tests`)
      }
    } catch (listError: any) {
      console.warn('Could not get test count from --list:', listError.message)
      appendTestLogs('playwright', 'Could not pre-count tests, will determine from output\n')
    }

    // Build test command - use default reporters from playwright.config.js (list, html, junit)
    // This ensures HTML report is generated for CI artifacts
    const testCmd = `npx playwright test${testArgs}`

    setTestState('playwright', { message: 'Running Playwright tests...' })
    appendTestLogs('playwright', `Running: ${testCmd}\n`)

    // Run tests - NODE_PATH needed so require('@playwright/test') finds global install
    const testProcess = spawn('sh', ['-c', `
      docker exec freegle-playwright sh -c "cd /app && export NODE_PATH=/usr/lib/node_modules && ${testCmd} 2>&1"
    `], { stdio: 'pipe' })

    testProcess.stdout.on('data', (data) => {
      const text = data.toString()
      appendTestLogs('playwright', text)
      parsePlaywrightOutput(text)
    })

    testProcess.stderr.on('data', (data) => {
      appendTestLogs('playwright', data.toString())
    })

    testProcess.on('close', (code) => {
      const state = getTestState('playwright')
      const p = state.progress

      setTestState('playwright', {
        status: code === 0 ? 'completed' : 'failed',
        success: code === 0,
        endTime: Date.now(),
        message: code === 0
          ? `All tests passed (${p.passed}✓)`
          : `Tests failed (${p.passed}✓ ${p.failed}✗)`,
      })
      console.log(`Playwright tests completed with code ${code}`)
    })

    testProcess.on('error', (error) => {
      setTestState('playwright', {
        status: 'failed',
        message: `Error: ${error.message}`,
        endTime: Date.now(),
      })
    })
  } catch (error: any) {
    setTestState('playwright', {
      status: 'failed',
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
    throw error
  }
}

function parsePlaywrightOutput(text: string) {
  const state = getTestState('playwright')

  // Look for test counts in JSON output (if available)
  const jsonPassedMatch = text.match(/"passed":\s*(\d+)/)
  const jsonFailedMatch = text.match(/"failed":\s*(\d+)/)
  const jsonTotalMatch = text.match(/"total":\s*(\d+)/)

  if (jsonPassedMatch) state.progress.passed = parseInt(jsonPassedMatch[1])
  if (jsonFailedMatch) state.progress.failed = parseInt(jsonFailedMatch[1])
  if (jsonTotalMatch) state.progress.total = parseInt(jsonTotalMatch[1])

  // Look for list reporter summary format: "X passed" or "X failed"
  const listPassedMatch = text.match(/(\d+)\s+passed/)
  const listFailedMatch = text.match(/(\d+)\s+failed/)
  const listSkippedMatch = text.match(/(\d+)\s+skipped/)

  if (listPassedMatch) state.progress.passed = parseInt(listPassedMatch[1])
  if (listFailedMatch) state.progress.failed = parseInt(listFailedMatch[1])

  // Look for test start markers and extract total count
  const testMatch = text.match(/Running (\d+) tests? using \d+ workers?/)
  if (testMatch) {
    state.message = testMatch[0]
    state.progress.total = parseInt(testMatch[1])
  }

  // Count individual test completions from accumulated logs (✓ or ✗ or ◼)
  // Use accumulated logs to get accurate total counts
  const allLogs = state.logs || ''
  const passSymbols = (allLogs.match(/✓/g) || []).length
  const failSymbols = (allLogs.match(/✗/g) || []).length

  // Symbol counts from accumulated logs are the authoritative count during test run
  // Only use these if we haven't seen summary stats yet
  if (!listPassedMatch && passSymbols > 0) state.progress.passed = passSymbols
  if (!listFailedMatch && failSymbols > 0) state.progress.failed = failSymbols

  // Update completed
  state.progress.completed = state.progress.passed + state.progress.failed

  setTestState('playwright', state)
}
