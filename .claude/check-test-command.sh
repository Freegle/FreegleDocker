#!/bin/bash
# Hook script to prevent running tests directly
# Tests should be run via the status container API:
#   curl -X POST http://localhost:8081/api/tests/playwright
#   curl -X POST http://localhost:8081/api/tests/php
#   curl -X POST http://localhost:8081/api/tests/go

# Read the tool input from stdin
INPUT=$(cat)

# Extract the command from the JSON input
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // empty')

if [ -z "$COMMAND" ]; then
  exit 0  # No command, allow
fi

# Check if this is a test execution command.
# We match actual test runner invocations, not arbitrary strings in URLs/paths.
# Each pattern is a regex that anchors to word boundaries or command structure.
TEST_PATTERNS=(
  '\bplaywright test\b'
  '\bnpx playwright test\b'
  '\bnpm run test\b'
  '\bnpm test\b'
  '\bgo test\b'
  '\bartisan test\b'
  '\bartisan dusk\b'
  '\bvendor/bin/phpunit\b'
  '\bvendor/phpunit/phpunit/phpunit\b'
  '\bphp\b.*\bphpunit\b'
  '\bvitest\b'
  '\bnpx vitest\b'
)

# Skip commands that are just downloading/reading files (not executing tests).
# These commonly contain test-related strings in URLs or file paths.
IS_DATA_COMMAND=false
if echo "$COMMAND" | grep -qE '\bcurl\b.*circle-artifacts\.com'; then
  IS_DATA_COMMAND=true
fi
if echo "$COMMAND" | grep -qE '\bcurl\b.*circleci\.com/api'; then
  IS_DATA_COMMAND=true
fi
if echo "$COMMAND" | grep -qE '^\s*(cat|tail|head|less|wc)\b.*/tmp/'; then
  IS_DATA_COMMAND=true
fi
# docker exec commands that only read processes/logs (not running tests)
if echo "$COMMAND" | grep -qE '\bdocker (top|logs)\b'; then
  IS_DATA_COMMAND=true
fi
# grep/ps commands looking at processes
if echo "$COMMAND" | grep -qE '\b(ps|pgrep)\b'; then
  IS_DATA_COMMAND=true
fi
# git commands (commit messages may mention test tools)
if echo "$COMMAND" | grep -qE '^\s*git\b'; then
  IS_DATA_COMMAND=true
fi

IS_TEST_COMMAND=false
if [ "$IS_DATA_COMMAND" = false ]; then
  for pattern in "${TEST_PATTERNS[@]}"; do
    if echo "$COMMAND" | grep -qE "$pattern"; then
      IS_TEST_COMMAND=true
      break
    fi
  done
fi

if [ "$IS_TEST_COMMAND" = false ]; then
  exit 0  # Not a test command, allow
fi

# Allow --list (dry run) and --help since they don't execute tests
if echo "$COMMAND" | grep -qE -- "--list|--help"; then
  exit 0
fi

# Block the command
echo "BLOCKED: Do not run tests directly. Use the status container API or CI:" >&2
echo "" >&2
echo "  Playwright:   curl -X POST http://localhost:8081/api/tests/playwright" >&2
echo "  PHPUnit:      curl -X POST http://localhost:8081/api/tests/php" >&2
echo "  Go tests:     curl -X POST http://localhost:8081/api/tests/go" >&2
echo "  iznik-batch:  curl -X POST http://localhost:8081/api/tests/iznik-batch" >&2
echo "  Vitest:       Push to branch and check CircleCI (runs in iznik-nuxt3 repo)" >&2
echo "" >&2
echo "To check status: curl -s http://localhost:8081/api/tests/<type>/status | jq '.'" >&2
exit 2
