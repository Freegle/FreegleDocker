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

    // Build test command
    let testCmd = 'npx playwright test --reporter=json'
    if (testFile) testCmd += ` ${testFile}`
    if (testName) testCmd += ` --grep "${testName}"`

    setTestState('playwright', { message: 'Running Playwright tests...' })
    appendTestLogs('playwright', `Running: ${testCmd}\n`)

    // Run tests
    const testProcess = spawn('sh', ['-c', `
      docker exec freegle-playwright sh -c "${testCmd} 2>&1"
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

  // Look for test counts in Playwright JSON output
  const passedMatch = text.match(/"passed":\s*(\d+)/)
  const failedMatch = text.match(/"failed":\s*(\d+)/)
  const totalMatch = text.match(/"total":\s*(\d+)/)

  if (passedMatch) state.progress.passed = parseInt(passedMatch[1])
  if (failedMatch) state.progress.failed = parseInt(failedMatch[1])
  if (totalMatch) state.progress.total = parseInt(totalMatch[1])

  // Look for test start markers
  const testMatch = text.match(/Running \d+ tests? using \d+ workers?/)
  if (testMatch) {
    state.message = testMatch[0]
  }

  // Update completed
  state.progress.completed = state.progress.passed + state.progress.failed

  setTestState('playwright', state)
}
