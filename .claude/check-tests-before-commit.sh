#!/bin/bash
# PreToolUse hook for git commit: remind to check tests
# Non-blocking — outputs a systemMessage reminder

cat <<'EOF'
{"systemMessage": "Pre-commit checklist: a) Have you added or updated tests for this change? (If you changed existing behaviour, existing tests may need updating too) b) Have you run the affected tests? c) Have you run the full test suite via the status API (Go: docker exec freegle-apiv2; Vitest: docker exec freegle-dev-local npx vitest run; Laravel: curl -X POST http://localhost:8081/api/tests/laravel)?"}
EOF
