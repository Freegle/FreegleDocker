#!/bin/bash
# Hook script to prevent running tests on the live server.
# On CI (CIRCLECI=true) this hook is a no-op — tests run normally there.

# Only block on production. CI and dev environments can run tests freely.
if [ -n "$CI" ]; then
  exit 0
fi

# Check COMPOSE_PROFILES from .env — if it doesn't contain "production", allow tests.
if [ -f "$CLAUDE_PROJECT_DIR/.env" ]; then
  PROFILES=$(grep -E '^COMPOSE_PROFILES=' "$CLAUDE_PROJECT_DIR/.env" | cut -d= -f2-)
  if ! echo "$PROFILES" | grep -q 'production'; then
    exit 0
  fi
fi

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

# Block curl POST to status container test API (triggers test execution on live).
# Allow GET requests (status checks) but block POST (test runs).
if echo "$COMMAND" | grep -qE '\bcurl\b.*-X\s*POST.*localhost:8081/api/tests'; then
  echo "BLOCKED: Do not run tests on the live server. Use CircleCI instead." >&2
  echo "" >&2
  echo "  Push to master and CI will run tests automatically." >&2
  echo "  To check CI status: gh run list --repo Freegle/FreegleDocker" >&2
  exit 2
fi

# Skip commands that are just downloading/reading files (not executing tests).
# These commonly contain test-related strings in URLs or file paths.
IS_DATA_COMMAND=false
if echo "$COMMAND" | grep -qE '\bcurl\b.*localhost:8081/api/tests.*/status'; then
  IS_DATA_COMMAND=true
fi
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
echo "BLOCKED: Do not run tests on the live server. Use CircleCI instead." >&2
echo "" >&2
echo "  Push to master and CI will run tests automatically." >&2
echo "  To check CI status: gh run list --repo Freegle/FreegleDocker" >&2
exit 2
