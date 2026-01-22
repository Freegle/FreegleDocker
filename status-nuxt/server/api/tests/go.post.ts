import { spawn } from 'child_process'
import { getTestState, setTestState, appendTestLogs, isTestRunning } from '../../utils/testState'

export default defineEventHandler(async (event) => {
  console.log('Starting Go tests...')

  const query = getQuery(event)
  const withCoverage = query.coverage === 'true'

  // Check if already running
  if (isTestRunning('go')) {
    throw createError({
      statusCode: 409,
      message: 'Go tests are already running'
    })
  }

  // Initialize test status
  setTestState('go', {
    status: 'running',
    message: 'Setting up Go test database...',
    logs: '',
    progress: { completed: 0, total: 0, passed: 0, failed: 0, current: '' },
    startTime: Date.now(),
    endTime: null,
    withCoverage,
  })

  // Build test command
  const testCmd = withCoverage
    ? 'export CGO_ENABLED=1 && export MYSQL_DBNAME=iznik_go_test && go mod tidy && go test -v -race -coverprofile=coverage.out ./test/... -coverpkg ./...'
    : 'export MYSQL_DBNAME=iznik_go_test && go test ./test/... -v'

  // Run tests asynchronously
  const testProcess = spawn('sh', ['-c', `
    set -e
    echo "Setting up Go test database (iznik_go_test)..."

    # Copy schema from main iznik database
    docker exec freegle-apiv1 sh -c "\\
      mysql -h percona -u root -piznik -e 'DROP DATABASE IF EXISTS iznik_go_test; CREATE DATABASE iznik_go_test;' && \\
      mysqldump -h percona -u root -piznik --no-data --routines --triggers iznik | mysql -h percona -u root -piznik iznik_go_test && \\
      mysql -h percona -u root -piznik -e \\"SET GLOBAL sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'\\" && \\
      mysql -h percona -u root -piznik -e \\"SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));\\"" || echo "Warning: Database setup had issues, continuing..."

    echo "Running Go tests against iznik_go_test database..."
    docker exec -w /app freegle-apiv2 sh -c "${testCmd} 2>&1"
  `], { stdio: 'pipe' })

  testProcess.stdout.on('data', (data) => {
    const text = data.toString()
    appendTestLogs('go', text)

    const state = getTestState('go')
    const lines = text.split('\n')

    for (const line of lines) {
      // Count test starts: === RUN   TestName
      const runMatch = line.match(/^=== RUN\s+(\S+)/)
      if (runMatch) {
        state.progress.current = runMatch[1]
      }
      // Count passes: --- PASS: TestName
      if (line.match(/^--- PASS:/)) {
        state.progress.passed++
        state.progress.completed++
      }
      // Count failures: --- FAIL: TestName
      if (line.match(/^--- FAIL:/)) {
        state.progress.failed++
        state.progress.completed++
      }
    }

    // Update message with current progress
    const p = state.progress
    if (p.current) {
      state.message = `Running: ${p.current} (${p.passed}✓ ${p.failed}✗)`
    }

    setTestState('go', state)
  })

  testProcess.stderr.on('data', (data) => {
    appendTestLogs('go', data.toString())
  })

  testProcess.on('close', (code) => {
    const state = getTestState('go')
    const p = state.progress
    setTestState('go', {
      status: code === 0 ? 'completed' : 'failed',
      success: code === 0,
      endTime: Date.now(),
      message: code === 0
        ? `All tests passed (${p.passed}✓)`
        : `Tests failed (${p.passed}✓ ${p.failed}✗)`,
    })
    console.log(`Go tests completed with code ${code}`)
  })

  testProcess.on('error', (error) => {
    setTestState('go', {
      status: 'failed',
      message: `Error: ${error.message}`,
      endTime: Date.now(),
    })
  })

  return { status: 'started' }
})
