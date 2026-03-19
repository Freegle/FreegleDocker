import { spawn, execSync } from 'child_process'
import { writeFileSync } from 'fs'
import { getTestState, setTestState, appendTestLogs, isTestRunning } from '../../utils/testState'

export default defineEventHandler(async (event) => {
  const query = getQuery(event)
  const body = await readBody(event).catch(() => ({}))

  // Get test file, name, and workers from query params or body
  const testFile = (body?.testFile || query.testSpec || query.spec) as string | null
  const testName = (body?.testName || body?.filter || query.testName || query.filter) as string | null
  const workers = (body?.workers || query.workers) as string | null

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

    // Pre-generate isolated test environments and copy to Playwright container
    setTestState('playwright', { message: 'Creating test environments...' })
    appendTestLogs('playwright', 'Pre-generating isolated test environments...\n')
    try {
      const prefixes = [
        'browse', 'explore', 'mtholdrelease', 'mtchatlist', 'mtdashboard',
        'mtedits', 'mtmemberlogs', 'mtmovemessage', 'mtpageloads', 'mtpendingmessages',
        'mtsupport', 'postflow', 'replyflowedgecases', 'replyflowexistinguser',
        'replyflowloggedin', 'replyflowlogging', 'replyflownewuser', 'replyflowsocial',
        'userratings', 'v2apipages',
      ]
      const envs: Record<string, any> = {}
      for (const prefix of prefixes) {
        try {
          const output = execSync(
            `docker exec freegle-apiv1 php /var/www/iznik/install/create-test-env.php ${prefix}`,
            { encoding: 'utf8', timeout: 30000 }
          )
          envs[prefix] = JSON.parse(output.trim())
        } catch (e: any) {
          const stderr = e.stderr ? e.stderr.toString().slice(0, 500) : 'no stderr'
          const stdout = e.stdout ? e.stdout.toString().slice(0, 500) : 'no stdout'
          appendTestLogs('playwright', `Warning: Failed to create env for ${prefix}: ${e.message}\nSTDERR: ${stderr}\nSTDOUT: ${stdout}\n`)
        }
      }
      // Write JSON file and copy to Playwright container
      const tmpFile = '/tmp/test-envs.json'
      writeFileSync(tmpFile, JSON.stringify(envs, null, 2))
      execSync(`docker cp ${tmpFile} freegle-playwright:/app/tests/e2e/test-envs.json`, {
        encoding: 'utf8', timeout: 5000,
      })
      appendTestLogs('playwright', `Created ${Object.keys(envs).length} test environments\n`)

    } catch (envError: any) {
      appendTestLogs('playwright', `Warning: Test env generation failed: ${envError.message}\n`)
    }

    // Build test args for both --list and actual run
    // If testFile is a bare name (no path separators), expand to tests/e2e/<name>.spec.js
    let resolvedTestFile = testFile
    if (resolvedTestFile && !resolvedTestFile.includes('/')) {
      const name = resolvedTestFile.replace(/\.spec\.js$/, '')
      resolvedTestFile = `tests/e2e/${name}.spec.js`
    }
    let testArgs = ''
    if (resolvedTestFile) testArgs += ` ${resolvedTestFile}`
    if (testName) testArgs += ` --grep "${testName}"`
    if (workers) testArgs += ` --workers=${parseInt(workers, 10)}`

    // Clear any stale status file from a previous run
    try {
      execSync(
        'docker exec freegle-playwright rm -f /app/test-results/test-status.json',
        { encoding: 'utf8', timeout: 5000 }
      )
    } catch {
      // ignore
    }

    // Build test command - use default reporters from playwright.config.js
    // (list, html, junit, status-reporter)
    const testCmd = `npx playwright test${testArgs}`

    setTestState('playwright', { message: 'Running Playwright tests...' })
    appendTestLogs('playwright', `Running: ${testCmd}\n`)

    // Run tests - NODE_PATH needed so require('@playwright/test') finds global install
    const testProcess = spawn('sh', ['-c', `
      docker exec freegle-playwright sh -c "cd /app && export NODE_PATH=/usr/lib/node_modules && ${testCmd} 2>&1"
    `], { stdio: 'pipe' })

    // Capture stdout/stderr for log display only — progress comes from status-reporter JSON
    testProcess.stdout.on('data', (data) => {
      const text = data.toString()
      appendTestLogs('playwright', text)

      // Only parse the "Running N tests" line for the initial message
      const runningMatch = text.match(/Running (\d+) tests? using \d+ workers?/)
      if (runningMatch) {
        setTestState('playwright', { message: runningMatch[0] })
      }
    })

    testProcess.stderr.on('data', (data) => {
      appendTestLogs('playwright', data.toString())
    })

    // Poll the status-reporter JSON file every 3 seconds for accurate progress
    const pollInterval = setInterval(() => {
      const containerStatus = readStatusFromContainer()
      if (containerStatus) {
        const state = getTestState('playwright')
        state.progress.total = containerStatus.total
        state.progress.passed = containerStatus.passed
        state.progress.failed = containerStatus.failed
        state.progress.completed = containerStatus.completed

        if (containerStatus.running.length > 0) {
          state.progress.current = containerStatus.running[0]
          if (containerStatus.running.length > 1) {
            state.progress.current += ` (+${containerStatus.running.length - 1} more)`
          }
        } else if (containerStatus.currentTest) {
          state.progress.current = containerStatus.currentTest
        }

        setTestState('playwright', state)
      }
    }, 3000)

    testProcess.on('close', (code) => {
      clearInterval(pollInterval)

      // Final read from status-reporter JSON for accurate counts
      const containerStatus = readStatusFromContainer()
      const state = getTestState('playwright')

      if (containerStatus) {
        state.progress.total = containerStatus.total
        state.progress.passed = containerStatus.passed
        state.progress.failed = containerStatus.failed
        state.progress.completed = containerStatus.completed
        state.progress.current = ''
      }

      setTestState('playwright', {
        status: code === 0 ? 'completed' : 'failed',
        success: code === 0,
        endTime: Date.now(),
        message: code === 0
          ? `All tests passed (${state.progress.passed} passed)`
          : `Tests completed (${state.progress.passed} passed, ${state.progress.failed} failed)`,
      })
      console.log(`Playwright tests completed with code ${code}`)
    })

    testProcess.on('error', (error) => {
      clearInterval(pollInterval)
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

function readStatusFromContainer(): {
  state: string
  total: number
  passed: number
  failed: number
  skipped: number
  completed: number
  running: string[]
  currentTest: string
} | null {
  try {
    const output = execSync(
      'docker exec freegle-playwright cat /app/test-results/test-status.json',
      { encoding: 'utf8', timeout: 5000 }
    )
    return JSON.parse(output)
  } catch {
    return null
  }
}
