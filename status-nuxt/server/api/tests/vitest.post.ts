import { spawn } from 'child_process'
import { getTestState, setTestState, appendTestLogs, isTestRunning } from '../../utils/testState'

export default defineEventHandler(async (event) => {
  console.log('Starting Vitest tests...')

  const body = await readBody(event).catch(() => ({}))
  const filter = body?.filter || ''

  if (isTestRunning('vitest')) {
    throw createError({
      statusCode: 409,
      message: 'Vitest tests are already running'
    })
  }

  setTestState('vitest', {
    status: 'running',
    message: 'Starting Vitest...',
    logs: '',
    progress: { completed: 0, total: 0, passed: 0, failed: 0, current: '' },
    startTime: Date.now(),
    endTime: null,
  })

  const filterArg = filter ? ` --reporter=verbose "${filter}"` : ' --reporter=verbose'
  const testCmd = `cd /app && npx vitest run${filterArg} 2>&1`

  const testProcess = spawn('sh', ['-c', `
    docker exec -w /app modtools-dev-local sh -c '${testCmd}'
  `], { stdio: 'pipe' })

  testProcess.stdout.on('data', (data) => {
    const text = data.toString()
    appendTestLogs('vitest', text)

    const state = getTestState('vitest')
    const lines = text.split('\n')

    for (const line of lines) {
      // Match pass: ✓ test name (duration)
      if (line.match(/^\s*[✓✔]/)) {
        state.progress.passed++
        state.progress.completed++
        const nameMatch = line.match(/[✓✔]\s+(.+?)(?:\s+\(\d+)/)
        if (nameMatch) {
          state.progress.current = nameMatch[1].trim()
        }
      }
      // Match fail: × test name or ✗ test name
      if (line.match(/^\s*[×✗✘]/)) {
        state.progress.failed++
        state.progress.completed++
      }
      // Match total from "Tests  X passed" line
      const totalMatch = line.match(/Tests\s+(\d+)\s+passed/)
      if (totalMatch) {
        state.progress.passed = parseInt(totalMatch[1])
      }
      const failedMatch = line.match(/(\d+)\s+failed/)
      if (failedMatch) {
        state.progress.failed = parseInt(failedMatch[1])
      }
      // Match test file progress: e.g. "✓ tests/unit/components/modtools/ModMessage.spec.js (30)"
      const fileMatch = line.match(/[✓✔]\s+(tests\/\S+)\s+\((\d+)\)/)
      if (fileMatch) {
        state.progress.current = fileMatch[1]
      }
    }

    const p = state.progress
    if (p.current) {
      state.message = `Running: ${p.current} (${p.passed}✓ ${p.failed}✗)`
    }

    setTestState('vitest', state)
  })

  testProcess.stderr.on('data', (data) => {
    appendTestLogs('vitest', data.toString())
  })

  testProcess.on('close', (code) => {
    const state = getTestState('vitest')
    const p = state.progress
    setTestState('vitest', {
      status: code === 0 ? 'completed' : 'failed',
      success: code === 0,
      endTime: Date.now(),
      message: code === 0
        ? `All tests passed (${p.passed}✓)`
        : `Tests failed (${p.passed}✓ ${p.failed}✗)`,
    })
    console.log(`Vitest tests completed with code ${code}`)
  })

  testProcess.on('error', (error) => {
    setTestState('vitest', {
      status: 'failed',
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
  })

  return { status: 'started' }
})
