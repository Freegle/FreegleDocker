#!/bin/bash
# Hook script to prevent running tests directly
# Tests should be run via the status container API:
#   curl -X POST http://localhost:8081/api/tests/playwright
#   curl -X POST http://localhost:8081/api/tests/php
#   curl -X POST http://localhost:8081/api/tests/go
#
# To override intentionally, add "# DIRECT_TEST_OK" at the end of the command

# Read the tool input from stdin
INPUT=$(cat)

# Extract the command from the JSON input
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // empty')

if [ -z "$COMMAND" ]; then
  exit 0  # No command, allow
fi

# Check if this is a test command
TEST_PATTERNS=(
  "playwright test"
  "npx playwright test"
  "npm run test"
  "npm test"
  "go test"
  "phpunit"
  "artisan test"
  "artisan dusk"
  "vendor/bin/phpunit"
)

IS_TEST_COMMAND=false
for pattern in "${TEST_PATTERNS[@]}"; do
  if echo "$COMMAND" | grep -q "$pattern"; then
    IS_TEST_COMMAND=true
    break
  fi
done

if [ "$IS_TEST_COMMAND" = false ]; then
  exit 0  # Not a test command, allow
fi

# Check for override comment
if echo "$COMMAND" | grep -q "# DIRECT_TEST_OK"; then
  exit 0  # Override present, allow
fi

# Block the command
echo "BLOCKED: Do not run tests directly. Use the status container API instead:" >&2
echo "" >&2
echo "  Playwright:   curl -X POST http://localhost:8081/api/tests/playwright" >&2
echo "  PHPUnit:      curl -X POST http://localhost:8081/api/tests/php" >&2
echo "  Go tests:     curl -X POST http://localhost:8081/api/tests/go" >&2
echo "  iznik-batch:  curl -X POST http://localhost:8081/api/tests/iznik-batch" >&2
echo "" >&2
echo "To check status: curl -s http://localhost:8081/api/tests/<type>/status | jq '.'" >&2
echo "" >&2
echo "To override (rare cases only), add '# DIRECT_TEST_OK' at the end of the command." >&2
exit 2
